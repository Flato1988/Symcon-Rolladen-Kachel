<?php

declare(strict_types=1);

class WaermepumpeKachel extends IPSModule
{
    private const INTERVAL = 900;          // Messintervall: 15 Minuten
    private const HISTORY_DAYS = 7;        // so lange werden die 15-Minuten-Werte in der Instanz gehalten
    private const MAX_GAP = 2 * 3600;      // längere Lücken (Symcon aus) werden nicht gemittelt

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('TileName', '');               // Bezeichnung = Titel der Kachel
        $this->RegisterPropertyInteger('EnergyVariableID', 0);         // Zählerstand Bezug in kWh
        $this->RegisterPropertyInteger('TemperatureVariableID', 0);    // Außentemperatur in °C
        $this->RegisterPropertyFloat('PricePerKWh', 0.25);             // Strompreis in €/kWh

        // laufende Viertelstunde: Beginn, Zählerstand zu Beginn, Temperatur-Mittelwert
        $this->RegisterAttributeInteger('IntervalStart', 0);
        $this->RegisterAttributeFloat('IntervalStartMeter', 0);
        $this->RegisterAttributeFloat('TempSum', 0);
        $this->RegisterAttributeInteger('TempCount', 0);
        // abgeschlossene Viertelstunden [[Beginn, kW, °C], ...] und Tagesspitzen {Datum: [kW, Beginn]}
        $this->RegisterAttributeString('History', '[]');
        $this->RegisterAttributeString('Peaks', '{}');
        $this->RegisterAttributeString('PeriodDay', '');
        $this->RegisterAttributeString('PeriodMonth', '');
        $this->RegisterAttributeString('PeriodYear', '');

