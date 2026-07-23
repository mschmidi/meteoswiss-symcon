<?php

declare(strict_types=1);

class MeteoSchweizHagelradar extends IPSModule
{
    private const DEFAULT_HELPER_CONFIG_PATH = '/etc/meteoswiss-hail-radar/config.json';
    // Kern-Instanz "Location Control", die den IP-Symcon-Systemstandort verwaltet.
    private const LOCATION_CONTROL_MODULE_ID = '{45E97A63-F870-408A-B259-2933F7EABF74}';

    public function Create()
    {
        parent::Create();

        [$defaultLat, $defaultLon] = $this->LiesSystemStandort() ?? [0.0, 0.0];

        $this->RegisterPropertyFloat('Latitude', $defaultLat);
        $this->RegisterPropertyFloat('Longitude', $defaultLon);
        $this->RegisterPropertyString('HelperConfigFilePath', self::DEFAULT_HELPER_CONFIG_PATH);
        $this->RegisterPropertyString('StatusFilePath', '/var/lib/meteoswiss-hail-radar/status.json');
        $this->RegisterPropertyInteger('UpdateInterval', 5);
        $this->RegisterPropertyInteger('POHSchwellenwert', 5);
        $this->RegisterPropertyFloat('MESHSSchwellenwert', 20.0);
        $this->RegisterPropertyInteger('MaxAlterMinuten', 20);

        $this->RegisterTimer('UpdateTimer', 0, 'MSHR_UpdateWarnung($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (!IPS_VariableProfileExists('MSHR.Prozent')) {
            IPS_CreateVariableProfile('MSHR.Prozent', 2); // 2 = Float
        }
        IPS_SetVariableProfileText('MSHR.Prozent', '', ' %');
        IPS_SetVariableProfileValues('MSHR.Prozent', 0, 100, 1);
        IPS_SetVariableProfileDigits('MSHR.Prozent', 1);

        if (!IPS_VariableProfileExists('MSHR.Millimeter')) {
            IPS_CreateVariableProfile('MSHR.Millimeter', 2); // 2 = Float
        }
        IPS_SetVariableProfileText('MSHR.Millimeter', '', ' mm');
        IPS_SetVariableProfileValues('MSHR.Millimeter', 0, 0, 1);
        IPS_SetVariableProfileDigits('MSHR.Millimeter', 1);

        // Primäres Vertrauens-Signal: true = Schutz aktuell NICHT gewährleistet
        // (Schnittstelle gestört, Daten veraltet oder Standort nicht konfiguriert).
        // Darauf lässt sich ein eigenes IP-Symcon-Ereignis aufbauen, unabhängig
        // vom Instanzstatus.
        $this->RegisterVariableBoolean('SchutzNichtGewaehrleistet', 'Schutz nicht gewährleistet (Schnittstelle gestört)', '~Alert', 5);
        $this->RegisterVariableFloat('POH', 'Hagelwahrscheinlichkeit (POH)', 'MSHR.Prozent', 10);
        $this->RegisterVariableFloat('MESHS', 'Erwartete Hagelkorngrösse (MESHS)', 'MSHR.Millimeter', 20);
        $this->RegisterVariableBoolean('HagelGefahr', 'Hagelgefahr (Schwellenwert überschritten)', '~Alert', 30);
        $this->RegisterVariableInteger('Datenzeitstempel', 'Zeitstempel der Radardaten', '~UnixTimestamp', 40);
        $this->RegisterVariableBoolean('SaisonAktiv', 'Hagelsaison aktiv (April-September)', '', 50);
        $this->RegisterVariableString('LetzterFehler', 'Letzter Fehler des Helper-Skripts', '', 60);

        $lat = $this->ReadPropertyFloat('Latitude');
        $lon = $this->ReadPropertyFloat('Longitude');

        if ($lat === 0.0 && $lon === 0.0) {
            $this->SetValue('SchutzNichtGewaehrleistet', true);
            $this->SetValue('HagelGefahr', false);
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(205);
            return;
        }

        $interval = max(1, $this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('UpdateTimer', $interval * 60 * 1000);

        if (!$this->SchreibeHelperKonfiguration($lat, $lon)) {
            $this->SetValue('SchutzNichtGewaehrleistet', true);
            $this->SetValue('HagelGefahr', false);
            $this->SetStatus(206);
        } else {
            $this->SetStatus(102);
        }

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UpdateWarnung();
        }
    }

    // Erlaubt es, den in IP-Symcon hinterlegten Systemstandort (z. B. bereits
    // fuer Sonnenauf-/-untergang gesetzt) jederzeit manuell als Standort fuer
    // dieses Modul zu uebernehmen, ohne Koordinaten von Hand eintippen zu muessen.
    public function StandortUebernehmen(): void
    {
        $standort = $this->LiesSystemStandort();
        if ($standort === null) {
            $this->LogMessage('MeteoSchweizHagelradar: IP-Symcon-Systemstandort konnte nicht gelesen werden.', KL_WARNING);
            return;
        }

        [$lat, $lon] = $standort;
        IPS_SetProperty($this->InstanceID, 'Latitude', $lat);
        IPS_SetProperty($this->InstanceID, 'Longitude', $lon);
        IPS_ApplyChanges($this->InstanceID);
    }

