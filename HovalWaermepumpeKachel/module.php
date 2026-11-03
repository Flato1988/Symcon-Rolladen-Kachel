<?php

declare(strict_types=1);

/**
 * Anlagenschema und Auswertung für Hoval-Wärmepumpen mit WFA-Funktionsautomat (z.B. UltraSource, Belaria Pro).
 * Datenpunkte laut Hoval-Modbus-Liste; Werte kommen aus beliebigen Symcon-Variablen (z.B. Modbus-Geräte).
 */
class HovalWaermepumpeKachel extends IPSModule
{
    // Schlüssel => [Bezeichnung, Hoval-Register (null = kein Hoval-Datenpunkt), Art]
    // Arten: temp (mit Offset), setp (Sollwert °C), pct, kw, num, int, flow, energy (Hoval-Zähler),
    //        mpower / menergy (externer Zähler), map:<Name> (Klartext), raw (Profil der Variable)
    private const DATAPOINTS = [
        'OutdoorTemp'     => ['Außentemperatur', 1477, 'temp'],
        'OperatingMode'   => ['Betriebswahl', 1561, 'map:mode'],
        'WEZStatus'       => ['Status Wärmeerzeuger', 18722, 'map:wez'],
        'WFAStatus'       => ['Status (WFA)', 18723, 'map:wfa'],
        'RunState'        => ['Betriebsmeldung', 18737, 'map:onoff'],
        'ErrorCode'       => ['Fehlercode Wärmepumpe (WFA)', 1534, 'map:error'],
        'WEZSetpoint'     => ['Vorlauftemperatur-Soll', 18724, 'setp'],
        'FlowTemp'        => ['Vorlauftemperatur-Ist', 18725, 'temp'],
        'ReturnTemp'      => ['Rücklauftemperatur', 1535, 'temp'],
        'WEZPower'        => ['Modulation', 18726, 'pct'],
        'FlowRate'        => ['Volumenstrom', 31708, 'flow'],
        'ThermPower'      => ['Aktuelle thermische Leistung', 25612, 'kw'],
        'ElecPower'       => ['Aktuelle elektrische Leistung', 25611, 'kw'],
        'COPPump'         => ['COP', 27490, 'num'],
        'JAZPump'         => ['JAZ', 27467, 'num'],
        'Hours'           => ['Betriebsstunden', 1516, 'int:h'],
        'Cycles'          => ['Schaltzyklen', 1518, 'int'],
        'ElecEnergy'      => ['Elektrische Gesamtenergie', 25613, 'energy'],
        'ThermEnergyHeat' => ['Thermische Gesamtenergie Heizen', 27484, 'energy'],
        'ThermEnergyDHW'  => ['Thermische Gesamtenergie Warmwasser', 27488, 'energy'],
        'HC1FlowTemp'     => ['Heizkreis 1 – Vorlauftemperatur-Ist', 1513, 'temp'],
        'HC1FlowSetpoint' => ['Heizkreis 1 – Vorlauftemperatur-Soll', 19562, 'setp'],
        'HC1Status'       => ['Heizkreis 1 – Status', 1501, 'map:hc'],
        'HC1Mode'         => ['Heizkreis 1 – Betriebswahl', 1478, 'map:hcmode'],
        'RoomSetpoint'    => ['Heizkreis 1 – Raum-Soll', 1493, 'setp'],
        'HC1Pump'         => ['Heizkreis 1 – Umwälzpumpe', 19706, 'map:onoff'],
        'DHWSetpoint'     => ['Warmwasser – Solltemperatur', 1499, 'setp'],
        'StorageTop'      => ['Warmwasser – Isttemperatur oben (SF1)', 1500, 'temp'],
        'StorageBottom'   => ['Warmwasser – Isttemperatur unten (SF2)', 27483, 'temp'],
        'DHWStatus'       => ['Warmwasser – Status', 1504, 'map:dhw'],
        'DHWMode'         => ['Warmwasser – Betriebswahl', 1496, 'map:dhwmode'],
        'BufferTemp'      => ['Puffer – Isttemperatur (WPF)', 27494, 'temp'],
        'BufferTop'       => ['Puffer – Temperatur oben (PF1)', 17386, 'temp'],
        'BufferBottom'    => ['Puffer – Temperatur unten (PF2)', 17387, 'temp'],
        'BufferSetpoint'  => ['Puffer – Solltemperatur', 17385, 'setp'],
        'BufferStatus'    => ['Puffer – Status', 17384, 'map:buffer'],
        'MeasuredPower'   => ['Elektrische Leistung gemessen (externer Zähler)', null, 'mpower'],
        'MeasuredEnergy'  => ['Elektrische Energie gemessen (externer Zähler)', null, 'menergy'],
        'WaterPressure'   => ['Wasserdruck', null, 'raw'],
        'Humidity'        => ['Luftfeuchtigkeit außen', null, 'hum']
    ];

    private const GROUPS = [
        'Wärmeerzeuger'                  => ['OutdoorTemp', 'OperatingMode', 'WEZStatus', 'WFAStatus', 'RunState', 'ErrorCode', 'WEZSetpoint',
                                             'FlowTemp', 'ReturnTemp', 'WEZPower', 'FlowRate', 'ThermPower', 'ElecPower', 'COPPump', 'JAZPump', 'Hours', 'Cycles'],
        'Energiezähler (Hoval)'          => ['ElecEnergy', 'ThermEnergyHeat', 'ThermEnergyDHW'],
        'Externer Zähler'                => ['MeasuredPower', 'MeasuredEnergy'],
        'Heizkreis 1 (FBH)'              => ['HC1FlowTemp', 'HC1FlowSetpoint', 'HC1Status', 'HC1Mode', 'RoomSetpoint', 'HC1Pump'],
        'Warmwasser'                     => ['DHWSetpoint', 'StorageTop', 'StorageBottom', 'DHWStatus', 'DHWMode'],
        'Pufferspeicher'                 => ['BufferTemp', 'BufferTop', 'BufferBottom', 'BufferSetpoint', 'BufferStatus'],
        'Sonstiges (nicht in Hoval-Liste)' => ['Humidity', 'WaterPressure']
    ];