        $this->RegisterTimer('Tick', 0, 'WPK_Tick($_IPS[\'TARGET\']);');

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
        foreach (['EnergyVariableID', 'TemperatureVariableID'] as $property) {
            if ($this->VariableConfigured($property)) {
                $id = $this->ReadPropertyInteger($property);
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

        // Variablen für die Großansicht (die Vollbildansicht zeigt die Variablen unterhalb der Instanz)
        $this->CreateProfile('WPK.Power', ' kW', 2, 'Electricity');
        $this->CreateProfile('WPK.Energy', ' kWh', 2, 'Electricity');
        $this->CreateProfile('WPK.Cost', ' €', 2, 'Euro');
        $this->RegisterVariableFloat('Power', 'Leistung (Ø 15 min)', 'WPK.Power', 1);
        $this->RegisterVariableFloat('Temperature', 'Außentemperatur', '~Temperature', 2);
        $this->RegisterVariableFloat('EnergyToday', 'Verbrauch heute', 'WPK.Energy', 3);
        $this->RegisterVariableFloat('CostToday', 'Kosten heute', 'WPK.Cost', 4);
        $this->RegisterVariableFloat('CostMonth', 'Kosten diesen Monat', 'WPK.Cost', 5);
        $this->RegisterVariableFloat('CostYear', 'Kosten dieses Jahr', 'WPK.Cost', 6);
        $this->RegisterVariableFloat('PeakToday', 'Spitze 15 min heute', 'WPK.Power', 7);
        $this->RegisterVariableInteger('PeakTodayTime', 'Spitze heute um', '~UnixTimestampTime', 8);
        $this->RegisterVariableFloat('PeakYesterday', 'Spitze 15 min gestern', 'WPK.Power', 9);
        $this->RegisterVariableFloat('DailyPeak', 'Spitze 15 min je Tag', 'WPK.Power', 10);
        $this->RegisterVariableFloat('EnergyTotal', 'Verbrauch gesamt', 'WPK.Energy', 11);
        $this->RegisterVariableFloat('CostTotal', 'Kosten gesamt', 'WPK.Cost', 12);

        // Aufzeichnung im Archiv: Verläufe als Standard, Gesamtwerte als Zähler
        // (Zähler = Symcon bildet im Diagramm die Summen je Tag/Woche/Monat/Jahr)
        $this->EnableLogging('Power', 0);
        $this->EnableLogging('Temperature', 0);
        $this->EnableLogging('DailyPeak', 0);
        $this->EnableLogging('EnergyTotal', 1);
        $this->EnableLogging('CostTotal', 1);

        if ($this->VariableConfigured('EnergyVariableID')) {
            $this->SetStatus(102);
            $this->SetTimerInterval('Tick', $this->MillisecondsToNextMinute());
        } else {
            $this->SetStatus(104);
            $this->SetTimerInterval('Tick', 0);
        }

        $this->UpdateTemperature();
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;

            case VM_UPDATE:
                if ($SenderID === $this->ReadPropertyInteger('EnergyVariableID')) {
                    $this->AddConsumption((float) $Data[0] - (float) $Data[2]);
                } elseif ($SenderID === $this->ReadPropertyInteger('TemperatureVariableID')) {
                    $this->UpdateTemperature();
                }
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

    /** Läuft jede Minute (kurz nach dem Minutenwechsel): Temperatur sammeln, Viertelstunde abschließen */
    public function Tick()
    {
        if (!$this->VariableConfigured('EnergyVariableID')) {
            return;
        }
        $now = time();
        $meter = (float) GetValue($this->ReadPropertyInteger('EnergyVariableID'));
        $boundary = intdiv($now, self::INTERVAL) * self::INTERVAL;
        $start = $this->ReadAttributeInteger('IntervalStart');

        if ($start > 0 && $boundary > $start) {
            $this->CloseInterval($start, $boundary, $meter);
        }
        if ($start === 0 || $boundary > $start) {
            $this->WriteAttributeInteger('IntervalStart', $boundary);
            $this->WriteAttributeFloat('IntervalStartMeter', $meter);
            $this->WriteAttributeFloat('TempSum', 0);
            $this->WriteAttributeInteger('TempCount', 0);
        }

        // Temperatur für den Viertelstunden-Mittelwert sammeln
        $temperature = $this->GetTemperature();
        if ($temperature !== null) {
            $this->WriteAttributeFloat('TempSum', $this->ReadAttributeFloat('TempSum') + $temperature);
            $this->WriteAttributeInteger('TempCount', $this->ReadAttributeInteger('TempCount') + 1);
        }

        $this->StartNewPeriods($now);
        $this->SetTimerInterval('Tick', $this->MillisecondsToNextMinute());
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
    }

    /** Mittlere Leistung der abgelaufenen Viertelstunde berechnen und speichern */
    private function CloseInterval(int $start, int $end, float $meter)
    {
        $duration = $end - $start;
        $delta = $meter - $this->ReadAttributeFloat('IntervalStartMeter');
        if ($duration > self::MAX_GAP || $delta < 0) {
            return; // Lücke oder Zählertausch: kein sinnvoller Wert
        }
        $kW = round($delta / ($duration / 3600), 3);
        $count = $this->ReadAttributeInteger('TempCount');
        $temperature = $count > 0 ? round($this->ReadAttributeFloat('TempSum') / $count, 1) : $this->GetTemperature();

        $history = json_decode($this->ReadAttributeString('History'), true) ?: [];
        $history[] = [$start, $kW, $temperature];
        $history = array_values(array_filter($history, fn ($p) => $p[0] >= $end - self::HISTORY_DAYS * 86400));
        $this->WriteAttributeString('History', json_encode($history));
        $this->SetValue('Power', $kW);

        // Tagesspitze (die Viertelstunde zählt zu dem Tag, an dem sie begonnen hat)
        $day = date('Y-m-d', $start);
        $peaks = json_decode($this->ReadAttributeString('Peaks'), true) ?: [];
        if (!isset($peaks[$day]) || $kW > $peaks[$day][0]) {
            $peaks[$day] = [$kW, $start];
        }
        ksort($peaks);
        $peaks = array_slice($peaks, -31, null, true);
        $this->WriteAttributeString('Peaks', json_encode($peaks));
        if ($day === date('Y-m-d')) {
            $this->SetValue('PeakToday', $peaks[$day][0]);
            $this->SetValue('PeakTodayTime', $peaks[$day][1]);
        }
    }

    /** Zählerzuwachs als Verbrauch und Kosten verbuchen */
    private function AddConsumption(float $kWh)
    {
        $this->StartNewPeriods(time());
        if ($kWh <= 0 || $kWh > 100) {
            return; // keine Änderung bzw. Zählertausch/Sprung
        }
        $cost = $kWh * $this->ReadPropertyFloat('PricePerKWh');
        foreach (['EnergyToday', 'EnergyTotal'] as $ident) {
            $this->SetValue($ident, $this->GetValue($ident) + $kWh);
        }
        foreach (['CostToday', 'CostMonth', 'CostYear', 'CostTotal'] as $ident) {
            $this->SetValue($ident, $this->GetValue($ident) + $cost);
        }
    }

    /** Tages-, Monats- und Jahreswerte zum Beginn eines neuen Zeitraums zurücksetzen */
    private function StartNewPeriods(int $now)
    {
        $previousDay = $this->ReadAttributeString('PeriodDay');
        $today = date('Y-m-d', $now);
        if ($previousDay !== $today) {
            if ($previousDay !== '') {
                // Spitze des abgelaufenen Tages festhalten (wird im Archiv aufgezeichnet)
                $peaks = json_decode($this->ReadAttributeString('Peaks'), true) ?: [];
                $peak = $peaks[$previousDay][0] ?? 0.0;
                $this->SetValue('PeakYesterday', $peak);
                $this->SetValue('DailyPeak', $peak);
                $this->SetValue('PeakToday', 0);
                $this->SetValue('PeakTodayTime', 0);
                $this->SetValue('EnergyToday', 0);
                $this->SetValue('CostToday', 0);
            }
            $this->WriteAttributeString('PeriodDay', $today);
        }
        foreach (['PeriodMonth' => ['Y-m', 'CostMonth'], 'PeriodYear' => ['Y', 'CostYear']] as $attribute => [$format, $ident]) {
            $current = date($format, $now);
            $previous = $this->ReadAttributeString($attribute);
            if ($previous !== $current) {
                if ($previous !== '') {
                    $this->SetValue($ident, 0);
                }
                $this->WriteAttributeString($attribute, $current);
            }
        }
    }

    private function UpdateTemperature()
    {
        $temperature = $this->GetTemperature();
        if ($temperature !== null) {
            $this->SetValue('Temperature', $temperature);
        }
    }

    private function GetFullUpdateMessage(): string
    {
        $now = time();
        $history = json_decode($this->ReadAttributeString('History'), true) ?: [];
        $peaks = json_decode($this->ReadAttributeString('Peaks'), true) ?: [];

        // Spitzen der letzten 7 Tage (auch Tage ohne Wert, damit die Leiste vollständig ist)
        $peakList = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-$i days", $now));
            $peakList[] = [$day, $peaks[$day][0] ?? null, $peaks[$day][1] ?? null];
        }

        $today = date('Y-m-d', $now);
        return json_encode([
            'now'         => $now,
            'power'       => count($history) > 0 ? end($history)[1] : null,
            'temperature' => $this->GetTemperature(),
            'energyToday' => $this->GetValue('EnergyToday'),
            'costToday'   => $this->GetValue('CostToday'),
            'peakToday'   => $peaks[$today] ?? null,
            'series'      => array_values(array_filter($history, fn ($p) => $p[0] >= $now - 86400)),
            'peaks'       => $peakList
        ]);
    }

    private function GetTemperature(): ?float
    {
        if (!$this->VariableConfigured('TemperatureVariableID')) {
            return null;
        }
        return round((float) GetValue($this->ReadPropertyInteger('TemperatureVariableID')), 1);
    }

    private function MillisecondsToNextMinute(): int
    {
        return (60 - (time() % 60) + 1) * 1000; // 1 s nach dem Minutenwechsel
    }

    private function VariableConfigured(string $property): bool
    {
        $id = $this->ReadPropertyInteger($property);
        return $id > 0 && IPS_VariableExists($id);
    }

    private function CreateProfile(string $name, string $suffix, int $digits, string $icon)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, VARIABLETYPE_FLOAT);
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
}
