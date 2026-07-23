<?php

declare(strict_types=1);

class MeteoSchweizHagelwarnung extends IPSModule
{
    private const API_BASE_URL = 'https://app-prod-ws.meteoswiss-app.ch/v3/plzDetail?plz=%d00';

    // MeteoSchweiz kennt keinen eigenen Warntyp für Hagel: Das Hagelrisiko wird
    // im Text der Gewitterwarnung (warnType 1) mitgeteilt.
    private const WARNTYP_GEWITTER = 1;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('PLZ', 8000);
        $this->RegisterPropertyInteger('UpdateInterval', 15);
        $this->RegisterPropertyBoolean('NurBeiHagelErwaehnung', true);

        $this->RegisterTimer('UpdateTimer', 0, 'MSH_UpdateWarnung($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (!IPS_VariableProfileExists('MSH.Warnstufe')) {
            IPS_CreateVariableProfile('MSH.Warnstufe', 1); // 1 = Integer
        }
        IPS_SetVariableProfileAssociation('MSH.Warnstufe', 0, 'Keine Warnung', '', -1);
        IPS_SetVariableProfileAssociation('MSH.Warnstufe', 1, 'Minimal', '', 0x8BC34A);
        IPS_SetVariableProfileAssociation('MSH.Warnstufe', 2, 'Mässig', '', 0xFFEB3B);
        IPS_SetVariableProfileAssociation('MSH.Warnstufe', 3, 'Erheblich', '', 0xFF9800);
        IPS_SetVariableProfileAssociation('MSH.Warnstufe', 4, 'Gross', '', 0xF44336);
        IPS_SetVariableProfileAssociation('MSH.Warnstufe', 5, 'Sehr gross', '', 0x9C27B0);

        // Primäres Vertrauens-Signal: true = auf die Warndaten ist gerade kein
        // Verlass (Abruf-/Parse-Fehler, ungültige PLZ). Analog zum gleichnamigen
        // Signal in MeteoSchweizHagelradar, damit sich in IP-Symcon ein
        // einheitliches "Schnittstelle gestört"-Ereignis bauen lässt.
        $this->RegisterVariableBoolean('SchutzNichtGewaehrleistet', 'Schutz nicht gewährleistet (Schnittstelle gestört)', '~Alert', 5);
        $this->RegisterVariableInteger('Warnstufe', 'Warnstufe (Gewitter/Hagel)', 'MSH.Warnstufe', 10);
        $this->RegisterVariableBoolean('HagelAktiv', 'Hagelwarnung aktiv', '~Alert', 20);
        $this->RegisterVariableString('WarnText', 'Warntext', '', 30);
        $this->RegisterVariableString('WarnTextHTML', 'Warntext (HTML)', '', 40);
        $this->RegisterVariableInteger('GueltigVon', 'Gültig von', '~UnixTimestamp', 50);
        $this->RegisterVariableInteger('GueltigBis', 'Gültig bis', '~UnixTimestamp', 60);
        $this->RegisterVariableBoolean('Ausblick', 'Ausblick (Vorwarnung)', '', 70);
        $this->RegisterVariableInteger('LetzteAktualisierung', 'Letzte erfolgreiche Aktualisierung', '~UnixTimestamp', 80);
        // Eigener Herzschlag, unabhängig vom Ergebnis - siehe UpdateWarnung().
        $this->RegisterVariableInteger('LetztePruefung', 'Letzte Prüfung durch dieses Modul', '~UnixTimestamp', 85);

        IPS_SetHidden($this->GetIDForIdent('WarnTextHTML'), true);

        $plz = $this->ReadPropertyInteger('PLZ');
        if ($plz < 1000 || $plz > 9999) {
            $this->SetValue('SchutzNichtGewaehrleistet', true);
            $this->SetValue('HagelAktiv', false);
            $this->SetTimerInterval('UpdateTimer', 0);
            $this->SetStatus(201);
            return;
        }

