<?php

declare(strict_types=1);

class WeihnachtsbeleuchtungKachel extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('TileName', '');     // Bezeichnung = Titel der Kachel
        $this->RegisterPropertyInteger('LuxVariableID', 0); // Helligkeit vom Bewegungsmelder
        $this->RegisterPropertyString('Outputs', '[]');     // Liste der Ausgänge [{Name, VariableID}]

        // Tag des letzten Einschaltens (einmal pro Tag) und geplante Ausschaltzeit
        $this->RegisterAttributeString('LastRunDate', '');
        $this->RegisterAttributeInteger('OffAt', 0);
        $this->RegisterAttributeInteger('OnSince', 0);

        $this->RegisterTimer('Evaluate', 0, 'XMS_Evaluate($_IPS[\'TARGET\']);');

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

        $luxID = $this->ReadPropertyInteger('LuxVariableID');
        if ($luxID > 0 && IPS_VariableExists($luxID)) {
            $this->RegisterReference($luxID);
            $this->RegisterMessage($luxID, VM_UPDATE);
        }
        foreach ($this->GetOutputIDs() as $outputID) {
            $this->RegisterReference($outputID);
        }

        // Variablen für die Großansicht (die Vollbildansicht zeigt die Variablen unterhalb der Instanz)
        $this->CreateProfile('XMS.Lux', VARIABLETYPE_INTEGER, ' lx', 0, 'Sun', 0, 500, 5);
        $this->CreateProfile('XMS.LuxValue', VARIABLETYPE_FLOAT, ' lx', 0, 'Sun', 0, 0, 0);
        $this->CreateProfile('XMS.Hours', VARIABLETYPE_FLOAT, ' h', 1, 'Clock', 0.5, 12, 0.5);

        $isNew = !@$this->GetIDForIdent('Active');
        $this->RegisterVariableBoolean('Active', 'Automatik aktiv', '~Switch', 1);
        $this->EnableAction('Active');
        $this->RegisterVariableBoolean('Lights', 'Beleuchtung', '~Switch', 2);
        $this->RegisterVariableString('LuxBar', 'Helligkeit / Schaltschwelle', '~HTMLBox', 3);
        $this->RegisterVariableInteger('Threshold', 'Schaltschwelle', 'XMS.Lux', 4);
        $this->EnableAction('Threshold');
        $this->RegisterVariableInteger('StartTime', 'Einschalten ab', '~UnixTimestampTime', 5);
        $this->EnableAction('StartTime');
        $this->RegisterVariableFloat('MaxDuration', 'Maximale Leuchtdauer', 'XMS.Hours', 6);
        $this->EnableAction('MaxDuration');
        $this->RegisterVariableInteger('OffTime', 'Ausschalten um', '~UnixTimestampTime', 7);
        $this->RegisterVariableFloat('Lux', 'Helligkeit aktuell', 'XMS.LuxValue', 8);
        $this->RegisterVariableString('Status', 'Status', '', 9);

        if ($isNew) {
            // Startwerte beim ersten Anlegen
            $this->SetValue('Active', true);
            $this->SetValue('Threshold', 30);
            $this->SetValue('StartTime', mktime(16, 0, 0));
            $this->SetValue('MaxDuration', 6.0);
        }

        $this->SetTimerInterval('Evaluate', 60 * 1000);

        if ($luxID > 0 && IPS_VariableExists($luxID) && count($this->GetOutputIDs()) > 0) {
            $this->SetStatus(102);
        } else {
            $this->SetStatus(104);
        }

        // Liste der Ausgänge geändert, während die Beleuchtung an ist -> neue Ausgänge mit einschalten
        if ($this->GetValue('Lights')) {
            $this->SetOutputs(true);
        }

        $this->Evaluate();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;

            case VM_UPDATE:
                $this->Evaluate();
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Active':
                $this->SetValue('Active', (bool) $Value);
                break;
            case 'ToggleActive': // Kachel: Klick auf den Kreis
                $this->SetValue('Active', !$this->GetValue('Active'));
                break;
            case 'Threshold':
                $this->SetValue('Threshold', max(0, (int) $Value));
                break;
            case 'StartTime':
                $this->SetValue('StartTime', (int) $Value);
                break;
            case 'MaxDuration':
                $this->SetValue('MaxDuration', max(0.5, (float) $Value));
                // eine laufende Beleuchtung bekommt die neue Dauer ab ihrem Einschaltzeitpunkt
                if ($this->GetValue('Lights')) {
                    $this->SetOffAt($this->ReadAttributeInteger('OnSince') + (int) round($this->GetValue('MaxDuration') * 3600));
                }
                break;
            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
        $this->Evaluate();
    }

    public function GetVisualizationTile()
    {
        // Startwerte direkt beim Laden der Kachel übergeben
        $initialHandling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        $module = file_get_contents(__DIR__ . '/module.html');
        return $module . $initialHandling;
    }

    /** Prüft alle Bedingungen und schaltet die Ausgänge – läuft minütlich und bei jeder Lux-Änderung */
    public function Evaluate()
    {
        $now = time();
        $today = date('Y-m-d', $now);
        $lux = $this->GetLux();
        $lights = $this->GetValue('Lights');

        if (!$this->GetValue('Active')) {
            if ($lights) {
                $this->SwitchOutputs(false);
            }
        } elseif ($lights) {
            if ($now >= $this->ReadAttributeInteger('OffAt')) {
                $this->SwitchOutputs(false); // maximale Leuchtdauer erreicht
            }
        } elseif ($this->ReadAttributeString('LastRunDate') === $today && $now < $this->ReadAttributeInteger('OffAt')) {
            // Automatik wurde während der heutigen Leuchtzeit aus- und wieder eingeschaltet:
            // weiterleuchten bis zur ursprünglichen Ausschaltzeit
            $this->SetValue('Lights', true);
            $this->SetOutputs(true);
        } else {
            $startTime = $this->GetValue('StartTime');
            $startToday = mktime((int) date('H', $startTime), (int) date('i', $startTime), 0);
            if ($now >= $startToday
                && $this->ReadAttributeString('LastRunDate') !== $today
                && $lux !== null && $lux < $this->GetValue('Threshold')) {
                $this->WriteAttributeString('LastRunDate', $today);
                $this->SwitchOutputs(true);
            }
        }

        $this->UpdateDisplay();
    }

    /** Alle Ausgänge schalten. Beim Einschalten läuft die maximale Leuchtdauer ab jetzt. */
    public function SwitchOutputs(bool $On)
    {
        if ($On) {
            $this->WriteAttributeInteger('OnSince', time());
            $this->SetOffAt(time() + (int) round($this->GetValue('MaxDuration') * 3600));
        }
        $this->SetValue('Lights', $On);
        $this->SetOutputs($On);
        $this->UpdateDisplay();
    }

    private function SetOutputs(bool $On)
    {
        foreach ($this->GetOutputIDs() as $outputID) {
            if (HasAction($outputID)) {
                RequestAction($outputID, $On);
            } else {
                SetValue($outputID, $On);
            }
        }
    }

    private function SetOffAt(int $timestamp)
    {
        $this->WriteAttributeInteger('OffAt', $timestamp);
        $this->SetValue('OffTime', $timestamp);
    }

    private function UpdateDisplay()
    {
        $lux = $this->GetLux();
        if ($lux !== null) {
            $this->SetValue('Lux', $lux);
        }
        $this->SetValue('LuxBar', $this->GetLuxBarHTML($lux, $this->GetValue('Threshold')));
        $this->SetValue('Status', $this->GetStatusText());
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    private function GetStatusText(): string
    {
        if (!$this->GetValue('Active')) {
            return 'Automatik aus';
        }
        if ($this->GetValue('Lights')) {
            return 'An bis ' . date('H:i', $this->ReadAttributeInteger('OffAt'));
        }
        $startTime = $this->GetValue('StartTime');
        if ($this->ReadAttributeString('LastRunDate') === date('Y-m-d')) {
            return 'Heute erledigt';
        }
        $startToday = mktime((int) date('H', $startTime), (int) date('i', $startTime), 0);
        if (time() < $startToday) {
            return 'Wartet bis ' . date('H:i', $startToday);
        }
        return 'Wartet auf Dämmerung';
    }

    private function GetFullUpdateMessage(): string
    {
        return json_encode([
            'active' => $this->GetValue('Active'),
            'lights' => $this->GetValue('Lights'),
            'status' => $this->GetStatusText()
        ]);
    }

    /** Balken für die Großansicht: aktueller Lux-Wert und Markierung der Schaltschwelle */
    private function GetLuxBarHTML(?float $lux, int $threshold): string
    {
        $max = max(100, $threshold * 4);          // Skala: Schwelle liegt bei ca. einem Viertel
        $luxPercent = $lux === null ? 0 : min(100, $lux / $max * 100);
        $thresholdPercent = min(100, $threshold / $max * 100);
        $below = $lux !== null && $lux < $threshold;
        $color = $below ? '#00cdab' : '#f2c94c';
        $luxText = $lux === null ? '–' : number_format($lux, 0, ',', '.') . ' lx';

        return '<div style="font-family:inherit;font-size:14px;padding:8px 4px 4px">'
            . '<div style="display:flex;justify-content:space-between;margin-bottom:6px">'
            . '<span>Aktuell: <b>' . $luxText . '</b></span>'
            . '<span>Schwelle: <b>' . $threshold . ' lx</b></span></div>'
            . '<div style="position:relative;height:18px;border-radius:9px;background:rgba(128,128,128,.2)">'
            . '<div style="position:absolute;left:0;top:0;bottom:0;width:' . round($luxPercent, 1) . '%;border-radius:9px;background:' . $color . '"></div>'
            . '<div style="position:absolute;top:-5px;bottom:-5px;left:' . round($thresholdPercent, 1) . '%;width:3px;margin-left:-1.5px;border-radius:2px;background:#e5484d"></div>'
            . '</div>'
            . '<div style="display:flex;justify-content:space-between;margin-top:4px;font-size:11px;opacity:.6">'
            . '<span>0 lx</span><span>' . ($below ? 'dunkel genug' : 'zu hell') . '</span><span>' . $max . ' lx' . ($lux !== null && $lux > $max ? ' +' : '') . '</span></div>'
            . '</div>';
    }

    private function GetLux(): ?float
    {
        $luxID = $this->ReadPropertyInteger('LuxVariableID');
        if ($luxID <= 0 || !IPS_VariableExists($luxID)) {
            return null;
        }
        return (float) GetValue($luxID);
    }

    /** @return int[] IDs der konfigurierten Ausgangsvariablen */
    private function GetOutputIDs(): array
    {
        $ids = [];
        foreach (json_decode($this->ReadPropertyString('Outputs'), true) ?: [] as $row) {
            $id = (int) ($row['VariableID'] ?? 0);
            if ($id > 0 && IPS_VariableExists($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    private function CreateProfile(string $name, int $type, string $suffix, int $digits, string $icon, float $min, float $max, float $step)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
            IPS_SetVariableProfileText($name, '', $suffix);
            if ($type === VARIABLETYPE_FLOAT) {
                IPS_SetVariableProfileDigits($name, $digits);
            }
            IPS_SetVariableProfileValues($name, $min, $max, $step);
            IPS_SetVariableProfileIcon($name, $icon);
        }
    }
}
