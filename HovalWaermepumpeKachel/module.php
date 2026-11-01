<?php

declare(strict_types=1);

class HovalWaermepumpeKachel extends IPSModule
{
    // Datenpunkte der Wärmepumpe: Schlüssel => Name (Property = Schlüssel + "ID")
    private const DATAPOINTS = [
        'OutdoorTemp'   => 'Außentemperatur',
        'RoomSetpoint'  => 'Sollwert Raum',
        'OperatingMode' => 'Betriebsart',
        'WaterPressure' => 'Wasserdruck',
        'WEZStatus'     => 'WEZ Status',
        'WEZSetpoint'   => 'WEZ Sollwert',
        'WEZTemp'       => 'WEZ Temperatur',
        'WEZPower'      => 'WEZ Leistung (Modulation)',
        'ElecPower'     => 'Elektrische Leistungsaufnahme (errechnet)',
        'MeasuredPower' => 'Elektrische Leistungsaufnahme (gemessen)',
        'ThermPower'    => 'Thermische Leistung',
        'FlowTemp'      => 'Vorlauftemperatur',
        'ReturnTemp'    => 'Rücklauftemperatur',
        'FlowRate'      => 'Volumenstrom',
        'PumpSpeed'     => 'Drehzahl Pumpe',
        'StorageTop'    => 'Speichertemperatur oben',
        'StorageBottom' => 'Speichertemperatur unten',
        'DHWSetpoint'   => 'Warmwasser Soll',
        'BufferTemp'    => 'Puffertemperatur',
        'Valve'         => 'Umschaltventil (Y-Ventil)'
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('TileName', ''); // Bezeichnung = Titel der Kachel
        foreach (array_keys(self::DATAPOINTS) as $key) {
            $this->RegisterPropertyInteger($key . 'ID', 0);
        }
        // Einheiten der beiden elektrischen Leistungen (0 = W, 1 = kW), damit sie vergleichbar sind
        $this->RegisterPropertyInteger('ElecPowerUnit', 1);
        $this->RegisterPropertyInteger('MeasuredPowerUnit', 0);
        // Wert der Ventil-Variable, bei dem das Ventil auf Warmwasser steht (sonst: Heizung/Puffer)
        $this->RegisterPropertyFloat('ValveDHWValue', 1);

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
        foreach (array_keys(self::DATAPOINTS) as $key) {
            $id = $this->GetVariableID($key);
            if ($id > 0) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        // COP (thermische ÷ elektrische Leistung) als eigene Variable, im Archiv aufgezeichnet
        if (!IPS_VariableProfileExists('HWP.COP')) {
            IPS_CreateVariableProfile('HWP.COP', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('HWP.COP', 2);
            IPS_SetVariableProfileIcon('HWP.COP', 'Graph');
        }
        if (!IPS_VariableProfileExists('HWP.Percent')) {
            IPS_CreateVariableProfile('HWP.Percent', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('HWP.Percent', '', ' %');
            IPS_SetVariableProfileDigits('HWP.Percent', 1);
            IPS_SetVariableProfileIcon('HWP.Percent', 'Graph');
        }
        $this->RegisterVariableFloat('COP', 'COP (aktuell)', 'HWP.COP', 0);
        $this->EnableLogging('COP');
        // Abweichung errechnete gegenüber gemessener Leistungsaufnahme
        $hasDeviation = $this->GetVariableID('ElecPower') > 0 && $this->GetVariableID('MeasuredPower') > 0;
        $this->MaintainVariable('Deviation', 'Abweichung errechnet / gemessen', VARIABLETYPE_FLOAT, 'HWP.Percent', 0, $hasDeviation);
        if ($hasDeviation) {
            $this->EnableLogging('Deviation');
        }

        // Großansicht: zeigt die Objekte unterhalb der Instanz -> Links auf alle konfigurierten Datenpunkte
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->MaintainLinks();
        }

        $this->SetStatus(102);
        $this->UpdateCOP();
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;

            case VM_UPDATE:
                $this->UpdateCOP();
                $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    public function GetVisualizationTile()
    {
        // Startwerte direkt beim Laden der Kachel übergeben
        $initialHandling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        $module = file_get_contents(__DIR__ . '/module.html');
        return $module . $initialHandling;
    }

    private function GetFullUpdateMessage(): string
    {
        $values = [];
        foreach (array_keys(self::DATAPOINTS) as $key) {
            $id = $this->GetVariableID($key);
            if ($id > 0) {
                // Rohwert für Berechnungen/Farben, formatierter Wert (mit Profil) für die Anzeige
                $values[$key] = ['v' => GetValue($id), 'f' => GetValueFormatted($id)];
            }
        }
        $cop = $this->CalculateCOP();
        $deviation = $this->CalculateDeviation();
        return json_encode([
            'values'    => $values,
            'cop'       => $cop === null ? null : round($cop, 2),
            'deviation' => $deviation === null ? null : round($deviation, 1),
            'valve'     => $this->GetValvePosition(),
            // läuft die Wärmepumpe? (über 100 W elektrisch oder Modulation > 0)
            'heating'   => ($this->GetPowerKW('MeasuredPower') ?? $this->GetPowerKW('ElecPower') ?? 0) > 0.1
                || ($this->GetVariableID('WEZPower') > 0 && (float) GetValue($this->GetVariableID('WEZPower')) > 0)
        ]);
    }

    /**
     * COP = thermische ÷ elektrische Leistung (nur wenn die Wärmepumpe läuft).
     * Verwendet die gemessene Leistung, wenn vorhanden, sonst die errechnete.
     * Die thermische Leistung hat dieselbe Einheit wie die errechnete elektrische.
     */
    private function CalculateCOP(): ?float
    {
        $thermID = $this->GetVariableID('ThermPower');
        $elecKW = $this->GetPowerKW('MeasuredPower') ?? $this->GetPowerKW('ElecPower');
        if ($thermID === 0 || $elecKW === null) {
            return null;
        }
        $thermKW = (float) GetValue($thermID) / ($this->ReadPropertyInteger('ElecPowerUnit') === 0 ? 1000 : 1);
        if ($elecKW <= 0.05 || $thermKW <= 0) {
            return null;
        }
        return min(15, $thermKW / $elecKW);
    }

    /** Abweichung in % = (errechnet − gemessen) ÷ gemessen × 100 (nur bei nennenswerter Leistung) */
    private function CalculateDeviation(): ?float
    {
        $calculated = $this->GetPowerKW('ElecPower');
        $measured = $this->GetPowerKW('MeasuredPower');
        if ($calculated === null || $measured === null || $measured < 0.1) {
            return null;
        }
        return ($calculated - $measured) / $measured * 100;
    }

    /** Leistung in kW (Einheit laut Einstellung), null wenn nicht konfiguriert */
    private function GetPowerKW(string $key): ?float
    {
        $id = $this->GetVariableID($key);
        if ($id === 0) {
            return null;
        }
        $unit = $this->ReadPropertyInteger($key === 'MeasuredPower' ? 'MeasuredPowerUnit' : 'ElecPowerUnit');
        return (float) GetValue($id) / ($unit === 0 ? 1000 : 1);
    }

    /** Stellung des Y-Ventils: "ww" (Warmwasser), "heat" (Puffer/FBH) oder null (unbekannt) */
    private function GetValvePosition(): ?string
    {
        $id = $this->GetVariableID('Valve');
        if ($id === 0) {
            return null;
        }
        return abs((float) GetValue($id) - $this->ReadPropertyFloat('ValveDHWValue')) < 0.001 ? 'ww' : 'heat';
    }

    private function UpdateCOP()
    {
        $cop = $this->CalculateCOP();
        $value = $cop === null ? 0.0 : round($cop, 2);
        if (@$this->GetIDForIdent('COP') && $this->GetValue('COP') !== $value) {
            $this->SetValue('COP', $value);
        }
        $deviation = $this->CalculateDeviation();
        if ($deviation !== null && @$this->GetIDForIdent('Deviation')) {
            $this->SetValue('Deviation', round($deviation, 1));
        }
    }

    /** Links unterhalb der Instanz anlegen/aktualisieren/entfernen (Ident "L" + Schlüssel) */
    private function MaintainLinks()
    {
        $position = 1;
        foreach (self::DATAPOINTS as $key => $caption) {
            $linkID = @IPS_GetObjectIDByIdent('L' . $key, $this->InstanceID);
            $targetID = $this->GetVariableID($key);
            if ($targetID > 0) {
                if ($linkID === false) {
                    $linkID = IPS_CreateLink();
                    IPS_SetParent($linkID, $this->InstanceID);
                    IPS_SetIdent($linkID, 'L' . $key);
                    IPS_SetName($linkID, $caption);
                }
                IPS_SetLinkTargetID($linkID, $targetID);
                IPS_SetPosition($linkID, $position);
            } elseif ($linkID !== false) {
                IPS_DeleteLink($linkID);
            }
            $position++;
        }
    }

    private function GetVariableID(string $key): int
    {
        $id = $this->ReadPropertyInteger($key . 'ID');
        return $id > 0 && IPS_VariableExists($id) ? $id : 0;
    }

    /** Aktiviert die Archivierung einer eigenen Variable (Standard-Aggregation) */
    private function EnableLogging(string $ident)
    {
        $archives = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (count($archives) === 0 || IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }
        $archiveID = $archives[0];
        $variableID = $this->GetIDForIdent($ident);
        if (!AC_GetLoggingStatus($archiveID, $variableID)) {
            AC_SetLoggingStatus($archiveID, $variableID, true);
            AC_SetAggregationType($archiveID, $variableID, 0);
            IPS_ApplyChanges($archiveID);
        }
    }
}