        $interval = max(1, $this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('UpdateTimer', $interval * 60 * 1000);
        $this->SetStatus(102);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UpdateWarnung();
        }
    }

    public function UpdateWarnung(): void
    {
        // Unbedingt zuerst setzen - auch wenn alles Folgende fehlschlägt, soll
        // sichtbar sein, dass dieser Timer-Durchlauf stattgefunden hat (Basis
        // für ein eigenes Watchdog-Ereignis, falls der Timer selbst ausfällt).
        $this->SetValue('LetztePruefung', time());

        $plz = $this->ReadPropertyInteger('PLZ');
        if ($plz < 1000 || $plz > 9999) {
            $this->SetValue('SchutzNichtGewaehrleistet', true);
            $this->SetValue('HagelAktiv', false);
            $this->SetStatus(201);
            return;
        }

        $warnings = $this->FetchWarnings($plz);
        if ($warnings === null) {
            // FetchWarnings() hat Status/Logmeldung bereits gesetzt. Variablen
            // aktiv auf "gestört" setzen statt beim letzten Wert einzufrieren -
            // sonst könnte HagelAktiv fälschlich auf "false" stehen bleiben,
            // während in Wirklichkeit nur die Schnittstelle ausgefallen ist.
            $this->SetValue('SchutzNichtGewaehrleistet', true);
            $this->SetValue('HagelAktiv', false);
            return;
        }

        $this->SetValue('SchutzNichtGewaehrleistet', false);

        $gewitterWarnungen = array_values(array_filter($warnings, function ($warnung) {
            return isset($warnung['warnType']) && (int) $warnung['warnType'] === self::WARNTYP_GEWITTER
                && (int) ($warnung['warnLevel'] ?? 0) > 0;
        }));

        usort($gewitterWarnungen, function ($a, $b) {
            return $b['warnLevel'] <=> $a['warnLevel'];
        });

        $aktuelleWarnung = null;
        foreach ($gewitterWarnungen as $warnung) {
            if (empty($warnung['outlook'])) {
                $aktuelleWarnung = $warnung;
                break;
            }
        }
        if ($aktuelleWarnung === null && count($gewitterWarnungen) > 0) {
            $aktuelleWarnung = $gewitterWarnungen[0];
        }

        if ($aktuelleWarnung === null) {
            $this->SetValue('Warnstufe', 0);
            $this->SetValue('HagelAktiv', false);
            $this->SetValue('WarnText', '');
            $this->SetValue('WarnTextHTML', '');
            $this->SetValue('GueltigVon', 0);
            $this->SetValue('GueltigBis', 0);
            $this->SetValue('Ausblick', false);
        } else {
            $text = (string) ($aktuelleWarnung['text'] ?? '');
            $html = (string) ($aktuelleWarnung['htmlText'] ?? '');
            $nurBeiHagelErwaehnung = $this->ReadPropertyBoolean('NurBeiHagelErwaehnung');

            $this->SetValue('Warnstufe', (int) $aktuelleWarnung['warnLevel']);
            $this->SetValue('HagelAktiv', $nurBeiHagelErwaehnung ? $this->IstHagelErwaehnt($text, $html) : true);
            $this->SetValue('WarnText', $text);
            $this->SetValue('WarnTextHTML', $html);
            $this->SetValue('GueltigVon', isset($aktuelleWarnung['validFrom']) ? intdiv((int) $aktuelleWarnung['validFrom'], 1000) : 0);
            $this->SetValue('GueltigBis', isset($aktuelleWarnung['validTo']) ? intdiv((int) $aktuelleWarnung['validTo'], 1000) : 0);
            $this->SetValue('Ausblick', !empty($aktuelleWarnung['outlook']));
        }

        $this->SetValue('LetzteAktualisierung', time());
        $this->SetStatus(102);
    }

    private function IstHagelErwaehnt(string $text, string $html): bool
    {
        return mb_stripos($text, 'Hagel') !== false || mb_stripos($html, 'Hagel') !== false;
    }

    private function FetchWarnings(int $plz): ?array
    {
        $url = sprintf(self::API_BASE_URL, $plz);

        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'header'        => "Accept: application/json\r\nUser-Agent: Mozilla/5.0 (compatible; IP-Symcon MeteoSchweizHagelwarnung)\r\n",
                'timeout'       => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $this->LogMessage('MeteoSchweizHagelwarnung: Warndaten konnten nicht abgerufen werden (Verbindungsfehler).', KL_WARNING);
            $this->SetStatus(202);
            return null;
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['warnings']) || !is_array($data['warnings'])) {
            $this->LogMessage('MeteoSchweizHagelwarnung: Warndaten konnten nicht ausgewertet werden (unerwartetes Antwortformat).', KL_WARNING);
            $this->SetStatus(202);
            return null;
        }

        return $data['warnings'];
    }
}
