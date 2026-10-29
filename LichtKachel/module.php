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

        foreach (['DimVariableID', 'DimStatusVariableID', 'SwitchVariableID'] as $property) {
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
