<?php

declare(strict_types=1);

class LichtKachel extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('TileName', '');          // Bezeichnung = Titel der Kachel
        $this->RegisterPropertyInteger('DimVariableID', 0);       // Befehl: Dimmen absolut (z.B. 6/3/2)
        $this->RegisterPropertyInteger('DimStatusVariableID', 0); // Rückmeldung: Status Dimmwert (z.B. 6/3/4)
        $this->RegisterPropertyInteger('SwitchVariableID', 0);    // Schalten (z.B. 6/3/0)
        $this->RegisterPropertyInteger('DiagnosisVariableID', 0); // Diagnosetext (z.B. 6/3/13)
        $this->RegisterPropertyInteger('PowerVariableID', 0);     // Wirkleistung in W (z.B. 6/3/7)
        $this->RegisterPropertyFloat('PricePerKWh', 0.30);        // Strompreis in €/kWh

        // Energiezählung: letzter Messzeitpunkt/-wert und aktuelle Zeiträume
        $this->RegisterAttributeInteger('LastTimestamp', 0);
        $this->RegisterAttributeFloat('LastPower', 0);
        $this->RegisterAttributeString('PeriodDay', '');
        $this->RegisterAttributeString('PeriodMonth', '');
        $this->RegisterAttributeString('PeriodYear', '');

        // Rechnet den Verbrauch auch dann weiter, wenn sich die Leistung länger nicht ändert
        $this->RegisterTimer('UpdateEnergy', 0, 'LTK_UpdateEnergy($_IPS[\'TARGET\']);');

        // Kachel über das HTML-SDK darstellen
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Symcon zeigt den Instanznamen als Kacheltitel an
        $name = trim($this->ReadPropertyString('TileName'));
        if ($name !== '' && $name !== IPS_GetName($this->InstanceID)) {
            IPS_SetName($this->InstanceID, $name);
        }

        // Alte Registrierungen entfernen
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }
        foreach ($this->GetReferenceList() as $referenceID) {
            $this->UnregisterReference($referenceID);
        }

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        foreach (['DimVariableID', 'DimStatusVariableID', 'SwitchVariableID', 'DiagnosisVariableID', 'PowerVariableID'] as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        // Die Vollbildansicht der Kachel-Visualisierung zeigt nicht das HTML, sondern die
        // Variablen unterhalb der Instanz – daher Schalten und Helligkeit als eigene Variablen.
        if (!IPS_VariableProfileExists('LTK.Brightness')) {
            IPS_CreateVariableProfile('LTK.Brightness', VARIABLETYPE_INTEGER);
            IPS_SetVariableProfileValues('LTK.Brightness', 0, 100, 1);
            IPS_SetVariableProfileText('LTK.Brightness', '', ' %');
            IPS_SetVariableProfileIcon('LTK.Brightness', 'Bulb');
        }
        $this->RegisterVariableBoolean('State', 'Schalten', '~Switch', 1);
        $this->EnableAction('State');
        $this->RegisterVariableInteger('Brightness', 'Helligkeit', 'LTK.Brightness', 2);
        $this->EnableAction('Brightness');

        // Diagnose, Leistung, Verbrauch und Kosten – nur wenn die jeweilige Variable konfiguriert ist
        $this->CreateProfile('LTK.Power', VARIABLETYPE_FLOAT, ' W', 1, 'Electricity');
        $this->CreateProfile('LTK.Energy', VARIABLETYPE_FLOAT, ' kWh', 3, 'Electricity');
        $this->CreateProfile('LTK.Cost', VARIABLETYPE_FLOAT, ' €', 2, 'Euro');
        $hasDiagnosis = $this->VariableConfigured('DiagnosisVariableID');
        $hasPower = $this->VariableConfigured('PowerVariableID');
        $this->MaintainVariable('Power', 'Wirkleistung', VARIABLETYPE_FLOAT, 'LTK.Power', 3, $hasPower);
        $this->MaintainVariable('EnergyToday', 'Verbrauch heute', VARIABLETYPE_FLOAT, 'LTK.Energy', 4, $hasPower);
        $this->MaintainVariable('CostToday', 'Kosten heute', VARIABLETYPE_FLOAT, 'LTK.Cost', 5, $hasPower);
        $this->MaintainVariable('CostMonth', 'Kosten diesen Monat', VARIABLETYPE_FLOAT, 'LTK.Cost', 6, $hasPower);
        $this->MaintainVariable('CostYear', 'Kosten dieses Jahr', VARIABLETYPE_FLOAT, 'LTK.Cost', 7, $hasPower);
        $this->MaintainVariable('EnergyTotal', 'Verbrauch gesamt', VARIABLETYPE_FLOAT, 'LTK.Energy', 8, $hasPower);
        $this->MaintainVariable('CostTotal', 'Kosten gesamt', VARIABLETYPE_FLOAT, 'LTK.Cost', 9, $hasPower);
        $this->MaintainVariable('Diagnosis', 'Diagnose', VARIABLETYPE_STRING, '', 10, $hasDiagnosis);

        if ($hasPower) {
            // Aufzeichnung im Archiv: Leistung als Verlauf, Gesamtwerte als Zähler
            // (Zähler = Symcon bildet im Diagramm die Summen je Stunde/Tag/Woche/Monat/Jahr)
            $this->EnableLogging('Power', 0);
            $this->EnableLogging('EnergyTotal', 1);
            $this->EnableLogging('CostTotal', 1);
            $this->SetTimerInterval('UpdateEnergy', 60 * 1000);
        } else {
            $this->SetTimerInterval('UpdateEnergy', 0);
        }

        $this->SyncChildVariables();

        $dimID = $this->ReadPropertyInteger('DimVariableID');
        if ($dimID > 0 && IPS_VariableExists($dimID)) {
            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }

        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;

            case VM_UPDATE:
                if ($SenderID === $this->ReadPropertyInteger('PowerVariableID')) {
                    // Verbrauch bis jetzt mit der bisherigen Leistung abrechnen, dann neue Leistung merken
                    $this->UpdateEnergy();
                }
                // Aktualisiert die Kachel auch bei Bedienung über Wandtaster, Zentral-Aus usw.
                $this->SyncChildVariables();
                $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Level':      // Kachel: runder Schieberegler
            case 'Brightness': // Vollbild: Variable "Helligkeit"
                $this->SetLevel((int) $Value);
                break;
            case 'Toggle':     // Kachel: Taste in der Mitte
                $this->Toggle();
                break;
            case 'State':      // Vollbild: Variable "Schalten"
                $this->SwitchLight((bool) $Value);
                break;
            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    public function GetVisualizationTile()
    {
        // Startwerte direkt beim Laden der Kachel übergeben
        $initialHandling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        $module = file_get_contents(__DIR__ . '/module.html');
        return $module . $initialHandling;
    }

    /** Dimmwert 0–100 % setzen */
    public function SetLevel(int $Level)
    {
        $dimID = $this->ReadPropertyInteger('DimVariableID');
        if ($dimID <= 0 || !IPS_VariableExists($dimID)) {
            echo 'Variable "Dimmen absolut" ist nicht konfiguriert';
            return;
        }
        $Level = max(0, min(100, $Level));
        if ($Level === 0) {
            $this->SwitchLight(false);
            return;
        }
        RequestAction($dimID, $Level);
    }

    /** Ein = immer 100 %, Aus = Schalten auf Aus (bzw. Dimmwert 0, wenn kein Schaltobjekt konfiguriert ist) */
    public function SwitchLight(bool $On)
    {
        if ($On) {
            $this->SetLevel(100);
            return;
        }
        $switchID = $this->ReadPropertyInteger('SwitchVariableID');
        if ($switchID > 0 && IPS_VariableExists($switchID)) {
            RequestAction($switchID, false);
            return;
        }
        $dimID = $this->ReadPropertyInteger('DimVariableID');
        if ($dimID > 0 && IPS_VariableExists($dimID)) {
            RequestAction($dimID, 0);
        }
    }

    /** Taste in der Mitte: an -> aus, aus -> 100 % */
    public function Toggle()
    {
        $this->SwitchLight($this->GetLevel() <= 0);
    }

    /** Verbrauch (kWh) und Kosten aus der Wirkleistung seit der letzten Messung aufaddieren */
    public function UpdateEnergy()
    {
        if (!$this->VariableConfigured('PowerVariableID') || !@$this->GetIDForIdent('EnergyTotal')) {
            return;
        }
        $now = time();
        $this->StartNewPeriods($now);

        $last = $this->ReadAttributeInteger('LastTimestamp');
        // Lücken über 15 Minuten (z.B. Symcon war aus) nicht hochrechnen
        if ($last > 0 && $now > $last && $now - $last <= 15 * 60) {
            $kWh = max(0, $this->ReadAttributeFloat('LastPower')) * ($now - $last) / 3600 / 1000;
            $cost = $kWh * $this->ReadPropertyFloat('PricePerKWh');
            if ($kWh > 0) {
                $this->SetValue('EnergyToday', $this->GetValue('EnergyToday') + $kWh);
                $this->SetValue('EnergyTotal', $this->GetValue('EnergyTotal') + $kWh);
                $this->SetValue('CostToday', $this->GetValue('CostToday') + $cost);
                $this->SetValue('CostMonth', $this->GetValue('CostMonth') + $cost);
                $this->SetValue('CostYear', $this->GetValue('CostYear') + $cost);
                $this->SetValue('CostTotal', $this->GetValue('CostTotal') + $cost);
            }
        }
        $this->WriteAttributeInteger('LastTimestamp', $now);
        $this->WriteAttributeFloat('LastPower', (float) GetValue($this->ReadPropertyInteger('PowerVariableID')));
    }

    /** Setzt Tages-, Monats- und Jahreswerte zum Beginn eines neuen Zeitraums zurück */
    private function StartNewPeriods(int $now)
    {
        $periods = [
            'PeriodDay'   => [date('Y-m-d', $now), ['EnergyToday', 'CostToday']],
            'PeriodMonth' => [date('Y-m', $now), ['CostMonth']],
            'PeriodYear'  => [date('Y', $now), ['CostYear']]
        ];
        foreach ($periods as $attribute => [$current, $idents]) {
            $previous = $this->ReadAttributeString($attribute);
            if ($previous !== $current) {
                if ($previous !== '') {
                    foreach ($idents as $ident) {
                        $this->SetValue($ident, 0);
                    }
                }
                $this->WriteAttributeString($attribute, $current);
            }
        }
    }

    private function VariableConfigured(string $property): bool
    {
        $id = $this->ReadPropertyInteger($property);
        return $id > 0 && IPS_VariableExists($id);
    }

    private function CreateProfile(string $name, int $type, string $suffix, int $digits, string $icon)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileDigits($name, $digits);
            IPS_SetVariableProfileIcon($name, $icon);
        }
    }

    /** Aktiviert die Archivierung einer eigenen Variable (0 = Standard, 1 = Zähler) */
    private function EnableLogging(string $ident, int $aggregationType)
    {
        $archives = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archives) === 0 || IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $archiveID = $archives[0];
        $variableID = $this->GetIDForIdent($ident);
        if (!AC_GetLoggingStatus($archiveID, $variableID) || AC_GetAggregationType($archiveID, $variableID) !== $aggregationType) {
            AC_SetLoggingStatus($archiveID, $variableID, true);
            AC_SetAggregationType($archiveID, $variableID, $aggregationType);
            IPS_ApplyChanges($archiveID);
        }
    }

    /** Hält die Variablen unterhalb der Instanz (für die Vollbildansicht) auf dem aktuellen Stand */
    private function SyncChildVariables()
    {
        $level = $this->GetLevel();
        if (@$this->GetIDForIdent('Brightness')) {
            $this->SetValue('Brightness', $level);
        }
        if (@$this->GetIDForIdent('State')) {
            $this->SetValue('State', $level > 0);
        }
        if ($this->VariableConfigured('PowerVariableID') && @$this->GetIDForIdent('Power')) {
            $this->SetValue('Power', (float) GetValue($this->ReadPropertyInteger('PowerVariableID')));
        }
        if ($this->VariableConfigured('DiagnosisVariableID') && @$this->GetIDForIdent('Diagnosis')) {
            $this->SetValue('Diagnosis', (string) GetValue($this->ReadPropertyInteger('DiagnosisVariableID')));
        }
    }

    private function GetFullUpdateMessage(): string
    {
        return json_encode(['level' => $this->GetLevel()]);
    }

    /** Aktueller Dimmwert aus der Rückmeldung (falls konfiguriert), sonst aus dem Befehlsobjekt */
    private function GetLevel(): int
    {
        $id = $this->ReadPropertyInteger('DimStatusVariableID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            $id = $this->ReadPropertyInteger('DimVariableID');
        }
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return 0;
        }
        $level = (int) round((float) GetValue($id));

        // Wurde über das Schaltobjekt ausgeschaltet, zählt das Licht als aus
        $switchID = $this->ReadPropertyInteger('SwitchVariableID');
        if ($switchID > 0 && IPS_VariableExists($switchID) && $id === $this->ReadPropertyInteger('DimVariableID') && !GetValue($switchID)) {
            return 0;
        }
        return max(0, min(100, $level));
    }
}