    public function UpdateWarnung(): void
    {
        $pfad = $this->ReadPropertyString('StatusFilePath');
        $inhalt = @file_get_contents($pfad);
        if ($inhalt === false) {
            $this->LogMessage("MeteoSchweizHagelradar: Statusdatei '$pfad' konnte nicht gelesen werden.", KL_WARNING);
            // Variablen bewusst aktiv auf "gestört" setzen statt beim letzten
            // bekannten Wert einzufrieren - sonst bliebe z. B. HagelGefahr auf
            // dem letzten "false" stehen und würde fälschlich Sicherheit vortäuschen.
            $this->SetValue('SchutzNichtGewaehrleistet', true);
            $this->SetValue('HagelGefahr', false);
            $this->SetStatus(202);
            return;
        }

        $daten = json_decode($inhalt, true);
        if (!is_array($daten)) {
            $this->LogMessage("MeteoSchweizHagelradar: Statusdatei '$pfad' enthält kein gültiges JSON.", KL_WARNING);
            $this->SetValue('SchutzNichtGewaehrleistet', true);
            $this->SetValue('HagelGefahr', false);
            $this->SetStatus(202);
            return;
        }

        $letzterFehler = (string) ($daten['last_error'] ?? '');
        $this->SetValue('LetzterFehler', $letzterFehler);
        $this->SetValue('SaisonAktiv', !empty($daten['season_active']));

        $generatedAt = isset($daten['generated_at']) ? strtotime((string) $daten['generated_at']) : false;
        $this->SetValue('Datenzeitstempel', $generatedAt !== false ? $generatedAt : 0);

        $maxAlterSekunden = $this->ReadPropertyInteger('MaxAlterMinuten') * 60;
        $istAktuell = $generatedAt !== false && (time() - $generatedAt) <= $maxAlterSekunden;

        $poh = $daten['poh_percent'] ?? null;
        $meshs = $daten['meshs_mm'] ?? null;

        $this->SetValue('POH', $poh !== null ? (float) $poh : 0.0);
        $this->SetValue('MESHS', $meshs !== null ? (float) $meshs : 0.0);

        // Vertrauenswürdig nur, wenn der Helper aktuell lief UND selbst keinen
        // Fehler meldet (z. B. "kein Asset gefunden" bei geänderter Quelle).
        $schutzNichtGewaehrleistet = !$istAktuell || $letzterFehler !== '';
        $this->SetValue('SchutzNichtGewaehrleistet', $schutzNichtGewaehrleistet);

        $pohSchwelle = $this->ReadPropertyInteger('POHSchwellenwert');
        $meshsSchwelle = $this->ReadPropertyFloat('MESHSSchwellenwert');

        $gefahr = !$schutzNichtGewaehrleistet
            && (($poh !== null && $poh >= $pohSchwelle) || ($meshs !== null && $meshs >= $meshsSchwelle));
        $this->SetValue('HagelGefahr', $gefahr);

        if ($letzterFehler !== '') {
            $this->SetStatus(204);
        } elseif (!$istAktuell) {
            $this->SetStatus(203);
        } else {
            $this->SetStatus(102);
        }
    }

    // Schreibt Standort und Ausgabepfad in die Konfigurationsdatei des
    // Python-Helpers, damit der Standort ausschliesslich in IP-Symcon
    // gepflegt werden muss und nie von Hand auf dem Host editiert werden muss.
    // Das Zielverzeichnis muss dafuer einmalig (siehe Installationsanleitung)
    // fuer den IP-Symcon-Prozess beschreibbar gemacht werden.
    private function SchreibeHelperKonfiguration(float $lat, float $lon): bool
    {
        $konfigPfad = $this->ReadPropertyString('HelperConfigFilePath');
        $verzeichnis = dirname($konfigPfad);

        if (!is_dir($verzeichnis) || !is_writable($verzeichnis)) {
            $this->LogMessage("MeteoSchweizHagelradar: Verzeichnis '$verzeichnis' existiert nicht oder ist für IP-Symcon nicht beschreibbar. Siehe Installationsanleitung.", KL_WARNING);
            return false;
        }

        $konfiguration = [
            'latitude'    => $lat,
            'longitude'   => $lon,
            'output_path' => $this->ReadPropertyString('StatusFilePath'),
        ];

        $tmpPfad = $konfigPfad . '.tmp';
        $json = json_encode($konfiguration, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($tmpPfad, $json) === false) {
            $this->LogMessage("MeteoSchweizHagelradar: Konnte '$tmpPfad' nicht schreiben.", KL_WARNING);
            return false;
        }
        @chmod($tmpPfad, 0644);

        if (!@rename($tmpPfad, $konfigPfad)) {
            $this->LogMessage("MeteoSchweizHagelradar: Konnte '$tmpPfad' nicht nach '$konfigPfad' verschieben.", KL_WARNING);
            @unlink($tmpPfad);
            return false;
        }

        return true;
    }

    /** @return array{0: float, 1: float}|null */
    private function LiesSystemStandort(): ?array
    {
        $instanzIDs = @IPS_GetInstanceListByModuleID(self::LOCATION_CONTROL_MODULE_ID);
        if (!is_array($instanzIDs) || count($instanzIDs) === 0) {
            return null;
        }

        $json = @IPS_GetProperty($instanzIDs[0], 'Location');
        $daten = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($daten) || !isset($daten['latitude'], $daten['longitude'])) {
            return null;
        }

        $lat = (float) $daten['latitude'];
        $lon = (float) $daten['longitude'];
        if ($lat === 0.0 && $lon === 0.0) {
            return null;
        }

        return [$lat, $lon];
    }
}