    // Klartexte laut Hoval-Datenpunktliste
    private const MAPS = [
        'onoff'  => [0 => 'Aus', 1 => 'An'],
        'mode'   => [0 => 'Wärmeerzeuger aus', 1 => 'Automatikbetrieb', 4 => 'Manueller Heizbetrieb', 5 => 'Manueller Kühlbetrieb'],
        'wez'    => [0 => 'Abgeschaltet', 1 => 'Heizbetrieb', 2 => 'Vorlaufzeit Heizbetrieb', 3 => 'Extern gesperrt', 4 => 'Kühlbetrieb',
                     5 => 'Vorlaufzeit Kühlbetrieb', 8 => 'Abtauvorgang', // 8 fehlt in der Hoval-Liste, von der Anlage gemeldet 15 => 'Alarm', 16 => 'Störung', 17 => 'Blockiert', 21 => 'WFmax Abschaltung',
                     22 => 'WFsoll Abschaltung', 26 => 'Bivalenzabschaltung', 27 => 'Warmwasser Ladesperre', 28 => 'Minimale Auszeit',
                     29 => 'Minimale Einzeit', 32 => 'Nachlauf', 33 => 'Verzögerung Folge WEZ', 34 => 'Betrieb Übertemperatur',
                     42 => 'Handbetrieb Heizen', 43 => 'Handbetrieb Warmwasser', 44 => 'Handbetrieb Kühlen'],
        'wfa'    => [0 => 'WP aus', 1 => 'Heizen mit WP', 2 => 'Aktivkühlen', 3 => 'Sperre', 4 => 'Warmwasser mit WP',
                     5 => 'WP-Frostschutz', 6 => 'WP-Temperatur zu tief'],
        'error'  => [255 => 'kein Fehler',
                     // Warnungen (W..)
                     74 => 'W11', 76 => 'W13', 80 => 'W17', 81 => 'W18', 82 => 'W19', 83 => 'W20', 84 => 'W21', 85 => 'W22',
                     86 => 'W23', 87 => 'W24', 88 => 'W25', 89 => 'W26', 90 => 'W27', 100 => 'W37', 101 => 'W38', 102 => 'W39',
                     103 => 'W40', 104 => 'W41', 106 => 'W43', 121 => 'W58', 122 => 'W59', 126 => 'W63',
                     // Blockierungen (B..)
                     128 => 'B01', 129 => 'B02', 130 => 'B03', 131 => 'B04', 132 => 'B05', 134 => 'B07', 135 => 'B08', 136 => 'B09',
                     137 => 'B10', 138 => 'B11', 139 => 'B12', 140 => 'B13', 142 => 'B15', 147 => 'B20', 148 => 'B21', 149 => 'B22',
                     150 => 'B23', 151 => 'B24', 152 => 'B25', 153 => 'B26', 159 => 'B32', 160 => 'B33', 161 => 'B34', 162 => 'B35',
                     163 => 'B36', 164 => 'B37', 171 => 'B44', 172 => 'B45', 173 => 'B46', 174 => 'B47', 175 => 'B48', 176 => 'B49',
                     177 => 'B50', 178 => 'B51', 181 => 'B54', 182 => 'B55', 183 => 'B56', 184 => 'B57', 185 => 'B58', 188 => 'B61',
                     189 => 'B62', 190 => 'B63',
                     // Fehler (E..)
                     192 => 'E01', 193 => 'E02', 194 => 'E03', 195 => 'E04', 198 => 'E07', 199 => 'E08', 200 => 'E09', 202 => 'E11',
                     203 => 'E12', 204 => 'E13', 206 => 'E15', 207 => 'E16', 213 => 'E22', 216 => 'E25', 217 => 'E26', 218 => 'E27'],
        'hc'     => [0 => 'Abgeschaltet', 1 => 'Normal Heizbetrieb', 2 => 'Komfort Heizbetrieb', 3 => 'Spar Heizbetrieb', 4 => 'Frostbetrieb',
                     5 => 'Zwangsabnahme', 6 => 'Zwangsdrosselung', 7 => 'Ferienbetrieb', 8 => 'Partybetrieb', 9 => 'Normal Kühlbetrieb',
                     10 => 'Komfort Kühlbetrieb', 11 => 'Spar Kühlbetrieb', 12 => 'Störung', 13 => 'Handbetrieb', 14 => 'Schutz Kühlbetrieb',
                     15 => 'Partybetrieb Kühlen', 16 => 'Austrocknung Aufheizphase', 17 => 'Austrocknung Stationärphase',
                     18 => 'Austrocknung Abkühlphase', 19 => 'Austrocknung Endphase', 22 => 'Kühlbetrieb extern', 23 => 'Heizbetrieb extern',
                     24 => 'Abwesend Heizbetrieb', 25 => 'Abwesend Kühlbetrieb', 26 => 'SmartGrid Heizen', 27 => 'SmartGrid Kühlen'],
        'hcmode' => [0 => 'Standby', 1 => 'Woche 1', 2 => 'Woche 2', 4 => 'Konstant', 5 => 'Sparbetrieb', 7 => 'Handbetrieb Heizen', 8 => 'Handbetrieb Kühlen'],
        'dhw'    => [0 => 'Abgeschaltet', 1 => 'Normal Ladebetrieb', 2 => 'Komfort Ladebetrieb', 3 => 'Zwangsdrosselung', 4 => 'Zwangsladung',
                     5 => 'Störung', 6 => 'WW-Entnahme', 7 => 'Warnung', 8 => 'Reduzierter Ladebetrieb', 9 => 'Frostbetrieb',
                     12 => 'SmartGrid Vorzugsbetrieb', 13 => 'SmartGrid Abnahmezwang'],
        'dhwmode' => [0 => 'Standby', 1 => 'Woche 1', 2 => 'Woche 2', 4 => 'Konstant', 5 => 'Sparbetrieb'],
        'buffer' => [0 => 'Keine Anforderung', 1 => 'Anforderung, Temperatur tief', 2 => 'Ladung läuft', 3 => 'Sollwert erfüllt, Nachlauf',
                     4 => 'Sollwert erfüllt', 6 => 'Energiezwang', 7 => 'Abschöpffunktion', 8 => 'SmartGrid Vorzugsbetrieb', 9 => 'SmartGrid Abnahmezwang']
    ];

    // Wasser: Dichte ca. 0,997 kg/l, spez. Wärmekapazität 4,18 kJ/(kg·K)
    private const WATER_KJ_PER_LITER_K = 0.997 * 4.18;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('TileName', ''); // Bezeichnung = Titel der Kachel
        foreach (self::DATAPOINTS as $key => [$caption, $register, $kind]) {
            $this->RegisterPropertyInteger($key . 'ID', 0);
            if ($kind === 'temp') {
                $this->RegisterPropertyFloat('Offset' . $key, 0); // Fühlerkorrektur in K
            }
        }
        $this->RegisterPropertyInteger('FlowRateUnit', 0);       // 0 = l/min (Hoval), 1 = l/h
        $this->RegisterPropertyInteger('HovalEnergyUnit', 1);    // 0 = kWh, 1 = MWh (Hoval)
        $this->RegisterPropertyInteger('MeasuredPowerUnit', 0);  // 0 = W, 1 = kW
        $this->RegisterPropertyInteger('MeasuredEnergyUnit', 1); // 0 = Wh, 1 = kWh, 2 = MWh
        $this->RegisterPropertyFloat('PricePerKWh', 0.25);       // Strompreis (Wärmepumpentarif) in €/kWh
        // Eigene Klartexte [{Key, Value, Text}] – haben Vorrang vor den Hoval-Texten
        $this->RegisterPropertyString('TextOverrides', '[]');

        // Vergleichszeitraum: Zählerstände zu Beginn, aufsummierte hydraulische Wärme
        $this->RegisterAttributeString('Baseline', '');
        $this->RegisterAttributeFloat('HydEnergy', 0);
        $this->RegisterAttributeInteger('LastHydTime', 0);
        // Abtauerkennung {active, last, start} und Tageswerte für das Verlaufsdiagramm
        $this->RegisterAttributeString('DefrostState', '');
        $this->RegisterAttributeString('DayStats', '');
        $this->RegisterAttributeString('DailyHistory', '[]');
        // Aufteilung des Stroms nach Betriebsart: {last, p (kW), mode: heat|ww|defrost}
        $this->RegisterAttributeString('SplitState', '');
        // 15-Minuten-Leistung: laufende Viertelstunde, abgeschlossene Viertelstunden [[Beginn, kW, °C]] (7 Tage),
        // Tagesspitzen {Datum: [kW, Beginn]} (31 Tage)
        $this->RegisterAttributeInteger('QStart', 0);
        $this->RegisterAttributeFloat('QEnergy', 0);
        $this->RegisterAttributeFloat('QTempSum', 0);
        $this->RegisterAttributeInteger('QTempCount', 0);
        $this->RegisterAttributeString('QHistory', '[]');
        $this->RegisterAttributeString('QPeaks', '{}');

        $this->RegisterTimer('Tick', 0, 'HWP_Tick($_IPS[\'TARGET\']);');

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

        // Eigene Berechnungen als Variablen (Großansicht + Archiv)
        $this->CreateProfile('HWP.COP', 2, '', 'Graph');
        $this->CreateProfile('HWP.Percent', 1, ' %', 'Graph');
        $this->CreateProfile('HWP.kW', 2, ' kW', 'Flame');
        $this->CreateProfile('HWP.kWh', 1, ' kWh', 'Electricity');
        $calculated = [
            'COPOwn'     => ['COP berechnet (Hydraulik)', 'HWP.COP'],
            'DevCOP'     => ['Abweichung COP Pumpe / berechnet', 'HWP.Percent'],
            'JAZOwn'     => ['JAZ berechnet (Vergleichszeitraum)', 'HWP.COP'],
            'DevJAZ'     => ['Abweichung JAZ Pumpe / berechnet', 'HWP.Percent'],
            'ThermHyd'   => ['Thermische Leistung berechnet (Hydraulik)', 'HWP.kW'],
            'DevTherm'   => ['Abweichung thermische Leistung', 'HWP.Percent'],
            'DevPower'   => ['Abweichung el. Leistung Hoval / gemessen', 'HWP.Percent'],
            'DevEnergy'  => ['Abweichung el. Energie Hoval / gemessen', 'HWP.Percent'],
            'HeatPeriod' => ['Wärme im Vergleichszeitraum', 'HWP.kWh'],
            'ElecPeriod' => ['Strom im Vergleichszeitraum', 'HWP.kWh']
        ];
        $position = 200; // nach den Anzeige-Variablen der Datenpunkte
        foreach ($calculated as $ident => [$caption, $profile]) {
            $this->RegisterVariableFloat($ident, $caption, $profile, $position++);
            $this->EnableLogging($ident);
        }
        // Abtauungen
        $this->RegisterVariableBoolean('DefrostActive', 'Abtauung aktiv', '~Switch', $position++);
        $this->RegisterVariableInteger('DefrostCountToday', 'Abtauungen heute', '', $position++);
        $this->RegisterVariableInteger('DefrostCountTotal', 'Abtauungen gesamt', '', $position++);
        $this->RegisterVariableFloat('DefrostLastMinutes', 'Dauer letzte Abtauung (min)', 'HWP.COP', $position++);
        foreach (['DefrostElecToday' => 'Strom für Abtauung heute', 'DefrostElecTotal' => 'Strom für Abtauung gesamt',
                     'DefrostHeatToday' => 'Wärmeentnahme Abtauung heute', 'DefrostHeatTotal' => 'Wärmeentnahme Abtauung gesamt'] as $ident => $caption) {
            $this->RegisterVariableFloat($ident, $caption, 'HWP.kWh', $position++);
        }
        // Tageswerte (am Tagesende geschrieben, im Archiv aufgezeichnet)
        $this->CreateProfile('HWP.Temp', 1, ' °C', 'Temperature');
        foreach (['DailyElec' => ['Stromverbrauch Vortag', 'HWP.kWh'], 'DailyHeat' => ['Wärme Vortag', 'HWP.kWh'],
                     'DailyTemp' => ['Außentemperatur Ø Vortag', 'HWP.Temp'], 'DailyDefrost' => ['Abtauungen Vortag', 'HWP.COP']] as $ident => [$caption, $profile]) {
            $this->RegisterVariableFloat($ident, $caption, $profile, $position++);
            $this->EnableLogging($ident);
        }
        $this->EnableLogging('DefrostCountTotal');
        $this->EnableLogging('DefrostElecTotal');

