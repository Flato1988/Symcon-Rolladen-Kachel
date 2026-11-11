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
        'Cycles'          => ['Schaltzyklen (Verdichterstarts)', 1518, 'int'],
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
        'PumpCP'          => ['Drehzahl Umwälzpumpe CP', 27495, 'pct'],
        // Kältekreis (WFA200, ab Gateway-Software V2.38; Drücke und Überhitzung mit Faktor 0,01 einlesen)
        'AirIntake'       => ['Ansauglufttemperatur', 31902, 'temp'],
        'SuctionGas'      => ['Sauggastemperatur', 31904, 'temp'],
        'HotGas'          => ['Heißgastemperatur', 31906, 'temp'],
        'Superheat'       => ['Überhitzung', 31912, 'kelvin'],
        'LowPressure'     => ['Niederdruck (relativ)', 31914, 'bar'],
        'HighPressure'    => ['Hochdruck (relativ)', 31916, 'bar'],
        'CompressorSpeed' => ['Drehzahl Verdichter (Drehzahl Ist)', 31920, 'pct'],
        'FanSpeed'        => ['Drehzahl WQP (bei Luft-WP meist nicht belegt)', 31910, 'pct'],
        'HPFlowTemp'      => ['WP-Vorlauftemperatur (an der WP)', 31892, 'temp'],
        'HPReturnTemp'    => ['WP-Rücklauftemperatur (an der WP)', 31894, 'temp'],
        'Humidity'        => ['Luftfeuchtigkeit außen', null, 'hum'],
        // Heizkurve und Regelstrategie Heizkreis 1 (lesen; schreibbar über dieselben Modbus-Geräte mit Funktion 6)
        'ControlStrategy' => ['Regelstrategie Heizkreis 1', 27510, 'map:strategy'],
        'CurveFoot'       => ['Heizkurve Fußpunkt VL-Temperatur', 30618, 'setp'],
        'CurveDesign'     => ['Heizkurve Auslegepunkt VL-Temperatur', 30615, 'setp'],
        'CurveDesignAT'   => ['Heizkurve Auslegepunkt Außentemperatur', 30612, 'setp'],
        'FlowConst'       => ['Vorlauf-Soll Konstantanforderung Heizen HK1', 1490, 'setp'],
        'CurveVlMax'      => ['Heizkreis 1 – max. Vorlauftemperatur', 30690, 'setp']
    ];

    private const GROUPS = [
        'Wärmeerzeuger'                  => ['OutdoorTemp', 'OperatingMode', 'WEZStatus', 'WFAStatus', 'RunState', 'ErrorCode', 'WEZSetpoint',
                                             'FlowTemp', 'ReturnTemp', 'WEZPower', 'FlowRate', 'PumpCP', 'ThermPower', 'ElecPower', 'COPPump', 'JAZPump', 'Hours', 'Cycles'],
        'Energiezähler (Hoval)'          => ['ElecEnergy', 'ThermEnergyHeat', 'ThermEnergyDHW'],
        'Externer Zähler'                => ['MeasuredPower', 'MeasuredEnergy'],
        'Heizkreis 1 (FBH)'              => ['HC1FlowTemp', 'HC1FlowSetpoint', 'HC1Status', 'HC1Mode', 'RoomSetpoint', 'HC1Pump'],
        'Warmwasser'                     => ['DHWSetpoint', 'StorageTop', 'StorageBottom', 'DHWStatus', 'DHWMode'],
        'Pufferspeicher'                 => ['BufferTemp', 'BufferTop', 'BufferBottom', 'BufferSetpoint', 'BufferStatus'],
        'Kältekreis (Gateway ab V2.38)'  => ['AirIntake', 'SuctionGas', 'HotGas', 'Superheat', 'LowPressure', 'HighPressure',
                                             'CompressorSpeed', 'FanSpeed', 'HPFlowTemp', 'HPReturnTemp'],
        'Heizkurve / Regelung (optional)'  => ['ControlStrategy', 'CurveFoot', 'CurveDesign', 'CurveDesignAT', 'FlowConst', 'CurveVlMax'],
        'Sonstiges (nicht in Hoval-Liste)' => ['Humidity', 'WaterPressure']
    ];

    // Klartexte laut Hoval-Datenpunktliste
    private const MAPS = [
        'onoff'  => [0 => 'Aus', 1 => 'An'],
        'mode'   => [0 => 'Wärmeerzeuger aus', 1 => 'Automatikbetrieb', 4 => 'Manueller Heizbetrieb', 5 => 'Manueller Kühlbetrieb'],
        'wez'    => [0 => 'Abgeschaltet', 1 => 'Heizbetrieb', 2 => 'Vorlaufzeit Heizbetrieb', 3 => 'Extern gesperrt', 4 => 'Kühlbetrieb',
                     5 => 'Vorlaufzeit Kühlbetrieb', 8 => 'Abtauvorgang', // 8 fehlt in der Hoval-Liste, wird von der Anlage gemeldet
                     15 => 'Alarm', 16 => 'Störung', 17 => 'Blockiert', 21 => 'WFmax Abschaltung',
                     22 => 'WFsoll Abschaltung', 26 => 'Bivalenzabschaltung', 27 => 'Warmwasser Ladesperre', 28 => 'Minimale Auszeit',
                     29 => 'Minimale Einzeit', 32 => 'Nachlauf', 33 => 'Verzögerung Folge WEZ', 34 => 'Betrieb Übertemperatur',
                     42 => 'Handbetrieb Heizen', 43 => 'Handbetrieb Warmwasser', 44 => 'Handbetrieb Kühlen'],
        'wfa'    => [0 => 'WP aus', 1 => 'Heizen mit WP', 2 => 'Aktivkühlen', 3 => 'Sperre', 4 => 'Warmwasser mit WP',
                     5 => 'WP-Frostschutz', 6 => 'WP-Temperatur zu tief', 7 => 'WP-Vorlauf zu hoch', 8 => 'Abtauen',
                     9 => 'Passivkühlen', 11 => 'HD-Störung', 12 => 'Niederdruckstörung', 16 => 'Wiedereinschaltverzögerung',
                     17 => 'EW-/Energieerzeuger-Sperre', 18 => 'Vorlaufzeit Primär', 19 => 'Nachlaufzeit Primär', 44 => 'MOP',
                     49 => 'Erfolglose Abtauung', 51 => 'Vorlaufzeit Kondenserpumpe', 55 => 'Störung Inverter / Modbus',
                     72 => 'Grundwasser Frostschutz', 73 => 'Durchfluss WQ/GW-Kreis', 77 => 'Begrenzung Verdichter',
                     96 => 'Elektrische Leistungsbegrenzung', 97 => 'Kompressoröl vorheizen', 98 => 'Kaltstart',
                     99 => 'Maschine nicht konfiguriert'],
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
        'strategy' => [0 => 'Reine Witterungsführung', 1 => 'Witterungsführung mit Raumaufschaltung', 2 => 'Reine Raumregelung', 3 => 'Konstantregelung',
                       4 => 'Witterungsführung Heizen + Konstant Kühlen', 5 => 'Witterung mit Raumaufschaltung + Konstant Kühlen'],
        'buffer' => [0 => 'Keine Anforderung', 1 => 'Anforderung, Temperatur tief', 2 => 'Ladung läuft', 3 => 'Sollwert erfüllt, Nachlauf',
                     4 => 'Sollwert erfüllt', 6 => 'Energiezwang', 7 => 'Abschöpffunktion', 8 => 'SmartGrid Vorzugsbetrieb', 9 => 'SmartGrid Abnahmezwang']
    ];

    // Wasser: Dichte ca. 0,997 kg/l, spez. Wärmekapazität 4,18 kJ/(kg·K)
    private const WATER_KJ_PER_LITER_K = 0.997 * 4.18;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('TileName', ''); // Bezeichnung = Titel der Kachel
        $this->RegisterPropertyBoolean('ShowDiagnostics', false); // Diagnosezeile in der Kachel (Fehlersuche Apps)
        $this->RegisterAttributeInteger('TileLoads', 0);
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
        $this->RegisterPropertyFloat('MeasuredCorrection', 0.0);
        $this->RegisterPropertyInteger('DayRetentionYears', 3); // Tageswerte so lange aufheben, danach nur Monatswerte // Korrektur des externen Zählers in % (Abgleich mit dem Netzbetreiber)
        // Heizkurve schreiben (Funktion 6): 30618 Fußpunkt, 30615 Auslegepunkt – für die Übernahme von Heizkurven-Vorschlägen
        $this->RegisterPropertyInteger('WriteCurveFoot', 0);
        $this->RegisterPropertyInteger('WriteCurveDesign', 0);
        // Symcon-Heizkurve: Hoval auf Konstantregelung (27510 = 3), Symcon gibt den Vorlauf-Soll vor (1490)
        $this->RegisterPropertyInteger('WriteStrategy', 0);
        $this->RegisterPropertyInteger('WriteFlowConst', 0);
        $this->RegisterPropertyInteger('WriteFlowMax', 0);      // 30690 max. Vorlauftemperatur HK1 (schreiben)
        // Push-Benachrichtigungen über eine Visualisierung (Kachel-Visualisierung oder WebFront)
        $this->RegisterPropertyInteger('NotifyVisuID', 0);
        $this->RegisterPropertyBoolean('NotifyError', true);
        $this->RegisterPropertyBoolean('NotifyMonitor', true);
        $this->RegisterPropertyBoolean('NotifyControl', true);
        $this->RegisterPropertyBoolean('NotifyAdvice', true);
        $this->RegisterPropertyFloat('PricePerKWh', 0.25);       // Strompreis (Wärmepumpentarif) in €/kWh
        $this->RegisterPropertyFloat('ReturnSetSpread', 5.0);    // Soll-Spreizung: RL-Soll = VL-Soll − Spreizung (Hoval hat keinen RL-Sollwert)
        $this->RegisterPropertyString('Refrigerant', 'R32');     // Kältemittel für Verdampfungs-/Kondensationstemperatur aus den Drücken

        // ---- Heizungssteuerung (schreibt in die Wärmepumpe, nur wenn aktiviert) ----
        $this->RegisterPropertyBoolean('ControlEnabled', false);
        $this->RegisterPropertyString('ControlPassword', '');    // für die Änderung der max. Warmwassertemperatur
        $this->RegisterPropertyInteger('WriteHC1Mode', 0);       // 1478 Betriebswahl Heizkreis 1 (schreiben)
        $this->RegisterPropertyInteger('WriteHC1Room', 0);       // 1481 Normal-Raumtemperatur Heizkreis 1 (schreiben)
        $this->RegisterPropertyInteger('WriteDHWMode', 0);       // 1496 Betriebswahl Warmwasser (schreiben)
        $this->RegisterPropertyInteger('WriteDHWTemp', 0);       // 1497 Normal-Warmwassertemperatur (schreiben)
        $this->RegisterPropertyInteger('SeasonMode', 1);         // 0 = manuell (Kachel), 1 = nach Außentemperatur (Ø 24 h)
        $this->RegisterPropertyFloat('SeasonWinterBelow', 10);   // Ø 24 h darunter: Winter
        $this->RegisterPropertyFloat('SeasonSummerAbove', 16);   // Ø 24 h darüber: Sommer (Heizung aus)
        $this->RegisterPropertyFloat('EcoWinter', 19);           // Absenktemperatur außerhalb der Komfortzeiten
        $this->RegisterPropertyFloat('EcoTransition', 18.5);
        $this->RegisterPropertyString('HeatPrograms', json_encode([
            ['Season' => 'winter', 'Days' => 'mo-fr', 'From' => '05:30', 'To' => '22:00', 'Temp' => 21.0],
            ['Season' => 'winter', 'Days' => 'sa-so', 'From' => '07:00', 'To' => '22:30', 'Temp' => 21.0],
            ['Season' => 'transition', 'Days' => 'mo-so', 'From' => '06:00', 'To' => '21:00', 'Temp' => 20.5]
        ]));
        $this->RegisterPropertyString('DHWPrograms', '[]');      // leer = Warmwasser bleibt bei Hoval (außer Aufheiz-Taste)

        // ---- Raumanforderung ----
        $this->RegisterPropertyBoolean('RoomDemandEnabled', false);
        $this->RegisterPropertyString('Rooms', '[]');            // [{Name, IstID, SollID, Offset}]
        $this->RegisterPropertyFloat('RoomHysteresis', 0.3);
        $this->RegisterPropertyInteger('RoomMinMinutes', 20);    // Mindestzeit zwischen zwei Wechseln (gegen Takten)

        // ---- Leistungsbegrenzung (15-min-Spitze) ----
        $this->RegisterPropertyBoolean('PowerLimitEnabled', false);
        $this->RegisterPropertyInteger('HousePowerID', 0);       // Leistung Haus (ohne WP oder gesamt)
        $this->RegisterPropertyInteger('HousePowerUnit', 0);     // 0 = W, 1 = kW
        $this->RegisterPropertyBoolean('HouseIncludesHP', false);// true: Hauszähler misst inkl. Wärmepumpe
        $this->RegisterPropertyFloat('MaxPeak15', 10);           // erlaubte 15-min-Spitze gesamt in kW
        $this->RegisterPropertyFloat('PowerMargin', 0.3);        // Sicherheitsabstand in kW
        $this->RegisterPropertyFloat('MinHPPower', 1.0);         // Mindestleistung, damit die WP ihre Minimalleistung halten kann
        $this->RegisterPropertyFloat('HPMaxPower', 4.0);         // max. el. Leistung der WP: darunter wird begrenzt
        $this->RegisterPropertyInteger('WriteSGPower', 0);       // 27536 Verfügbare el. Leistung gedämpft (schreiben)
        $this->RegisterPropertyInteger('WriteSGTrigger', 0);     // 27546 Auslöser Smart Grid Funktion (schreiben, 2 = Systembus)
        $this->RegisterPropertyInteger('WriteSGMode', 0);        // 27545 Smart Grid über Systembus (schreiben, 1 = Vorzugsbetrieb)

        // ---- PV-Überschuss (Hoval Anwendungsfall 8: Smart-Grid-Vorzugsbetrieb mit Offsets) ----
        $this->RegisterPropertyInteger('PVPowerID', 0);          // Netzleistung oder PV-Überschuss
        $this->RegisterPropertyInteger('PVSignMode', 0);         // 0 = Netzleistung (+ Bezug / − Einspeisung), 1 = Überschuss positiv
        $this->RegisterPropertyInteger('PVUnit', 0);             // 0 = W, 1 = kW
        $this->RegisterPropertyInteger('WriteSGOffsetRoom', 0);  // 27528 Smart Grid Offset Raum Soll Heizen HK1 (schreiben)
        $this->RegisterPropertyInteger('WriteSGOffsetDHW', 0);   // 27509 Smart Grid Offset Warmwasser (schreiben)
        $this->RegisterPropertyInteger('WriteSGOffsetBuffer', 0);// 28839 Smart Grid Offset Puffer Heizen (schreiben)

        // Einstellungen aus der Visualisierung (Aktivierung, Programme, Grenzwerte) – ersetzen die früheren Instanz-Einstellungen
        $this->RegisterAttributeString('Settings', '');
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
        // 15-Minuten-Leistung: laufende Viertelstunde, abgeschlossene Viertelstunden [[Beginn, kW, °C]] (31 Tage),
        // Tagesspitzen {Datum: [kW, Beginn]} (31 Tage)
        $this->RegisterAttributeInteger('QStart', 0);
        $this->RegisterAttributeFloat('QEnergy', 0);
        $this->RegisterAttributeFloat('QTempSum', 0);
        $this->RegisterAttributeInteger('QTempCount', 0);
        // Temperaturen je Viertelstunde (Summe, Anzahl) für das Leistungsdiagramm: Raum Ø Ist/Soll, Vorlauf Ist/Soll, Rücklauf
        $this->RegisterAttributeString('QTemps', '{}');
        $this->RegisterAttributeString('QHistory', '[]');
        $this->RegisterAttributeString('QPeaks', '{}');
        // aus der gemessenen Leistung aufsummierte Energie (ersetzt einen fehlenden kWh-Zählerstand des externen Zählers)
        $this->RegisterAttributeFloat('MeasEnergyInt', 0);
        // Steuerung: Bedienzustand, letzte Schreibvorgänge, max. Warmwassertemperatur, gleitende Außentemperatur
        $this->RegisterAttributeString('CtlState', '');
        $this->RegisterAttributeString('CtlWrites', '{}');
        $this->RegisterAttributeFloat('DHWMax', 55);
        $this->RegisterAttributeFloat('TempAvg', -999);
        // Leistungsbegrenzung: Hausenergie der laufenden Viertelstunde, letzter Messwert
        $this->RegisterAttributeFloat('QHouseEnergy', 0);
        $this->RegisterAttributeString('HouseState', '');
        // Kältekreis: Minutenwerte der letzten 6 Stunden für den Verlauf
        $this->RegisterAttributeString('RefHist', '[]');
        // Überwachung: letzter Gesamtstatus (ok | warn | alarm | learn | off) für die Anzeige am Reiter
        $this->RegisterAttributeString('MonState', 'learn');
        // zuletzt gewählte Ansicht der Kachel {v: Reiter, c: Steuerung-Unterseite, s: Auswertungsbereich, r: Leistungszeitraum} –
        // bleibt erhalten, wenn die App die Kachel neu lädt (dort gibt es keinen Browser-Speicher)
        $this->RegisterAttributeString('ViewState', '{}');
        $this->RegisterAttributeFloat('MeterFactorLast', 1.0);
        // Benachrichtigungen (zuletzt gesendet je Schlüssel), Einsparempfehlungen (erledigt/verworfen), letzter Fehlercode
        $this->RegisterAttributeString('NotifyLog', '{}');
        $this->RegisterAttributeString('AdviceLog', '{}');
        $this->RegisterAttributeInteger('LastErrorCode', 255);
        // EVU-/Energieerzeuger-Sperre: laufende Sperre und abgeschlossene Zeiträume [[Beginn, Ende]] (60 Tage)
        $this->RegisterAttributeString('LockState', '');
        $this->RegisterAttributeString('LockLog', '[]');
        // Monatswerte für Zeiträume, deren Tageswerte nicht mehr aufgehoben werden (Langzeitvergleich)
        $this->RegisterAttributeString('MonthlyHistory', '{}');
        // Heizkurve: gesicherte Hoval-Originalwerte, Lernwerte der Symcon-Heizkurve, gedämpfte Außentemperatur (3 h)
        $this->RegisterAttributeString('HovalOriginal', '');
        $this->RegisterAttributeString('CurveLearn', '');
        $this->RegisterAttributeFloat('OutAvg3h', -999);
        // Aufheizoptimierung: gelernte Aufheizgeschwindigkeit der Räume (K/h) je Außentemperatur, letzte Aufheizvorgänge
        $this->RegisterAttributeString('HeatRate', '');
        // Warmwasserladungen: laufende Ladung, aufsummierter WW-Strom, letzte Ladungen [{ts, min, t0, t1, dk, kwh, out, df}]
        $this->RegisterAttributeString('DhwCharge', '');
        $this->RegisterAttributeFloat('DhwElecTotal', 0);
        $this->RegisterAttributeString('DhwCharges', '[]');

        $this->RegisterTimer('Tick', 0, 'HWP_Tick($_IPS[\'TARGET\']);');
        // Kachel-Aktualisierung bei Variablenänderungen sammeln: höchstens alle 2 s eine Nachricht statt einer je Modbus-Wert
        $this->RegisterTimer('Push', 0, 'HWP_PushUpdate($_IPS[\'TARGET\']);');

        // Kachel über das HTML-SDK darstellen
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->UpdateMonitor(false);
        $this->RescaleMeter();

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
        $this->EnableLogging('DefrostActive'); // für Abtauungen im Kältekreis-Verlauf über längere Zeiträume

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

        // Steuerung / Raumanforderung / Leistungsbegrenzung / PV (Aktivierung erfolgt in der Visualisierung)
        $this->MaintainControlVariables();
        foreach (['HousePowerID', 'PVPowerID'] as $property) {
            $id = $this->ReadPropertyInteger($property);
            if ($id > 0 && IPS_VariableExists($id)) {
                $this->RegisterReference($id);
                $this->RegisterMessage($id, VM_UPDATE);
            }
        }

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
                // nur echte Änderungen verarbeiten – Modbus meldet jede Abfrage, auch wenn der Wert gleich bleibt
                if (is_array($Data) && array_key_exists(1, $Data) && !$Data[1]) {
                    break;
                }
                foreach (array_keys(self::DATAPOINTS) as $key) {
                    if ($SenderID === $this->GetVariableID($key)) {
                        $this->UpdateMirror($key);
                    }
                }
                // Strom bis jetzt der bisherigen Betriebsart zuordnen (Leistung/Ventil/Status können sich geändert haben)
                $this->IntegrateSplit(time());
                $this->IntegrateHouse(time());
                // Abtauung möglichst sofort erkennen (nicht erst beim nächsten Minutentakt)
                foreach (['WEZStatus', 'FlowTemp', 'ReturnTemp', 'FlowRate'] as $key) {
                    if ($SenderID === $this->GetVariableID($key)) {
                        $this->EvaluateDefrost(time());
                        break;
                    }
                }
                $this->UpdateCalculations();
                if ($this->GetTimerInterval('Push') === 0) {
                    $this->SetTimerInterval('Push', 5000);
                }
                break;
        }
    }

    /** Gesammelte Aktualisierung an die Kachel senden (Timer „Push“) */
    public function PushUpdate()
    {
        $this->SetTimerInterval('Push', 0);
        $this->SendTileUpdate();
    }

    /** Vollständige Nachricht an die Kachel – nur wenn sich gegenüber der letzten etwas geändert hat */
    private function SendTileUpdate(bool $force = false)
    {
        $message = $this->GetFullUpdateMessage();
        // Zeitstempel nicht vergleichen, sonst wäre jede Nachricht „neu“
        $hash = md5(preg_replace('/"now":\d+/', '', $message));
        if (!$force && $this->GetBuffer('LastPush') === $hash) {
            return;
        }
        $this->SetBuffer('LastPush', $hash);
        $this->UpdateVisualizationValue($message);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Stats': // Kachel fordert die Auswertung an: {"p": "day"|"week"|"month", "o": Seite (0 = aktuell)}
                $request = json_decode((string) $Value, true) ?: [];
                $period = in_array($request['p'] ?? '', ['day', 'week', 'month'], true) ? $request['p'] : 'day';
                $this->UpdateVisualizationValue(json_encode(['stats' => $this->GetStats($period, max(0, (int) ($request['o'] ?? 0)))]));
                break;
            case 'PowerDay': // Kachel fordert die 15-min-Werte eines Tages an: "YYYY-MM-DD"
                $this->UpdateVisualizationValue(json_encode(['powerDay' => $this->GetPowerDay((string) $Value)]));
                break;
            case 'Year': // Jahresvergleich
                $this->UpdateVisualizationValue(json_encode(['year' => $this->GetYearView()]));
                break;
            case 'Cost': // Kachel fordert die Kostenübersicht an
                $this->UpdateVisualizationValue(json_encode(['cost' => $this->GetCostView()]));
                break;
            case 'CostRange': // Zeitraum (und Vergleichszeitraum) tageweise: {"a": ["YYYY-MM-DD", "YYYY-MM-DD"], "b": [...] | null}
                $request = json_decode((string) $Value, true) ?: [];
                $this->UpdateVisualizationValue(json_encode(['costRange' => $this->GetCostRange($request['a'] ?? null, $request['b'] ?? null)], JSON_PARTIAL_OUTPUT_ON_ERROR));
                break;
            case 'Monitor': // Überwachung anfordern bzw. ein-/ausschalten: {"on": true|false} oder leer
                $request = json_decode((string) $Value, true) ?: [];
                if (isset($request['on'])) {
                    $this->SaveSettings(['monOn' => (bool) $request['on']]);
                    $this->UpdateMonitor(false);
                }
                $this->UpdateVisualizationValue(json_encode(['monitor' => $this->EvaluateMonitor()]));
                break;
            case 'View': // Kachel merkt sich die gewählte Ansicht (kein Neuzeichnen nötig)
                $state = json_decode((string) $Value, true);
                if (is_array($state)) {
                    $clean = array_map(fn ($v) => substr(preg_replace('/[^a-z]/', '', (string) $v), 0, 12), array_intersect_key($state, array_flip(['v', 'c', 's', 'r', 'h'])));
                    $this->WriteAttributeString('ViewState', json_encode($clean));
                }
                break;
            case 'ResetCmp': // Vergleichszeitraum (Energie, JAZ) aus der Kachel neu starten
                $this->ResetComparison();
                break;
            case 'Dhw': // Kachel fordert die Warmwasser-Auswertung an
                $this->UpdateVisualizationValue(json_encode(['dhw' => $this->GetDhwView()]));
                break;
            case 'Advice': // Einsparempfehlungen: {"a": "list"|"done"|"dismiss"|"reopen"|"applyCurve", "id": …, "pw": …}
                $request = json_decode((string) $Value, true) ?: [];
                $result = $this->HandleAdvice($request);
                if ($result) {
                    $this->UpdateVisualizationValue(json_encode(['ctlResult' => $result]));
                }
                $this->UpdateVisualizationValue(json_encode(['advice' => $this->GetAdvice()]));
                break;
            case 'Refri': // Kachel fordert den Kältekreis-Verlauf an: leer = letzte 6 h, sonst {"r": Stunden, "e": Ende (Unix-Zeit, 0 = jetzt)}
                $request = json_decode((string) $Value, true) ?: [];
                $hours = max(1, min(24 * 14, (int) ($request['r'] ?? 6)));
                $end = (int) ($request['e'] ?? 0);
                $this->UpdateVisualizationValue(json_encode($this->RefriHistory($hours, $end), JSON_PARTIAL_OUTPUT_ON_ERROR));
                break;
            case 'Ctl': // Bedienung aus dem Reiter "Steuerung"
                $result = $this->HandleControlAction(json_decode((string) $Value, true) ?: []);
                $this->ControlTick(time());
                $this->UpdateVisualizationValue(json_encode(['ctlResult' => $result]));
                $this->SendTileUpdate(true);
                break;
            default:
                throw new Exception('Invalid Ident: ' . $Ident);
        }
    }

    public function GetConfigurationForm()
    {
        $elements = [
            ['type' => 'ValidationTextBox', 'name' => 'TileName', 'caption' => 'Bezeichnung (Titel der Kachel, z.B. Wärmepumpe)'],
            ['type' => 'CheckBox', 'name' => 'ShowDiagnostics', 'caption' => 'Diagnosezeile in der Kachel anzeigen (Fehlersuche, z.B. Symcon-App)'],
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
                case 'Kältekreis (Gateway ab V2.38)':
                    $items[] = ['type' => 'Select', 'name' => 'Refrigerant', 'caption' => 'Kältemittel (Typenschild)', 'options' => [
                        ['caption' => 'R32', 'value' => 'R32'], ['caption' => 'R410A', 'value' => 'R410A'], ['caption' => 'R290 (Propan)', 'value' => 'R290']]];
                    $items[] = ['type' => 'Label', 'caption' => 'Alle Werte S32 (2 Register, Startadresse „high“), Funktion 3. Temperaturen und Drehzahlen Faktor 0,1; '
                        . 'Niederdruck, Hochdruck und Überhitzung Faktor 0,01 (laut Messwerten, abweichend von der Hoval-Liste).'];
                    break;
                case 'Heizkurve / Regelung (optional)':
                    array_unshift($items,
                        ['type' => 'Label', 'caption' => 'Nur LESEN – hier wird nichts verstellt. Verwendet für: Anzeige der Hoval-Heizkurve in der Kachel (Steuerung › Heizkurve),'],
                        ['type' => 'Label', 'caption' => 'Sicherung der Hoval-Originalwerte vor dem Umschalten auf die Symcon-Heizkurve, Heizkurven-Vorschläge (Tipps)'],
                        ['type' => 'Label', 'caption' => 'und Rückkontrolle, ob geschriebene Werte übernommen wurden. Zum Verstellen: unten „Heizkurve schreiben“ (dieselbe Variable ist möglich).']);
                    break;
                case 'Externer Zähler':
                    $items[] = ['type' => 'NumberSpinner', 'name' => 'PricePerKWh', 'caption' => 'Strompreis (Wärmepumpentarif)', 'suffix' => '€/kWh', 'digits' => 4, 'minimum' => 0];
                    $items[] = ['type' => 'NumberSpinner', 'name' => 'ReturnSetSpread', 'caption' => 'Soll-Spreizung für „RL-Soll“ im Leistungsdiagramm (RL-Soll = VL-Soll − Spreizung)', 'suffix' => 'K', 'digits' => 1, 'minimum' => 0];
                    $items[] = ['type' => 'Select', 'name' => 'MeasuredPowerUnit', 'caption' => 'Einheit Leistung', 'options' => [
                        ['caption' => 'W', 'value' => 0], ['caption' => 'kW', 'value' => 1]]];
                    $items[] = ['type' => 'Select', 'name' => 'MeasuredEnergyUnit', 'caption' => 'Einheit Energie', 'options' => [
                        ['caption' => 'Wh', 'value' => 0], ['caption' => 'kWh', 'value' => 1], ['caption' => 'MWh', 'value' => 2]]];
                    $items[] = ['type' => 'NumberSpinner', 'name' => 'DayRetentionYears', 'caption' => 'Tageswerte aufheben (danach nur Monatswerte, unbegrenzt)', 'suffix' => 'Jahre', 'minimum' => 1, 'maximum' => 10];
                    $items[] = ['type' => 'NumberSpinner', 'name' => 'MeasuredCorrection', 'caption' => 'Korrektur Zähler (z.B. +1,5 wenn der Netzbetreiber 1,5 % mehr misst)', 'suffix' => '%', 'digits' => 2];
                    break;
            }
            $elements[] = ['type' => 'ExpansionPanel', 'caption' => $group, 'items' => $items];
        }

        // ---- Steuerung: hier nur Variablen und Passwort; Aktivierung und Einstellungen in der Visualisierung ----
        $write = fn ($name, $caption) => ['type' => 'SelectVariable', 'name' => $name, 'caption' => $caption];
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => 'Steuerung – Passwort', 'items' => [
            ['type' => 'Label', 'caption' => 'Heizungssteuerung, Raumanforderung, Leistungsbegrenzung und PV-Überschuss werden in der Kachel (Reiter „Steuerung“) '
                . 'mit diesem Passwort aktiviert und eingestellt (Zeitprogramme, Saison, Grenzwerte, Räume). Hier werden nur die Variablen hinterlegt.'],
            ['type' => 'PasswordTextBox', 'name' => 'ControlPassword', 'caption' => 'Passwort für die Steuerung in der Kachel'],
            ['type' => 'Label', 'caption' => 'Ohne Passwort ist keine Aktivierung möglich. Bereits vorhandene Einstellungen aus älteren Versionen werden übernommen.']
        ]];
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => 'Steuerung – Schreib-Variablen Heizkreis / Warmwasser', 'items' => [
            ['type' => 'Label', 'caption' => 'Modbus-Geräte mit Funktion 6 (Write Single Register), Faktor 0,1 bei Temperaturen. '
                . 'Beim Deaktivieren der Heizungssteuerung werden Heizkreis und Warmwasser wieder auf Hoval „Woche 1“ gestellt.'],
            $write('WriteHC1Mode', 'Betriebswahl Heizkreis 1 schreiben (1478)'),
            $write('WriteHC1Room', 'Normal-Raumtemperatur Heizkreis 1 schreiben (1481)'),
            $write('WriteDHWMode', 'Betriebswahl Warmwasser schreiben (1496)'),
            $write('WriteDHWTemp', 'Normal-Warmwassertemperatur schreiben (1497)')
        ]];
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => 'Steuerung – Räume', 'items' => [
            ['type' => 'Label', 'caption' => 'Räume für die Raumanforderung und die Durchschnittstemperatur. Soll: eigene Soll-Variable, sonst Programmtemperatur (+ Offset). '
                . 'In der Kachel kann jeder Raum einzeln auf „nicht berücksichtigt“ geschaltet werden.'],
            ['type' => 'List', 'name' => 'Rooms', 'caption' => 'Räume', 'rowCount' => 8, 'add' => true, 'delete' => true, 'columns' => [
                ['caption' => 'Name', 'name' => 'Name', 'width' => '180px', 'add' => 'Raum', 'edit' => ['type' => 'ValidationTextBox']],
                ['caption' => 'Raumtemperatur Ist', 'name' => 'IstID', 'width' => '260px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                ['caption' => 'Soll (optional)', 'name' => 'SollID', 'width' => '260px', 'add' => 0, 'edit' => ['type' => 'SelectVariable']],
                ['caption' => 'Offset K', 'name' => 'Offset', 'width' => 'auto', 'add' => 0, 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]]]]
        ]];
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => 'Steuerung – Smart Grid (Leistungsbegrenzung / PV-Überschuss)', 'items' => [
            ['type' => 'Label', 'caption' => 'Ablauf laut Hoval: 27546 = 2 (Systembus), dann 27545 = 1 (Vorzugsbetrieb) → 27536 (Leistung) und die Offsets wirken; 27545 = 0 → Normalbetrieb.'],
            ['type' => 'Label', 'caption' => '⚠ Voraussetzung: Grundeinstellung Smart Grid durch den Hoval-Service.'],
            $write('WriteSGTrigger', 'Auslöser Smart Grid Funktion schreiben (27546)'),
            $write('WriteSGMode', 'Smart Grid über Systembus schreiben (27545)'),
            $write('WriteSGPower', 'Verfügbare el. Leistung gedämpft schreiben (27536, kW) – Leistungsbegrenzung'),
            $write('WriteSGOffsetRoom', 'Offset Raumtemperatur HK1 schreiben (27528) – PV'),
            $write('WriteSGOffsetDHW', 'Offset Warmwasser schreiben (27509) – PV'),
            $write('WriteSGOffsetBuffer', 'Offset Puffer schreiben (28839) – PV'),
            ['type' => 'SelectVariable', 'name' => 'HousePowerID', 'caption' => 'Leistung Haus (Leistungsbegrenzung)'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Select', 'name' => 'HousePowerUnit', 'caption' => 'Einheit', 'options' => [['caption' => 'W', 'value' => 0], ['caption' => 'kW', 'value' => 1]]],
                ['type' => 'CheckBox', 'name' => 'HouseIncludesHP', 'caption' => 'Hauszähler misst inkl. Wärmepumpe']]],
            ['type' => 'SelectVariable', 'name' => 'PVPowerID', 'caption' => 'Netz- bzw. Überschussleistung (PV-Überschuss)'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'Select', 'name' => 'PVSignMode', 'caption' => 'Vorzeichen', 'options' => [
                    ['caption' => 'Netzzähler: + Bezug / − Einspeisung', 'value' => 0], ['caption' => 'Überschuss positiv', 'value' => 1]]],
                ['type' => 'Select', 'name' => 'PVUnit', 'caption' => 'Einheit', 'options' => [['caption' => 'W', 'value' => 0], ['caption' => 'kW', 'value' => 1]]]]]
        ]];

        // Offsets für alle Temperaturfühler
        $offsets = [['type' => 'Label', 'caption' => 'Korrektur in Kelvin, wird zum Messwert addiert (z.B. -0,5 wenn der Fühler 0,5 K zu hoch misst).']];
        foreach (self::DATAPOINTS as $key => [$caption, $register, $kind]) {
            if ($kind === 'temp') {
                $offsets[] = ['type' => 'NumberSpinner', 'name' => 'Offset' . $key, 'caption' => $caption, 'digits' => 1, 'suffix' => 'K'];
            }
        }
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => 'Fühler-Offsets', 'items' => $offsets];
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => 'Push-Benachrichtigungen', 'items' => [
            ['type' => 'Label', 'caption' => 'Benachrichtigungen aufs Handy über eine Visualisierung (Kachel-Visualisierung oder WebFront). Die App muss Benachrichtigungen erlauben.'],
            ['type' => 'SelectInstance', 'name' => 'NotifyVisuID', 'caption' => 'Visualisierung'],
            ['type' => 'CheckBox', 'name' => 'NotifyError', 'caption' => 'Störung der Wärmepumpe (und wenn sie behoben ist)'],
            ['type' => 'CheckBox', 'name' => 'NotifyMonitor', 'caption' => 'Überwachung: Auffälligkeiten (z.B. Effizienz, Kältekreis, Warmwasser)'],
            ['type' => 'CheckBox', 'name' => 'NotifyControl', 'caption' => 'Steuerung: Vorgabe wird nicht übernommen, Urlaubsmodus beendet'],
            ['type' => 'CheckBox', 'name' => 'NotifyAdvice', 'caption' => 'Einsparempfehlungen: Ergebnis nach einer umgesetzten Empfehlung'],
            ['type' => 'Button', 'caption' => 'Testnachricht senden', 'onClick' => 'HWP_TestNotification($id);']
        ]];
        $elements[] = ['type' => 'ExpansionPanel', 'caption' => 'Heizkurve schreiben / Symcon-Heizkurve (optional)', 'items' => [
            ['type' => 'Label', 'caption' => 'Schreib-Variablen = Modbus-Geräte mit Funktion 6 (Write Single Register). Temperaturen: S16, Faktor 0,1; Regelstrategie: U8, Faktor 1.'],
            ['type' => 'Label', 'caption' => 'Dieselben Register können oben unter „Heizkurve / Regelung (optional)“ zusätzlich zum Lesen eingetragen werden (Rückkontrolle).'],
            ['type' => 'Label', 'caption' => '▸ Heizkurven-Vorschläge per Knopfdruck übernehmen:'],
            ['type' => 'SelectVariable', 'name' => 'WriteCurveFoot', 'caption' => '30618 – Fußpunkt VL-Temperatur Heizkennlinie (schreiben)'],
            ['type' => 'SelectVariable', 'name' => 'WriteCurveDesign', 'caption' => '30615 – Auslegepunkt VL-Temperatur Heizkennlinie (schreiben)'],
            ['type' => 'Label', 'caption' => '▸ Selbstlernende Symcon-Heizkurve (Umschalter in der Kachel): Hoval auf Konstantregelung, Symcon gibt den Vorlauf-Soll vor:'],
            ['type' => 'SelectVariable', 'name' => 'WriteStrategy', 'caption' => '27510 – Regelstrategie HK1 (schreiben, U8)'],
            ['type' => 'SelectVariable', 'name' => 'WriteFlowConst', 'caption' => '1490 – Vorlauf-Soll Konstantanforderung Heizen HK1 (schreiben)'],
            ['type' => 'Label', 'caption' => '▸ Maximaltemperatur Heizkreis (Einstellung in der Kachel unter Steuerung › Heizung):'],
            ['type' => 'SelectVariable', 'name' => 'WriteFlowMax', 'caption' => '30690 – Max. Vorlauftemperatur HK1 (schreiben)']
        ]];

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
            'elements' => self::WidenSelects($elements),
            'actions'  => [
                ['type' => 'Label', 'caption' => 'Vergleichszeitraum für Energie und JAZ seit: ' . $since],
                ['type' => 'Button', 'caption' => 'Speicherbedarf der Aufzeichnungen berechnen', 'onClick' => 'HWP_StorageInfo($id);'],
                ['type' => 'Button', 'caption' => 'Vergleichszeitraum neu starten', 'onClick' => 'HWP_ResetComparison($id);',
                 'confirm' => 'Energie- und JAZ-Vergleich ab jetzt neu beginnen?']
            ]
        ]);
    }

    /** Auswahlfelder breiter machen, damit die langen Bezeichnungen (mit Registeradresse) vollständig lesbar sind */
    private static function WidenSelects(array $elements): array
    {
        foreach ($elements as &$e) {
            if (isset($e['items']) && is_array($e['items'])) {
                $e['items'] = self::WidenSelects($e['items']);
            }
            if (in_array($e['type'] ?? '', ['SelectVariable', 'Select', 'ValidationTextBox', 'PasswordTextBox'], true) && !isset($e['width'])) {
                $e['width'] = '720px';
            }
        }
        return $elements;
    }

    public function GetVisualizationTile()
    {
        // Startwerte direkt beim Laden der Kachel übergeben
        // Ladezähler: zeigt in der Diagnosezeile, ob eine App die Kachel bei Bedienung neu lädt
        $loads = $this->ReadAttributeInteger('TileLoads') + 1;
        $this->WriteAttributeInteger('TileLoads', $loads);
        $initialHandling = '<script>window.HWP_LOAD = ' . $loads . '; window.HWP_DIAG = ' . ($this->ReadPropertyBoolean('ShowDiagnostics') ? 'true' : 'false') . ';'
            . 'handleMessage(' . json_encode($this->GetFullUpdateMessage()) . ');</script>';
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
        $this->IntegrateHouse($now);
        $this->UpdateQuarter($now);
        $this->ControlTick($now);
        $this->RecordRefrigerant($now);
        $this->TrackDhwCharge($now);
        $this->TrackLock($now);
        $this->CheckAlerts();
        $this->UpdateDayStats($now);
        foreach (array_keys(self::DATAPOINTS) as $key) {
            $this->UpdateMirror($key);
        }
        $this->UpdateCalculations();
        $this->SendTileUpdate();
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
        $this->SendTileUpdate(true);
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
            // 18722 = 8 "Abtauvorgang" bzw. Status (WFA, 18723) = 8 "Abtauen"
            $active = (int) round($wez) === 8 || (int) round($this->Value('WFAStatus') ?? -1) === 8;
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
    /** Gemessene Energie in kWh: Zählerstand des externen Zählers, sonst die aufsummierte gemessene Leistung */
    private function MeasuredEnergyKWh(): ?float
    {
        $meter = $this->EnergyKWh('MeasuredEnergy');
        if ($meter !== null) {
            return $meter;
        }
        return $this->GetVariableID('MeasuredPower') > 0 ? $this->ReadAttributeFloat('MeasEnergyInt') : null;
    }

    /** Quelle der Tagesbilanz Strom: gemessen (Zähler/Leistung), sonst Hoval */
    private function DayElecSource(): string
    {
        return $this->MeasuredEnergyKWh() !== null ? 'meas' : 'hoval';
    }

    private function DayElecCounter(): ?float
    {
        return $this->MeasuredEnergyKWh() ?? $this->EnergyKWh('ElecEnergy');
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
            'dfe'  => round($this->GetValue('DefrostElecToday'), 2),
            // Betrieb: Laufzeit Verdichter (min), Starts, Ø Kältekreiswerte bei eingeschwungenem Betrieb (Überwachung)
            'n'    => $day['tCount'],                       // Minuten mit Daten (vollständiger Tag ≈ 1440)
            'trk'  => isset($day['cycStart']) ? !empty($day['cycFull']) : !empty($day['trk']), // Starts ab Tagesbeginn erfasst
            'run'  => $day['runMin'] ?? null,
            'st'   => $this->DayStarts($day),
            'lk'   => $day['lockMin'] ?? 0,                   // Minuten EVU-/Energieerzeuger-Sperre
            'dev'  => isset($day['dev']) && $day['dev'][1] >= 60 ? round($day['dev'][0] / $day['dev'][1], 2) : null,
            'rdev' => array_map(fn ($v) => round($v[0] / $v[1], 2), array_filter($day['rdev'] ?? [], fn ($v) => $v[1] >= 60))
        ] + $this->DayRefAverages($day);
    }

    /** Starts und Laufzeit heute (für die Kältekreis-Ansicht) */
    private function TodayRun(): array
    {
        $day = json_decode($this->ReadAttributeString('DayStats'), true) ?: [];
        $starts = $this->DayStarts($day);
        $run = $day['runMin'] ?? null;
        return ['starts' => $starts, 'run' => $run, 'perStart' => ($starts && $run !== null) ? round($run / $starts) : null];
    }

    /** Verdichterstarts des Tages: Hoval-Zähler Schaltzyklen, sonst selbst gezählte Starts */
    private function DayStarts(array $day): ?int
    {
        $cycles = $this->Value('Cycles');
        if (isset($day['cycStart']) && $cycles !== null && $cycles >= $day['cycStart']) {
            return (int) round($cycles - $day['cycStart']);
        }
        return isset($day['starts']) ? (int) $day['starts'] : null;
    }

    private function DayRefAverages(array $day): array
    {
        $result = [];
        foreach (['sh', 'air', 'hot', 'ratio'] as $key) {
            $sum = $day['ref'][$key] ?? null;
            $result[$key] = ($sum && $sum[1] >= 30) ? round($sum[0] / $sum[1], 2) : null;
        }
        return $result;
    }

    /** Jede Minute: Verdichterlaufzeit, Starts und Kältekreiswerte des Tages sammeln */
    private function AccumulateRun(array &$day, int $now)
    {
        $ref = $this->GetRefrigerantView();
        $running = $ref !== null && $ref['v']['CompressorSpeed'] !== null ? $ref['running'] : $this->IsRunning();
        $defrost = @$this->GetIDForIdent('DefrostActive') && $this->GetValue('DefrostActive');
        if ($running && empty($day['wasRun'])) {
            $day['starts'] = ($day['starts'] ?? 0) + 1;
            $day['runSince'] = $now;
        }
        if ($running) {
            $day['runMin'] = ($day['runMin'] ?? 0) + 1;
            // erst nach 10 min Laufzeit und nicht beim Abtauen: Werte sind dann eingeschwungen
            if ($ref !== null && !$defrost && $now - ($day['runSince'] ?? $now) >= 600) {
                foreach (['sh' => $ref['sh'], 'air' => $ref['airDelta'], 'hot' => $ref['v']['HotGas'], 'ratio' => $ref['ratio']] as $key => $value) {
                    if ($value !== null) {
                        $sum = $day['ref'][$key] ?? [0, 0];
                        $day['ref'][$key] = [$sum[0] + $value, $sum[1] + 1];
                    }
                }
            }
        }
        $day['wasRun'] = $running;
        // Raumabweichungen (Ist − Soll) je Raum und gesamt – Grundlage der Einsparempfehlungen
        $ctlState = $this->GetCtlState();
        foreach ($ctlState['roomList'] ?? [] as $r) {
            if (($r['active'] ?? true) && $r['soll'] !== null && $r['ist'] !== null) {
                $sum = $day['rdev'][$r['name']] ?? [0, 0];
                $day['rdev'][$r['name']] = [$sum[0] + $r['ist'] - $r['soll'], $sum[1] + 1];
            }
        }
        $rs = $ctlState['roomStats'] ?? null;
        if ($rs && $rs['avgIst'] !== null && $rs['avgSoll'] !== null) {
            $day['dev'] = [($day['dev'][0] ?? 0) + $rs['avgIst'] - $rs['avgSoll'], ($day['dev'][1] ?? 0) + 1];
        }
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
            $this->WriteAttributeString('DailyHistory', json_encode($this->RollupOldDays($history)));
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
            if (@$this->GetIDForIdent('Total15PeakToday')) {
                $this->SetValue('Total15PeakToday', 0);
            }
            foreach (['DefrostCountToday', 'DefrostElecToday', 'DefrostHeatToday'] as $ident) {
                $this->SetValue($ident, 0);
            }
            $carry = ['wasRun' => $day['wasRun'] ?? false, 'runSince' => $day['runSince'] ?? $now];
            $day = null;
            $this->UpdateMonitor(true);
            $this->GetAdvice(); // Nachverfolgung umgesetzter Empfehlungen (benachrichtigt bei Ergebnis)
        }
        if (!$day) {
            $day = ['date' => $today, 'tSum' => 0, 'tCount' => 0, 'tMin' => null, 'tMax' => null, 'humSum' => 0, 'humCount' => 0,
                    'eDhw' => 0, 'eInt' => 0, 'elecStart' => $this->DayElecCounter(), 'heatStart' => $this->DayHeatCounter(),
                    'runMin' => 0, 'starts' => 0, 'trk' => true] + ($carry ?? []);
        }
        // Zähler, die erst später verfügbar werden, nachtragen
        $day['elecStart'] = $day['elecStart'] ?? $this->DayElecCounter();
        // Quelle gewechselt (z.B. Update auf gemessene Werte): Startstand so setzen, dass der bisherige Tagesverbrauch
        // (aufsummierte Leistung) erhalten bleibt
        if (($day['elecSrc'] ?? '') !== $this->DayElecSource()) {
            $counter = $this->DayElecCounter();
            $day['elecStart'] = $counter === null ? null : $counter - ($day['eInt'] ?? 0);
            $day['elecSrc'] = $this->DayElecSource();
        }
        $day['heatStart'] = $day['heatStart'] ?? $this->DayHeatCounter();
        // Verdichterstarts aus dem Hoval-Zähler Schaltzyklen (1518), sobald eingetragen
        $cycles = $this->Value('Cycles');
        if ($cycles !== null && !isset($day['cycStart'])) {
            $day['cycStart'] = $cycles;
            $day['cycFull'] = ($day['tCount'] ?? 0) === 0; // ab Tagesbeginn gezählt?
        }

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
        $this->AccumulateRun($day, $now);
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
            if (!empty($state['meas'])) {
                $this->WriteAttributeFloat('MeasEnergyInt', $this->ReadAttributeFloat('MeasEnergyInt') + $state['p'] * $dt / 3600);
            }
        }
        if ($day && $dt > 0 && $state['p'] > 0) {
            $kWh = $state['p'] * $dt / 3600;
            $day['eInt'] = ($day['eInt'] ?? 0) + $kWh;
            if ($state['mode'] === 'ww') {
                $day['eDhw'] = ($day['eDhw'] ?? 0) + $kWh;
                $this->WriteAttributeFloat('DhwElecTotal', $this->ReadAttributeFloat('DhwElecTotal') + $kWh);
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
            'last' => $now, 'p' => max(0, $this->MeasuredPowerKW() ?? $this->Value('ElecPower') ?? 0), 'mode' => $mode,
            'meas' => $this->MeasuredPowerKW() !== null
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
                $history[] = [$start, $kW, $temperature, $this->QuarterTemps()];
                $history = array_values(array_filter($history, fn ($q) => $q[0] >= $boundary - 32 * 86400)); // 31 Tage für die Tagesauswahl
                $this->WriteAttributeString('QHistory', json_encode($history));
                $this->SetValue('Power15', $kW);
                if ($this->Setting('limitOn') && @$this->GetIDForIdent('Total15')) {
                    $total = round(($this->ReadAttributeFloat('QEnergy') + $this->ReadAttributeFloat('QHouseEnergy')) / ($duration / 3600), 3);
                    $this->SetValue('Total15', $total);
                    if (date('Y-m-d', $start) === date('Y-m-d', $now) && $total > $this->GetValue('Total15PeakToday')) {
                        $this->SetValue('Total15PeakToday', $total);
                    }
                }

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
            $this->WriteAttributeFloat('QHouseEnergy', 0);
            $this->WriteAttributeFloat('QTempSum', 0);
            $this->WriteAttributeInteger('QTempCount', 0);
            $this->WriteAttributeString('QTemps', '{}');
        }
        // Temperaturen der laufenden Viertelstunde sammeln: [Raum Ø Ist, Raum Ø Soll, Vorlauf Ist, Vorlauf Soll, Rücklauf]
        $rs = $this->GetCtlState()['roomStats'] ?? [];
        $acc = json_decode($this->ReadAttributeString('QTemps'), true) ?: [];
        foreach ([$rs['avgIst'] ?? null, $rs['avgSoll'] ?? null, $this->Value('FlowTemp'), $this->Value('WEZSetpoint'), $this->Value('ReturnTemp'),
                  $this->Value('StorageTop'), $this->Value('StorageBottom'), $this->Value('DHWSetpoint')] as $i => $v) {
            if ($v !== null) {
                $acc[$i] = [($acc[$i][0] ?? 0) + $v, ($acc[$i][1] ?? 0) + 1];
            }
        }
        $this->WriteAttributeString('QTemps', json_encode($acc));
        $temperature = $this->Value('OutdoorTemp');
        if ($temperature !== null) {
            $this->WriteAttributeFloat('QTempSum', $this->ReadAttributeFloat('QTempSum') + $temperature);
            $this->WriteAttributeInteger('QTempCount', $this->ReadAttributeInteger('QTempCount') + 1);
        }
    }

    /** Außentemperatur heute: Tiefst-, Höchst- und Mittelwert */
    private function TodayOutdoor(): ?array
    {
        $day = json_decode($this->ReadAttributeString('DayStats'), true);
        if (!$day || ($day['tCount'] ?? 0) === 0) {
            return null;
        }
        return ['min' => round($day['tMin'], 1), 'max' => round($day['tMax'], 1), 'avg' => round($day['tSum'] / $day['tCount'], 1)];
    }

    /** Tageswerte eines Datums (abgeschlossener Tag oder heute) */
    private function DayRecord(string $date): ?array
    {
        foreach (array_reverse($this->AllDays()) as $d) {
            if ($d['d'] === $date) {
                return $d;
            }
        }
        return null;
    }

    /** Mittelwerte der Temperaturen der abgelaufenen Viertelstunde [Raum Ist, Raum Soll, VL Ist, VL Soll, RL Ist] (null = keine Werte) */
    private function QuarterTemps(): ?array
    {
        $acc = json_decode($this->ReadAttributeString('QTemps'), true) ?: [];
        $result = [];
        for ($i = 0; $i < 8; $i++) {
            $result[] = isset($acc[$i]) && $acc[$i][1] > 0 ? round($acc[$i][0] / $acc[$i][1], 1) : null;
        }
        return array_filter($result, fn ($v) => $v !== null) ? $result : null;
    }

    /** Mittelwert der Zusatztemperaturen über mehrere Viertelstunden */
    private static function MeanTemps(array $quarters): ?array
    {
        $sum = array_fill(0, 8, 0.0);
        $n = array_fill(0, 8, 0);
        foreach ($quarters as $q) {
            foreach ($q[3] ?? [] as $i => $v) {
                if ($v !== null) {
                    $sum[$i] += $v;
                    $n[$i]++;
                }
            }
        }
        $result = [];
        for ($i = 0; $i < 8; $i++) {
            $result[] = $n[$i] > 0 ? round($sum[$i] / $n[$i], 1) : null;
        }
        return array_filter($result, fn ($v) => $v !== null) ? $result : null;
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
            'measured' => $this->MeasuredPowerKW() !== null,
            'avg15'   => count($history) > 0 ? end($history)[1] : null,
            'temp'    => $this->Value('OutdoorTemp'),
            'energy'  => $energyToday,
            'cost'    => $energyToday === null ? null : round($energyToday * $this->ReadPropertyFloat('PricePerKWh'), 2),
            'peak'    => $peaks[date('Y-m-d', $now)] ?? null,
            'series'  => array_values(array_filter($history, fn ($q) => $q[0] >= $now - 86400)),
            'oldest'  => count($history) > 0 ? $history[0][0] : $now,
            'peaks'   => $peakList,
            'week'    => $this->GetPowerWeek($history, $now),
            'month'   => $this->GetPowerMonth($peaks, $now, $history),
            'rlSpread' => $this->ReadPropertyFloat('ReturnSetSpread'),
            'locks'    => $this->LocksBetween($now - 7 * 86400, $now)
        ];
    }

    /** Woche: Stundenmittel der letzten 7 Tage aus den 15-min-Werten [[Stundenbeginn, Ø kW, max kW, °C]] */
    private function GetPowerWeek(array $history, int $now): array
    {
        $hours = [];
        foreach ($history as $q) {
            [$start, $kW, $temp] = $q;
            if ($start < $now - 7 * 86400) {
                continue;
            }
            $h = intdiv($start, 3600) * 3600;
            $hours[$h]['q'][] = $q;
            $hours[$h]['kw'][] = $kW;
            if ($temp !== null) {
                $hours[$h]['t'][] = $temp;
            }
        }
        ksort($hours);
        $result = [];
        foreach ($hours as $h => $v) {
            $result[] = [$h, round(array_sum($v['kw']) / count($v['kw']), 3), max($v['kw']),
                         isset($v['t']) ? round(array_sum($v['t']) / count($v['t']), 1) : null, self::MeanTemps($v['q'])];
        }
        return $result;
    }

    /** Monat: je Tag (31 Tage) [Datum, Ø kW (Tagesenergie ÷ Stunden), 15-min-Spitze kW, Zeitpunkt Spitze, Ø °C] */
    private function GetPowerMonth(array $peaks, int $now, array $history = []): array
    {
        $byDate = [];
        foreach ($history as $q) {
            $byDate[date('Y-m-d', $q[0])][] = $q;
        }
        $days = [];
        foreach (json_decode($this->ReadAttributeString('DailyHistory'), true) ?: [] as $d) {
            $days[$d['d']] = $d;
        }
        $current = json_decode($this->ReadAttributeString('DayStats'), true);
        if ($current) {
            $days[$current['date']] = $this->DaySummary($current) + ['p' => true];
        }
        $result = [];
        for ($i = 30; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days", $now));
            $d = $days[$date] ?? null;
            $hours = ($d && !empty($d['p'])) ? max(1, ($now - strtotime($date)) / 3600) : 24;
            $result[] = [$date, ($d && $d['e'] !== null) ? round($d['e'] / $hours, 3) : null,
                         $peaks[$date][0] ?? null, $peaks[$date][1] ?? null, $d['t'] ?? null,
                         isset($byDate[$date]) ? self::MeanTemps($byDate[$date]) : null];
        }
        return $result;
    }


    // ---------------------------------------------------------------- Leistung: einzelner Tag

    /** 15-min-Werte eines Kalendertags (für die Tagesauswahl in der Ansicht "Leistung") */
    private function GetPowerDay(string $date): array
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $start = strtotime($date . ' 00:00');
        $end = strtotime($date . ' 00:00 +1 day');
        $series = array_values(array_filter(json_decode($this->ReadAttributeString('QHistory'), true) ?: [],
            fn ($q) => $q[0] >= $start && $q[0] < $end));
        $peaks = json_decode($this->ReadAttributeString('QPeaks'), true) ?: [];
        $energy = null;
        foreach ($series as $q) {
            $energy = ($energy ?? 0) + $q[1] * 0.25;
        }
        $temps = array_filter(array_column($series, 2), fn ($t) => $t !== null);
        $rec = $this->DayRecord($date);
        return [
            'date'   => $date,
            'start'  => $start,
            'end'    => $end,
            // Tageswerte für die Kennzahlen oben: Energie, Kosten, Außen Ø/min/max, Abtauungen, Starts, Laufzeit
            'day'    => $rec === null ? null : [
                'e' => $rec['e'] ?? null, 'c' => ($rec['e'] ?? null) === null ? null : round($rec['e'] * $this->ReadPropertyFloat('PricePerKWh'), 2),
                't' => $rec['t'] ?? null, 'tmin' => $rec['tmin'] ?? null, 'tmax' => $rec['tmax'] ?? null, 'df' => $rec['df'] ?? null,
                'st' => !empty($rec['trk']) ? ($rec['st'] ?? null) : null, 'run' => $rec['run'] ?? null, 'h' => $rec['h'] ?? null],
            'series' => $series,
            'locks'  => $this->LocksBetween($start, $end),
            'peak'   => $peaks[$date] ?? null,
            'energy' => $energy === null ? null : round($energy, 2),
            'cost'   => $energy === null ? null : round($energy * $this->ReadPropertyFloat('PricePerKWh'), 2),
            'temp'   => count($temps) ? round(array_sum($temps) / count($temps), 1) : null
        ];
    }

    // ---------------------------------------------------------------- Kosten

    /** Alle Tage inkl. des laufenden (p = laufend) */
    private function AllDays(): array
    {
        $days = json_decode($this->ReadAttributeString('DailyHistory'), true) ?: [];
        $current = json_decode($this->ReadAttributeString('DayStats'), true);
        if ($current) {
            $days[] = $this->DaySummary($current) + ['p' => true];
        }
        return $days;
    }

    /** Kostenübersicht: laufende Zeiträume und Kosten je Tag nach Ø Außentemperatur (Erfahrungswerte) */
    private function GetCostView(): array
    {
        $price = $this->ReadPropertyFloat('PricePerKWh');
        $now = time();
        $days = $this->AllDays();
        $sum = function (callable $filter) use ($days, $price) {
            $e = 0.0; $n = 0; $tSum = 0.0; $tN = 0; $h = 0.0;
            foreach ($days as $d) {
                if (!$filter($d) || ($d['e'] ?? null) === null) {
                    continue;
                }
                $e += $d['e']; $n++; $h += $d['h'] ?? 0;
                if (($d['t'] ?? null) !== null) { $tSum += $d['t']; $tN++; }
            }
            return ['e' => round($e, 1), 'c' => round($e * $price, 2), 'days' => $n, 't' => $tN ? round($tSum / $tN, 1) : null];
        };
        $today = date('Y-m-d', $now);
        $weekStart = date('Y-m-d', strtotime('monday this week', $now));
        $monthStart = date('Y-m-01', $now);
        $yearAgo = date('Y-m-d', strtotime('-365 days', $now));
        $periods = [
            ['Heute', $sum(fn ($d) => $d['d'] === $today)],
            ['Gestern', $sum(fn ($d) => $d['d'] === date('Y-m-d', strtotime('-1 day', $now)))],
            ['Diese Woche', $sum(fn ($d) => $d['d'] >= $weekStart)],
            ['Dieser Monat', $sum(fn ($d) => $d['d'] >= $monthStart)],
            ['Letzter Monat', $sum(fn ($d) => $d['d'] >= date('Y-m-01', strtotime('first day of last month', $now)) && $d['d'] < $monthStart)],
            ['Letzte 365 Tage', $sum(fn ($d) => $d['d'] > $yearAgo)]
        ];

        // Kosten je Tag nach Ø Außentemperatur in 2-K-Schritten (nur vollständige Tage)
        $bins = [];
        foreach ($days as $d) {
            if (!empty($d['p']) || ($d['e'] ?? null) === null || ($d['t'] ?? null) === null || ($d['n'] ?? 1440) < 1200) {
                continue;
            }
            $key = (int) (floor(max(-20, min(26, $d['t'])) / 2) * 2);
            $b = &$bins[$key];
            $b['n'] = ($b['n'] ?? 0) + 1;
            foreach (['e', 'eh', 'ew', 'ed', 'h'] as $field) {
                if (($d[$field] ?? null) !== null) {
                    $b[$field] = ($b[$field] ?? 0) + $d[$field];
                    $b[$field . 'N'] = ($b[$field . 'N'] ?? 0) + 1;
                }
            }
            unset($b);
        }
        ksort($bins);
        $rows = [];
        foreach ($bins as $t => $b) {
            $avg = fn ($f) => isset($b[$f . 'N']) ? $b[$f] / $b[$f . 'N'] : null;
            $e = $avg('e');
            $rows[] = [
                'from' => $t, 'to' => $t + 2, 'n' => $b['n'],
                'e'  => round($e, 2), 'c' => round($e * $price, 2), 'month' => round($e * $price * 30.4, 0),
                'eh' => $avg('eh') === null ? null : round($avg('eh'), 2), 'ew' => $avg('ew') === null ? null : round($avg('ew'), 2),
                'az' => ($avg('h') !== null && $e > 0.5) ? round($avg('h') / $e, 2) : null
            ];
        }
        return ['price' => $price, 'periods' => $periods, 'bins' => $rows];
    }

    /**
     * Kosten tageweise für einen frei wählbaren Zeitraum und optional einen Vergleichszeitraum: je Tag Ø Außentemperatur,
     * Strom (Heizen/Warmwasser), Kosten; Summen mit Heizgradtagen (Σ max(0, 20 − Ø außen)) für einen witterungsbereinigten Vergleich.
     */
    private function GetCostRange($a, $b): array
    {
        $price = $this->ReadPropertyFloat('PricePerKWh');
        $days = [];
        foreach ($this->AllDays() as $d) {
            $days[$d['d']] = $d;
        }
        $valid = fn ($r) => is_array($r) && count($r) === 2 && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r[0]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $r[1]);
        $period = function ($range) use ($days, $price, $valid) {
            if (!$valid($range)) {
                return null;
            }
            [$from, $to] = $range[0] <= $range[1] ? $range : [$range[1], $range[0]];
            $rows = [];
            $sum = ['e' => 0.0, 'eh' => 0.0, 'ew' => 0.0, 'h' => 0.0, 'hN' => 0, 'c' => 0.0, 'tSum' => 0.0, 'tN' => 0, 'hdd' => 0.0, 'n' => 0, 'missing' => 0, 'lk' => 0];
            $ts = strtotime($from . ' 12:00');
            $end = strtotime($to . ' 12:00');
            for ($i = 0; $ts <= $end && $i < 400; $ts = strtotime('+1 day', $ts), $i++) {
                $key = date('Y-m-d', $ts);
                $d = $days[$key] ?? null;
                if ($d === null || ($d['e'] ?? null) === null) {
                    $rows[] = ['d' => $key, 'e' => null];
                    $sum['missing']++;
                    continue;
                }
                $c = round($d['e'] * $price, 2);
                $rows[] = ['d' => $key, 't' => $d['t'] ?? null, 'tmin' => $d['tmin'] ?? null, 'tmax' => $d['tmax'] ?? null, 'e' => $d['e'],
                           'eh' => $d['eh'] ?? null, 'ew' => $d['ew'] ?? null, 'h' => $d['h'] ?? null, 'c' => $c, 'lk' => $d['lk'] ?? 0, 'p' => !empty($d['p'])];
                $sum['n']++;
                $sum['e'] += $d['e'];
                $sum['eh'] += $d['eh'] ?? 0;
                $sum['ew'] += $d['ew'] ?? 0;
                $sum['c'] += $c;
                $sum['lk'] += $d['lk'] ?? 0;
                if (($d['h'] ?? null) !== null) {
                    $sum['h'] += $d['h'];
                    $sum['hN']++;
                }
                if (($d['t'] ?? null) !== null) {
                    $sum['tSum'] += $d['t'];
                    $sum['tN']++;
                    $sum['hdd'] += max(0, 20 - $d['t']);
                }
            }
            return ['from' => $from, 'to' => $to, 'days' => $rows, 'sum' => [
                'n' => $sum['n'], 'missing' => $sum['missing'], 'e' => round($sum['e'], 1), 'eh' => round($sum['eh'], 1), 'ew' => round($sum['ew'], 1),
                'c' => round($sum['c'], 2), 'cDay' => $sum['n'] ? round($sum['c'] / $sum['n'], 2) : null, 'eDay' => $sum['n'] ? round($sum['e'] / $sum['n'], 2) : null,
                't' => $sum['tN'] ? round($sum['tSum'] / $sum['tN'], 1) : null, 'hdd' => round($sum['hdd'], 1),
                'ehHdd' => $sum['hdd'] >= 5 ? round($sum['eh'] / $sum['hdd'], 3) : null,
                'az' => ($sum['hN'] === $sum['n'] && $sum['e'] > 1) ? round($sum['h'] / $sum['e'], 2) : null, 'lk' => $sum['lk']
            ]];
        };
        $first = count($days) ? min(array_keys($days)) : null;
        return ['price' => $price, 'first' => $first, 'a' => $period($a), 'b' => $period($b)];
    }

    // ---------------------------------------------------------------- Überwachung (Erfahrungswerte)

    /**
     * Kennzahlen der Überwachung: [Schlüssel, Bezeichnung, Einheit, Stellen, abhängig von Außentemp., Richtung,
     * Warnschwelle, Alarmschwelle, Art der Schwelle (rel = %, abs = Einheit, cnt = Faktor/Zuschlag), Mindestanzahl Referenztage, Hinweis]
     */
    private const MONITOR = [
        ['az', 'Arbeitszahl (Wärme ÷ Strom)', '', 2, true, 'low', 15, 25, 'rel', 14,
            'Effizienz gesunken – mögliche Ursachen: Kältemittelverlust, verschmutzter/vereister Verdampfer, geänderte Einstellungen.'],
        ['eh', 'Strom Heizen je Tag', ' kWh', 1, true, 'high', 25, 45, 'rel', 14,
            'Mehrverbrauch beim Heizen bei gleicher Außentemperatur.'],
        ['ew', 'Strom Warmwasser je Tag', ' kWh', 2, false, 'high', 35, 60, 'rel', 14,
            'Ungewöhnlich hoher Energieaufwand für Warmwasser – z.B. Zirkulation, Speicherfühler, Heizstab, Verkalkung, höherer Verbrauch.'],
        ['df', 'Abtauungen je Tag', '', 1, true, 'high', [1.5, 2], [2, 4], 'cnt', 14,
            'Häufigeres Abtauen – Verdampfer verschmutzt oder Kältemittelmangel.'],
        ['st', 'Verdichterstarts je Tag', '', 0, true, 'high', [1.5, 3], [2, 6], 'cnt', 10,
            'Häufiges Takten – Heizkurve, Mindestlaufzeit, Volumenstrom oder Hydraulik prüfen.'],
        ['sh', 'Ø Überhitzung', ' K', 1, true, 'high', 3, 5, 'abs', 10,
            'Steigende Überhitzung ist ein typisches Anzeichen für Kältemittelmangel oder ein Problem am Expansionsventil.'],
        ['air', 'Ø Luft − Verdampfung', ' K', 1, true, 'high', 3, 5, 'abs', 10,
            'Sinkender Verdampfungsdruck – Kältemittelmangel, Vereisung oder verschmutzter Verdampfer.'],
        ['hot', 'Ø Heißgas', ' °C', 1, true, 'high', 8, 12, 'abs', 10,
            'Steigende Heißgastemperatur – Kältemittelmangel oder Verdichterproblem.'],
        ['ratio', 'Ø Druckverhältnis', '', 2, true, 'high', 0.6, 1.0, 'abs', 10,
            'Höheres Druckverhältnis bei gleicher Außentemperatur – höherer Vorlauf/Hochdruck oder sinkender Niederdruck (Kältemittel, Verdampfer).'],
        ['wwc', 'Warmwasser-Aufheizzeit je K', ' min/K', 2, true, 'high', 40, 75, 'rel', 8,
            'Warmwasserbereitung dauert ungewöhnlich lange – mögliche Ursachen: verkalkter/verschmutzter Wärmetauscher im Speicher, '
            . 'zu geringer Volumenstrom (Pumpe, Filter, Luft im System), Speicherfühler, Zapfung während der Ladung.']
    ];

    /** Wert einer Kennzahl für einen Tag (null = nicht verwertbar) */
    private static function MonitorValue(array $d, string $key): ?float
    {
        if (!empty($d['p']) || ($d['n'] ?? 1440) < 1200) {
            return null; // laufender oder unvollständiger Tag
        }
        switch ($key) {
            case 'az':
                return (($d['e'] ?? 0) >= 1 && ($d['h'] ?? 0) > 0) ? $d['h'] / $d['e'] : null;
            case 'eh':
                return ($d['eh'] ?? null) !== null && $d['eh'] >= 0.5 ? (float) $d['eh'] : null;
            case 'ew':
                return ($d['ew'] ?? null) !== null && $d['ew'] > 0.2 ? (float) $d['ew'] : null;
            case 'st':
                return !empty($d['trk']) && ($d['st'] ?? null) !== null ? (float) $d['st'] : null;
            default:
                return isset($d[$key]) && $d[$key] !== null ? (float) $d[$key] : null;
        }
    }

    private static function Median(array $values): ?float
    {
        if (!$values) {
            return null;
        }
        sort($values);
        $n = count($values);
        return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /** Erwartungswert aus den Referenztagen: ähnliche Außentemperatur (±2,5 K), sonst lineare Regression */
    private static function Expected(array $ref, ?float $t, bool $tempDependent): ?float
    {
        if (!$tempDependent || $t === null) {
            return self::Median(array_column($ref, 1));
        }
        $near = array_filter($ref, fn ($r) => $r[0] !== null && abs($r[0] - $t) <= 2.5);
        if (count($near) >= 5) {
            return self::Median(array_column($near, 1));
        }
        $pts = array_values(array_filter($ref, fn ($r) => $r[0] !== null));
        $n = count($pts);
        if ($n < 5) {
            return null;
        }
        $mx = array_sum(array_column($pts, 0)) / $n;
        $my = array_sum(array_column($pts, 1)) / $n;
        $sxx = 0; $sxy = 0;
        foreach ($pts as [$x, $y]) {
            $sxx += ($x - $mx) ** 2;
            $sxy += ($x - $mx) * ($y - $my);
        }
        $value = $sxx > 0 ? $my + $sxy / $sxx * ($t - $mx) : $my;
        $ys = array_column($pts, 1);
        return max(min($ys), min(max($ys), $value)); // nicht über die Erfahrung hinaus hochrechnen
    }

    /** Überwachung auswerten: letzte 3 vollständige Tage gegen die Erfahrungswerte der Tage davor */
    private function EvaluateMonitor(): array
    {
        if (!$this->Setting('monOn')) {
            return ['on' => false];
        }
        $days = json_decode($this->ReadAttributeString('DailyHistory'), true) ?: [];
        $days = array_slice($days, -400);
        $checks = [];
        $worst = 'ok';
        $rank = ['learn' => 0, 'nodata' => 0, 'ok' => 1, 'warn' => 2, 'alarm' => 3];
        foreach (self::MONITOR as [$key, $label, $unit, $digits, $tempDep, $dir, $warn, $alarm, $kind, $minRef, $hint]) {
            $series = [];
            if ($key === 'wwc') {
                // je Warmwasserladung statt je Tag: Minuten je Kelvin Temperaturanstieg im Speicher
                foreach ($this->DhwCharges() as $c) {
                    if (self::ChargeValid($c)) {
                        $series[] = [$c['out'], $c['min'] / $c['dk'], date('Y-m-d H:i', $c['ts'])];
                    }
                }
                $series = array_slice($series, -200);
            } else {
                foreach ($days as $d) {
                    $value = self::MonitorValue($d, $key);
                    if ($value !== null) {
                        $series[] = [$d['t'] ?? null, $value, $d['d']];
                    }
                }
            }
            $recent = array_slice($series, -3);
            $ref = array_slice($series, 0, max(0, count($series) - 3));
            $check = ['key' => $key, 'label' => $label, 'unit' => $unit, 'digits' => $digits, 'hint' => $hint,
                      'refDays' => count($ref), 'minRef' => $minRef, 'refUnit' => $key === 'wwc' ? 'Ladungen' : 'Tagen'];
            if (count($series) === 0) {
                $check['status'] = 'nodata';
            } elseif (count($ref) < $minRef || count($recent) < 2) {
                $check['status'] = 'learn';
            } else {
                $act = []; $exp = [];
                foreach ($recent as [$t, $value]) {
                    $e = self::Expected($ref, $t, $tempDep);
                    if ($e !== null) {
                        $act[] = $value;
                        $exp[] = $e;
                    }
                }
                if (!$act) {
                    $check['status'] = 'learn';
                } else {
                    // Warmwasser: Median der letzten 3 Ladungen – eine einzelne Ladung mit gleichzeitiger Zapfung löst keine Meldung aus
                    $a = $key === 'wwc' ? self::Median($act) : array_sum($act) / count($act);
                    $e = $key === 'wwc' ? self::Median($exp) : array_sum($exp) / count($exp);
                    $check['actual'] = round($a, 2);
                    $check['expected'] = round($e, 2);
                    $sign = $dir === 'high' ? 1 : -1;
                    $level = function ($limit) use ($kind, $a, $e, $sign) {
                        if ($kind === 'rel') {
                            return $e > 0 && $sign * ($a - $e) / $e * 100 > $limit;
                        }
                        if ($kind === 'cnt') {
                            return $a > $e * $limit[0] + $limit[1];
                        }
                        return $sign * ($a - $e) > $limit;
                    };
                    $check['status'] = $level($alarm) ? 'alarm' : ($level($warn) ? 'warn' : 'ok');
                    $check['dev'] = $kind === 'rel' || $kind === 'cnt'
                        ? ($e > 0 ? round(($a - $e) / $e * 100, 0) : null) : round($a - $e, 1);
                    $check['devUnit'] = $kind === 'abs' ? $unit : ' %';
                }
            }
            if ($rank[$check['status']] > $rank[$worst]) {
                $worst = $check['status'];
            }
            $checks[] = $check;
        }
        $learned = count(array_filter($checks, fn ($c) => !in_array($c['status'], ['learn', 'nodata'], true)));
        return ['on' => true, 'status' => $learned === 0 ? 'learn' : $worst, 'checks' => $checks,
                'days' => count(array_filter($days, fn ($d) => ($d['n'] ?? 1440) >= 1200)), 'ts' => time()];
    }

    private function MaintainMonitorVariables()
    {
        $on = (bool) $this->Setting('monOn');
        $this->MaintainVariable('MonWarn', 'Überwachung: Auffälligkeit', VARIABLETYPE_BOOLEAN, '~Alert', 270, $on);
        $this->MaintainVariable('MonText', 'Überwachung: Meldung', VARIABLETYPE_STRING, '', 271, $on);
    }

    /** Überwachung auswerten und Variablen setzen (täglich nach Tagesabschluss und beim Umschalten) */
    private function UpdateMonitor(bool $log)
    {
        $this->MaintainMonitorVariables();
        $monitor = $this->EvaluateMonitor();
        $this->WriteAttributeString('MonState', empty($monitor['on']) ? 'off' : $monitor['status']);
        if (empty($monitor['on'])) {
            return;
        }
        $issues = array_filter($monitor['checks'], fn ($c) => in_array($c['status'], ['warn', 'alarm'], true));
        $text = implode(' · ', array_map(fn ($c) => ($c['status'] === 'alarm' ? '⚠ ' : '') . $c['label'] . ': '
            . $this->Number($c['actual'], $c['digits']) . $c['unit'] . ' (erwartet ' . $this->Number($c['expected'], $c['digits']) . $c['unit'] . ')', $issues));
        $warn = count($issues) > 0;
        if ($this->GetValue('MonWarn') !== $warn) {
            $this->SetValue('MonWarn', $warn);
        }
        if ($this->GetValue('MonText') !== $text) {
            $this->SetValue('MonText', $text);
            if ($log && $warn) {
                $this->LogMessage('Wärmepumpe – Auffälligkeit: ' . $text, KL_WARNING);
                $this->Notify('monitor', 'Wärmepumpe – Auffälligkeit', $text, 'monitor');
            }
        }
    }


    // ---------------------------------------------------------------- Warmwasserladungen

    private function DhwCharges(): array
    {
        return json_decode($this->ReadAttributeString('DhwCharges'), true) ?: [];
    }

    /** Für die Bewertung brauchbar: mind. 5 min, mind. 3 K Anstieg, ohne Abtauung dazwischen */
    private static function ChargeValid(array $c): bool
    {
        return $c['min'] >= 5 && ($c['dk'] ?? 0) >= 3 && empty($c['df']) && ($c['out'] ?? null) !== null;
    }

    /**
     * Jede Minute: Warmwasserladung erkennen (Y-Ventil auf Warmwasser bei laufender WP) und am Ende Dauer,
     * Temperaturanstieg im Speicher, Strom und Ø Außentemperatur speichern. Eine Abtauung unterbricht die Ladung nicht.
     */
    private function TrackDhwCharge(int $now)
    {
        $st = json_decode($this->ReadAttributeString('DhwCharge'), true) ?: ['active' => false];
        $defrost = @$this->GetIDForIdent('DefrostActive') && $this->GetValue('DefrostActive');
        $charging = $this->GetValvePosition() === 'ww' && $this->IsRunning();
        $temp = $this->Value('StorageTop') ?? $this->Value('StorageBottom');
        $out = $this->Value('OutdoorTemp');

        if (!empty($st['active']) && $now - ($st['last'] ?? $now) > 15 * 60) {
            $st = ['active' => false]; // Lücke (Symcon aus): Ladung verwerfen
        }
        if ($charging && empty($st['active'])) {
            $st = ['active' => true, 'start' => $now, 'last' => $now, 't0' => $temp, 't1' => $temp,
                   'e0' => $this->ReadAttributeFloat('DhwElecTotal'), 'oSum' => 0, 'oN' => 0, 'df' => false];
        }
        if (!empty($st['active'])) {
            if ($charging || $defrost) {
                $st['last'] = $now;
                $st['df'] = $st['df'] || $defrost;
                if ($temp !== null) {
                    $st['t0'] = $st['t0'] ?? $temp;
                    $st['t1'] = max($st['t1'] ?? $temp, $temp);
                }
                if ($out !== null) {
                    $st['oSum'] += $out;
                    $st['oN']++;
                }
            } else {
                // Ladung beendet
                $minutes = round(($now - $st['start']) / 60, 1);
                $charge = [
                    'ts'  => $st['start'],
                    'min' => $minutes,
                    't0'  => $st['t0'] === null ? null : round($st['t0'], 1),
                    't1'  => $st['t1'] === null ? null : round($st['t1'], 1),
                    'dk'  => ($st['t0'] !== null && $st['t1'] !== null) ? round($st['t1'] - $st['t0'], 1) : null,
                    'kwh' => round(max(0, $this->ReadAttributeFloat('DhwElecTotal') - $st['e0']), 2),
                    'out' => $st['oN'] > 0 ? round($st['oSum'] / $st['oN'], 1) : $out,
                    'df'  => $st['df']
                ];
                $st = ['active' => false];
                if ($minutes >= 2) {
                    $charges = $this->DhwCharges();
                    $charges[] = $charge;
                    $this->WriteAttributeString('DhwCharges', json_encode(array_slice($charges, -300)));
                    $this->SendDebug('Warmwasserladung', json_encode($charge), 0);
                    if (self::ChargeValid($charge)) {
                        $this->UpdateMonitor(true); // gleich prüfen, nicht erst nach Mitternacht
                    }
                }
            }
        }
        $this->WriteAttributeString('DhwCharge', json_encode($st));
    }

    /** Daten für die Auswertung „Warmwasser“ */
    private function GetDhwView(): array
    {
        $charges = $this->DhwCharges();
        $valid = array_values(array_filter($charges, [self::class, 'ChargeValid']));
        $ref = array_map(fn ($c) => [$c['out'], $c['min'] / $c['dk']], array_slice($valid, 0, max(0, count($valid) - 3)));
        $out = $this->Value('OutdoorTemp');
        // Erwartung je Außentemperatur (für die Kurve im Diagramm) aus den Erfahrungswerten
        $curve = [];
        if (count($ref) >= 8) {
            $temps = array_column($ref, 0);
            for ($t = (int) floor(min($temps)); $t <= (int) ceil(max($temps)); $t++) {
                $curve[] = [$t, round(self::Expected($ref, (float) $t, true), 3)];
            }
        }
        $st = json_decode($this->ReadAttributeString('DhwCharge'), true) ?: [];
        $today = strtotime('today');
        $todayCharges = array_filter($charges, fn ($c) => $c['ts'] >= $today);
        return [
            'charges'  => array_slice($charges, -150),
            'curve'    => $curve,
            'expected' => (count($ref) >= 8 && $out !== null) ? round(self::Expected($ref, $out, true), 2) : null,
            'out'      => $out,
            'refN'     => count($ref),
            'running'  => !empty($st['active']) ? ['since' => $st['start'], 't0' => $st['t0'], 't' => $this->Value('StorageTop') ?? $this->Value('StorageBottom')] : null,
            'today'    => ['n' => count($todayCharges), 'min' => round(array_sum(array_column($todayCharges, 'min')), 0),
                           'kwh' => round(array_sum(array_column($todayCharges, 'kwh')), 2)],
            'price'    => $this->ReadPropertyFloat('PricePerKWh')
        ];
    }





    // ---------------------------------------------------------------- Heizkurve (Hoval oder selbstlernend über Symcon)

    private const CURVE_BINS = [-15, -10, -5, 0, 5, 10, 15];

    /**
     * Heizkurve je Modus: „hoval“ → Hoval rechnet selbst (ggf. Regelstrategie zurückstellen);
     * „symcon“ → Konstantregelung (27510 = 3) und Vorlauf-Soll (1490) aus der lernenden Symcon-Kurve, inkl. PV-Anhebung.
     */
    private function CurveControl(array &$state, int $now, bool $heating, float $roomSet, bool $holiday)
    {
        $symcon = $this->Setting('curveMode') === 'symcon' && $this->Setting('ctlOn')
            && $this->ReadPropertyInteger('WriteStrategy') > 0 && $this->ReadPropertyInteger('WriteFlowConst') > 0;
        if (!$symcon) {
            if (!empty($state['curveActive'])) {
                $orig = json_decode($this->ReadAttributeString('HovalOriginal'), true) ?: [];
                $this->WriteTo('WriteStrategy', (float) ($orig['strategy'] ?? 1), 0.01, 0);
                $state['curveActive'] = false;
            }
            $state['curveNow'] = null;
            return;
        }
        $out = $this->OutdoorDamped();
        if ($out === null) {
            return;
        }
        $curve = $this->SymconCurve($out, $roomSet);
        $vl = $curve['vl'];
        $pvBoost = 0.0;
        // PV-Überschuss per Vorlauf-Anhebung: ohne Puffer +1…3 K, mit Puffer bis zum Maximalwert, bis er durchgewärmt ist
        if ($this->Setting('pvOn') && $this->Setting('pvMethod') === 'vl' && !empty($state['pvActive']) && $heating) {
            $bufBottom = $this->Value('BufferBottom') ?? $this->Value('BufferTemp');
            $bufMax = (float) $this->Setting('pvBufMax');
            if ($bufBottom !== null) {
                if ($bufBottom >= $bufMax - 2) {
                    $state['pvBufDone'] = true;
                }
                $pvBoost = empty($state['pvBufDone']) ? max(0, $bufMax - $vl) : min(3, (float) $this->Setting('pvVlBoost'));
            } else {
                $pvBoost = min(3, (float) $this->Setting('pvVlBoost'));
            }
        } else {
            $state['pvBufDone'] = false;
        }
        $target = round(min(max($vl, $vl + $pvBoost), max((float) $this->Setting('cVlMax'), $this->Setting('pvMethod') === 'vl' ? (float) $this->Setting('pvBufMax') : 0)), 1);
        if ($this->Setting('hcMaxOn')) {
            $target = min($target, (float) $this->Setting('hcMax'));        // Maximaltemperatur Heizkreis gilt immer
        }
        $this->WriteTo('WriteStrategy', 3, 0.01, 1800, 'ControlStrategy');   // Konstantregelung
        $this->WriteTo('WriteFlowConst', $target, 0.15, 1800, 'FlowConst');  // Vorlauf-Soll aus der Symcon-Kurve
        $state['curveActive'] = true;
        $state['curveNow'] = $curve + ['target' => $target, 'pv' => round($pvBoost, 1), 'out' => round($out, 1), 'roomSet' => $roomSet, 'heating' => $heating];
        if ($this->Setting('cLearn') && !$holiday) {
            $this->CurveLearnTick($state, $now, $heating && $this->CurrentSeason($state) !== 'summer', $roomSet);
        }
    }

    /** Gedämpfte Außentemperatur (Mittel über ca. 3 h, wie die Gebäudeträgheit) */
    private function OutdoorDamped(): ?float
    {
        $avg = $this->ReadAttributeFloat('OutAvg3h');
        return $avg <= -900 ? $this->Value('OutdoorTemp') : $avg;
    }

    private function CurveLearnState(): array
    {
        return (json_decode($this->ReadAttributeString('CurveLearn'), true) ?: []) + ['offsets' => [], 'hour' => 0, 'devSum' => 0, 'outSum' => 0, 'n' => 0, 'log' => []];
    }

    /** Lernanteil bei einer Außentemperatur: lineare Interpolation zwischen den Stützstellen */
    private function LearnOffset(array $offsets, float $out): float
    {
        $bins = self::CURVE_BINS;
        if ($out <= $bins[0]) {
            return (float) ($offsets[$bins[0]] ?? 0);
        }
        if ($out >= end($bins)) {
            return (float) ($offsets[end($bins)] ?? 0);
        }
        for ($i = 1; $i < count($bins); $i++) {
            if ($out <= $bins[$i]) {
                $w = ($out - $bins[$i - 1]) / ($bins[$i] - $bins[$i - 1]);
                return (1 - $w) * ($offsets[$bins[$i - 1]] ?? 0) + $w * ($offsets[$bins[$i]] ?? 0);
            }
        }
        return 0.0;
    }

    /** Symcon-Heizkurve: Grundkurve (Fuß-/Auslegepunkt) + gelernter Anteil + Raumsoll-Verschiebung */
    private function SymconCurve(float $out, float $roomSet, ?array $learn = null): array
    {
        $foot = (float) $this->Setting('cFoot');
        $design = (float) $this->Setting('cDesign');
        $at = (float) $this->Setting('cDesignAT');
        $base = $foot + ($design - $foot) * (20 - $out) / max(1, 20 - $at);
        $offset = $this->LearnOffset(($learn ?? $this->CurveLearnState())['offsets'], $out);
        $room = ($roomSet - 20) * (float) $this->Setting('cRoomFactor');
        $vl = max((float) $this->Setting('cVlMin'), min((float) $this->Setting('cVlMax'), $base + $offset + $room));
        return ['vl' => round($vl, 1), 'base' => round($base, 1), 'learn' => round($offset, 2), 'room' => round($room, 1)];
    }

    /**
     * Lernen (jede Minute, wirksam stündlich): Liegt der Raum-Durchschnitt unter dem Soll, wird die Kurve an dieser
     * Außentemperatur angehoben, sonst abgesenkt – langsam (Fußbodenheizung ist träge): je K Raumabweichung 0,15 K/h,
     * max. 0,3 K je Stunde und ±6 K gesamt. Nicht gelernt wird bei Sollwertwechsel (3 h), PV-Anhebung, Urlaub, Sommer.
     */
    private function CurveLearnTick(array $state, int $now, bool $heating, ?float $roomSet)
    {
        $learn = $this->CurveLearnState();
        $rs = $state['roomStats'] ?? null;
        $out = $this->OutdoorDamped();
        $ok = $heating && $rs && $rs['avgIst'] !== null && $out !== null && empty($state['pvActive'])
            && $now - ($state['setChange'] ?? 0) >= 3 * 3600;
        $hour = intdiv($now, 3600);
        if ($learn['hour'] !== $hour) {
            if ($learn['n'] >= 45) {
                $dev = $learn['devSum'] / $learn['n'];
                $o = $learn['outSum'] / $learn['n'];
                $step = max(-0.3, min(0.3, -0.15 * $dev));
                $bins = self::CURVE_BINS;
                // auf die zwei benachbarten Stützstellen verteilen
                $lo = $bins[0];
                $hi = end($bins);
                foreach ($bins as $i => $b) {
                    if ($o >= $b) {
                        $lo = $b;
                        $hi = $bins[$i + 1] ?? $b;
                    }
                }
                $w = $hi === $lo ? 1 : ($o - $lo) / ($hi - $lo);
                foreach ([[$lo, 1 - $w], [$hi, $w]] as [$b, $weight]) {
                    if ($weight > 0) {
                        $learn['offsets'][$b] = round(max(-6, min(6, ($learn['offsets'][$b] ?? 0) + $step * $weight)), 2);
                    }
                }
                $learn['log'][] = [$learn['hour'] * 3600, round($o, 1), round($dev, 2), round($step, 2)];
                $learn['log'] = array_slice($learn['log'], -336); // 14 Tage Stundenwerte
            }
            $learn = ['offsets' => $learn['offsets'], 'hour' => $hour, 'devSum' => 0, 'outSum' => 0, 'n' => 0, 'log' => $learn['log']];
        }
        if ($ok) {
            $learn['devSum'] += $rs['avgIst'] - $rs['avgSoll'];
            $learn['outSum'] += $out;
            $learn['n']++;
        }
        $this->WriteAttributeString('CurveLearn', json_encode($learn));
    }

    /** Hoval-Originalwerte sichern (Regelstrategie, Vorlauf-Konstant, Heizkurve) – nur beim ersten Mal oder auf Wunsch */
    private function BackupHoval(bool $force = false): ?string
    {
        if (!$force && $this->ReadAttributeString('HovalOriginal') !== '') {
            return null;
        }
        $values = ['strategy' => $this->Value('ControlStrategy'), 'flowConst' => $this->Value('FlowConst'), 'foot' => $this->Value('CurveFoot'),
                   'design' => $this->Value('CurveDesign'), 'designAT' => $this->Value('CurveDesignAT'), 'vlMax' => $this->Value('CurveVlMax')];
        if ($values['strategy'] === null) {
            return 'Regelstrategie (27510) ist nicht als Datenpunkt eingetragen – Originalwerte können nicht gesichert werden.';
        }
        $this->WriteAttributeString('HovalOriginal', json_encode(['ts' => time()] + $values));
        return null;
    }

    /** Symcon-Grundkurve beim ersten Einschalten aus der Hoval-Kurve übernehmen (gleiches Verhalten zum Start) */
    private function InitCurveFromHoval()
    {
        $orig = json_decode($this->ReadAttributeString('HovalOriginal'), true) ?: [];
        $changes = [];
        foreach (['foot' => 'cFoot', 'design' => 'cDesign', 'designAT' => 'cDesignAT'] as $src => $dst) {
            if (($orig[$src] ?? null) !== null) {
                $changes[$dst] = $orig[$src];
            }
        }
        if ($changes) {
            $this->SaveSettings($changes);
        }
    }

    /** Auf die gesicherten Hoval-Werte zurücksetzen (Regelstrategie, Vorlauf-Konstant, Heizkurve) */
    private function RestoreHoval(): string
    {
        $orig = json_decode($this->ReadAttributeString('HovalOriginal'), true);
        if (!$orig) {
            return 'Es sind keine Hoval-Originalwerte gesichert.';
        }
        $done = [];
        foreach (['strategy' => 'WriteStrategy', 'flowConst' => 'WriteFlowConst', 'foot' => 'WriteCurveFoot', 'design' => 'WriteCurveDesign', 'vlMax' => 'WriteFlowMax'] as $key => $property) {
            if (($orig[$key] ?? null) !== null && $this->ReadPropertyInteger($property) > 0) {
                @RequestAction($this->ReadPropertyInteger($property), $orig[$key]);
                $done[] = $key;
            }
        }
        $this->SaveSettings(['curveMode' => 'hoval', 'hcMaxOn' => false]);
        $state = $this->GetCtlState();
        $state['curveActive'] = false;
        $state['flowMaxActive'] = false;
        $this->SetCtlState($state);
        $this->LogMessage('Hoval-Originalwerte wiederhergestellt: ' . json_encode($orig), KL_MESSAGE);
        return 'Hoval-Werte wiederhergestellt (' . implode(', ', $done) . ')';
    }

    /** Daten für die Kachel: Kurven zum Vergleich, aktueller Punkt, Lernwerte */
    private function CurveView(array $state): array
    {
        $learn = $this->CurveLearnState();
        $orig = json_decode($this->ReadAttributeString('HovalOriginal'), true);
        $hFoot = $this->Value('CurveFoot') ?? ($orig['foot'] ?? null);
        $hDesign = $this->Value('CurveDesign') ?? ($orig['design'] ?? null);
        $hAT = $this->Value('CurveDesignAT') ?? ($orig['designAT'] ?? -12.0);
        $points = [];
        for ($t = -15; $t <= 20; $t++) {
            $sc = $this->SymconCurve((float) $t, 20.0, $learn);
            $points[] = [$t, ($hFoot !== null && $hDesign !== null) ? round($hFoot + ($hDesign - $hFoot) * (20 - $t) / max(1, 20 - $hAT), 1) : null,
                         $sc['base'], round($sc['base'] + $sc['learn'], 1)];
        }
        $out = $this->OutdoorDamped();
        return [
            'mode'      => $this->Setting('curveMode'),
            'out'       => $out === null ? null : round($out, 1),
            'now'       => $state['curveNow'] ?? null,
            'points'    => $points,
            'offsets'   => $learn['offsets'],
            'lastLearn' => array_slice($learn['log'], -6),
            'hoval'     => ['foot' => $hFoot, 'design' => $hDesign, 'designAT' => $hAT, 'strategy' => $this->Value('ControlStrategy'), 'flowConst' => $this->Value('FlowConst')],
            'original'  => $orig ?: null,
            'canSymcon' => $this->ReadPropertyInteger('WriteStrategy') > 0 && $this->ReadPropertyInteger('WriteFlowConst') > 0,
            'hasBuffer' => $this->Value('BufferTop') !== null || $this->Value('BufferBottom') !== null || $this->Value('BufferTemp') !== null
        ];
    }

    // ---------------------------------------------------------------- EVU-Sperre

    /** Sperre aktiv? WFA 3 „Sperre“ / 17 „EW-/Energieerzeuger-Sperre“ oder Wärmeerzeuger 3 „Extern gesperrt“ */
    private function LockActive(): bool
    {
        $wfa = $this->Value('WFAStatus');
        $wez = $this->Value('WEZStatus');
        return ($wfa !== null && in_array((int) $wfa, [3, 17], true)) || ($wez !== null && (int) $wez === 3);
    }

    /** Jede Minute: Sperrzeiten erfassen (Zeiträume für die Diagramme, Minuten je Tag für die Auswertung) */
    private function TrackLock(int $now)
    {
        $state = json_decode($this->ReadAttributeString('LockState'), true) ?: ['active' => false];
        $active = $this->LockActive();
        if ($active) {
            $day = json_decode($this->ReadAttributeString('DayStats'), true);
            if ($day) {
                $day['lockMin'] = ($day['lockMin'] ?? 0) + 1;
                $this->WriteAttributeString('DayStats', json_encode($day));
            }
        }
        if ($active && empty($state['active'])) {
            $state = ['active' => true, 'start' => $now];
        } elseif (!$active && !empty($state['active'])) {
            $log = json_decode($this->ReadAttributeString('LockLog'), true) ?: [];
            $log[] = [$state['start'], $now];
            $log = array_values(array_filter($log, fn ($l) => $l[1] >= $now - 60 * 86400));
            $this->WriteAttributeString('LockLog', json_encode($log));
            $state = ['active' => false];
        }
        $this->WriteAttributeString('LockState', json_encode($state));
    }

    /** Sperrzeiträume, die ein Zeitfenster berühren (laufende Sperre bis jetzt) */
    private function LocksBetween(int $from, int $to): array
    {
        $log = json_decode($this->ReadAttributeString('LockLog'), true) ?: [];
        $state = json_decode($this->ReadAttributeString('LockState'), true) ?: [];
        if (!empty($state['active'])) {
            $log[] = [$state['start'], time()];
        }
        return array_values(array_filter($log, fn ($l) => $l[1] >= $from && $l[0] <= $to));
    }

    // ---------------------------------------------------------------- Langzeit: Monatswerte

    /** Tageswerte älter als die Aufbewahrungszeit zu Monatswerten zusammenfassen (die bleiben unbegrenzt) */
    private function RollupOldDays(array $history): array
    {
        $years = max(1, $this->ReadPropertyInteger('DayRetentionYears'));
        $limit = date('Y-m-d', strtotime("-$years years"));
        $keep = [];
        $monthly = json_decode($this->ReadAttributeString('MonthlyHistory'), true) ?: [];
        $changed = false;
        foreach ($history as $d) {
            if ($d['d'] >= $limit) {
                $keep[] = $d;
                continue;
            }
            $m = substr($d['d'], 0, 7);
            $b = $monthly[$m] ?? ['days' => 0];
            $b['days']++;
            foreach (['e', 'eh', 'ew', 'ed', 'h', 'df', 'lk'] as $f) {
                if (isset($d[$f]) && $d[$f] !== null) {
                    $b[$f] = round(($b[$f] ?? 0) + $d[$f], 3);
                }
            }
            if (!empty($d['trk']) && isset($d['st'])) {
                $b['st'] = ($b['st'] ?? 0) + $d['st'];
                $b['run'] = ($b['run'] ?? 0) + ($d['run'] ?? 0);
            }
            foreach (['t', 'hum'] as $f) {
                if (isset($d[$f]) && $d[$f] !== null) {
                    $b[$f . 'Sum'] = round(($b[$f . 'Sum'] ?? 0) + $d[$f], 2);
                    $b[$f . 'N'] = ($b[$f . 'N'] ?? 0) + 1;
                }
            }
            if (isset($d['tmin']) && $d['tmin'] !== null) {
                $b['tmin'] = isset($b['tmin']) ? min($b['tmin'], $d['tmin']) : $d['tmin'];
            }
            if (isset($d['tmax']) && $d['tmax'] !== null) {
                $b['tmax'] = isset($b['tmax']) ? max($b['tmax'], $d['tmax']) : $d['tmax'];
            }
            $monthly[$m] = $b;
            $changed = true;
        }
        if ($changed) {
            ksort($monthly);
            $this->WriteAttributeString('MonthlyHistory', json_encode($monthly));
        }
        return $keep;
    }

    /** Monatswerte aus Tages- und Langzeitdaten: ['Y-m' => [e, eh, ew, ed, h, df, lk, t, tmin, tmax, hum, days, p]] */
    private function MonthTotals(): array
    {
        $months = [];
        foreach (json_decode($this->ReadAttributeString('MonthlyHistory'), true) ?: [] as $m => $b) {
            $months[$m] = $b;
        }
        foreach ($this->AllDays() as $d) {
            $m = substr($d['d'], 0, 7);
            $b = $months[$m] ?? ['days' => 0];
            $b['days']++;
            $b['p'] = ($b['p'] ?? false) || !empty($d['p']);
            foreach (['e', 'eh', 'ew', 'ed', 'h', 'df', 'lk'] as $f) {
                if (isset($d[$f]) && $d[$f] !== null) {
                    $b[$f] = ($b[$f] ?? 0) + $d[$f];
                }
            }
            if (!empty($d['trk']) && isset($d['st'])) {
                $b['st'] = ($b['st'] ?? 0) + $d['st'];
                $b['run'] = ($b['run'] ?? 0) + ($d['run'] ?? 0);
            }
            foreach (['t', 'hum'] as $f) {
                if (isset($d[$f]) && $d[$f] !== null) {
                    $b[$f . 'Sum'] = ($b[$f . 'Sum'] ?? 0) + $d[$f];
                    $b[$f . 'N'] = ($b[$f . 'N'] ?? 0) + 1;
                }
            }
            if (isset($d['tmin']) && $d['tmin'] !== null) {
                $b['tmin'] = isset($b['tmin']) ? min($b['tmin'], $d['tmin']) : $d['tmin'];
            }
            if (isset($d['tmax']) && $d['tmax'] !== null) {
                $b['tmax'] = isset($b['tmax']) ? max($b['tmax'], $d['tmax']) : $d['tmax'];
            }
            $months[$m] = $b;
        }
        ksort($months);
        return $months;
    }

    /** Jahresvergleich: je Jahr und Monat Strom, Wärme, Kosten, Ø Außentemperatur */
    private function GetYearView(): array
    {
        $price = $this->ReadPropertyFloat('PricePerKWh');
        $years = [];
        foreach ($this->MonthTotals() as $m => $b) {
            [$y, $mo] = array_map('intval', explode('-', $m));
            $e = $b['e'] ?? null;
            $years[$y][$mo] = [
                'e' => $e === null ? null : round($e, 1), 'h' => isset($b['h']) ? round($b['h'], 1) : null,
                'c' => $e === null ? null : round($e * $price, 2),
                't' => !empty($b['tN']) ? round($b['tSum'] / $b['tN'], 1) : null,
                'tmin' => $b['tmin'] ?? null, 'tmax' => $b['tmax'] ?? null,
                'az' => ($e !== null && $e > 1 && isset($b['h'])) ? round($b['h'] / $e, 2) : null,
                'df' => $b['df'] ?? null, 'lk' => isset($b['lk']) ? (int) $b['lk'] : null, 'days' => $b['days'], 'p' => !empty($b['p'])
            ];
        }
        ksort($years);
        return ['years' => $years, 'price' => $price];
    }

    // ---------------------------------------------------------------- Einsparempfehlungen

    /** Heiztage der letzten Zeit (vollständig, mit Heizstrom) – neueste zuletzt */
    private function AdviceDays(int $since = 0, int $max = 10): array
    {
        $days = array_values(array_filter(json_decode($this->ReadAttributeString('DailyHistory'), true) ?: [],
            fn ($d) => ($d['n'] ?? 1440) >= 1200 && ($d['eh'] ?? 0) >= 1 && strtotime($d['d']) >= $since));
        return array_slice($days, -$max);
    }

    /** Kennzahl einer Empfehlung über eine Tagesliste (Mittelwert), null = zu wenig Daten */
    private static function AdviceMetric(string $metric, array $days): ?float
    {
        $vals = [];
        foreach ($days as $d) {
            if (strpos($metric, 'rdev:') === 0) {
                $v = $d['rdev'][substr($metric, 5)] ?? null;
            } elseif ($metric === 'st') {
                $v = !empty($d['trk']) ? ($d['st'] ?? null) : null;
            } else {
                $v = $d[$metric] ?? null;
            }
            if ($v !== null) {
                $vals[] = (float) $v;
            }
        }
        return count($vals) >= 3 ? array_sum($vals) / count($vals) : null;
    }

    /** Heizkurven-Vorschlag aus Raumabweichung über der Außentemperatur (lineare Regression) */
    private function CurveSuggestion(array $days): ?array
    {
        $pts = [];
        foreach ($days as $d) {
            if (($d['dev'] ?? null) !== null && ($d['t'] ?? null) !== null) {
                $pts[] = [(float) $d['t'], (float) $d['dev']];
            }
        }
        if (count($pts) < 5) {
            return null;
        }
        $n = count($pts);
        $mx = array_sum(array_column($pts, 0)) / $n;
        $my = array_sum(array_column($pts, 1)) / $n;
        $sxx = 0; $sxy = 0;
        foreach ($pts as [$x, $y]) { $sxx += ($x - $mx) ** 2; $sxy += ($x - $mx) * ($y - $my); }
        $b = $sxx > 4 ? $sxy / $sxx : 0; // erst bei genug Temperaturspreizung die Neigung schätzen
        $designAT = $this->Value('CurveDesignAT') ?? -12.0;
        $devWarm = $my + $b * (15 - $mx);      // Abweichung bei mildem Wetter → Fußpunkt
        $devCold = $my + $b * ($designAT - $mx); // Abweichung bei Auslegungstemperatur → Auslegepunkt
        // Faustregel Flächenheizung: 1 K Raumtemperatur ≈ 1,5 K Vorlauf
        $r = fn ($v) => max(-3, min(3, round(-1.5 * $v * 2) / 2));
        $foot = $this->Value('CurveFoot');
        $design = $this->Value('CurveDesign');
        return ['dFoot' => $r($devWarm), 'dDesign' => $r($devCold), 'devWarm' => round($devWarm, 1), 'devCold' => round($devCold, 1),
                'foot' => $foot, 'design' => $design, 'designAT' => $designAT,
                'canApply' => $foot !== null && $design !== null && $this->ReadPropertyInteger('WriteCurveFoot') > 0 && $this->ReadPropertyInteger('WriteCurveDesign') > 0];
    }

    /** Empfehlungen ermitteln und umgesetzte nachverfolgen */
    private function GetAdvice(): array
    {
        $days = $this->AdviceDays();
        $log = json_decode($this->ReadAttributeString('AdviceLog'), true) ?: [];
        $fmtK = fn ($v) => ($v > 0 ? '+' : '') . number_format($v, 1, ',', '') . ' K';
        $items = [];
        if (count($days) >= 4) {
            // je Raum zu warm / zu kalt
            $names = [];
            foreach ($days as $d) {
                $names += array_flip(array_keys($d['rdev'] ?? []));
            }
            $warmRooms = 0;
            foreach (array_keys($names) as $name) {
                $dev = self::AdviceMetric('rdev:' . $name, $days);
                if ($dev === null) {
                    continue;
                }
                $warmRooms += $dev > 0.5 ? 1 : 0;
                if ($dev > 1.0) {
                    $items[] = ['id' => 'room-warm:' . $name, 'metric' => 'rdev:' . $name, 'value' => round($dev, 2), 'icon' => '🌡',
                                'title' => "$name ist zu warm (Ø " . $fmtK($dev) . ')',
                                'text' => "Durchfluss dieses Raums am Heizkreisverteiler etwas verringern (z.B. ¼ Umdrehung am Ventil). "
                                    . 'Jedes Grad weniger spart grob 6 % der Heizenergie für diesen Raum.'];
                } elseif ($dev < -1.0) {
                    $items[] = ['id' => 'room-cold:' . $name, 'metric' => 'rdev:' . $name, 'value' => round($dev, 2), 'icon' => '❄',
                                'title' => "$name ist zu kalt (Ø " . $fmtK($dev) . ')',
                                'text' => 'Durchfluss am Heizkreisverteiler erhöhen oder Stellantrieb/Raumthermostat prüfen – '
                                    . 'besser als die ganze Heizkurve anzuheben.'];
                }
            }
            // ganzes Haus zu warm/kalt → Heizkurve
            $devAll = self::AdviceMetric('dev', $days);
            if ($devAll !== null && abs($devAll) > 0.7 && ($devAll < 0 || $warmRooms >= max(1, (int) ceil(count($names) * 0.6)))) {
                $cs = $this->CurveSuggestion($days);
                $txt = $devAll > 0 ? 'Alle Räume sind im Mittel zu warm – die Heizkurve kann abgesenkt werden. Jedes Grad weniger spart grob 6 % Heizenergie.'
                    : 'Die Räume sind im Mittel zu kalt – die Heizkurve sollte etwas angehoben werden.';
                if ($cs) {
                    $txt .= sprintf(' Vorschlag: Fußpunkt %s, Auslegepunkt %s.', $fmtK($cs['dFoot']), $fmtK($cs['dDesign']));
                }
                $items[] = ['id' => 'curve', 'metric' => 'dev', 'value' => round($devAll, 2), 'icon' => '📈',
                            'title' => 'Heizkurve ' . ($devAll > 0 ? 'absenken' : 'anheben') . ' (Ø ' . $fmtK($devAll) . ')', 'text' => $txt, 'curve' => $cs];
            }
            // Takten
            $st = self::AdviceMetric('st', $days);
            $run = self::AdviceMetric('run', $days);
            if ($st !== null && $st > 12 && $run !== null && $run / max(1, $st) < 25) {
                $items[] = ['id' => 'cycling', 'metric' => 'st', 'value' => round($st, 1), 'icon' => '🔁',
                            'title' => sprintf('Verdichter taktet (Ø %d Starts/Tag, Ø %d min je Start)', round($st), round($run / $st)),
                            'text' => 'Häufige kurze Läufe kosten Effizienz und Lebensdauer. Möglich: Heizkurve etwas flacher, Raumthermostate weiter offen '
                                . '(mehr Volumen), Mindestlaufzeit/Schaltdifferenz vom Hoval-Service prüfen lassen.'];
            }
            // Warmwassertemperatur
            $dhwSet = $this->Setting('dhwOn') ? (float) $this->Setting('dhwComfort') : ($this->Value('DHWSetpoint') ?? 0);
            $ew = self::AdviceMetric('ew', $days);
            if ($dhwSet >= 52 && $ew !== null) {
                $items[] = ['id' => 'dhw-temp', 'metric' => 'ew', 'value' => round($ew, 2), 'icon' => '🚿',
                            'title' => sprintf('Warmwasser %s °C – etwas niedriger reicht meist', number_format($dhwSet, 0)),
                            'text' => 'Je 1 K weniger Warmwassertemperatur sinkt der Warmwasser-Strom um ca. 2–3 %. 48–50 °C reichen im Alltag meist; '
                                . 'für Legionellenschutz lieber gezielt einmal pro Woche „Aufheizen“.'];
            }
        }
        // Nachverfolgung umgesetzter Empfehlungen
        $changed = false;
        foreach ($log as $id => &$entry) {
            if (($entry['state'] ?? '') !== 'done' || isset($entry['result'])) {
                continue;
            }
            $after = $this->AdviceDays($entry['ts'] + 86400, 30);
            if (count($after) < 4) {
                continue;
            }
            $now = self::AdviceMetric($entry['metric'], $after);
            if ($now === null) {
                continue;
            }
            $before = (float) $entry['before'];
            $isDev = strpos($entry['metric'], 'dev') !== false;
            $better = $isDev ? abs($now) <= abs($before) - 0.3 : $now <= $before * 0.85;
            if ($better || count($after) >= 10) {
                $entry['result'] = $better ? 'better' : 'same';
                $entry['after'] = round($now, 2);
                $changed = true;
                $unit = $isDev ? ' K' : '';
                $msg = $entry['title'] . ': ' . ($better
                    ? 'Werte haben sich verbessert – jetzt ' . number_format($now, 1, ',', '') . $unit . ' (vorher ' . number_format($before, 1, ',', '') . $unit . ').'
                    : 'noch keine deutliche Verbesserung (jetzt ' . number_format($now, 1, ',', '') . $unit . ') – Schritt ggf. wiederholen.');
                $entry['msg'] = $msg;
                $this->Notify('advice-' . $id, 'Wärmepumpe – Empfehlung', $msg, 'advice');
            }
        }
        unset($entry);
        if ($changed) {
            $this->WriteAttributeString('AdviceLog', json_encode($log));
        }
        // offene Empfehlungen ohne erledigte/verworfene (verworfene 30 Tage ausblenden)
        $open = array_values(array_filter($items, function ($i) use ($log) {
            $e = $log[$i['id']] ?? null;
            if (!$e) {
                return true;
            }
            if ($e['state'] === 'dismissed') {
                return time() - $e['ts'] > 30 * 86400;
            }
            return isset($e['result']) && $e['result'] === 'same' && time() - $e['ts'] > 14 * 86400;
        }));
        $done = [];
        foreach ($log as $id => $e) {
            if ($e['state'] === 'done' && time() - $e['ts'] < 60 * 86400) {
                $done[] = ['id' => $id] + $e;
            }
        }
        return ['open' => $open, 'done' => $done, 'days' => count($days), 'needDays' => 4];
    }

    /** Erledigt / Verwerfen / Heizkurve übernehmen */
    private function HandleAdvice(array $r): ?array
    {
        $a = $r['a'] ?? 'list';
        if ($a === 'list') {
            return null;
        }
        $log = json_decode($this->ReadAttributeString('AdviceLog'), true) ?: [];
        $id = (string) ($r['id'] ?? '');
        $current = null;
        foreach ($this->GetAdvice()['open'] as $item) {
            if ($item['id'] === $id) {
                $current = $item;
            }
        }
        switch ($a) {
            case 'done':
                if ($current === null) {
                    return ['ok' => false, 'msg' => 'Empfehlung nicht mehr aktuell'];
                }
                $log[$id] = ['state' => 'done', 'ts' => time(), 'metric' => $current['metric'], 'before' => $current['value'], 'title' => $current['title']];
                $this->WriteAttributeString('AdviceLog', json_encode($log));
                return ['ok' => true, 'msg' => 'Gemerkt – in ca. 4 Heiztagen kommt das Ergebnis'];
            case 'dismiss':
                $log[$id] = ['state' => 'dismissed', 'ts' => time()];
                $this->WriteAttributeString('AdviceLog', json_encode($log));
                return ['ok' => true, 'msg' => 'Empfehlung ausgeblendet (30 Tage)'];
            case 'forget':
                unset($log[$id]);
                $this->WriteAttributeString('AdviceLog', json_encode($log));
                return ['ok' => true, 'msg' => 'Entfernt'];
            case 'applyCurve':
                $error = $this->CheckPassword($r['pw'] ?? '');
                if ($error !== null) {
                    return ['ok' => false, 'msg' => $error, 'locked' => true];
                }
                $cs = $current['curve'] ?? null;
                if (!$cs || !$cs['canApply']) {
                    return ['ok' => false, 'msg' => 'Heizkurve kann nicht geschrieben werden (Variablen in der Instanz eintragen)'];
                }
                $foot = round($cs['foot'] + $cs['dFoot'], 1);
                $design = round($cs['design'] + $cs['dDesign'], 1);
                @RequestAction($this->ReadPropertyInteger('WriteCurveFoot'), $foot);
                @RequestAction($this->ReadPropertyInteger('WriteCurveDesign'), $design);
                $this->LogMessage("Heizkurve angepasst: Fußpunkt {$cs['foot']} → $foot °C, Auslegepunkt {$cs['design']} → $design °C", KL_MESSAGE);
                $log[$id] = ['state' => 'done', 'ts' => time(), 'metric' => $current['metric'], 'before' => $current['value'], 'title' => $current['title'],
                             'applied' => ['foot' => [$cs['foot'], $foot], 'design' => [$cs['design'], $design]]];
                $this->WriteAttributeString('AdviceLog', json_encode($log));
                return ['ok' => true, 'msg' => "Heizkurve übernommen: Fußpunkt $foot °C, Auslegepunkt $design °C"];
        }
        return null;
    }

    // ---------------------------------------------------------------- Benachrichtigungen

    /** Speicherbedarf: interne Verläufe (Instanz-Attribute) und Archiv (aufgezeichnete Variablen, hochgerechnet aus den letzten 24 h) */
    public function StorageInfo()
    {
        $attrs = ['DailyHistory' => 'Tageswerte', 'QHistory' => '15-min-Werte (31 Tage)', 'QPeaks' => '15-min-Spitzen', 'RefHist' => 'Kältekreis 6 h',
                  'DhwCharges' => 'Warmwasserladungen', 'Settings' => 'Einstellungen', 'CtlState' => 'Steuerung', 'DayStats' => 'laufender Tag'];
        $lines = [];
        $total = 0;
        foreach ($attrs as $name => $label) {
            $bytes = strlen($this->ReadAttributeString($name));
            $total += $bytes;
            $lines[] = sprintf('  %-28s %8.1f kB', $label, $bytes / 1024);
        }
        $out = "Interne Verläufe (in der Instanz): " . number_format($total / 1024, 1, ',', '.') . " kB\n" . implode("\n", $lines) . "\n"
            . "  (Tageswerte wachsen ca. 0,4 kB je Tag, max. ca. 3 Jahre; alles andere ist begrenzt)\n\n";
        $archives = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if ($archives) {
            $archive = $archives[0];
            $rows = 0;
            $vars = 0;
            foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
                if (IPS_VariableExists($id) && AC_GetLoggingStatus($archive, $id)) {
                    $vars++;
                    $rows += count(@AC_GetLoggedValues($archive, $id, time() - 86400, time(), 0) ?: []);
                }
            }
            $perYear = $rows * 365 * 30; // ca. 30 Byte je Archiveintrag (Rohwert) + Verdichtungen
            $out .= "Archiv: $vars aufgezeichnete Variablen, " . number_format($rows, 0, ',', '.') . " Einträge in den letzten 24 h\n"
                . '  Hochrechnung ca. ' . number_format($perYear / 1048576, 1, ',', '.') . " MB pro Jahr\n"
                . "  (das Archiv speichert nur Änderungen; ältere Rohdaten können im Archiv-Steuerer automatisch gelöscht werden)";
        }
        echo $out;
    }

    public function TestNotification()
    {
        echo $this->Notify('test', 'Wärmepumpe', 'Testnachricht aus der Hoval-Kachel – Benachrichtigungen funktionieren.', 'test', true)
            ? 'Testnachricht gesendet.' : 'Nicht gesendet – bitte eine Visualisierung (Kachel-Visualisierung oder WebFront) auswählen und übernehmen.';
    }

    /**
     * Push-Benachrichtigung über die gewählte Visualisierung. Gleiche Meldung je Schlüssel höchstens alle 12 h.
     * Kategorie: error | monitor | control | advice | test (Schalter in der Instanz)
     */
    private function Notify(string $key, string $title, string $text, string $category, bool $force = false): bool
    {
        $visu = $this->ReadPropertyInteger('NotifyVisuID');
        if ($visu <= 0 || !IPS_InstanceExists($visu)) {
            return false;
        }
        $switch = ['error' => 'NotifyError', 'monitor' => 'NotifyMonitor', 'control' => 'NotifyControl', 'advice' => 'NotifyAdvice'][$category] ?? null;
        if (!$force && $switch !== null && !$this->ReadPropertyBoolean($switch)) {
            return false;
        }
        $log = json_decode($this->ReadAttributeString('NotifyLog'), true) ?: [];
        $sig = md5($text);
        if (!$force && isset($log[$key]) && $log[$key]['s'] === $sig && time() - $log[$key]['t'] < 12 * 3600) {
            return false;
        }
        $text = mb_substr($text, 0, 240);
        $sent = false;
        try {
            if (function_exists('VISU_PostNotification')) {
                $sent = @VISU_PostNotification($visu, $title, $text, 'Warning', $this->InstanceID) !== false;
            }
        } catch (Throwable $e) {
            $sent = false;
        }
        if (!$sent && function_exists('WFC_PushNotification')) {
            try {
                $sent = @WFC_PushNotification($visu, $title, mb_substr($text, 0, 100), '', 0) !== false;
            } catch (Throwable $e) {
                $sent = false;
            }
        }
        $this->SendDebug('Benachrichtigung', ($sent ? 'gesendet: ' : 'fehlgeschlagen: ') . $title . ' – ' . $text, 0);
        if ($sent) {
            $log[$key] = ['s' => $sig, 't' => time()];
            $this->WriteAttributeString('NotifyLog', json_encode($log));
        }
        return $sent;
    }

    /** Jede Minute: Störung und nicht übernommene Vorgaben melden */
    private function CheckAlerts()
    {
        $code = $this->Value('ErrorCode');
        if ($code !== null) {
            $code = (int) $code;
            $last = $this->ReadAttributeInteger('LastErrorCode');
            if ($code !== $last) {
                if ($code !== 255 && $code !== 0) {
                    $this->Notify('error', 'Wärmepumpe – Störung', $this->Format('ErrorCode'), 'error');
                } elseif ($last !== 255 && $last !== 0) {
                    $this->Notify('error', 'Wärmepumpe', 'Störung behoben.', 'error');
                }
                $this->WriteAttributeInteger('LastErrorCode', $code);
            }
        }
        $issues = $this->WriteIssues((bool) $this->Setting('ctlOn'), (bool) $this->Setting('roomsOn'), (bool) $this->Setting('dhwOn'));
        $issues = array_filter($issues, fn ($t) => strpos($t, 'übernimmt') !== false); // fehlende Variablen nicht melden, nur echte Probleme
        if ($issues) {
            $this->Notify('write', 'Wärmepumpe – Vorgabe nicht übernommen', implode(' ', $issues), 'control');
        }
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
            if (!empty($day['trk']) && isset($day['st']) && $day['st'] !== null) {
                $b['st'] = ($b['st'] ?? 0) + $day['st'];
                $b['run'] = ($b['run'] ?? 0) + ($day['run'] ?? 0);
            }
            foreach (['e', 'eh', 'ew', 'ed', 'h', 'df', 'lk'] as $field) {
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

        // Monate, deren Tageswerte schon zu Monatswerten zusammengefasst wurden
        if ($period === 'month') {
            foreach (json_decode($this->ReadAttributeString('MonthlyHistory'), true) ?: [] as $m => $mb) {
                if (!isset($labels[$m])) {
                    $hasOlder = $hasOlder || $m < $oldestKey;
                    continue;
                }
                $b = $buckets[$m] ?? [];
                foreach (['e', 'eh', 'ew', 'ed', 'h', 'df', 'lk', 'st', 'run'] as $f) {
                    if (isset($mb[$f])) {
                        $b[$f] = ($b[$f] ?? 0) + $mb[$f];
                    }
                }
                $b['days'] = ($b['days'] ?? 0) + $mb['days'];
                foreach (['t', 'hum'] as $f) {
                    if (!empty($mb[$f . 'N'])) {
                        $b[$f . 'Sum'] = ($b[$f . 'Sum'] ?? 0) + $mb[$f . 'Sum'];
                        $b[$f . 'Count'] = ($b[$f . 'Count'] ?? 0) + $mb[$f . 'N'];
                    }
                }
                foreach (['tmin' => 'min', 'tmax' => 'max'] as $f => $fn) {
                    if (isset($mb[$f])) {
                        $b[$f] = isset($b[$f]) ? $fn($b[$f], $mb[$f]) : $mb[$f];
                    }
                }
                $buckets[$m] = $b;
            }
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
                'df'    => isset($b['df']) ? (int) $b['df'] : null,
                'c'     => $e === null ? null : round($e * $this->ReadPropertyFloat('PricePerKWh'), 2),
                'st'    => isset($b['st']) ? (int) $b['st'] : null,
                'lk'    => isset($b['lk']) ? (int) $b['lk'] : null,
                'rps'   => (isset($b['st']) && $b['st'] > 0) ? round($b['run'] / $b['st'], 0) : null // Ø Laufzeit je Start (min)
            ];
        }
        return ['period' => $period, 'page' => $page, 'rows' => $rows, 'older' => $hasOlder, 'price' => $this->ReadPropertyFloat('PricePerKWh')];
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
        if (!is_finite($value)) {
            return null; // gestörter Wert (NAN/INF) wie „nicht vorhanden“ behandeln
        }
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
            case 'bar':
                return $this->Number($value, 2) . ' bar';
            case 'kelvin':
                return $this->Number($value, 1) . ' K';
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
            return $value * [0.001, 1, 1000][$this->ReadPropertyInteger('MeasuredEnergyUnit')] * $this->MeterFactor();
        }
        return $value * ($this->ReadPropertyInteger('HovalEnergyUnit') === 1 ? 1000 : 1);
    }

    /** Korrektur geändert: Startwerte von Tag und Vergleichszeitraum mitskalieren, damit kein Sprung im Verbrauch entsteht */
    private function RescaleMeter()
    {
        $old = $this->ReadAttributeFloat('MeterFactorLast');
        $new = $this->MeterFactor();
        if (abs($old - $new) < 1e-9 || $old <= 0) {
            return;
        }
        $ratio = $new / $old;
        $metered = $this->EnergyKWh('MeasuredEnergy') !== null; // nur ein Zählerstand skaliert mit; die aufsummierte Leistung nicht
        if ($metered) {
            $day = json_decode($this->ReadAttributeString('DayStats'), true);
            if ($day && ($day['elecSrc'] ?? '') === 'meas' && $day['elecStart'] !== null) {
                $day['elecStart'] *= $ratio;
                $this->WriteAttributeString('DayStats', json_encode($day));
            }
            $baseline = json_decode($this->ReadAttributeString('Baseline'), true);
            if ($baseline && isset($baseline['elecMeas'])) {
                $baseline['elecMeas'] *= $ratio;
                $this->WriteAttributeString('Baseline', json_encode($baseline));
            }
        }
        $this->WriteAttributeFloat('MeterFactorLast', $new);
    }

    /** Korrekturfaktor des externen Zählers (Instanz: Korrektur in %) */
    private function MeterFactor(): float
    {
        return 1 + $this->ReadPropertyFloat('MeasuredCorrection') / 100;
    }

    /** Gemessene elektrische Leistung in kW */
    private function MeasuredPowerKW(): ?float
    {
        $value = $this->Value('MeasuredPower');
        if ($value === null) {
            return null;
        }
        return ($this->ReadPropertyInteger('MeasuredPowerUnit') === 0 ? $value / 1000 : $value) * $this->MeterFactor();
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

    /** Läuft der Verdichter? Drehzahl (31920), sonst Modulation, sonst el. Leistung; während der Abtauung nein */
    private function CompressorRunning(): bool
    {
        if (@$this->GetIDForIdent('DefrostActive') && $this->GetValue('DefrostActive')) {
            return false;
        }
        $speed = $this->Value('CompressorSpeed');
        if ($speed !== null) {
            return $speed > 0;
        }
        $modulation = $this->Value('WEZPower');
        if ($modulation !== null) {
            return $modulation > 0;
        }
        return ($this->MeasuredPowerKW() ?? $this->Value('ElecPower') ?? 0) > 0.3;
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
            'elecMeas'   => $this->MeasuredEnergyKWh(),
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
        // eigener COP nur bei laufendem Verdichter (nicht bei Pumpennachlauf, Stillstand oder Abtauung)
        $compressor = $this->CompressorRunning();
        $copOwn = ($compressor && $qHyd !== null && $qHyd > 0 && $pElec !== null && $pElec > 0.1) ? min(15, $qHyd / $pElec) : null;

        // Energie und JAZ im Vergleichszeitraum
        $baseline = $this->GetBaseline();
        $elecHoval = $this->EnergyKWh('ElecEnergy');
        $elecMeas = $this->MeasuredEnergyKWh();
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
            'compressor' => $compressor,
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
            // Verdichter aus: COP 0 (= aus), damit das Archiv nicht den letzten Wert weiterführt
            'COPOwn' => $c['compressor'] ? $c['copOwn'] : 0.0, 'DevCOP' => $c['compressor'] ? $c['devCOP'] : 0.0,
            'JAZOwn' => $c['jazOwn'], 'DevJAZ' => $c['devJAZ'],
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
        $message = [
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
                'total'  => $this->GetValue('DefrostCountTotal'),
                'last'   => $this->GetValue('DefrostLastMinutes')
            ],
            // Tageswerte: abgeschlossene Tage + der laufende Tag (p = unvollständig)
            'daily'   => $this->GetDailySeries(),
            'power'   => $this->GetPowerView(),
            'control' => $this->GetControlView(),
            'refri'   => $this->GetRefrigerantView(),
            'monState' => $this->ReadAttributeString('MonState'),
            'today'    => $this->TodayOutdoor(),
            'view'     => json_decode($this->ReadAttributeString('ViewState'), true) ?: new stdClass(),
            'since'    => date('d.m.Y H:i', $c['since'])
        ];
        // nie eine leere Nachricht senden: ungültige Zahlen (NAN/INF, z.B. aus einem gestörten Modbus-Wert) werden zu 0,
        // ungültige Zeichen ersetzt – sonst kommt bei der Kachel nur „false“ an und alle Werte verschwinden
        $json = json_encode($message, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->SendDebug('Kachel', 'JSON-Fehler: ' . json_last_error_msg(), 0);
        }
        return $json;
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

    // ---------------------------------------------------------------- Kältekreis

    // Dampfdruckkurven (Näherung): Temperatur °C => absoluter Druck bar
    private const SATURATION = [
        'R32'   => [-40 => 1.77, -30 => 2.73, -20 => 4.05, -10 => 5.82, 0 => 8.13, 10 => 11.07, 20 => 14.74, 30 => 19.27, 40 => 24.78, 50 => 31.41, 60 => 39.27, 70 => 48.5],
        'R410A' => [-40 => 1.75, -30 => 2.70, -20 => 4.00, -10 => 5.73, 0 => 7.98, 10 => 10.85, 20 => 14.44, 30 => 18.85, 40 => 24.17, 50 => 30.55, 60 => 38.2, 70 => 47.4],
        'R290'  => [-40 => 1.11, -30 => 1.68, -20 => 2.44, -10 => 3.45, 0 => 4.74, 10 => 6.36, 20 => 8.36, 30 => 10.79, 40 => 13.69, 50 => 17.13, 60 => 21.16, 70 => 25.84]
    ];

    /** Sättigungstemperatur zu einem Relativdruck (bar) – logarithmisch zwischen den Stützpunkten interpoliert */
    private function SaturationTemp(?float $relativeBar): ?float
    {
        if ($relativeBar === null) {
            return null;
        }
        $table = self::SATURATION[$this->ReadPropertyString('Refrigerant')] ?? self::SATURATION['R32'];
        $p = log(max(0.1, $relativeBar + 1.013));
        $points = [];
        foreach ($table as $t => $bar) {
            $points[] = [$t, log($bar)];
        }
        for ($i = 1; $i < count($points); $i++) {
            if ($p <= $points[$i][1] || $i === count($points) - 1) {
                [$t0, $p0] = $points[$i - 1];
                [$t1, $p1] = $points[$i];
                return round($t0 + ($p - $p0) / ($p1 - $p0) * ($t1 - $t0), 1);
            }
        }
        return null;
    }

    /** Aktuelle Kältekreiswerte und daraus berechnete Kennwerte */
    private function GetRefrigerantView(): ?array
    {
        $keys = ['AirIntake', 'SuctionGas', 'HotGas', 'Superheat', 'LowPressure', 'HighPressure', 'CompressorSpeed', 'FanSpeed', 'HPFlowTemp', 'HPReturnTemp'];
        $v = [];
        // Grenzen für plausible Werte – Ersatzwerte des Gateways (z.B. -3276,8) oder Fühlerfehler werden verworfen
        $limits = ['LowPressure' => [-1, 60], 'HighPressure' => [-1, 70], 'Superheat' => [-30, 80], 'CompressorSpeed' => [0, 150], 'FanSpeed' => [0, 150]];
        foreach ($keys as $key) {
            $value = $this->Value($key);
            [$min, $max] = $limits[$key] ?? [-60, 160];
            $v[$key] = ($value === null || !is_finite($value) || $value < $min || $value > $max) ? null : $value;
        }
        if (count(array_filter($v, fn ($x) => $x !== null)) === 0) {
            return null;
        }
        $evap = $this->SaturationTemp($v['LowPressure']);
        $cond = $this->SaturationTemp($v['HighPressure']);
        $flow = $v['HPFlowTemp'] ?? $this->Value('FlowTemp');
        $r = fn ($x, $d = 1) => $x === null ? null : round($x, $d);
        return [
            'v'         => array_map(fn ($x) => $r($x, 2), $v),
            'evap'      => $evap,
            'cond'      => $cond,
            // Überhitzung: gemessen, sonst Sauggas − Verdampfungstemperatur
            'sh'        => $r($v['Superheat'] ?? (($v['SuctionGas'] !== null && $evap !== null) ? $v['SuctionGas'] - $evap : null)),
            'ratio'     => ($v['LowPressure'] !== null && $v['HighPressure'] !== null) ? round(($v['HighPressure'] + 1.013) / ($v['LowPressure'] + 1.013), 2) : null,
            'airDelta'  => ($v['AirIntake'] !== null && $evap !== null) ? round($v['AirIntake'] - $evap, 1) : null,
            'condDelta' => ($cond !== null && $flow !== null) ? round($cond - $flow, 1) : null,
            'running'   => ($v['CompressorSpeed'] ?? 0) > 0,
            'defrost'   => @$this->GetIDForIdent('DefrostActive') ? $this->GetValue('DefrostActive') : false,
            'refrigerant' => $this->ReadPropertyString('Refrigerant'),
            'today'     => $this->TodayRun()
        ];
    }

    /**
     * Kältekreis-Verlauf für einen Zeitraum: die letzten 6 h aus den eigenen Minutenwerten, alles andere aus dem Archiv
     * (Anzeige-Variablen werden archiviert; ab 12 h als 5-Minuten-Mittel)
     */
    private function RefriHistory(int $hours, int $end): array
    {
        $now = time();
        $end = ($end <= 0 || $end > $now) ? $now : $end;
        $start = $end - $hours * 3600;
        $live = json_decode($this->ReadAttributeString('RefHist'), true) ?: [];
        $meta = ['hours' => $hours, 'end' => $end, 'start' => $start];
        if ($hours <= 6 && $end >= $now - 60) {
            return ['refriHist' => $live] + $meta + ['src' => 'live', 'locks' => $this->LocksBetween($start, $end)];
        }
        $archives = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (!$archives) {
            return ['refriHist' => array_values(array_filter($live, fn ($r) => $r[0] >= $start && $r[0] <= $end))] + $meta + ['src' => 'live'];
        }
        $archive = $archives[0];
        $span = $hours <= 12 ? 6 : 5; // 6 = 1-Minuten-, 5 = 5-Minuten-Mittel
        $step = $span === 6 ? 60 : 300;
        $series = [];
        foreach (['LowPressure' => 1, 'HighPressure' => 2, 'Superheat' => 3, 'HotGas' => 4, 'SuctionGas' => 5, 'AirIntake' => 6,
                  'CompressorSpeed' => 8, 'FanSpeed' => 9] as $key => $index) {
            $id = @$this->GetIDForIdent('M' . $key);
            if (!$id || !AC_GetLoggingStatus($archive, $id)) {
                continue;
            }
            foreach (@AC_GetAggregatedValues($archive, $id, $span, $start, $end, 0) ?: [] as $row) {
                $t = intdiv((int) $row['TimeStamp'], $step) * $step;
                $series[$t][$index] = round((float) $row['Avg'], 2);
            }
        }
        $defrost = [];
        $dfID = @$this->GetIDForIdent('DefrostActive');
        if ($dfID && AC_GetLoggingStatus($archive, $dfID)) {
            foreach (@AC_GetLoggedValues($archive, $dfID, $start - 6 * 3600, $end, 0) ?: [] as $row) {
                $defrost[] = [(int) $row['TimeStamp'], (bool) $row['Value']];
            }
            usort($defrost, fn ($a, $b) => $a[0] <=> $b[0]);
        }
        ksort($series);
        $rows = [];
        $di = 0;
        $dfState = false;
        foreach ($series as $t => $v) {
            while ($di < count($defrost) && $defrost[$di][0] <= $t) {
                $dfState = $defrost[$di][1];
                $di++;
            }
            $evap = $this->SaturationTemp($v[1] ?? null);
            $sh = $v[3] ?? ((isset($v[5]) && $evap !== null) ? round($v[5] - $evap, 1) : null);
            $rows[] = [$t, $v[1] ?? null, $v[2] ?? null, $sh, $v[4] ?? null, $v[5] ?? null, $v[6] ?? null, $evap, $v[8] ?? null, $v[9] ?? null, $dfState ? 1 : 0];
        }
        return ['refriHist' => $rows] + $meta + ['src' => 'archive', 'step' => $step, 'locks' => $this->LocksBetween($start, $end)];
    }

    /** Minutenwerte für den Verlauf: [Zeit, ND, HD, Überhitzung, Heißgas, Sauggas, Luft, Verdampfung, Verdichter %, Ventilator %, Abtauung] */
    private function RecordRefrigerant(int $now)
    {
        $view = $this->GetRefrigerantView();
        if ($view === null) {
            return;
        }
        $v = $view['v'];
        $history = json_decode($this->ReadAttributeString('RefHist'), true) ?: [];
        $history[] = [$now, $v['LowPressure'], $v['HighPressure'], $view['sh'], $v['HotGas'], $v['SuctionGas'], $v['AirIntake'],
                      $view['evap'], $v['CompressorSpeed'], $v['FanSpeed'], $view['defrost'] ? 1 : 0];
        $history = array_values(array_filter($history, fn ($h) => $h[0] >= $now - 6 * 3600));
        $this->WriteAttributeString('RefHist', json_encode($history));
    }

    // ---------------------------------------------------------------- Heizungssteuerung

    // Tagesauswahl für Zeitprogramme: Schlüssel => [Bezeichnung, Wochentage 1 = Mo … 7 = So]
    private const DAY_SETS = [
        'mo-so' => ['Mo–So', [1, 2, 3, 4, 5, 6, 7]], 'mo-fr' => ['Mo–Fr', [1, 2, 3, 4, 5]], 'sa-so' => ['Sa–So', [6, 7]],
        'mo' => ['Montag', [1]], 'di' => ['Dienstag', [2]], 'mi' => ['Mittwoch', [3]], 'do' => ['Donnerstag', [4]],
        'fr' => ['Freitag', [5]], 'sa' => ['Samstag', [6]], 'so' => ['Sonntag', [7]]
    ];
    private const SEASONS = ['winter' => 'Winter', 'transition' => 'Übergang', 'summer' => 'Sommer'];

    // Zahlen-Einstellungen aus der Visualisierung: Schlüssel => [min, max]
    private const SETTING_LIMITS = [
        'winterBelow' => [-10, 20], 'summerAbove' => [5, 30], 'ecoWinter' => [10, 25], 'ecoTransition' => [10, 25],
        'comfortWinter' => [10, 30], 'comfortTransition' => [10, 30], 'comfortSummer' => [10, 30],
        'dhwComfort' => [30, 70], 'dhwBoost' => [30, 70], 'dhwHyst' => [0, 20], 'optMax' => [0.5, 6], 'hcMax' => [25, 60],
        'cFoot' => [15, 45], 'cDesign' => [20, 65], 'cDesignAT' => [-30, 5], 'cVlMin' => [15, 40], 'cVlMax' => [25, 65],
        'cRoomFactor' => [0, 5], 'pvVlBoost' => [0, 5], 'pvBufMax' => [25, 65],
        'roomHyst' => [0.1, 2], 'roomMin' => [0, 120], 'maxPeak' => [1, 100], 'margin' => [0, 5], 'minHP' => [0, 20], 'hpMax' => [0.5, 30],
        'pvStart' => [0.1, 30], 'pvStop' => [0, 30], 'pvStartMin' => [0, 60], 'pvStopMin' => [0, 60], 'pvMinRun' => [0, 240],
        'pvOffRoom' => [0, 12], 'pvOffDHW' => [0, 30], 'pvOffBuffer' => [0, 30]
    ];

    /** Einstellungen (Visualisierung) – fehlende Werte kommen aus den früheren Instanz-Einstellungen bzw. Vorgaben */
    private $settingsCache = null;
    private function Settings(): array
    {
        if ($this->settingsCache === null) {
            $defaults = [
                'ctlOn' => $this->ReadPropertyBoolean('ControlEnabled'), 'roomsOn' => $this->ReadPropertyBoolean('RoomDemandEnabled'),
                'limitOn' => $this->ReadPropertyBoolean('PowerLimitEnabled'), 'pvOn' => false,
                'dhwComfort' => 50.0, 'dhwBoost' => 58.0, 'dhwHyst' => 0.0, 'optOn' => false, 'optMax' => 3.0, 'hcMaxOn' => false, 'hcMax' => 40.0,
                'curveMode' => 'hoval', 'cLearn' => true, 'cFoot' => 24.0, 'cDesign' => 35.0, 'cDesignAT' => -12.0, 'cVlMin' => 20.0, 'cVlMax' => 45.0,
                'cRoomFactor' => 1.5, 'pvMethod' => 'sg', 'pvVlBoost' => 2.0, 'pvBufMax' => 45.0,
                'seasonMode' => $this->ReadPropertyInteger('SeasonMode'), 'winterBelow' => $this->ReadPropertyFloat('SeasonWinterBelow'),
                'summerAbove' => $this->ReadPropertyFloat('SeasonSummerAbove'), 'ecoWinter' => $this->ReadPropertyFloat('EcoWinter'),
                'ecoTransition' => $this->ReadPropertyFloat('EcoTransition'),
                'comfortWinter' => 21.0, 'comfortTransition' => 21.0, 'comfortSummer' => 21.0,
                'heatPrograms' => json_decode($this->ReadPropertyString('HeatPrograms'), true) ?: [],
                'dhwPrograms' => json_decode($this->ReadPropertyString('DHWPrograms'), true) ?: [],
                'roomHyst' => $this->ReadPropertyFloat('RoomHysteresis'), 'roomMin' => $this->ReadPropertyInteger('RoomMinMinutes'),
                'roomMode' => 'any', 'roomExcluded' => [],
                'maxPeak' => $this->ReadPropertyFloat('MaxPeak15'), 'margin' => $this->ReadPropertyFloat('PowerMargin'),
                'minHP' => $this->ReadPropertyFloat('MinHPPower'), 'hpMax' => $this->ReadPropertyFloat('HPMaxPower'),
                'pvStart' => 1.5, 'pvStop' => 0.5, 'pvStartMin' => 5, 'pvStopMin' => 10, 'pvMinRun' => 30,
                'pvOffRoom' => 2.0, 'pvOffDHW' => 10.0, 'pvOffBuffer' => 0.0, 'monOn' => true
            ];
            $stored = json_decode($this->ReadAttributeString('Settings'), true) ?: [];
            // Warmwassersteuerung war bis 4.3 Teil der Heizungssteuerung: beim ersten Mal deren Zustand übernehmen
            $defaults['dhwOn'] = $stored['ctlOn'] ?? $defaults['ctlOn'];
            $this->settingsCache = $stored + $defaults;
        }
        return $this->settingsCache;
    }

    private function Setting(string $key)
    {
        return $this->Settings()[$key];
    }

    /** Geprüfte Änderungen speichern; liefert eine Fehlermeldung oder null */
    private function SaveSettings(array $changes): ?string
    {
        $settings = $this->Settings();
        foreach ($changes as $key => $value) {
            if (isset(self::SETTING_LIMITS[$key])) {
                [$min, $max] = self::SETTING_LIMITS[$key];
                $settings[$key] = round(max($min, min($max, (float) $value)), 2);
            } elseif (in_array($key, ['ctlOn', 'dhwOn', 'roomsOn', 'limitOn', 'pvOn', 'monOn', 'cLearn', 'optOn', 'hcMaxOn'], true)) {
                $settings[$key] = (bool) $value;
            } elseif ($key === 'seasonMode') {
                $settings[$key] = (int) $value === 0 ? 0 : 1;
            } elseif ($key === 'roomMode') {
                $settings[$key] = $value === 'avg' ? 'avg' : 'any';
            } elseif ($key === 'cInit') {
                $settings[$key] = (bool) $value;
            } elseif ($key === 'curveMode') {
                $settings[$key] = $value === 'symcon' ? 'symcon' : 'hoval';
            } elseif ($key === 'pvMethod') {
                $settings[$key] = $value === 'vl' ? 'vl' : 'sg';
            } elseif ($key === 'roomExcluded') {
                $settings[$key] = array_values(array_map('strval', (array) $value));
            } elseif ($key === 'heatPrograms' || $key === 'dhwPrograms') {
                $rows = [];
                foreach ((array) $value as $row) {
                    $from = (string) ($row['From'] ?? '');
                    $to = (string) ($row['To'] ?? '');
                    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $from) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $to)) {
                        return 'Ungültige Uhrzeit im Zeitprogramm (Format HH:MM).';
                    }
                    $clean = ['Days' => isset(self::DAY_SETS[$row['Days'] ?? '']) ? $row['Days'] : 'mo-so', 'From' => $from, 'To' => $to,
                              'Temp' => round(max(10, min($key === 'heatPrograms' ? 30 : 70, (float) ($row['Temp'] ?? 20))) * 2) / 2];
                    if ($key === 'dhwPrograms') {
                        // Stufe: comfort / boost (Temperatur aus den Warmwasser-Einstellungen) oder custom (eigene Temperatur)
                        $clean['Level'] = in_array($row['Level'] ?? '', ['comfort', 'boost', 'custom'], true) ? $row['Level'] : 'custom';
                    }
                    if ($key === 'heatPrograms') {
                        $clean['Season'] = isset(self::SEASONS[$row['Season'] ?? '']) ? $row['Season'] : 'winter';
                        // Stufe: comfort / eco (Temperatur aus den Saison-Einstellungen) oder custom (eigene Temperatur)
                        $clean['Level'] = in_array($row['Level'] ?? '', ['comfort', 'eco', 'custom'], true) ? $row['Level'] : 'custom';
                    }
                    $rows[] = $clean;
                }
                $settings[$key] = $rows;
            }
        }
        $this->WriteAttributeString('Settings', json_encode($settings));
        $this->settingsCache = null;
        return null;
    }

    private function CheckPassword(?string $password): ?string
    {
        $stored = $this->ReadPropertyString('ControlPassword');
        if ($stored === '') {
            return 'In der Instanz ist kein Passwort hinterlegt.';
        }
        return hash_equals($stored, (string) $password) ? null : 'Passwort falsch.';
    }

    /** Variablen der aktivierten Funktionen anlegen bzw. entfernen */
    private function MaintainControlVariables()
    {
        $limit = (bool) $this->Setting('limitOn');
        $this->MaintainVariable('HeatDemand', 'Heizungsanforderung (Räume)', VARIABLETYPE_BOOLEAN, '~Switch', 260, (bool) $this->Setting('roomsOn'));
        $this->MaintainVariable('AllowedHPPower', 'Erlaubte WP-Leistung', VARIABLETYPE_FLOAT, 'HWP.kW', 261, $limit);
        $this->MaintainVariable('HousePower', 'Leistung Haus ohne WP', VARIABLETYPE_FLOAT, 'HWP.kW', 262, $limit);
        $this->MaintainVariable('Total15', 'Gesamt Ø 15 min (Haus + WP)', VARIABLETYPE_FLOAT, 'HWP.kW', 263, $limit);
        $this->MaintainVariable('Total15PeakToday', 'Spitze 15 min gesamt heute', VARIABLETYPE_FLOAT, 'HWP.kW', 264, $limit);
        $this->MaintainVariable('PVBoost', 'PV-Überschuss aktiv', VARIABLETYPE_BOOLEAN, '~Switch', 265, (bool) $this->Setting('pvOn'));
        if ($limit) {
            foreach (['AllowedHPPower', 'Total15', 'Total15PeakToday'] as $ident) {
                $this->EnableLogging($ident);
            }
        }
        if ($this->Setting('pvOn')) {
            $this->EnableLogging('PVBoost');
        }
    }

    /** Zeitprogramme (aus den Einstellungen) */
    private function Programs(string $key): array
    {
        return $this->Setting($key);
    }

    private function GetCtlState(): array
    {
        return (json_decode($this->ReadAttributeString('CtlState'), true) ?: []) + [
            'mode' => 'auto', 'constTemp' => 21.0, 'season' => 'winter', 'seasonAuto' => 'winter',
            'boost' => null, 'demand' => true, 'demandSince' => 0, 'rooms' => [], 'ctlActive' => false, 'limitActive' => false
        ];
    }

    private function SetCtlState(array $state)
    {
        $this->WriteAttributeString('CtlState', json_encode($state));
    }

    private static function Minutes(string $hhmm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm . ':0'));
        return $h * 60 + $m;
    }

    /** Liegt der Zeitpunkt in einem Zeitfenster (auch über Mitternacht, dann zählt der Starttag)? */
    private static function InWindow(array $row, int $ts): bool
    {
        $days = self::DAY_SETS[$row['Days'] ?? 'mo-so'][1] ?? [1, 2, 3, 4, 5, 6, 7];
        $from = self::Minutes((string) ($row['From'] ?? '00:00'));
        $to = self::Minutes((string) ($row['To'] ?? '00:00'));
        $minute = (int) date('G', $ts) * 60 + (int) date('i', $ts);
        $dow = (int) date('N', $ts);
        $yesterday = $dow === 1 ? 7 : $dow - 1;
        if ($from < $to) {
            return in_array($dow, $days, true) && $minute >= $from && $minute < $to;
        }
        return (in_array($dow, $days, true) && $minute >= $from) || (in_array($yesterday, $days, true) && $minute < $to);
    }

    /** Aktuelle Saison: manuell oder nach gleitendem 24-h-Mittel der Außentemperatur (mit 1 K Hysterese) */
    private function CurrentSeason(array &$state): string
    {
        if ((int) $this->Setting('seasonMode') === 0) {
            return $state['season'];
        }
        $avg = $this->ReadAttributeFloat('TempAvg');
        if ($avg <= -900) {
            return $state['seasonAuto'];
        }
        $winter = (float) $this->Setting('winterBelow');
        $summer = (float) $this->Setting('summerAbove');
        $current = $state['seasonAuto'];
        if ($avg < $winter || ($current === 'winter' && $avg < $winter + 1)) {
            $season = 'winter';
        } elseif ($avg > $summer || ($current === 'summer' && $avg > $summer - 1)) {
            $season = 'summer';
        } else {
            $season = 'transition';
        }
        $state['seasonAuto'] = $season;
        return $season;
    }

    /** Heizprogramm zu einem Zeitpunkt: ['kind' => comfort|eco|off, 'temp' => °C|null] */
    private function HeatProgramAt(string $season, int $ts): array
    {
        $eco = $season === 'summer' ? null : (float) $this->Setting($season === 'winter' ? 'ecoWinter' : 'ecoTransition');
        foreach ($this->Programs('heatPrograms') as $row) {
            if (($row['Season'] ?? '') === $season && self::InWindow($row, $ts)) {
                switch ($row['Level'] ?? 'custom') {
                    case 'comfort':
                        return ['kind' => 'comfort', 'temp' => (float) $this->Setting('comfort' . ucfirst($season))];
                    case 'eco':
                        return $eco === null ? ['kind' => 'off', 'temp' => null] : ['kind' => 'eco', 'temp' => $eco];
                    default:
                        return ['kind' => 'custom', 'temp' => round((float) $row['Temp'], 1)];
                }
            }
        }
        if ($season === 'summer') {
            return ['kind' => 'off', 'temp' => null];
        }
        return ['kind' => 'eco', 'temp' => $eco];
    }

    /** Warmwasserprogramm zu einem Zeitpunkt: null = keine Programme (Hoval regelt), sonst ['kind' => on|off, 'temp'] */
    private function DHWProgramAt(int $ts): ?array
    {
        $rows = $this->Programs('dhwPrograms');
        if (count($rows) === 0) {
            return null;
        }
        foreach ($rows as $row) {
            if (self::InWindow($row, $ts)) {
                $level = $row['Level'] ?? 'custom';
                $temp = $level === 'comfort' ? (float) $this->Setting('dhwComfort') : ($level === 'boost' ? (float) $this->Setting('dhwBoost') : (float) $row['Temp']);
                return ['kind' => $level === 'boost' ? 'boost' : 'on', 'level' => $level, 'temp' => min($temp, $this->ReadAttributeFloat('DHWMax'))];
            }
        }
        return ['kind' => 'off', 'temp' => null];
    }

    /** Nächster Wechsel eines Programms (Suche in 15-min-Schritten, max. 2 Tage) */
    private function NextChange(callable $at, int $now): ?int
    {
        $current = $at($now);
        for ($ts = intdiv($now, 900) * 900 + 900; $ts <= $now + 2 * 86400; $ts += 900) {
            if ($at($ts) != $current) {
                return $ts;
            }
        }
        return null;
    }

    /** Wert in eine Schreib-Variable schreiben – nur bei Änderung oder zur Auffrischung (Standard: alle 30 min) */
    private function WriteTo(string $property, float $value, float $tolerance = 0.01, int $refresh = 1800, ?string $readKey = null): void
    {
        $id = $this->ReadPropertyInteger($property);
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return;
        }
        $writes = json_decode($this->ReadAttributeString('CtlWrites'), true) ?: [];
        $last = $writes[$property] ?? null;
        // Rückkontrolle: zeigt der gelesene Hoval-Wert (z.B. 1478 Betriebswahl) etwas anderes, wurde die Vorgabe nicht
        // übernommen oder am Bedienteil verstellt → nach 90 s erneut schreiben (nach 3 Versuchen nur noch alle 30 min)
        $read = $readKey !== null ? $this->Value($readKey) : null;
        $mismatch = $read !== null && abs($read - $value) > max($tolerance, 0.05);
        $tries = $last[2] ?? 0;
        if ($last !== null && abs($last[0] - $value) < $tolerance) {
            $age = time() - $last[1];
            if (!$mismatch && $age < $refresh) {
                if ($tries > 0) {
                    $writes[$property][2] = 0; // übernommen
                    $this->WriteAttributeString('CtlWrites', json_encode($writes));
                }
                return;
            }
            if ($mismatch && $age < ($tries >= 3 ? 1800 : 90)) {
                return;
            }
        } else {
            $tries = 0;
        }
        if ($mismatch) {
            $tries++;
            $this->SendDebug('Rückkontrolle', $property . ': gelesen ' . $read . ', soll ' . $value . ' (Versuch ' . $tries . ')', 0);
        }
        $ok = @RequestAction($id, $value);
        $this->SendDebug('Schreiben', $property . ' = ' . $value . ($ok === false ? ' (fehlgeschlagen)' : ''), 0);
        if ($ok === false) {
            $this->LogMessage('Schreiben fehlgeschlagen: ' . $property . ' = ' . $value . ' (Variable ' . $id . ')', KL_WARNING);
        }
        $writes[$property] = [$value, time(), $tries];
        $this->WriteAttributeString('CtlWrites', json_encode($writes));
    }

    /**
     * Raumanforderung. Verfahren "any": Anforderung, sobald ein berücksichtigter Raum unter Soll − Hysterese liegt;
     * "avg": wenn der Durchschnitt der berücksichtigten Räume unter dem Durchschnitts-Soll − Hysterese liegt.
     * Ende jeweils erst über Soll + Hysterese; Mindestzeit zwischen zwei Wechseln.
     */
    private function EvaluateRooms(array &$state, ?float $programTemp, int $now): bool
    {
        $hyst = (float) $this->Setting('roomHyst');
        $excluded = $this->Setting('roomExcluded');
        $any = false;
        $rooms = [];
        $istSum = $sollSum = 0.0;
        $count = 0;
        foreach (json_decode($this->ReadPropertyString('Rooms'), true) ?: [] as $row) {
            $istID = (int) ($row['IstID'] ?? 0);
            if ($istID <= 0 || !IPS_VariableExists($istID)) {
                continue;
            }
            $name = (string) ($row['Name'] ?? ('Raum ' . $istID));
            $sollID = (int) ($row['SollID'] ?? 0);
            $soll = ($sollID > 0 && IPS_VariableExists($sollID)) ? (float) GetValue($sollID) : $programTemp;
            $ist = (float) GetValue($istID);
            $active = !in_array($name, $excluded, true);
            $was = $state['rooms'][$name] ?? false;
            $demand = false;
            if ($soll !== null) {
                $soll += (float) ($row['Offset'] ?? 0);
                $demand = $active && ($was ? $ist < $soll + $hyst : $ist < $soll - $hyst);
                if ($active) {
                    $istSum += $ist;
                    $sollSum += $soll;
                    $count++;
                }
            }
            $state['rooms'][$name] = $demand;
            $rooms[] = ['name' => $name, 'ist' => round($ist, 1), 'soll' => $soll === null ? null : round($soll, 1),
                        'demand' => $demand, 'active' => $active];
            $any = $any || $demand;
        }
        $avgIst = $count > 0 ? $istSum / $count : null;
        $avgSoll = $count > 0 ? $sollSum / $count : null;
        $avgDemand = $state['avgDemand'] ?? false;
        if ($avgIst !== null) {
            $avgDemand = $avgDemand ? $avgIst < $avgSoll + $hyst : $avgIst < $avgSoll - $hyst;
        }
        $state['avgDemand'] = $avgDemand;
        $state['roomList'] = $rooms;
        $state['roomStats'] = [
            'avgIst' => $avgIst === null ? null : round($avgIst, 2), 'avgSoll' => $avgSoll === null ? null : round($avgSoll, 2),
            'dev' => ($avgIst !== null && $avgSoll > 0) ? round(($avgIst - $avgSoll) / $avgSoll * 100, 1) : null,
            'count' => $count, 'anyDemand' => $any, 'avgDemand' => $avgDemand
        ];
        $wanted = $this->Setting('roomMode') === 'avg' ? $avgDemand : $any;
        // Mindestzeit zwischen Wechseln
        if ($wanted !== $state['demand'] && $now - $state['demandSince'] >= (int) $this->Setting('roomMin') * 60) {
            $state['demand'] = $wanted;
            $state['demandSince'] = $now;
        }
        return $state['demand'];
    }

    /** Läuft jede Minute und nach jeder Bedienung: Programme auswerten und in die Wärmepumpe schreiben */
    private function ControlTick(int $now)
    {
        $control = (bool) $this->Setting('ctlOn');
        $rooms = (bool) $this->Setting('roomsOn');
        $state = $this->GetCtlState();

        // gleitendes 24-h-Mittel der Außentemperatur (für die automatische Saison) und 3-h-Mittel (Heizkurve)
        $outdoor = $this->Value('OutdoorTemp');
        if ($outdoor !== null) {
            $avg = $this->ReadAttributeFloat('TempAvg');
            $this->WriteAttributeFloat('TempAvg', $avg <= -900 ? $outdoor : $avg + ($outdoor - $avg) / 1440);
            $avg3 = $this->ReadAttributeFloat('OutAvg3h');
            $this->WriteAttributeFloat('OutAvg3h', $avg3 <= -900 ? $outdoor : $avg3 + ($outdoor - $avg3) / 180);
        }

        $season = $this->CurrentSeason($state);
        $program = $this->HeatProgramAt($season, $now);
        // Aufheizoptimierung: vor einem Wechsel auf eine höhere Temperatur früher beginnen, damit sie zur Startzeit erreicht ist
        $preheat = $this->Preheat($state, $season, $program, $now);
        if ($preheat !== null) {
            $program = $preheat;
        }
        $target = $state['mode'] === 'const' ? (float) $state['constTemp'] : $program['temp'];

        // Räume immer auswerten (Durchschnitt/Abweichung in der Kachel), wirksam nur bei aktiver Raumanforderung
        $roomDemand = $this->EvaluateRooms($state, $target, $now);
        $state['roomTarget'] = $target;
        $demand = true;
        if ($rooms) {
            $demand = $roomDemand;
            if (@$this->GetIDForIdent('HeatDemand') && $this->GetValue('HeatDemand') !== $demand) {
                $this->SetValue('HeatDemand', $demand);
            }
        }

        $dhwControl = (bool) $this->Setting('dhwOn');
        // Urlaub: Heizung auf Absenkung (bzw. Frostschutz), Warmwasser aus bzw. niedrig
        $holiday = $this->HolidayActive($state, $now);
        $holidayFrost = false;
        if ($holiday !== null) {
            $season = $this->CurrentSeason($state);
            if ($holiday['heat'] === 'frost' || $season === 'summer') {
                $holidayFrost = true;
            } else {
                $target = (float) $this->Setting($season === 'winter' ? 'ecoWinter' : 'ecoTransition');
            }
        }
        if ($control) {
            // ---- Heizkreis 1 ----
            if ($holidayFrost || ($holiday === null && ($state['mode'] === 'frost' || ($state['mode'] === 'auto' && $program['kind'] === 'off'))) || !$demand) {
                $this->WriteTo('WriteHC1Mode', 0, 0.01, 1800, 'HC1Mode'); // Standby = Frostschutz
            } else {
                // Rückkontrolle der Raumtemperatur über 1493 nur ohne PV-Offset (der hebt den Hoval-Sollwert an)
                $this->WriteTo('WriteHC1Room', max(10, min(30, $target)), 0.01, 1800, empty($state['pvActive']) ? 'RoomSetpoint' : null);
                $this->WriteTo('WriteHC1Mode', 4, 0.01, 1800, 'HC1Mode'); // Konstant mit Normal-Raumtemperatur
            }
            $heatingNow = !($holidayFrost || ($holiday === null && ($state['mode'] === 'frost' || ($state['mode'] === 'auto' && $program['kind'] === 'off'))) || !$demand);
            // Sollwertwechsel merken (Lernpause, weil die Räume dem neuen Soll erst folgen)
            if (abs(($state['lastTarget'] ?? $target) - $target) > 0.2) {
                $state['setChange'] = $now;
            }
            $state['lastTarget'] = $target;
            $this->HeatUpLearn($state, $now, $heatingNow && $holiday === null ? $target : null);
            $this->CurveControl($state, $now, $heatingNow, $target, $holiday !== null);
            $state['ctlActive'] = true;
        } elseif ($rooms) {
            // nur Raumanforderung: zwischen Hoval-Programm und Standby umschalten
            $this->WriteTo('WriteHC1Mode', $demand ? 1 : 0, 0.01, 1800, 'HC1Mode');
            $state['roomsActive'] = true;
        }
        if ($dhwControl) {
            // ---- Warmwasser (eigenständig aktivierbar) ----
            $boost = $state['boost'];
            if ($boost && $boost['until'] <= $now) {
                $state['boost'] = $boost = null;
                $state['dhwReturn'] = true;                          // nach dem Aufheizen an Programm/Hoval zurückgeben
            }
            $dhw = $this->DHWProgramAt($now);
            if ($holiday !== null) {
                $state['boost'] = $boost = null;
                if ($holiday['dhw'] === 'low') {
                    $this->WriteTo('WriteDHWTemp', 40);
                    $this->WriteTo('WriteDHWMode', 4, 0.01, 1800, 'DHWMode');
                } else {
                    $this->WriteTo('WriteDHWMode', 0, 0.01, 1800, 'DHWMode'); // Standby (Frostschutz bleibt aktiv)
                }
                $state['dhwReturn'] = true;
            } elseif ($boost) {
                $state['dhwCharge'] = null;
                $this->WriteTo('WriteDHWTemp', min($boost['temp'], $this->ReadAttributeFloat('DHWMax')));
                $this->WriteTo('WriteDHWMode', 4, 0.01, 1800, 'DHWMode');                    // Dauernd Normalbetrieb
            } elseif ($dhw !== null) {
                if ($dhw['kind'] === 'on' && (float) $this->Setting('dhwHyst') > 0 && $this->Value('StorageTop') !== null) {
                    $this->DhwHysteresis($state, $dhw, $now);
                } elseif ($dhw['kind'] !== 'off') {
                    $state['dhwCharge'] = null;
                    $this->WriteTo('WriteDHWTemp', $dhw['temp']);
                    $this->WriteTo('WriteDHWMode', 4, 0.01, 1800, 'DHWMode');
                } else {
                    $state['dhwCharge'] = null;
                    $this->WriteTo('WriteDHWMode', 0, 0.01, 1800, 'DHWMode');                // Standby außerhalb der Ladezeiten
                }
            } elseif (!empty($state['dhwReturn'])) {
                $this->WriteTo('WriteDHWMode', 1);                    // keine Programme: zurück an Hoval Woche 1
            }
            $state['dhwReturn'] = false;
            $state['dhwActive'] = true;
        }
        if (!$control && !empty($state['curveActive'])) {
            $this->CurveControl($state, $now, false, 20.0, false); // Heizungssteuerung aus: Hoval-Regelstrategie wiederherstellen
        }
        if (!$control && !empty($state['ctlActive'])) {
            // Heizungssteuerung wurde deaktiviert: Heizkreis an Hoval zurückgeben (falls keine Raumanforderung)
            if (!$rooms) {
                $this->WriteTo('WriteHC1Mode', 1, 0.01, 0);
            }
            if (!isset($state['dhwActive'])) {
                $this->WriteTo('WriteDHWMode', 1, 0.01, 0); // Stand vor der Trennung: Warmwasser hing an der Heizungssteuerung
            }
            $state['ctlActive'] = false;
        }
        if (!$dhwControl && !empty($state['dhwActive'])) {
            // Warmwassersteuerung wurde deaktiviert: Warmwasser an Hoval Woche 1
            $this->WriteTo('WriteDHWMode', 1, 0.01, 0);
            $state['dhwActive'] = false;
            $state['boost'] = null;
        }
        if (!$control && !$rooms && !empty($state['roomsActive'])) {
            // Raumanforderung wurde deaktiviert: Heizkreis wieder an Hoval Woche 1
            $this->WriteTo('WriteHC1Mode', 1, 0.01, 0);
            $state['roomsActive'] = false;
        }

        $this->FlowMaxTick($state);
        $this->SmartGridTick($state, $now);
        $this->SetCtlState($state);
    }

    /**
     * Warmwasser-Hysterese (Symcon): Innerhalb einer Ladezeit bleibt Warmwasser auf Standby, bis der Speicher oben unter
     * Soll − Hysterese fällt; dann wird bis zum Soll geladen und wieder auf Standby gestellt. Startet Hoval die Ladung nicht
     * (eigene Schaltdifferenz größer), wird der Hoval-Sollwert nach 20 min um die Hysterese angehoben – Symcon beendet die
     * Ladung trotzdem beim eingestellten Soll. Sicherheitsende nach 4 h.
     */
    private function DhwHysteresis(array &$state, array $dhw, int $now)
    {
        $soll = (float) $dhw['temp'];
        $hyst = (float) $this->Setting('dhwHyst');
        $top = (float) $this->Value('StorageTop');
        $charge = $state['dhwCharge'] ?? null;
        if ($charge !== null && abs(($charge['temp'] ?? $soll) - $soll) > 0.1) {
            $charge['temp'] = $soll; // Stufe gewechselt: Ladung mit neuem Soll fortsetzen
        }
        if ($charge === null && $top <= $soll - $hyst) {
            $charge = ['since' => $now, 'temp' => $soll, 't0' => round($top, 1), 'kick' => false];
        }
        if ($charge !== null) {
            $loading = $this->GetValvePosition() === 'ww';
            if ($loading) {
                $charge['seen'] = true;
            }
            $done = $top >= $soll - 0.3 || $now - $charge['since'] > 4 * 3600
                || (!empty($charge['seen']) && !$loading && $now - $charge['since'] > 600 && $top >= $soll - 1.5);
            if ($done) {
                $state['dhwLast'] = ['ts' => $now, 't0' => $charge['t0'], 't1' => round($top, 1), 'min' => (int) round(($now - $charge['since']) / 60)];
                $charge = null;
            }
        }
        if ($charge !== null) {
            if (empty($charge['seen']) && !$charge['kick'] && $now - $charge['since'] >= 1200) {
                $charge['kick'] = true; // Hoval lädt nicht von selbst: Sollwert anheben
            }
            $set = $charge['kick'] ? min($this->ReadAttributeFloat('DHWMax'), $soll + $hyst) : $soll;
            $this->WriteTo('WriteDHWTemp', $set);
            $this->WriteTo('WriteDHWMode', 4, 0.01, 1800, 'DHWMode');
        } else {
            $this->WriteTo('WriteDHWTemp', $soll);
            $this->WriteTo('WriteDHWMode', 0, 0.01, 1800, 'DHWMode'); // warten, bis Soll − Hysterese unterschritten ist
        }
        $state['dhwCharge'] = $charge;
    }

    /** Maximaltemperatur Heizkreis: Hoval-Parameter 30690 schreiben (Originalwert wird gesichert und beim Ausschalten zurückgeschrieben) */
    private function FlowMaxTick(array &$state)
    {
        if ($this->ReadPropertyInteger('WriteFlowMax') <= 0) {
            return;
        }
        if ($this->Setting('hcMaxOn')) {
            $orig = json_decode($this->ReadAttributeString('HovalOriginal'), true) ?: [];
            if (!array_key_exists('vlMax', $orig) && $this->Value('CurveVlMax') !== null) {
                $orig['vlMax'] = $this->Value('CurveVlMax');
                $orig['ts'] = $orig['ts'] ?? time();
                $this->WriteAttributeString('HovalOriginal', json_encode($orig));
            }
            $this->WriteTo('WriteFlowMax', (float) $this->Setting('hcMax'), 0.05, 86400, 'CurveVlMax');
            $state['flowMaxActive'] = true;
        } elseif (!empty($state['flowMaxActive'])) {
            $orig = json_decode($this->ReadAttributeString('HovalOriginal'), true) ?: [];
            if (($orig['vlMax'] ?? null) !== null) {
                $this->WriteTo('WriteFlowMax', (float) $orig['vlMax'], 0.05, 0);
            }
            $state['flowMaxActive'] = false;
        }
    }

    // ---------------------------------------------------------------- Aufheizoptimierung

    private function HeatRateState(): array
    {
        return (json_decode($this->ReadAttributeString('HeatRate'), true) ?: []) + ['bins' => [], 'log' => []];
    }

    /** Gelernte Aufheizgeschwindigkeit (K/h) bei einer Außentemperatur: eigener 5-K-Bereich, sonst nächster gelernter, sonst 0,5 K/h */
    private function HeatRateAt(?float $out): array
    {
        $bins = $this->HeatRateState()['bins'];
        $key = (int) (round(max(-20, min(20, $out ?? 0)) / 5) * 5);
        if (isset($bins[$key])) {
            return ['rate' => $bins[$key][0], 'learned' => true, 'n' => $bins[$key][1]];
        }
        $best = null;
        foreach ($bins as $k => $v) {
            if ($best === null || abs($k - $key) < abs($best - $key)) {
                $best = $k;
            }
        }
        if ($best !== null) {
            // Nachbarbereich: je 5 K kälter etwas langsamer
            $rate = $bins[$best][0] * max(0.5, min(1.5, 1 + ($key - $best) * 0.03));
            return ['rate' => round($rate, 2), 'learned' => true, 'n' => 0];
        }
        return ['rate' => 0.5, 'learned' => false, 'n' => 0];
    }

    /**
     * Steht innerhalb der max. Vorlaufzeit ein Wechsel auf eine höhere Temperatur an (z.B. Absenkung → Komfort um 6:00),
     * wird so früh begonnen, dass die Räume zur Startzeit warm sind: Vorlauf = (neues Soll − Raum Ø) ÷ gelernte K/h.
     * Einmal begonnen, bleibt das Vorheizen bis zum Wechsel aktiv.
     */
    private function Preheat(array &$state, string $season, array $program, int $now): ?array
    {
        $plan = null;
        $active = $state['preheat'] ?? null;
        $h = $state['holiday'] ?? null;
        $holiday = $h && $h['from'] <= $now && $h['until'] > $now;
        if ($this->Setting('optOn') && $state['mode'] === 'auto' && !$holiday && $program['kind'] !== 'off') {
            $maxLead = (float) $this->Setting('optMax') * 3600;
            $next = $this->NextChange(fn ($ts) => $this->HeatProgramAt($season, $ts), $now);
            if ($next !== null && $next - $now <= $maxLead + 900) {
                $nextProg = $this->HeatProgramAt($season, $next);
                if ($nextProg['temp'] !== null && $nextProg['temp'] > (float) $program['temp'] + 0.2) {
                    $ist = $state['roomStats']['avgIst'] ?? null;
                    // Raum-Offsets/eigene Soll-Variablen: Abstand Ø Raum-Soll ↔ Programmtemperatur beibehalten
                    $off = ($state['roomStats']['avgSoll'] ?? null) !== null && ($state['roomTarget'] ?? null) !== null
                        ? max(-3, min(3, $state['roomStats']['avgSoll'] - $state['roomTarget'])) : 0;
                    $out = $this->OutdoorDamped();
                    $r = $this->HeatRateAt($out);
                    $need = $ist !== null ? max(0, $nextProg['temp'] + $off - 0.2 - $ist) : $nextProg['temp'] - $program['temp'];
                    $lead = min($maxLead, $need / max(0.1, $r['rate']) * 3600);
                    $plan = ['next' => $next, 'start' => $next - (int) round($lead), 'temp' => $nextProg['temp'], 'from' => $program['temp'],
                             'rate' => $r['rate'], 'learned' => $r['learned'], 'ist' => $ist, 'out' => $out === null ? null : round($out, 1)];
                    if ($active && $active['next'] === $next) {
                        $plan['start'] = $active['start']; // läuft bereits
                    }
                }
            }
        }
        $state['preheatPlan'] = $plan;
        if ($plan !== null && $now >= $plan['start']) {
            $state['preheat'] = ['next' => $plan['next'], 'start' => min($plan['start'], $now)];
            return ['kind' => 'preheat', 'temp' => $plan['temp']];
        }
        $state['preheat'] = null;
        return null;
    }

    /**
     * Lernen der Aufheizgeschwindigkeit: bei jedem Anstieg des Raum-Solls um ≥ 0,5 K (Vorheizen oder Programmwechsel) wird
     * gemessen, wie lange der Raum-Durchschnitt bis Soll − 0,2 K braucht. K/h je 5-K-Außentemperaturbereich, gleitend gemittelt.
     */
    private function HeatUpLearn(array &$state, int $now, ?float $target)
    {
        $ist = $state['roomStats']['avgIst'] ?? null;
        $soll = $state['roomStats']['avgSoll'] ?? null;
        $run = $state['heatUp'] ?? null;
        $last = $state['heatUpTarget'] ?? null;
        $state['heatUpTarget'] = $target;
        if ($ist === null || $soll === null || $target === null) {
            $state['heatUp'] = null;
            return;
        }
        if ($run !== null && abs($run['tgt'] - $target) > 0.2) {
            $run = null; // Soll geändert: Messung verwerfen
        }
        if ($run === null && $last !== null && $target >= $last + 0.5 && $ist < $soll - 0.5) {
            $out = $this->OutdoorDamped();
            $run = ['ts' => $now, 'ist' => round($ist, 2), 'tgt' => $target, 'out' => $out === null ? null : round($out, 1),
                    'due' => ($state['preheat']['next'] ?? null) ?: $now, 'pre' => !empty($state['preheat'])];
        }
        if ($run !== null) {
            $hours = ($now - $run['ts']) / 3600;
            $reached = $ist >= $soll - 0.2;
            if ($reached || $hours > 10) {
                $rise = ($reached ? $soll - 0.2 : $ist) - $run['ist'];
                if ($hours >= 0.25 && $rise >= 0.3) {
                    $rate = max(0.05, min(3, $rise / $hours));
                    $learn = $this->HeatRateState();
                    $key = (int) (round(max(-20, min(20, $run['out'] ?? 0)) / 5) * 5);
                    $old = $learn['bins'][$key] ?? null;
                    $learn['bins'][$key] = $old ? [round($old[0] * 0.7 + $rate * 0.3, 3), min(99, $old[1] + 1)] : [round($rate, 3), 1];
                    $learn['log'][] = ['ts' => $now, 'start' => $run['ts'], 'from' => $run['ist'], 'to' => round($soll, 2), 'out' => $run['out'],
                                       'rate' => round($rate, 2), 'late' => $reached ? (int) round(($now - $run['due']) / 60) : null, 'pre' => $run['pre']];
                    $learn['log'] = array_slice($learn['log'], -12);
                    ksort($learn['bins']);
                    $this->WriteAttributeString('HeatRate', json_encode($learn));
                }
                $run = null;
            }
        }
        $state['heatUp'] = $run;
    }

    /** Urlaub aktiv? Abgelaufenen Urlaub beenden (mit Benachrichtigung); geplanter Urlaub ist noch nicht aktiv */
    private function HolidayActive(array &$state, int $now): ?array
    {
        $h = $state['holiday'] ?? null;
        if (!$h) {
            return null;
        }
        if ($h['until'] <= $now) {
            $state['holiday'] = null;
            $this->Notify('holiday', 'Wärmepumpe', 'Urlaubsmodus beendet – Heizung und Warmwasser laufen wieder normal.', 'control');
            return null;
        }
        return $h['from'] <= $now ? $h : null;
    }

    /** Bedienung aus der Kachel; liefert ['ok' => bool, 'msg' => Text] */
    private function HandleControlAction(array $action): array
    {
        $a = $action['a'] ?? '';
        $fmt = fn ($v) => number_format((float) $v, 1, ',', '');

        // ---- passwortgeschützt: Entsperren, Funktionen, Einstellungen, Räume, max. Warmwassertemperatur ----
        if (in_array($a, ['unlock', 'feature', 'settings', 'room', 'maxTemp', 'curveMode', 'curveBackup', 'curveRestore', 'curveLearnReset'], true)) {
            $error = $this->CheckPassword($action['pw'] ?? '');
            if ($error !== null) {
                return ['ok' => false, 'msg' => $error, 'locked' => true];
            }
            switch ($a) {
                case 'unlock':
                    return ['ok' => true, 'msg' => 'Entsperrt', 'unlocked' => true];
                case 'feature':
                    $names = ['ctlOn' => 'Heizungssteuerung', 'dhwOn' => 'Warmwassersteuerung', 'roomsOn' => 'Raumanforderung', 'limitOn' => 'Leistungsbegrenzung', 'pvOn' => 'PV-Überschuss'];
                    $key = $action['f'] ?? '';
                    if (!isset($names[$key])) {
                        return ['ok' => false, 'msg' => 'Unbekannte Funktion'];
                    }
                    $this->SaveSettings([$key => !empty($action['v'])]);
                    $this->MaintainControlVariables();
                    return ['ok' => true, 'msg' => $names[$key] . (!empty($action['v']) ? ' aktiviert' : ' deaktiviert')];
                case 'settings':
                    $error = $this->SaveSettings(array_intersect_key((array) ($action['values'] ?? []),
                        self::SETTING_LIMITS + array_flip(['seasonMode', 'roomMode', 'heatPrograms', 'dhwPrograms', 'pvMethod', 'cLearn', 'optOn', 'hcMaxOn'])));
                    return $error === null ? ['ok' => true, 'msg' => 'Einstellungen gespeichert'] : ['ok' => false, 'msg' => $error];
                case 'room':
                    $excluded = array_values(array_diff($this->Setting('roomExcluded'), [(string) ($action['name'] ?? '')]));
                    if (empty($action['v'])) {
                        $excluded[] = (string) $action['name'];
                    }
                    $this->SaveSettings(['roomExcluded' => $excluded]);
                    return ['ok' => true, 'msg' => ($action['name'] ?? '') . (empty($action['v']) ? ' wird nicht berücksichtigt' : ' wird berücksichtigt')];
                case 'curveMode':
                    if (($action['v'] ?? '') === 'symcon') {
                        if (!$this->Setting('ctlOn')) {
                            return ['ok' => false, 'msg' => 'Zuerst die Heizungssteuerung aktivieren.'];
                        }
                        if ($this->ReadPropertyInteger('WriteStrategy') <= 0 || $this->ReadPropertyInteger('WriteFlowConst') <= 0) {
                            return ['ok' => false, 'msg' => 'In der Instanz die Schreib-Variablen für 27510 und 1490 eintragen.'];
                        }
                        $error = $this->BackupHoval();
                        if ($error !== null) {
                            return ['ok' => false, 'msg' => $error];
                        }
                        if (empty($this->Settings()['cInit'])) {
                            $this->InitCurveFromHoval();
                            $this->SaveSettings(['cInit' => true]);
                        }
                        $this->SaveSettings(['curveMode' => 'symcon']);
                        return ['ok' => true, 'msg' => 'Heizkurve: Symcon (selbstlernend) – Hoval-Werte sind gesichert'];
                    }
                    $this->SaveSettings(['curveMode' => 'hoval']);
                    return ['ok' => true, 'msg' => 'Heizkurve: Hoval'];
                case 'curveBackup':
                    $error = $this->BackupHoval(true);
                    return $error === null ? ['ok' => true, 'msg' => 'Hoval-Werte gesichert'] : ['ok' => false, 'msg' => $error];
                case 'curveRestore':
                    return ['ok' => true, 'msg' => $this->RestoreHoval()];
                case 'curveLearnReset':
                    $this->WriteAttributeString('CurveLearn', '');
                    return ['ok' => true, 'msg' => 'Lernwerte zurückgesetzt'];
                case 'maxTemp':
                    $value = round(max(30, min(70, (float) ($action['v'] ?? 55))) * 2) / 2;
                    $this->WriteAttributeFloat('DHWMax', $value);
                    return ['ok' => true, 'msg' => 'Max. Warmwassertemperatur: ' . $fmt($value) . ' °C'];
            }
        }

        // ---- Urlaub (ohne Passwort), braucht Heizungs- oder Warmwassersteuerung ----
        if ($a === 'holiday' || $a === 'holidayEnd') {
            if (!$this->Setting('ctlOn') && !$this->Setting('dhwOn')) {
                return ['ok' => false, 'msg' => 'Für den Urlaubsmodus Heizungs- oder Warmwassersteuerung aktivieren.'];
            }
            $state = $this->GetCtlState();
            if ($a === 'holidayEnd') {
                $state['holiday'] = null;
                $this->SetCtlState($state);
                return ['ok' => true, 'msg' => 'Urlaubsmodus beendet'];
            }
            $from = max(time() - 60, (int) ($action['from'] ?? time()));
            $until = (int) ($action['until'] ?? 0);
            if ($until <= $from + 600 || $until > time() + 120 * 86400) {
                return ['ok' => false, 'msg' => 'Bitte ein gültiges Ende (max. 120 Tage) wählen.'];
            }
            $state['holiday'] = ['from' => $from, 'until' => $until, 'heat' => ($action['heat'] ?? 'eco') === 'frost' ? 'frost' : 'eco',
                                 'dhw' => ($action['dhw'] ?? 'off') === 'low' ? 'low' : 'off'];
            $this->SetCtlState($state);
            return ['ok' => true, 'msg' => 'Urlaub bis ' . date('d.m. H:i', $until) . ' eingestellt'];
        }

        // ---- tägliche Bedienung (ohne Passwort): Warmwasser nur bei aktiver Warmwassersteuerung, sonst Heizungssteuerung ----
        if (in_array($a, ['boost', 'boostStop'], true)) {
            if (!$this->Setting('dhwOn')) {
                return ['ok' => false, 'msg' => 'Warmwassersteuerung ist nicht aktiviert.'];
            }
        } elseif (!$this->Setting('ctlOn')) {
            return ['ok' => false, 'msg' => 'Heizungssteuerung ist nicht aktiviert.'];
        }
        $state = $this->GetCtlState();
        $msg = '';
        switch ($a) {
            case 'mode':
                if (in_array($action['v'] ?? '', ['auto', 'frost', 'const'], true)) {
                    $state['mode'] = $action['v'];
                    $msg = ['auto' => 'Automatik (Zeitprogramm)', 'frost' => 'Frostschutz', 'const' => 'Dauerbetrieb'][$state['mode']];
                }
                break;
            case 'const':
                $state['constTemp'] = round(max(10, min(30, (float) ($action['v'] ?? 21))) * 2) / 2;
                $state['mode'] = 'const';
                $msg = 'Dauerbetrieb ' . $fmt($state['constTemp']) . ' °C';
                break;
            case 'temps': // Komfort-/Absenktemperatur einer Saison (z.B. aus dem Anlagenschema)
                $season = isset(self::SEASONS[$action['season'] ?? '']) ? $action['season'] : 'winter';
                $changes = [];
                if (isset($action['comfort'])) {
                    $changes['comfort' . ucfirst($season)] = round((float) $action['comfort'] * 2) / 2;
                }
                if (isset($action['eco']) && $season !== 'summer') {
                    $changes['eco' . ucfirst($season)] = round((float) $action['eco'] * 2) / 2;
                }
                $this->SaveSettings($changes);
                $msg = self::SEASONS[$season] . ': Komfort ' . $fmt($this->Setting('comfort' . ucfirst($season)))
                    . ($season !== 'summer' ? ' °C · Absenkung ' . $fmt($this->Setting('eco' . ucfirst($season))) : '') . ' °C';
                break;
            case 'season':
                if (isset(self::SEASONS[$action['v'] ?? ''])) {
                    $state['season'] = $action['v'];
                    $msg = 'Saison: ' . self::SEASONS[$state['season']];
                }
                break;
            case 'boost':
                $max = $this->ReadAttributeFloat('DHWMax');
                $hours = max(0.5, min(12, (float) ($action['h'] ?? 2)));
                $temp = round(max(30, min($max, (float) ($action['t'] ?? $max))) * 2) / 2;
                $state['boost'] = ['until' => time() + (int) round($hours * 3600), 'temp' => $temp];
                $msg = 'Warmwasser ' . $fmt($hours) . ' h auf ' . $fmt($temp) . ' °C';
                break;
            case 'boostStop':
                $state['boost'] = null;
                $state['dhwReturn'] = true;
                $msg = 'Aufheizen beendet';
                break;
            default:
                return ['ok' => false, 'msg' => 'Unbekannte Aktion'];
        }
        $this->SetCtlState($state);
        return ['ok' => true, 'msg' => $msg];
    }

    // ---------------------------------------------------------------- Leistungsbegrenzung

    /** Hausleistung in kW ohne Wärmepumpe */
    private function HouseRestKW(): ?float
    {
        $id = $this->ReadPropertyInteger('HousePowerID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $kW = (float) GetValue($id) / ($this->ReadPropertyInteger('HousePowerUnit') === 0 ? 1000 : 1);
        if ($this->ReadPropertyBoolean('HouseIncludesHP')) {
            $kW -= $this->MeasuredPowerKW() ?? $this->Value('ElecPower') ?? 0;
        }
        return max(0, $kW);
    }

    /** Hausenergie der laufenden Viertelstunde aufsummieren (Leistung × Zeit) */
    private function IntegrateHouse(int $now)
    {
        if (!$this->Setting('limitOn')) {
            return;
        }
        $state = json_decode($this->ReadAttributeString('HouseState'), true) ?: ['last' => 0, 'p' => 0];
        $dt = ($state['last'] > 0 && $now > $state['last'] && $now - $state['last'] <= 15 * 60) ? $now - $state['last'] : 0;
        if ($dt > 0) {
            $this->WriteAttributeFloat('QHouseEnergy', $this->ReadAttributeFloat('QHouseEnergy') + $state['p'] * $dt / 3600);
        }
        $this->WriteAttributeString('HouseState', json_encode(['last' => $now, 'p' => $this->HouseRestKW() ?? 0]));
    }

    /**
     * 15-min-Spitze begrenzen: Budget der Viertelstunde = max. Spitze × 0,25 h. Was davon noch übrig ist, darf auf die
     * Restzeit verteilt werden; davon geht die aktuelle Hausleistung ab – der Rest ist der WP erlaubt.
     */
    private function LimitCalculation(int $now): ?array
    {
        $house = $this->HouseRestKW();
        if ($house === null) {
            return null;
        }
        $start = $this->ReadAttributeInteger('QStart') ?: intdiv($now, 900) * 900;
        $max = (float) $this->Setting('maxPeak') - (float) $this->Setting('margin');
        $used = $this->ReadAttributeFloat('QEnergy') + $this->ReadAttributeFloat('QHouseEnergy');
        $remaining = max(60, $start + 900 - $now);
        $budget = $max * 0.25 - $used;
        $allowed = $budget / ($remaining / 3600) - $house;
        $allowed = round(max((float) $this->Setting('minHP'), min($max, $allowed)), 2);
        $hp = max(0, $this->MeasuredPowerKW() ?? $this->Value('ElecPower') ?? 0);
        // Prognose der Viertelstunde, wenn Haus und WP so weiterlaufen
        $forecast = ($used + ($house + $hp) * ($start + 900 - $now) / 3600) / 0.25;
        return ['house' => round($house, 2), 'hp' => round($hp, 2), 'allowed' => $allowed, 'forecast' => round($forecast, 2),
                'used' => round($used, 3), 'end' => $start + 900, 'max' => (float) $this->Setting('maxPeak')];
    }

    /** Für die WP verfügbarer PV-Überschuss in kW: Einspeisung + aktuelle WP-Leistung (die WP gehört zum Eigenverbrauch) */
    private function PVAvailableKW(): ?float
    {
        $id = $this->ReadPropertyInteger('PVPowerID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return null;
        }
        $value = (float) GetValue($id) / ($this->ReadPropertyInteger('PVUnit') === 0 ? 1000 : 1);
        $export = $this->ReadPropertyInteger('PVSignMode') === 0 ? -$value : $value;
        // Überschuss = Einspeisung (bei Netzbezug negativ) + was die WP gerade selbst verbraucht
        return round($export + max(0, $this->MeasuredPowerKW() ?? $this->Value('ElecPower') ?? 0), 2);
    }

    /**
     * PV-Überschuss: Start, wenn der verfügbare Überschuss die Einschaltschwelle für die Verzögerungszeit hält;
     * Ende, wenn er nach der Mindestlaufzeit die Ausschaltschwelle für die Ausschaltverzögerung unterschreitet.
     */
    private function EvaluatePV(array &$state, int $now): bool
    {
        $avail = $this->PVAvailableKW();
        $state['pvAvail'] = $avail;
        $active = !empty($state['pvActive']);
        if ($avail === null) {
            $state['pvActive'] = false;
            return false;
        }
        $since = $state['pvSince'] ?? 0;
        if (!$active) {
            if ($avail >= (float) $this->Setting('pvStart')) {
                $since = $since ?: $now;
                if ($now - $since >= (int) $this->Setting('pvStartMin') * 60) {
                    $active = true;
                    $state['pvStarted'] = $now;
                    $since = 0;
                }
            } else {
                $since = 0;
            }
        } elseif ($avail < (float) $this->Setting('pvStop') && $now - ($state['pvStarted'] ?? 0) >= (int) $this->Setting('pvMinRun') * 60) {
            $since = $since ?: $now;
            if ($now - $since >= (int) $this->Setting('pvStopMin') * 60) {
                $active = false;
                $since = 0;
            }
        } else {
            $since = 0;
        }
        $state['pvSince'] = $since;
        $state['pvActive'] = $active;
        return $active;
    }

    /**
     * Smart Grid (Hoval Anwendungsfälle 6 und 8) – Leistungsbegrenzung und PV-Überschuss teilen sich die Register:
     * 27546 = 2 (Systembus) aktiviert 27545; 27545 = 1 (Vorzugsbetrieb) macht 27536 (verfügbare Leistung) und die Offsets
     * (27528 Raum, 27509 Warmwasser, 28839 Puffer) wirksam, 27545 = 0 (Normalbetrieb) hebt beides auf.
     * - PV-Überschuss aktiv: Offsets setzen, Vorzugsbetrieb; mit Leistungsbegrenzung zusätzlich die erlaubte Leistung.
     * - nur Begrenzung nötig (erlaubte Leistung < WP-Maximalleistung): Offsets 0, Vorzugsbetrieb, erlaubte Leistung.
     * - sonst: Normalbetrieb.
     */
    private function SmartGridTick(array &$state, int $now)
    {
        $limitOn = (bool) $this->Setting('limitOn');
        $pvOn = (bool) $this->Setting('pvOn');
        $hpMax = (float) $this->Setting('hpMax');

        $calc = $limitOn ? $this->LimitCalculation($now) : null;
        $limitNeed = false;
        if ($calc !== null) {
            $limitNeed = !empty($state['sgOn']) ? $calc['allowed'] < $hpMax + 0.3 : $calc['allowed'] < $hpMax;
            foreach (['AllowedHPPower' => 'allowed', 'HousePower' => 'house'] as $ident => $field) {
                if (@$this->GetIDForIdent($ident)) {
                    $this->SetValue($ident, $calc[$field]);
                }
            }
        }
        $state['sgOn'] = $limitNeed;
        $pv = $pvOn && $this->EvaluatePV($state, $now);
        if (!$pvOn) {
            $state['pvActive'] = false;
        }
        if (@$this->GetIDForIdent('PVBoost') && $this->GetValue('PVBoost') !== $pv) {
            $this->SetValue('PVBoost', $pv);
        }
        // PV per Vorlauf-Anhebung (Symcon-Heizkurve): kein Smart Grid für PV
        $pvViaSG = $this->Setting('pvMethod') !== 'vl';
        $pv = $pv && $pvViaSG;
        $pvOn = $pvOn && $pvViaSG;

        if (!$limitOn && !$pvOn) {
            if ($state['limitActive']) {
                $this->WriteTo('WriteSGMode', 0, 0.01, 0);           // Normalbetrieb
                foreach (['WriteSGOffsetRoom', 'WriteSGOffsetDHW', 'WriteSGOffsetBuffer'] as $property) {
                    $this->WriteTo($property, 0, 0.01, 0);
                }
                $state['limitActive'] = false;
            }
            return;
        }
        $this->WriteTo('WriteSGTrigger', 2);                          // 2 = Systembus (aktiviert 27545)
        // Offsets zuerst, damit der Vorzugsbetrieb nie mit falschen Offsets startet
        $this->WriteTo('WriteSGOffsetRoom', $pv ? (float) $this->Setting('pvOffRoom') : 0);
        $this->WriteTo('WriteSGOffsetDHW', $pv ? (float) $this->Setting('pvOffDHW') : 0);
        $this->WriteTo('WriteSGOffsetBuffer', $pv ? (float) $this->Setting('pvOffBuffer') : 0);
        if ($pv || $limitNeed) {
            $this->WriteTo('WriteSGPower', $calc !== null ? $calc['allowed'] : $hpMax, 0.1, 300);
            $this->WriteTo('WriteSGMode', 1);                         // Vorzugsbetrieb
        } else {
            $this->WriteTo('WriteSGMode', 0);                         // Normalbetrieb
            $this->WriteTo('WriteSGPower', $hpMax, 0.1, 1800);
        }
        $state['limitActive'] = true;
    }

    /** Daten für den Reiter "Steuerung" (sichtbar, sobald ein Passwort oder eine Schreib-Variable hinterlegt ist) */
    /** Welche Jahreszeit die Automatik bei der aktuellen Ø-24-h-Außentemperatur wählen würde (Anzeige, auch im manuellen Modus) */
    private function DetectSeason(): ?string
    {
        $avg = $this->ReadAttributeFloat('TempAvg');
        if ($avg <= -900) {
            return null;
        }
        if ($avg < (float) $this->Setting('winterBelow')) {
            return 'winter';
        }
        return $avg > (float) $this->Setting('summerAbove') ? 'summer' : 'transition';
    }

    /** Hinweise, wenn Vorgaben nicht ankommen: Schreib-Variable fehlt oder Hoval übernimmt den Wert nicht (Rückkontrolle) */
    private function WriteIssues(bool $control, bool $rooms, bool $dhw = false): array
    {
        $issues = [];
        $need = [];
        if ($control) {
            $need = ['WriteHC1Mode' => ['Betriebswahl HK1', 1478, 'HC1Mode'], 'WriteHC1Room' => ['Normal-Raumtemperatur HK1', 1481, 'RoomSetpoint']];
        } elseif ($rooms) {
            $need = ['WriteHC1Mode' => ['Betriebswahl HK1', 1478, 'HC1Mode']];
        }
        if ($control && $this->Setting('curveMode') === 'symcon') {
            $need += ['WriteStrategy' => ['Regelstrategie', 27510, 'ControlStrategy'], 'WriteFlowConst' => ['Vorlauf-Soll Konstant', 1490, 'FlowConst']];
        }
        if ($dhw) {
            $need += ['WriteDHWMode' => ['Betriebswahl Warmwasser', 1496, 'DHWMode'], 'WriteDHWTemp' => ['Normal-Warmwassertemperatur', 1497, null]];
        }
        $writes = json_decode($this->ReadAttributeString('CtlWrites'), true) ?: [];
        foreach ($need as $property => [$label, $register, $readKey]) {
            if ($this->ReadPropertyInteger($property) <= 0) {
                $issues[] = "Schreib-Variable für $label ($register) ist in der Instanz nicht eingetragen.";
                continue;
            }
            $w = $writes[$property] ?? null;
            if ($w !== null && ($w[2] ?? 0) >= 2 && $readKey !== null) {
                $read = $this->Value($readKey);
                $issues[] = "Hoval übernimmt $label ($register) nicht: geschrieben " . $this->Number($w[0], $register === 1481 ? 1 : 0)
                    . ', gelesen ' . ($read === null ? '–' : $this->Number($read, $register === 1481 ? 1 : 0))
                    . '. Modbus-Gerät prüfen (Funktion 6, ' . ($register === 1481 ? 'Faktor 0,1, ' : '') . 'Adresse ' . $register . ', nicht „Nur lesen“).';
            }
        }
        return $issues;
    }

    private function GetControlView(): ?array
    {
        $configured = $this->ReadPropertyString('ControlPassword') !== '';
        foreach (['WriteHC1Mode', 'WriteHC1Room', 'WriteDHWMode', 'WriteDHWTemp', 'WriteSGMode', 'WriteSGPower',
                  'WriteSGOffsetRoom', 'WriteSGOffsetDHW', 'WriteSGOffsetBuffer'] as $property) {
            $configured = $configured || $this->ReadPropertyInteger($property) > 0;
        }
        $configured = $configured || (json_decode($this->ReadPropertyString('Rooms'), true) ?: []) !== [];
        if (!$configured) {
            return null;
        }
        $settings = $this->Settings();
        $now = time();
        $state = $this->GetCtlState();
        $season = (int) $settings['seasonMode'] === 0 ? $state['season'] : $state['seasonAuto'];
        $program = $this->HeatProgramAt($season, $now);
        $dhw = $this->DHWProgramAt($now);
        $avg = $this->ReadAttributeFloat('TempAvg');
        $limit = (bool) $settings['limitOn'];
        $editable = array_intersect_key($settings, self::SETTING_LIMITS + array_flip(['seasonMode', 'roomMode', 'heatPrograms', 'dhwPrograms', 'pvMethod', 'cLearn', 'optOn', 'hcMaxOn']));
        return [
            'ctl'      => (bool) $settings['ctlOn'],
            'dhwCtl'   => (bool) $settings['dhwOn'],
            'rooms'    => (bool) $settings['roomsOn'],
            'limit'    => $limit,
            'pv'       => (bool) $settings['pvOn'],
            'pwSet'    => $this->ReadPropertyString('ControlPassword') !== '',
            'settings' => $editable,
            'heat'     => [
                'mode'       => $state['mode'],
                'detected'   => $this->DetectSeason(),
                'comfort'    => (float) $settings['comfort' . ucfirst($season)],
                'eco'        => $season === 'summer' ? null : (float) $settings[$season === 'winter' ? 'ecoWinter' : 'ecoTransition'],
                'constTemp'  => $state['constTemp'],
                'season'     => $season,
                'seasonAuto' => (int) $settings['seasonMode'] === 1,
                'tAvg'       => $avg <= -900 ? null : round($avg, 1),
                'prog'       => !empty($state['preheat']) && !empty($state['preheatPlan'])
                    ? ['kind' => 'preheat', 'temp' => $state['preheatPlan']['temp'], 'until' => $state['preheatPlan']['next']]
                    : $program + ['until' => $this->NextChange(fn ($ts) => $this->HeatProgramAt($season, $ts), $now)],
                'hc1'        => $this->Value('HC1Mode') !== null ? $this->Format('HC1Mode') : null,
                'roomSet'    => $this->Value('RoomSetpoint') !== null ? $this->Format('RoomSetpoint') : null,
                'preheat'    => $state['preheatPlan'] ?? null,
                'preheatOn'  => !empty($state['preheat']),
                'rate'       => $this->HeatRateState() + ['now' => $this->HeatRateAt($this->OutdoorDamped())],
                'vlMaxRead'  => $this->Value('CurveVlMax'),
                'canVlMax'   => $this->ReadPropertyInteger('WriteFlowMax') > 0
            ],
            'dhw'      => [
                'boost'   => $state['boost'],
                'comfort' => (float) $settings['dhwComfort'],
                'boostT'  => (float) $settings['dhwBoost'],
                'bottom'  => $this->Value('StorageBottom'),
                'max'     => $this->ReadAttributeFloat('DHWMax'),
                'prog'    => $dhw === null ? null : $dhw + ['until' => $this->NextChange(fn ($ts) => $this->DHWProgramAt($ts), $now)],
                'status'  => $this->Value('DHWStatus') !== null ? $this->Format('DHWStatus') : null,
                'top'     => $this->Value('StorageTop'),
                'set'     => $this->Value('DHWSetpoint'),
                'charge'  => $state['dhwCharge'] ?? null,
                'last'    => $state['dhwLast'] ?? null
            ],
            'demand'    => $state['demand'],
            'roomList'  => $state['roomList'] ?? [],
            'roomStats' => $state['roomStats'] ?? null,
            'holiday'   => $state['holiday'] ?? null,
            'curve'     => $this->CurveView($state),
            'strategy'  => $this->Value('ControlStrategy') !== null ? $this->Format('ControlStrategy') : null,
            'writeIssues' => $this->WriteIssues((bool) $settings['ctlOn'], (bool) $settings['roomsOn'], (bool) $settings['dhwOn']),
            'pw'        => $limit ? $this->LimitCalculation($now) : null,
            'sgOn'      => !empty($state['sgOn']),
            'total15'   => $limit && @$this->GetIDForIdent('Total15') ? $this->GetValue('Total15') : null,
            'totalPeak' => $limit && @$this->GetIDForIdent('Total15PeakToday') ? $this->GetValue('Total15PeakToday') : null,
            'pvState'   => [
                'avail'   => $state['pvAvail'] ?? $this->PVAvailableKW(),
                'active'  => !empty($state['pvActive']),
                'since'   => $state['pvSince'] ?? 0,
                'started' => $state['pvStarted'] ?? 0,
                'hasVar'  => $this->ReadPropertyInteger('PVPowerID') > 0
            ]
        ];
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
        $this->CreateProfile('HWP.Bar', 2, ' bar', 'Gauge');
        $this->CreateProfile('HWP.K', 1, ' K', 'Temperature');
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
                    case 'bar':
                        $this->RegisterVariableFloat($ident, $caption, 'HWP.Bar', $position);
                        $logging = true;
                        break;
                    case 'kelvin':
                        $this->RegisterVariableFloat($ident, $caption, 'HWP.K', $position);
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
