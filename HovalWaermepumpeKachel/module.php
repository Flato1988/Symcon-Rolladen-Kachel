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
        'ElecPower'     => 'Elektrische Leistungsaufnahme',
        'ThermPower'    => 'Thermische Leistung',
        'FlowTemp'      => 'Vorlauftemperatur',
        'ReturnTemp'    => 'Rücklauftemperatur',
        'FlowRate'      => 'Volumenstrom',
        'PumpSpeed'     => 'Drehzahl Pumpe',
        'StorageTop'    => 'Speichertemperatur oben',
        'StorageBottom' => 'Speichertemperatur unten',
        'DHWSetpoint'   => 'Warmwasser Soll'
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('TileName', ''); // Bezeichnung = Titel der Kachel
        foreach (array_keys(self::DATAPOINTS) as $key) {
            $this->RegisterPropertyInteger($key . 'ID', 0);
        }

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
        $this->RegisterVariableFloat('COP', 'COP (aktuell)', 'HWP.COP', 0);
        $this->EnableLogging('COP');

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
        return json_encode([
            'values' => $values,
            'cop'    => $cop === null ? null : round($cop, 2)
        ]);
    }

    /** COP = thermische ÷ elektrische Leistung (nur wenn die Wärmepumpe läuft) */
    private function CalculateCOP(): ?float
    {
        $elecID = $this->GetVariableID('ElecPower');
        $thermID = $this->GetVariableID('ThermPower');
        if ($elecID === 0 || $thermID === 0) {
            return null;
        }
        $elec = (float) GetValue($elecID);
        $therm = (float) GetValue($thermID);
        if ($elec <= 0.05 || $therm <= 0) {
            return null;
        }
        return min(15, $therm / $elec);
    }

    private function UpdateCOP()
    {
        $cop = $this->CalculateCOP();
        $value = $cop === null ? 0.0 : round($cop, 2);
        if (@$this->GetIDForIdent('COP') && $this->GetValue('COP') !== $value) {
            $this->SetValue('COP', $value);
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