        // 15-Minuten-Leistung und Tagesspitzen
        $this->CreateProfile('HWP.Euro', 2, ' €', 'Euro');
        $this->RegisterVariableFloat('Power15', 'Leistung Ø 15 min', 'HWP.kW', $position++);
        $this->RegisterVariableFloat('Peak15Today', 'Spitze 15 min heute', 'HWP.kW', $position++);
        $this->RegisterVariableInteger('Peak15TodayTime', 'Spitze heute um', '~UnixTimestampTime', $position++);
        $this->RegisterVariableFloat('Peak15Yesterday', 'Spitze 15 min gestern', 'HWP.kW', $position++);
        $this->RegisterVariableFloat('Peak15Daily', 'Spitze 15 min je Tag', 'HWP.kW', $position++);
        $this->RegisterVariableFloat('CostToday', 'Stromkosten heute', 'HWP.Euro', $position++);
        $this->EnableLogging('Power15');
        $this->EnableLogging('Peak15Daily');

        @$this->UnregisterVariable('COP');        // aus Version 1.6
        @$this->UnregisterVariable('Deviation');  // aus Version 1.6

        // Großansicht: zeigt die Objekte unterhalb der Instanz -> eigene Anzeige-Variablen (ohne Aktion, mit Klartext/Einheit)
        $this->MaintainMirrors();

        $this->SetStatus(102);
        $this->SetTimerInterval('Tick', $this->MillisecondsToNextMinute());
        $this->Tick();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;

            case VM_UPDATE:
                foreach (array_keys(self::DATAPOINTS) as $key) {
                    if ($SenderID === $this->GetVariableID($key)) {
                        $this->UpdateMirror($key);
                    }
                }
                // Strom bis jetzt der bisherigen Betriebsart zuordnen (Leistung/Ventil/Status können sich geändert haben)
                $this->IntegrateSplit(time());
                // Abtauung möglichst sofort erkennen (nicht erst beim nächsten Minutentakt)
                foreach (['WEZStatus', 'FlowTemp', 'ReturnTemp', 'FlowRate'] as $key) {
                    if ($SenderID === $this->GetVariableID($key)) {
                        $this->EvaluateDefrost(time());
                        break;
                    }
                }
                $this->UpdateCalculations();
                $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
                break;
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Stats': // Kachel fordert die Auswertung an: {"p": "day"|"week"|"month", "o": Seite (0 = aktuell)}
                $request = json_decode((string) $Value, true) ?: [];
                $period = in_array($request['p'] ?? '', ['day', 'week', 'month'], true) ? $request['p'] : 'day';
                $this->UpdateVisualizationValue(json_encode(['stats' => $this->GetStats($period, max(0, (int) ($request['o'] ?? 0)))]));
                break;
            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm()
    {
        $elements = [
            ['type' => 'ValidationTextBox', 'name' => 'TileName', 'caption' => 'Bezeichnung (Titel der Kachel, z.B. Wärmepumpe)'],
            ['type' => 'Label', 'caption' => 'Datenpunkte laut Hoval-Modbus-Liste (WFA, z.B. UltraSource). Alle Felder sind optional; in Klammern die Registeradresse.']
        ];
        foreach (self::GROUPS as $group => $keys) {
            $items = [];
            foreach ($keys as $key) {
                [$caption, $register] = self::DATAPOINTS[$key];
                $items[] = ['type' => 'SelectVariable', 'name' => $key . 'ID', 'caption' => $caption . ($register ? " ($register)" : '')];
            }
            switch ($group) {
                case 'Wärmeerzeuger':
                    $items[] = ['type' => 'Select', 'name' => 'FlowRateUnit', 'caption' => 'Einheit Volumenstrom', 'options' => [
                        ['caption' => 'l/min (Hoval)', 'value' => 0], ['caption' => 'l/h', 'value' => 1]]];
                    break;
                case 'Energiezähler (Hoval)':
                    $items[] = ['type' => 'Select', 'name' => 'HovalEnergyUnit', 'caption' => 'Einheit der Hoval-Zähler', 'options' => [
                        ['caption' => 'kWh', 'value' => 0], ['caption' => 'MWh (Hoval)', 'value' => 1]]];
                    break;
                case 'Externer Zähler':
                    $items[] = ['type' => 'NumberSpinner', 'name' => 'PricePerKWh', 'caption' => 'Strompreis (Wärmepumpentarif)', 'suffix' => '€/kWh', 'digits' => 4, 'minimum' => 0];
                    $items[] = ['type' => 'Select', 'name' => 'MeasuredPowerUnit', 'caption' => 'Einheit Leistung', 'options' => [
                        ['caption' => 'W', 'value' => 0], ['caption' => 'kW', 'value' => 1]]];
                    $items[] = ['type' => 'Select', 'name' => 'MeasuredEnergyUnit', 'caption' => 'Einheit Energie', 'options' => [
                        ['caption' => 'Wh', 'value' => 0], ['caption' => 'kWh', 'value' => 1], ['caption' => 'MWh', 'value' => 2]]];
                    break;
            }
            $elements[] = ['type' => 'ExpansionPanel', 'caption' => $group, 'items' => $items];
        }

        // Offsets für alle Temperaturfühler
        $offsets = [['type' => 'Label', 'caption' => 'Korrektur in Kelvin, wird zum Messwert addiert (z.B. -0,5 wenn der Fühler 0,5 K zu hoch misst).']];
        foreach (self::DATAPOINTS as $key => [$caption, $register, $kind]) {
            if ($kind === 'temp') {
                $offsets[] = ['type' => 'NumberSpinner', 'name' => 'Offset' . $key, 'caption' => $caption, 'digits' => 1, 'suffix' => 'K'];
            }
        }
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => 'Fühler-Offsets', 'items' => $offsets];

