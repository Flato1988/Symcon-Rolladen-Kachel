<?php

declare(strict_types=1);

class RollladenKachel extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('PositionVariableID', 0);  // Befehl: Position absolut (z.B. 6/2/5)
        $this->RegisterPropertyInteger('StatusVariableID', 0);    // Rückmeldung: Status aktuelle Position (z.B. 6/2/6)
        $this->RegisterPropertyInteger('StopVariableID', 0);
        $this->RegisterPropertyFloat('MinValue', 0);    // Rohwert "offen"
        $this->RegisterPropertyFloat('MaxValue', 100);  // Rohwert "geschlossen"
        $this->RegisterPropertyString('TileName', '');  // Bezeichnung = Titel der Kachel

        // Letzte Fahrtrichtung: 1 = schließt, -1 = öffnet, 0 = unbekannt
        $this->RegisterAttributeInteger('LastDirection', 0);

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

        foreach (['PositionVariableID', 'StatusVariableID', 'StopVariableID'] as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        $positionID = $this->ReadPropertyInteger('PositionVariableID');
        if ($positionID > 0 && IPS_VariableExists($positionID)) {
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
                if ($SenderID === $this->GetStatusVariableID()) {
                    // $Data[0] = neuer Wert, $Data[2] = alter Wert
                    $new = $this->RawToPercent((float) $Data[0]);
                    $old = $this->RawToPercent((float) $Data[2]);
                    if ($new > $old) {
                        $this->WriteAttributeInteger('LastDirection', 1);
                    } elseif ($new < $old) {
                        $this->WriteAttributeInteger('LastDirection', -1);
                    }
                    // Aktualisiert die Kachel auch bei Bedienung über den physischen Taster
                    $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
                }
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Position':
                $this->SetPosition((float) $Value);
                break;
            case 'Open':
                $this->Open();
                break;
            case 'Close':
                $this->Close();
                break;
            case 'Toggle':
                $this->Toggle();
                break;
            case 'Stop':
                $this->Stop();
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

    /** Position in Prozent setzen: 0 = offen, 100 = geschlossen */
    public function SetPosition(float $Percent)
    {
        $positionID = $this->ReadPropertyInteger('PositionVariableID');
        if ($positionID <= 0 || !IPS_VariableExists($positionID)) {
            echo 'Positionsvariable ist nicht konfiguriert';
            return;
        }

        $Percent = max(0, min(100, $Percent));
        $current = $this->GetPercent();
        if ($current !== null && $Percent !== $current) {
            $this->WriteAttributeInteger('LastDirection', $Percent > $current ? 1 : -1);
        }

        $raw = $this->PercentToRaw($Percent);
        $variable = IPS_GetVariable($positionID);
        switch ($variable['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                $raw = $Percent >= 50;
                break;
            case VARIABLETYPE_INTEGER:
                $raw = (int) round($raw);
                break;
        }
        RequestAction($positionID, $raw);
    }

    public function Open()
    {
        $this->SetPosition(0);
    }

    public function Close()
    {
        $this->SetPosition(100);
    }

    /** Ein Klick: offen -> schließen, geschlossen -> öffnen, Zwischenstellung -> entgegen der letzten Fahrtrichtung */
    public function Toggle()
    {
        $percent = $this->GetPercent();
        if ($percent === null || $percent <= 0) {
            $this->Close();
        } elseif ($percent >= 100) {
            $this->Open();
        } elseif ($this->ReadAttributeInteger('LastDirection') === 1) {
            $this->Open();
        } else {
            $this->Close();
        }
    }

    /** Löst den Taster kurzzeit aus (bei KNX = Stopp/Lamellenverstellung) */
    public function Stop()
    {
        $stopID = $this->ReadPropertyInteger('StopVariableID');
        if ($stopID <= 0 || !IPS_VariableExists($stopID)) {
            echo 'Variable "Taster kurzzeit" ist nicht konfiguriert';
            return;
        }
        $value = true;
        switch (IPS_GetVariable($stopID)['VariableType']) {
            case VARIABLETYPE_INTEGER:
                $value = 1;
                break;
            case VARIABLETYPE_FLOAT:
                $value = 1.0;
                break;
            case VARIABLETYPE_STRING:
                $value = '1';
                break;
        }
        if (HasAction($stopID)) {
            RequestAction($stopID, $value);
        } else {
            SetValue($stopID, $value);
        }
    }

    private function GetFullUpdateMessage(): string
    {
        $stopID = $this->ReadPropertyInteger('StopVariableID');
        return json_encode([
            'position'  => $this->GetPercent(),
            'hasStop'   => $stopID > 0 && IPS_VariableExists($stopID)
        ]);
    }

    /** Statusvariable, falls konfiguriert – sonst die Positionsvariable selbst */
    private function GetStatusVariableID(): int
    {
        $statusID = $this->ReadPropertyInteger('StatusVariableID');
        if ($statusID > 0 && IPS_VariableExists($statusID)) {
            return $statusID;
        }
        return $this->ReadPropertyInteger('PositionVariableID');
    }

    private function GetPercent(): ?float
    {
        $statusID = $this->GetStatusVariableID();
        if ($statusID <= 0 || !IPS_VariableExists($statusID)) {
            return null;
        }
        return $this->RawToPercent((float) GetValue($statusID));
    }

    private function RawToPercent(float $raw): float
    {
        $min = $this->ReadPropertyFloat('MinValue');
        $max = $this->ReadPropertyFloat('MaxValue');
        if ($max == $min) {
            return 0;
        }
        return round(max(0, min(100, ($raw - $min) / ($max - $min) * 100)), 1);
    }

    private function PercentToRaw(float $percent): float
    {
        $min = $this->ReadPropertyFloat('MinValue');
        $max = $this->ReadPropertyFloat('MaxValue');
        return $min + ($max - $min) * $percent / 100;
    }
}
