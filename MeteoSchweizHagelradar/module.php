<?php

declare(strict_types=1);

class MeteoSchweizHagelradar extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('StatusFilePath', '/var/lib/meteoswiss-hail-radar/status.json');
        $this->RegisterPropertyInteger('UpdateInterval', 5);
        $this->RegisterPropertyInteger('POHSchwellenwert', 50);
        $this->RegisterPropertyFloat('MESHSSchwellenwert', 20.0);
        $this->RegisterPropertyInteger('MaxAlterMinuten', 20);

        $this->RegisterTimer('UpdateTimer', 0, 'MSHR_UpdateWarnung($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfileFloatEx('MSHR.Prozent', '', '', ' %', []);
        IPS_SetVariableProfileValues('MSHR.Prozent', 0, 100, 1);
        IPS_SetVariableProfileDigits('MSHR.Prozent', 1);

        $this->RegisterVariableFloat('POH', 'Hagelwahrscheinlichkeit (POH)', 'MSHR.Prozent', 10);
        $this->RegisterVariableFloat('MESHS', 'Erwartete Hagelkorngrösse (MESHS)', '', 20);
        $this->RegisterVariableBoolean('HagelGefahr', 'Hagelgefahr (Schwellenwert überschritten)', '~Alert', 30);
        $this->RegisterVariableInteger('Datenzeitstempel', 'Zeitstempel der Radardaten', '~UnixTimestamp', 40);
        $this->RegisterVariableBoolean('SaisonAktiv', 'Hagelsaison aktiv (April-September)', '', 50);
        $this->RegisterVariableString('LetzterFehler', 'Letzter Fehler des Helper-Skripts', '', 60);

        $interval = max(1, $this->ReadPropertyInteger('UpdateInterval'));
        $this->SetTimerInterval('UpdateTimer', $interval * 60 * 1000);
        $this->SetStatus(102);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UpdateWarnung();
        }
    }

    public function UpdateWarnung(): void
    {
        $pfad = $this->ReadPropertyString('StatusFilePath');
        $inhalt = @file_get_contents($pfad);
        if ($inhalt === false) {
            $this->LogMessage("MeteoSchweizHagelradar: Statusdatei '$pfad' konnte nicht gelesen werden.", KL_WARNING);
            $this->SetStatus(202);
            return;
        }

        $daten = json_decode($inhalt, true);
        if (!is_array($daten)) {
            $this->LogMessage("MeteoSchweizHagelradar: Statusdatei '$pfad' enthält kein gültiges JSON.", KL_WARNING);
            $this->SetStatus(202);
            return;
        }

        $this->SetValue('LetzterFehler', (string) ($daten['last_error'] ?? ''));
        $this->SetValue('SaisonAktiv', !empty($daten['season_active']));

        $generatedAt = isset($daten['generated_at']) ? strtotime((string) $daten['generated_at']) : false;
        $this->SetValue('Datenzeitstempel', $generatedAt !== false ? $generatedAt : 0);

        $maxAlterSekunden = $this->ReadPropertyInteger('MaxAlterMinuten') * 60;
        $istAktuell = $generatedAt !== false && (time() - $generatedAt) <= $maxAlterSekunden;

        $poh = isset($daten['poh_percent']) ? $daten['poh_percent'] : null;
        $meshs = isset($daten['meshs_mm']) ? $daten['meshs_mm'] : null;

        $this->SetValue('POH', $poh !== null ? (float) $poh : 0.0);
        $this->SetValue('MESHS', $meshs !== null ? (float) $meshs : 0.0);

        $pohSchwelle = $this->ReadPropertyInteger('POHSchwellenwert');
        $meshsSchwelle = $this->ReadPropertyFloat('MESHSSchwellenwert');

        $gefahr = $istAktuell
            && (($poh !== null && $poh >= $pohSchwelle) || ($meshs !== null && $meshs >= $meshsSchwelle));
        $this->SetValue('HagelGefahr', $gefahr);

        $this->SetStatus($istAktuell ? 102 : 203);
    }
}