        // Eigene Klartexte für Status, Betriebsarten, Störungen
        $options = [];
        foreach ($this->GetTextKeys() as $key) {
            $options[] = ['caption' => self::DATAPOINTS[$key][0], 'value' => $key];
        }
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => 'Klartexte (Status, Betriebsarten, Störungen)', 'items' => [
            ['type' => 'Label', 'caption' => 'Eigener Text je Datenpunkt und Wert, z.B. „Status Wärmeerzeuger“ · 1 · „Betriebsbereit“. '
                . 'Eigene Texte haben Vorrang; Werte ohne Eintrag zeigen den Hoval-Text bzw. „Code X“.'],
            ['type' => 'List', 'name' => 'TextOverrides', 'caption' => 'Klartexte', 'rowCount' => 12, 'add' => true, 'delete' => true,
             'sort' => ['column' => 'Key', 'direction' => 'ascending'],
             'columns' => [
                 ['caption' => 'Datenpunkt', 'name' => 'Key', 'width' => '320px', 'add' => 'WEZStatus', 'edit' => ['type' => 'Select', 'options' => $options]],
                 ['caption' => 'Wert', 'name' => 'Value', 'width' => '90px', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 0]],
                 ['caption' => 'Klartext', 'name' => 'Text', 'width' => 'auto', 'add' => '', 'edit' => ['type' => 'ValidationTextBox']]
             ]],
            ['type' => 'Button', 'caption' => 'Hoval-Standardtexte als Vorlage laden', 'onClick' => 'HWP_LoadDefaultTexts($id);'],
            ['type' => 'Label', 'caption' => 'Lädt die Texte aus der Hoval-Liste für alle eingetragenen Datenpunkte in die Tabelle (vorhandene Einträge bleiben). Danach anpassen und „Übernehmen“.']
        ]];

        $baseline = json_decode($this->ReadAttributeString('Baseline'), true);
        $since = $baseline ? date('d.m.Y H:i', $baseline['ts']) : 'noch nicht gestartet';
        return json_encode([
            'elements' => $elements,
            'actions'  => [
                ['type' => 'Label', 'caption' => 'Vergleichszeitraum für Energie und JAZ seit: ' . $since],
                ['type' => 'Button', 'caption' => 'Vergleichszeitraum neu starten', 'onClick' => 'HWP_ResetComparison($id);',
                 'confirm' => 'Energie- und JAZ-Vergleich ab jetzt neu beginnen?']
            ]
        ]);
    }

    public function GetVisualizationTile()
    {
        // Startwerte direkt beim Laden der Kachel übergeben
        $initialHandling = '<script>handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
        $module = file_get_contents(__DIR__ . '/module.html');
        return $module . $initialHandling;
    }

    /** Jede Minute: hydraulische Wärme aufsummieren, Berechnungen und Kachel aktualisieren */
    public function Tick()
    {
        $now = time();
        $last = $this->ReadAttributeInteger('LastHydTime');
        $q = $this->GetHydraulicPower();
        if ($last > 0 && $now > $last && $now - $last <= 15 * 60 && $q !== null && $q > 0) {
            $this->WriteAttributeFloat('HydEnergy', $this->ReadAttributeFloat('HydEnergy') + $q * ($now - $last) / 3600);
        }
        $this->WriteAttributeInteger('LastHydTime', $now);

        $this->EvaluateDefrost($now);
        $this->IntegrateSplit($now);
        $this->UpdateQuarter($now);
        $this->UpdateDayStats($now);
        foreach (array_keys(self::DATAPOINTS) as $key) {
            $this->UpdateMirror($key);
        }
        $this->UpdateCalculations();
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        // nächster Lauf kurz nach dem Minutenwechsel, damit die Viertelstunden genau an :00/:15/:30/:45 enden
        $this->SetTimerInterval('Tick', $this->MillisecondsToNextMinute());
    }

    private function MillisecondsToNextMinute(): int
    {
        return (60 - (time() % 60) + 1) * 1000;
    }

    /** Hoval-Standardtexte als Vorlage in die Klartext-Tabelle des Formulars laden (vorhandene Einträge bleiben) */
    public function LoadDefaultTexts()
    {
        $rows = json_decode($this->ReadPropertyString('TextOverrides'), true) ?: [];
        $existing = [];
        foreach ($rows as $row) {
            $existing[$row['Key'] . '|' . (int) $row['Value']] = true;
        }
        $configured = array_filter($this->GetTextKeys(), fn ($key) => $this->GetVariableID($key) > 0);
        foreach ($configured ?: $this->GetTextKeys() as $key) {
            $kind = self::DATAPOINTS[$key][2];
            if (strpos($kind, 'map:') !== 0) {
                continue;
            }
            foreach (self::MAPS[substr($kind, 4)] as $value => $text) {
                if (!isset($existing[$key . '|' . $value])) {
                    $rows[] = ['Key' => $key, 'Value' => $value, 'Text' => $text];
                }
            }
        }
        $this->UpdateFormField('TextOverrides', 'values', json_encode($rows));
    }

    /** Vergleichszeitraum (Energie, JAZ) ab jetzt neu beginnen */
    public function ResetComparison()
    {
        $this->WriteAttributeString('Baseline', '');
        // Tagesbilanz (falls sie die hydraulische Wärme nutzt) um den Reset korrigieren
        $day = json_decode($this->ReadAttributeString('DayStats'), true);
        if ($day && $this->ThermEnergyHovalKWh() === null && $day['heatStart'] !== null) {
            $day['heatStart'] -= $this->ReadAttributeFloat('HydEnergy');
            $this->WriteAttributeString('DayStats', json_encode($day));
        }
        $this->WriteAttributeFloat('HydEnergy', 0);
        $this->UpdateCalculations();
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
        $this->ReloadForm();
    }

    // ---------------------------------------------------------------- Abtauung

    /**
     * Abtauung erkennen und Energie aufsummieren. Während der Abtauung läuft der Kältekreis umgekehrt:
     * Die Wärmepumpe entzieht dem Heizwasser Wärme (Vorlauf kälter als Rücklauf) und braucht dafür Strom.
     */
    private function EvaluateDefrost(int $now)
    {
        $state = (json_decode($this->ReadAttributeString('DefrostState'), true) ?: []) + ['active' => false, 'last' => 0, 'start' => 0, 'q' => 0, 'p' => 0];
        $q = $this->GetHydraulicPower();
        $p = $this->MeasuredPowerKW() ?? $this->Value('ElecPower') ?? 0;

        // Energie seit der letzten Auswertung mit den Werten von damals dem bisherigen Zustand zuordnen
        $dt = ($state['last'] > 0 && $now > $state['last'] && $now - $state['last'] <= 15 * 60) ? $now - $state['last'] : 0;
        if ($state['active'] && $dt > 0) {
            $elec = max(0, $state['p']) * $dt / 3600;
            $heat = max(0, -$state['q']) * $dt / 3600;
            foreach (['DefrostElecToday' => $elec, 'DefrostElecTotal' => $elec, 'DefrostHeatToday' => $heat, 'DefrostHeatTotal' => $heat] as $ident => $add) {
                if ($add > 0) {
                    $this->SetValue($ident, $this->GetValue($ident) + $add);
                }
            }
        }

        // Zustand bestimmen: Status Wärmeerzeuger (18722) = 8 "Abtauvorgang";
        // nur falls der Status nicht eingetragen ist: Ersatz über die Spreizung (VL mind. 0,5 K unter RL, Ende bei VL ≥ RL)
        $wez = $this->Value('WEZStatus');
        if ($wez !== null) {
            $active = (int) round($wez) === 8;
        } else {
            $vl = $this->Value('FlowTemp');
            $rl = $this->Value('ReturnTemp');
            $flow = $this->Value('FlowRate') ?? 0;
            $spread = ($vl !== null && $rl !== null) ? $vl - $rl : null;
            $limit = $state['active'] ? 0 : -0.5;
            $active = $spread !== null && $flow > 0 && $spread < $limit;
        }

        if ($active && !$state['active']) {
            $state['start'] = $now;
            $this->SetValue('DefrostCountToday', $this->GetValue('DefrostCountToday') + 1);
            $this->SetValue('DefrostCountTotal', $this->GetValue('DefrostCountTotal') + 1);
        } elseif (!$active && $state['active'] && $state['start'] > 0) {
            $this->SetValue('DefrostLastMinutes', round(($now - $state['start']) / 60, 1));
        }
        if ($this->GetValue('DefrostActive') !== $active) {
            $this->SetValue('DefrostActive', $active);
        }
        $state['active'] = $active;
        $state['last'] = $now;
        $state['q'] = $q ?? 0;
        $state['p'] = $p;
        $this->WriteAttributeString('DefrostState', json_encode($state));
    }

    // ---------------------------------------------------------------- Tageswerte

    /** Zählerstand Strom in kWh für die Tagesbilanz: externer Zähler, sonst Hoval */
    private function DayElecCounter(): ?float
    {
        return $this->EnergyKWh('MeasuredEnergy') ?? $this->EnergyKWh('ElecEnergy');
    }

    /** Zählerstand Wärme in kWh: Hoval-Wärmezähler, sonst die selbst aufsummierte hydraulische Wärme */
    private function DayHeatCounter(): ?float
    {
        $hoval = $this->ThermEnergyHovalKWh();
        if ($hoval !== null) {
            return $hoval;
        }
        return $this->GetVariableID('FlowRate') > 0 ? $this->ReadAttributeFloat('HydEnergy') : null;
    }

    /** Tagesbilanz aus den Zählerständen zu Tagesbeginn */
    private function DaySummary(array $day): array
    {
        $elec = $this->DayElecCounter();
        $heat = $this->DayHeatCounter();
        $dElec = ($elec !== null && $day['elecStart'] !== null && $elec >= $day['elecStart']) ? $elec - $day['elecStart'] : null;
        $dHeat = ($heat !== null && $day['heatStart'] !== null && $heat >= $day['heatStart']) ? $heat - $day['heatStart'] : null;
        // Strom gesamt: Zähler, sonst die aufsummierte Leistung; aufgeteilt in Abtauen, Warmwasser, Heizen (Rest)
        $total = $dElec ?? (($day['eInt'] ?? 0) > 0 ? $day['eInt'] : null);
        $defrost = $this->GetValue('DefrostElecToday');
        $dhw = $day['eDhw'] ?? 0;
        if ($total !== null && $defrost + $dhw > $total) {
            // Zähler und aufsummierte Leistung weichen leicht ab: Anteile auf den Zählerwert begrenzen
            $scale = $total / ($defrost + $dhw);
            $defrost *= $scale;
            $dhw *= $scale;
        }
        return [
            'd'    => $day['date'],
            't'    => $day['tCount'] > 0 ? round($day['tSum'] / $day['tCount'], 1) : null,
            'tmin' => $day['tMin'],
            'tmax' => $day['tMax'],
            'hum'  => ($day['humCount'] ?? 0) > 0 ? round($day['humSum'] / $day['humCount'], 0) : null,
            'e'    => $total === null ? null : round($total, 2),
            'eh'   => $total === null ? null : round(max(0, $total - $defrost - $dhw), 2),
            'ew'   => round($dhw, 2),
            'ed'   => round($defrost, 2),
            'h'    => $dHeat === null ? null : round($dHeat, 2),
            'df'   => $this->GetValue('DefrostCountToday'),
            'dfe'  => round($this->GetValue('DefrostElecToday'), 2)
        ];
    }

    /** Jede Minute: Außentemperatur sammeln; um Mitternacht den Tag abschließen */
    private function UpdateDayStats(int $now)
    {
        $today = date('Y-m-d', $now);
        $day = json_decode($this->ReadAttributeString('DayStats'), true);

        if ($day && $day['date'] !== $today) {
            // Tag abschließen: in den Verlauf schreiben und als Tageswerte (Archiv) setzen
            $summary = $this->DaySummary($day);
            $history = json_decode($this->ReadAttributeString('DailyHistory'), true) ?: [];
            $history[] = $summary;
            // dauerhaft speichern (ca. 3 Jahre) – Grundlage für Tages-, Wochen- und Monatsvergleiche
            $this->WriteAttributeString('DailyHistory', json_encode(array_slice($history, -1100)));
            foreach (['DailyElec' => 'e', 'DailyHeat' => 'h', 'DailyTemp' => 't', 'DailyDefrost' => 'df'] as $ident => $field) {
                if ($summary[$field] !== null) {
                    $this->SetValue($ident, $summary[$field]);
                }
            }
            $peaks = json_decode($this->ReadAttributeString('QPeaks'), true) ?: [];
            $peak = $peaks[$day['date']][0] ?? 0.0;
            $this->SetValue('Peak15Yesterday', $peak);
            $this->SetValue('Peak15Daily', $peak);
            $this->SetValue('Peak15Today', 0);
            $this->SetValue('Peak15TodayTime', 0);
            foreach (['DefrostCountToday', 'DefrostElecToday', 'DefrostHeatToday'] as $ident) {
                $this->SetValue($ident, 0);
            }
            $day = null;
        }
        if (!$day) {
            $day = ['date' => $today, 'tSum' => 0, 'tCount' => 0, 'tMin' => null, 'tMax' => null, 'humSum' => 0, 'humCount' => 0,
                    'eDhw' => 0, 'eInt' => 0, 'elecStart' => $this->DayElecCounter(), 'heatStart' => $this->DayHeatCounter()];
        }
        // Zähler, die erst später verfügbar werden, nachtragen
        $day['elecStart'] = $day['elecStart'] ?? $this->DayElecCounter();
        $day['heatStart'] = $day['heatStart'] ?? $this->DayHeatCounter();

        $temperature = $this->Value('OutdoorTemp');
        if ($temperature !== null) {
            $day['tSum'] += $temperature;
            $day['tCount']++;
            $day['tMin'] = $day['tMin'] === null ? $temperature : min($day['tMin'], $temperature);
            $day['tMax'] = $day['tMax'] === null ? $temperature : max($day['tMax'], $temperature);
        }
        $humidity = $this->Value('Humidity');
        if ($humidity !== null) {
            $day['humSum'] = ($day['humSum'] ?? 0) + $humidity;
            $day['humCount'] = ($day['humCount'] ?? 0) + 1;
        }
        $this->WriteAttributeString('DayStats', json_encode($day));
    }

    /**
     * Strom der Wärmepumpe nach Betriebsart aufsummieren: Leistung × Zeit seit der letzten Auswertung wird der
     * Betriebsart zugeordnet, die in dieser Zeit galt (Abtauung > Warmwasser (Y-Ventil) > Heizen).
     */
    private function IntegrateSplit(int $now)
    {
        $state = (json_decode($this->ReadAttributeString('SplitState'), true) ?: []) + ['last' => 0, 'p' => 0, 'mode' => 'heat'];
        $day = json_decode($this->ReadAttributeString('DayStats'), true);
        $dt = ($state['last'] > 0 && $now > $state['last'] && $now - $state['last'] <= 15 * 60) ? $now - $state['last'] : 0;
        if ($dt > 0 && $state['p'] > 0) {
            $this->WriteAttributeFloat('QEnergy', $this->ReadAttributeFloat('QEnergy') + $state['p'] * $dt / 3600);
        }
        if ($day && $dt > 0 && $state['p'] > 0) {
            $kWh = $state['p'] * $dt / 3600;
            $day['eInt'] = ($day['eInt'] ?? 0) + $kWh;
            if ($state['mode'] === 'ww') {
                $day['eDhw'] = ($day['eDhw'] ?? 0) + $kWh;
            }
            $this->WriteAttributeString('DayStats', json_encode($day));
        }
        $mode = 'heat';
        if (@$this->GetIDForIdent('DefrostActive') && $this->GetValue('DefrostActive')) {
            $mode = 'defrost';
        } elseif ($this->GetValvePosition() === 'ww') {
            $mode = 'ww';
        }
        $this->WriteAttributeString('SplitState', json_encode([
            'last' => $now, 'p' => max(0, $this->MeasuredPowerKW() ?? $this->Value('ElecPower') ?? 0), 'mode' => $mode
        ]));
    }

    // ---------------------------------------------------------------- 15-Minuten-Leistung

    /**
     * Läuft jede Minute kurz nach dem Minutenwechsel: Außentemperatur sammeln und zu jeder vollen Viertelstunde
     * die mittlere Leistung (Energie der Viertelstunde × 4) speichern – wie die 15-min-Leistung beim Netzbetreiber.
     */
    private function UpdateQuarter(int $now)
    {
        $boundary = intdiv($now, 900) * 900;
        $start = $this->ReadAttributeInteger('QStart');
        if ($start > 0 && $boundary > $start) {
            $duration = $boundary - $start;
            if ($duration <= 2 * 3600) { // längere Lücken (Symcon aus) nicht mitteln
                $kW = round($this->ReadAttributeFloat('QEnergy') / ($duration / 3600), 3);
                $count = $this->ReadAttributeInteger('QTempCount');
                $temperature = $count > 0 ? round($this->ReadAttributeFloat('QTempSum') / $count, 1) : $this->Value('OutdoorTemp');

                $history = json_decode($this->ReadAttributeString('QHistory'), true) ?: [];
                $history[] = [$start, $kW, $temperature];
                $history = array_values(array_filter($history, fn ($q) => $q[0] >= $boundary - 7 * 86400));
                $this->WriteAttributeString('QHistory', json_encode($history));
                $this->SetValue('Power15', $kW);

                // Tagesspitze (die Viertelstunde zählt zu dem Tag, an dem sie begonnen hat)
                $date = date('Y-m-d', $start);
                $peaks = json_decode($this->ReadAttributeString('QPeaks'), true) ?: [];
                if (!isset($peaks[$date]) || $kW > $peaks[$date][0]) {
                    $peaks[$date] = [$kW, $start];
                }
                ksort($peaks);
                $this->WriteAttributeString('QPeaks', json_encode(array_slice($peaks, -31, null, true)));
                if ($date === date('Y-m-d', $now)) {
                    $this->SetValue('Peak15Today', $peaks[$date][0]);
                    $this->SetValue('Peak15TodayTime', $peaks[$date][1]);
                }
            }
        }
        if ($start === 0 || $boundary > $start) {
            $this->WriteAttributeInteger('QStart', $boundary);
            $this->WriteAttributeFloat('QEnergy', 0);
            $this->WriteAttributeFloat('QTempSum', 0);
            $this->WriteAttributeInteger('QTempCount', 0);
        }
        $temperature = $this->Value('OutdoorTemp');
        if ($temperature !== null) {
            $this->WriteAttributeFloat('QTempSum', $this->ReadAttributeFloat('QTempSum') + $temperature);
            $this->WriteAttributeInteger('QTempCount', $this->ReadAttributeInteger('QTempCount') + 1);
        }
    }

    /** Daten für die Ansicht "Leistung": aktuelle Leistung, Kennzahlen, 24-h-Verlauf, Spitzen der letzten 7 Tage */
    private function GetPowerView(): array
    {
        $now = time();
        $history = json_decode($this->ReadAttributeString('QHistory'), true) ?: [];
        $peaks = json_decode($this->ReadAttributeString('QPeaks'), true) ?: [];
        $peakList = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days", $now));
            $peakList[] = [$date, $peaks[$date][0] ?? null, $peaks[$date][1] ?? null];
        }
        $day = json_decode($this->ReadAttributeString('DayStats'), true);
        $energyToday = $day ? $this->DaySummary($day)['e'] : null;
        $current = $this->MeasuredPowerKW() ?? $this->Value('ElecPower');
        return [
            'now'     => $now,
            'current' => $current === null ? null : round(max(0, $current), 3),
            'avg15'   => count($history) > 0 ? end($history)[1] : null,
            'temp'    => $this->Value('OutdoorTemp'),
            'energy'  => $energyToday,
            'cost'    => $energyToday === null ? null : round($energyToday * $this->ReadPropertyFloat('PricePerKWh'), 2),
            'peak'    => $peaks[date('Y-m-d', $now)] ?? null,
            'series'  => array_values(array_filter($history, fn ($q) => $q[0] >= $now - 86400)),
            'peaks'   => $peakList
        ];
    }

    // ---------------------------------------------------------------- Auswertung Tage / Wochen / Monate

    private const WEEKDAYS = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
    private const MONTHS = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];

    /** Schlüssel des Zeitraums, zu dem ein Tag gehört */
    private static function PeriodKey(string $period, int $ts): string
    {
        switch ($period) {
            case 'week':
                return date('o-W', $ts);  // ISO-Kalenderwoche
            case 'month':
                return date('Y-m', $ts);
            default:
                return date('Y-m-d', $ts);
        }
    }

    /**
     * Auswertung für die Kachel: N Zeiträume (plus einen davor für den Vergleich), Seite $page zählt rückwärts.
     * Je Zeitraum: Strom gesamt / Heizen / Warmwasser / Abtauen, Wärme, Arbeitszahl, Ø Außentemperatur (Min/Max),
     * Ø Luftfeuchtigkeit, Anzahl Abtauungen.
     */
    private function GetStats(string $period, int $page): array
    {
        $count = ['day' => 14, 'week' => 12, 'month' => 12][$period];

        // alle Tage inkl. des laufenden
        $days = json_decode($this->ReadAttributeString('DailyHistory'), true) ?: [];
        $current = json_decode($this->ReadAttributeString('DayStats'), true);
        if ($current) {
            $days[] = $this->DaySummary($current) + ['p' => true];
        }

        // Zeiträume rückwärts ab heute bestimmen
        $keys = [];
        $labels = [];
        $now = time();
        for ($i = $page * $count; $i < ($page + 1) * $count + 1; $i++) {
            switch ($period) {
                case 'week':
                    $ts = strtotime("monday this week -$i weeks", $now);
                    $labels[self::PeriodKey($period, $ts)] = ['KW ' . (int) date('W', $ts), date('d.m.', $ts) . '–' . date('d.m.', $ts + 6 * 86400)];
                    break;
                case 'month':
                    $ts = strtotime(date('Y-m-01', $now) . " -$i months");
                    $labels[self::PeriodKey($period, $ts)] = [self::MONTHS[(int) date('n', $ts) - 1] . ' ' . date('y', $ts), self::MONTHS[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts)];
                    break;
                default:
                    $ts = strtotime("-$i days", strtotime(date('Y-m-d 12:00', $now)));
                    $labels[self::PeriodKey($period, $ts)] = [date('d.m.', $ts), self::WEEKDAYS[(int) date('w', $ts)] . ', ' . date('d.m.Y', $ts)];
            }
            $keys[] = self::PeriodKey($period, $ts);
        }
        $keys = array_reverse($keys); // älteste zuerst

        // Tage den Zeiträumen zuordnen und summieren
        $buckets = [];
        $oldestKey = $keys[0];
        $hasOlder = false;
        foreach ($days as $day) {
            $key = self::PeriodKey($period, strtotime($day['d'] . ' 12:00'));
            if (!isset($labels[$key])) {
                $hasOlder = $hasOlder || $key < $oldestKey;
                continue;
            }
            $b = &$buckets[$key];
            $b['days'] = ($b['days'] ?? 0) + 1;
            $b['partial'] = ($b['partial'] ?? false) || !empty($day['p']);
            // Tage vor Version 2.6 ohne Aufteilung: Abtauen aus dfe, Rest zählt als Heizen
            if (isset($day['e']) && $day['e'] !== null && !isset($day['eh'])) {
                $day['ed'] = $day['ed'] ?? ($day['dfe'] ?? 0);
                $day['ew'] = $day['ew'] ?? 0;
                $day['eh'] = max(0, $day['e'] - $day['ed'] - $day['ew']);
            }
            foreach (['e', 'eh', 'ew', 'ed', 'h', 'df'] as $field) {
                if (isset($day[$field]) && $day[$field] !== null) {
                    $b[$field] = ($b[$field] ?? 0) + $day[$field];
                }
            }
            foreach (['t', 'hum'] as $field) {
                if (isset($day[$field]) && $day[$field] !== null) {
                    $b[$field . 'Sum'] = ($b[$field . 'Sum'] ?? 0) + $day[$field];
                    $b[$field . 'Count'] = ($b[$field . 'Count'] ?? 0) + 1;
                }
            }
            if (isset($day['tmin']) && $day['tmin'] !== null) {
                $b['tmin'] = isset($b['tmin']) ? min($b['tmin'], $day['tmin']) : $day['tmin'];
            }
            if (isset($day['tmax']) && $day['tmax'] !== null) {
                $b['tmax'] = isset($b['tmax']) ? max($b['tmax'], $day['tmax']) : $day['tmax'];
            }
            unset($b);
        }

        $rows = [];
        foreach ($keys as $key) {
            $b = $buckets[$key] ?? [];
            $r = fn ($v, $d = 2) => $v === null ? null : round($v, $d);
            $e = $b['e'] ?? null;
            // Tage ohne Aufteilung (vor Version 2.6): Rest als Heizen
            $eh = $b['eh'] ?? ($e !== null ? max(0, $e - ($b['ew'] ?? 0) - ($b['ed'] ?? 0)) : null);
            $rows[] = [
                'k'     => $key,
                'label' => $labels[$key][0],
                'title' => $labels[$key][1],
                'days'  => $b['days'] ?? 0,
                'p'     => $b['partial'] ?? false,
                'e'     => $r($e),
                'eh'    => $r($eh),
                'ew'    => $r($b['ew'] ?? ($e !== null ? 0 : null)),
                'ed'    => $r($b['ed'] ?? ($e !== null ? 0 : null)),
                'h'     => $r($b['h'] ?? null),
                'az'    => ($e !== null && $e > 0.5 && isset($b['h'])) ? round($b['h'] / $e, 2) : null,
                't'     => isset($b['tCount']) ? round($b['tSum'] / $b['tCount'], 1) : null,
                'tmin'  => $b['tmin'] ?? null,
                'tmax'  => $b['tmax'] ?? null,
                'hum'   => isset($b['humCount']) ? round($b['humSum'] / $b['humCount'], 0) : null,
                'df'    => isset($b['df']) ? (int) $b['df'] : null
            ];
        }
        return ['period' => $period, 'page' => $page, 'rows' => $rows, 'older' => $hasOlder];
    }

    // ---------------------------------------------------------------- Werte

    private function GetVariableID(string $key): int
    {
        $id = $this->ReadPropertyInteger($key . 'ID');
        return $id > 0 && IPS_VariableExists($id) ? $id : 0;
    }

    /** Zahlenwert eines Datenpunkts (Temperaturen inkl. Offset), null wenn nicht konfiguriert */
    private function Value(string $key): ?float
    {
        $id = $this->GetVariableID($key);
        if ($id === 0) {
            return null;
        }
        $value = (float) GetValue($id);
        if (self::DATAPOINTS[$key][2] === 'temp') {
            $value += $this->ReadPropertyFloat('Offset' . $key);
        }
        return $value;
    }

    private function Format(string $key): string
    {
        $value = $this->Value($key);
        if ($value === null) {
            return '–';
        }
        $kind = self::DATAPOINTS[$key][2];
        // eigener Klartext hat Vorrang
        $own = $this->GetTextOverrides()[$key][(int) round($value)] ?? null;
        if ($own !== null) {
            return $own;
        }
        if (strpos($kind, 'map:') === 0) {
            $map = self::MAPS[substr($kind, 4)];
            return $map[(int) round($value)] ?? ('Code ' . (int) round($value));
        }
        switch ($kind) {
            case 'temp':
            case 'setp':
                return $this->Number($value, 1) . ' °C';
            case 'pct':
            case 'hum':
                return $this->Number($value, 0) . ' %';
            case 'kw':
                return $this->Number($value, 2) . ' kW';
            case 'num':
                return $this->Number($value, 1);
            case 'flow':
                return $this->Number($value, 1) . ($this->ReadPropertyInteger('FlowRateUnit') === 0 ? ' l/min' : ' l/h');
            case 'energy':
            case 'menergy':
                return $this->Number($this->EnergyKWh($key), 0) . ' kWh';
            case 'mpower':
                return $this->ReadPropertyInteger('MeasuredPowerUnit') === 0 ? $this->Number($value, 0) . ' W' : $this->Number($value, 2) . ' kW';
            case 'raw':
                return GetValueFormatted($this->GetVariableID($key));
        }
        if (strpos($kind, 'int') === 0) {
            return $this->Number($value, 0) . (strpos($kind, ':') ? ' ' . substr($kind, 4) : '');
        }
        return (string) $value;
    }

    /** Datenpunkte, für die Klartexte sinnvoll sind (Status, Betriebsarten, Störungen, Sonstiges) */
    private function GetTextKeys(): array
    {
        return array_keys(array_filter(self::DATAPOINTS, fn ($d) => strpos($d[2], 'map:') === 0 || $d[2] === 'raw'));
    }

    /** Eigene Klartexte als [Schlüssel][Wert] => Text */
    private function GetTextOverrides(): array
    {
        $overrides = [];
        foreach (json_decode($this->ReadPropertyString('TextOverrides'), true) ?: [] as $row) {
            $text = trim((string) ($row['Text'] ?? ''));
            if ($text !== '' && isset($row['Key'])) {
                $overrides[$row['Key']][(int) ($row['Value'] ?? 0)] = $text;
            }
        }
        return $overrides;
    }

    private function Number(float $value, int $digits): string
    {
        return number_format($value, $digits, ',', '.');
    }

    /** Energiezähler in kWh (Einheit laut Einstellung) */
    private function EnergyKWh(string $key): ?float
    {
        $value = $this->Value($key);
        if ($value === null) {
            return null;
        }
        if ($key === 'MeasuredEnergy') {
            return $value * [0.001, 1, 1000][$this->ReadPropertyInteger('MeasuredEnergyUnit')];
        }
        return $value * ($this->ReadPropertyInteger('HovalEnergyUnit') === 1 ? 1000 : 1);
    }

    /** Gemessene elektrische Leistung in kW */
    private function MeasuredPowerKW(): ?float
    {
        $value = $this->Value('MeasuredPower');
        if ($value === null) {
            return null;
        }
        return $this->ReadPropertyInteger('MeasuredPowerUnit') === 0 ? $value / 1000 : $value;
    }

    /** Thermische Leistung aus Volumenstrom und Temperaturspreizung: Q = V̇ · ρ · c · (VL − RL) in kW */
    private function GetHydraulicPower(): ?float
    {
        $flow = $this->Value('FlowRate');
        $vl = $this->Value('FlowTemp');
        $rl = $this->Value('ReturnTemp');
        if ($flow === null || $vl === null || $rl === null) {
            return null;
        }
        $litersPerSecond = $this->ReadPropertyInteger('FlowRateUnit') === 0 ? $flow / 60 : $flow / 3600;
        return $litersPerSecond * self::WATER_KJ_PER_LITER_K * ($vl - $rl);
    }

    private function IsRunning(): bool
    {
        $powerKW = $this->MeasuredPowerKW() ?? $this->Value('ElecPower') ?? 0;
        return ($this->Value('RunState') ?? 0) >= 1 || ($this->Value('WEZPower') ?? 0) > 0 || $powerKW > 0.1;
    }

    /** Stellung des Y-Ventils aus dem Status (WFA / Wärmeerzeuger) – "ww" oder "heat" */
    private function GetValvePosition(): string
    {
        // Status (WFA): 4 = Warmwasser mit WP, 1 = Heizen mit WP
        $wfa = $this->Value('WFAStatus');
        if ($wfa !== null && in_array((int) $wfa, [1, 4], true)) {
            return (int) $wfa === 4 ? 'ww' : 'heat';
        }
        // Status Wärmeerzeuger: 43 = Handbetrieb Warmwasser; Heiz-Zustände -> Heizung
        $wez = $this->Value('WEZStatus');
        if ($wez !== null && (int) $wez === 43) {
            return 'ww';
        }
        // sonst Grundstellung des Y-Ventils: Heizung
        // (z.B. Verdichter aus, aber Umwälzpumpe läuft – dann fließt das Wasser über den Heizkreis)
        return 'heat';
    }

    // ---------------------------------------------------------------- Berechnungen

    /** Abweichung in % = (Wert − Referenz) ÷ Referenz × 100 */
    private static function Deviation(?float $value, ?float $reference, float $minReference): ?float
    {
        if ($value === null || $reference === null || abs($reference) < $minReference) {
            return null;
        }
        return ($value - $reference) / $reference * 100;
    }

    /** Zählerstände zu Beginn des Vergleichszeitraums (fehlende werden nachgetragen, sobald verfügbar) */
    private function GetBaseline(): array
    {
        $baseline = json_decode($this->ReadAttributeString('Baseline'), true) ?: ['ts' => time()];
        $current = [
            'elecHoval'  => $this->EnergyKWh('ElecEnergy'),
            'elecMeas'   => $this->EnergyKWh('MeasuredEnergy'),
            'thermHoval' => $this->ThermEnergyHovalKWh()
        ];
        $changed = !isset($baseline['ts']) || $this->ReadAttributeString('Baseline') === '';
        foreach ($current as $field => $value) {
            if (!isset($baseline[$field]) && $value !== null) {
                $baseline[$field] = $value;
                $changed = true;
            }
        }
        if ($changed) {
            $this->WriteAttributeString('Baseline', json_encode($baseline));
        }
        return $baseline;
    }

    private function ThermEnergyHovalKWh(): ?float
    {
        $heat = $this->EnergyKWh('ThermEnergyHeat');
        $dhw = $this->EnergyKWh('ThermEnergyDHW');
        if ($heat === null && $dhw === null) {
            return null;
        }
        return ($heat ?? 0) + ($dhw ?? 0);
    }

    /** Alle Vergleichswerte berechnen */
    private function Calculate(): array
    {
        $running = $this->IsRunning();

        // Leistung
        $pHoval = $this->Value('ElecPower');
        $pMeas = $this->MeasuredPowerKW();
        $pElec = $pMeas ?? $pHoval;
        $qHoval = $this->Value('ThermPower');
        $qHyd = $this->GetHydraulicPower();

        // COP: Pumpe vs. eigene Berechnung (Hydraulik ÷ elektrisch, bevorzugt gemessen)
        // COP/JAZ der Pumpe: 0 bedeutet "kein Wert" (z.B. Verdichter aus, noch keine Jahresbilanz)
        $copPump = $this->Value('COPPump');
        $copPump = ($copPump !== null && $copPump > 0) ? $copPump : null;
        $copOwn = ($running && $qHyd !== null && $qHyd > 0 && $pElec !== null && $pElec > 0.1) ? min(15, $qHyd / $pElec) : null;

        // Energie und JAZ im Vergleichszeitraum
        $baseline = $this->GetBaseline();
        $elecHoval = $this->EnergyKWh('ElecEnergy');
        $elecMeas = $this->EnergyKWh('MeasuredEnergy');
        $thermHoval = $this->ThermEnergyHovalKWh();
        $dElecHoval = ($elecHoval !== null && isset($baseline['elecHoval'])) ? $elecHoval - $baseline['elecHoval'] : null;
        $dElecMeas = ($elecMeas !== null && isset($baseline['elecMeas'])) ? $elecMeas - $baseline['elecMeas'] : null;
        $dThermHoval = ($thermHoval !== null && isset($baseline['thermHoval'])) ? $thermHoval - $baseline['thermHoval'] : null;
        $hydEnergy = $this->ReadAttributeFloat('HydEnergy');

        // Wärme: Hoval-Zähler, sonst die selbst aufsummierte hydraulische Wärme
        $heat = ($dThermHoval !== null && $dThermHoval > 0) ? $dThermHoval : ($hydEnergy > 0 ? $hydEnergy : null);
        $heatSource = ($dThermHoval !== null && $dThermHoval > 0) ? 'Wärmezähler Hoval' : 'Hydraulik';
        // Strom: externer Zähler, sonst Hoval
        $elec = $dElecMeas ?? $dElecHoval;
        $elecSource = $dElecMeas !== null ? 'Zähler' : 'Hoval';
        $jazPump = $this->Value('JAZPump');
        $jazPump = ($jazPump !== null && $jazPump > 0) ? $jazPump : null;
        $jazOwn = ($heat !== null && $elec !== null && $elec >= 1) ? min(15, $heat / $elec) : null;

        return [
            'running'    => $running,
            'pHoval'     => $pHoval, 'pMeas' => $pMeas, 'devPower' => self::Deviation($pHoval, $pMeas, 0.1),
            'eHoval'     => $dElecHoval, 'eMeas' => $dElecMeas, 'devEnergy' => self::Deviation($dElecHoval, $dElecMeas, 1),
            'qHoval'     => $qHoval, 'qHyd' => $qHyd, 'devTherm' => $running ? self::Deviation($qHoval, $qHyd, 0.3) : null,
            'copPump'    => $copPump, 'copOwn' => $copOwn, 'devCOP' => $copOwn !== null ? self::Deviation($copPump, $copOwn, 0.1) : null,
            'jazPump'    => $jazPump, 'jazOwn' => $jazOwn, 'devJAZ' => self::Deviation($jazPump, $jazOwn, 0.1),
            'heat'       => $heat, 'heatSource' => $heatSource, 'elec' => $elec, 'elecSource' => $elecSource,
            'since'      => $baseline['ts']
        ];
    }

    private function UpdateCalculations()
    {
        $day = json_decode($this->ReadAttributeString('DayStats'), true);
        $energyToday = $day ? $this->DaySummary($day)['e'] : null;
        if ($energyToday !== null && @$this->GetIDForIdent('CostToday')) {
            $cost = round($energyToday * $this->ReadPropertyFloat('PricePerKWh'), 2);
            if ($this->GetValue('CostToday') !== $cost) {
                $this->SetValue('CostToday', $cost);
            }
        }
        $c = $this->Calculate();
        $values = [
            'COPOwn' => $c['copOwn'], 'DevCOP' => $c['devCOP'], 'JAZOwn' => $c['jazOwn'], 'DevJAZ' => $c['devJAZ'],
            'ThermHyd' => $c['qHyd'] !== null ? max(0, $c['qHyd']) : null, 'DevTherm' => $c['devTherm'],
            'DevPower' => $c['devPower'], 'DevEnergy' => $c['devEnergy'], 'HeatPeriod' => $c['heat'], 'ElecPeriod' => $c['elec']
        ];
        foreach ($values as $ident => $value) {
            // nur gültige Werte schreiben – z.B. kein COP im Stillstand, damit das Archiv nicht mit 0 gefüllt wird
            if ($value !== null && @$this->GetIDForIdent($ident)) {
                $rounded = round($value, 2);
                if ($this->GetValue($ident) !== $rounded) {
                    $this->SetValue($ident, $rounded);
                }
            }
        }
    }

    // ---------------------------------------------------------------- Kachel

    private function GetFullUpdateMessage(): string
    {
        $values = [];
        foreach (array_keys(self::DATAPOINTS) as $key) {
            $value = $this->Value($key);
            if ($value !== null) {
                $values[$key] = ['v' => round($value, 3), 'f' => $this->Format($key)];
            }
        }
        $c = $this->Calculate();
        $n = fn (?float $v, int $d, string $u = '') => $v === null ? '–' : $this->Number($v, $d) . $u;
        $since = 'seit ' . date('d.m.', $c['since']);

        // Vergleichstabelle: [Bezeichnung, Pumpe/Hoval, eigene Messung/Berechnung, Hinweis, Abweichung %]
        $rows = [];
        if ($c['pHoval'] !== null || $c['pMeas'] !== null) {
            $rows[] = ['El. Leistung', $n($c['pHoval'], 2, ' kW'), $n($c['pMeas'], 2, ' kW'), 'Zähler', $c['devPower']];
        }
        if ($c['eHoval'] !== null || $c['eMeas'] !== null) {
            $rows[] = ['El. Energie', $n($c['eHoval'], 1, ' kWh'), $n($c['eMeas'], 1, ' kWh'), 'Zähler, ' . $since, $c['devEnergy']];
        }
        if ($c['qHoval'] !== null || $c['qHyd'] !== null) {
            $rows[] = ['Therm. Leistung', $n($c['qHoval'], 2, ' kW'), $n($c['qHyd'] !== null ? max(0, $c['qHyd']) : null, 2, ' kW'), 'Hydraulik', $c['devTherm']];
        }
        if ($c['copPump'] !== null || $c['qHyd'] !== null) {
            $rows[] = ['COP', $n($c['copPump'], 1), $n($c['copOwn'], 2), 'Hydraulik ÷ ' . ($c['pMeas'] !== null ? 'Zähler' : 'Hoval'), $c['devCOP']];
        }
        if ($c['jazPump'] !== null || $c['jazOwn'] !== null) {
            $rows[] = ['JAZ', $n($c['jazPump'], 1), $n($c['jazOwn'], 2), $c['heatSource'] . ' ÷ ' . $c['elecSource'] . ', ' . $since, $c['devJAZ']];
        }
        foreach ($rows as &$row) {
            $row[4] = $row[4] === null ? null : round($row[4], 1);
        }

        $error = $this->Value('ErrorCode');
        return json_encode([
            'values'  => $values,
            'rows'    => $rows,
            'valve'   => $this->GetValvePosition(),
            'heating' => $c['running'],
            'error'   => ($error !== null && (int) $error !== 255) ? $this->Format('ErrorCode') : null,
            'defrost' => [
                'active' => $this->GetValue('DefrostActive'),
                'today'  => $this->GetValue('DefrostCountToday'),
                'elec'   => round($this->GetValue('DefrostElecToday'), 2),
                'heat'   => round($this->GetValue('DefrostHeatToday'), 2),
                'total'  => $this->GetValue('DefrostCountTotal')
            ],
            // Tageswerte: abgeschlossene Tage + der laufende Tag (p = unvollständig)
            'daily'   => $this->GetDailySeries(),
            'power'   => $this->GetPowerView()
        ]);
    }

    private function GetDailySeries(): array
    {
        $history = array_slice(json_decode($this->ReadAttributeString('DailyHistory'), true) ?: [], -29);
        $day = json_decode($this->ReadAttributeString('DayStats'), true);
        if ($day) {
            $history[] = $this->DaySummary($day) + ['p' => true];
        }
        return $history;
    }

    // ---------------------------------------------------------------- Verwaltung

    public function Destroy()
    {
        parent::Destroy();
        // Klartext-Profile dieser Instanz entfernen
        foreach (array_keys(self::DATAPOINTS) as $key) {
            if (IPS_VariableProfileExists($this->TextProfileName($key))) {
                IPS_DeleteVariableProfile($this->TextProfileName($key));
            }
        }
    }

    private function TextProfileName(string $key): string
    {
        return 'HWP.' . $this->InstanceID . '.' . $key;
    }

    /**
     * Anzeige-Variablen für die Großansicht (Ident "M" + Schlüssel): reine Anzeige ohne Aktion
     * (kein Schieberegler), Temperaturen inkl. Offset, Status/Betriebsarten/Fehler mit Klartext-Profil.
     */
    private function MaintainMirrors()
    {
        $this->CreateProfile('HWP.Temp', 1, ' °C', 'Temperature');
        $this->CreateProfile('HWP.Pct', 0, ' %', 'Intensity');
        $this->CreateProfile('HWP.Num', 1, '', 'Graph');
        $this->CreateProfile('HWP.FlowMin', 1, ' l/min', 'Drops');
        $this->CreateProfile('HWP.FlowH', 0, ' l/h', 'Drops');
        $this->CreateProfile('HWP.kWh0', 0, ' kWh', 'Electricity');
        $this->CreateProfile('HWP.W', 0, ' W', 'Electricity');
        $this->CreateProfile('HWP.Hours', 0, ' h', 'Clock', VARIABLETYPE_INTEGER);
        $this->CreateProfile('HWP.Count', 0, '', 'Repeat', VARIABLETYPE_INTEGER);
        $overrides = $this->GetTextOverrides();

        $position = 10;
        foreach (self::DATAPOINTS as $key => [$caption, $register, $kind]) {
            $ident = 'M' . $key;
            if ($this->GetVariableID($key) === 0) {
                @$this->UnregisterVariable($ident);
                $position++;
                continue;
            }
            $logging = false;
            if (strpos($kind, 'map:') === 0) {
                // Klartext-Profil dieser Instanz: Hoval-Texte + eigene Texte (neu aufgebaut, damit Änderungen greifen)
                $profile = $this->TextProfileName($key);
                if (IPS_VariableProfileExists($profile)) {
                    IPS_DeleteVariableProfile($profile);
                }
                IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
                IPS_SetVariableProfileIcon($profile, $key === 'ErrorCode' ? 'Warning' : 'Information');
                $texts = ($overrides[$key] ?? []) + self::MAPS[substr($kind, 4)];
                ksort($texts);
                foreach ($texts as $value => $text) {
                    IPS_SetVariableProfileAssociation($profile, $value, $text, '', -1);
                }
                $this->RegisterVariableInteger($ident, $caption, $profile, $position);
            } else {
                switch ($kind) {
                    case 'temp':
                    case 'setp':
                        $this->RegisterVariableFloat($ident, $caption, 'HWP.Temp', $position);
                        $logging = $kind === 'temp';
                        break;
                    case 'pct':
                    case 'hum':
                        $this->RegisterVariableFloat($ident, $caption, 'HWP.Pct', $position);
                        $logging = true;
                        break;
                    case 'kw':
                        $this->RegisterVariableFloat($ident, $caption, 'HWP.kW', $position);
                        $logging = true;
                        break;
                    case 'num':
                        $this->RegisterVariableFloat($ident, $caption, 'HWP.Num', $position);
                        break;
                    case 'flow':
                        $this->RegisterVariableFloat($ident, $caption, $this->ReadPropertyInteger('FlowRateUnit') === 0 ? 'HWP.FlowMin' : 'HWP.FlowH', $position);
                        $logging = true;
                        break;
                    case 'energy':
                    case 'menergy':
                        $this->RegisterVariableFloat($ident, $caption, 'HWP.kWh0', $position);
                        break;
                    case 'mpower':
                        $this->RegisterVariableFloat($ident, $caption, $this->ReadPropertyInteger('MeasuredPowerUnit') === 0 ? 'HWP.W' : 'HWP.kW', $position);
                        $logging = true;
                        break;
                    case 'int:h':
                        $this->RegisterVariableInteger($ident, $caption, 'HWP.Hours', $position);
                        break;
                    case 'int':
                        $this->RegisterVariableInteger($ident, $caption, 'HWP.Count', $position);
                        break;
                    default: // raw: formatierter Text (Profil der Quelle bzw. eigener Klartext)
                        $this->RegisterVariableString($ident, $caption, '', $position);
                }
            }
            if ($logging) {
                $this->EnableLogging($ident);
            }
            $this->UpdateMirror($key);
            $position++;
        }

        // Anzeige-Variablen entfernter Datenpunkte (bis 2.6) löschen
        foreach (['MValve', 'MPumpSpeed', 'MDefrostState'] as $ident) {
            @$this->UnregisterVariable($ident);
        }

        // Links früherer Versionen (bis 2.2) entfernen
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            if (IPS_LinkExists($childID) && strpos(IPS_GetObject($childID)['ObjectIdent'], 'L') === 0) {
                IPS_DeleteLink($childID);
            }
        }
    }

    /** Anzeige-Variable eines Datenpunkts auf den aktuellen Wert setzen */
    private function UpdateMirror(string $key)
    {
        $ident = 'M' . $key;
        if (!@$this->GetIDForIdent($ident) || $this->Value($key) === null) {
            return;
        }
        $kind = self::DATAPOINTS[$key][2];
        if (strpos($kind, 'map:') === 0 || strpos($kind, 'int') === 0) {
            $value = (int) round($this->Value($key));
        } elseif ($kind === 'energy' || $kind === 'menergy') {
            $value = round($this->EnergyKWh($key), 1);
        } elseif ($kind === 'raw') {
            $value = $this->Format($key);
        } else {
            $value = round($this->Value($key), 3);
        }
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    private function CreateProfile(string $name, int $digits, string $suffix, string $icon, int $type = VARIABLETYPE_FLOAT)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
            if ($type === VARIABLETYPE_FLOAT) {
                IPS_SetVariableProfileDigits($name, $digits);
            }
            IPS_SetVariableProfileText($name, '', $suffix);
            IPS_SetVariableProfileIcon($name, $icon);
        }
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
