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
        'Humidity'        => ['Luftfeuchtigkeit außen', null, 'hum']
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
        $this->RegisterPropertyFloat('PricePerKWh', 0.25);       // Strompreis (Wärmepumpentarif) in €/kWh
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
                    $this->SetTimerInterval('Push', 2000);
                }
                break;
        }
    }

    /** Gesammelte Aktualisierung an die Kachel senden (Timer „Push“) */
    public function PushUpdate()
    {
        $this->SetTimerInterval('Push', 0);
        $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
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
            case 'Cost': // Kachel fordert die Kostenübersicht an
                $this->UpdateVisualizationValue(json_encode(['cost' => $this->GetCostView()]));
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
                    $clean = array_map(fn ($v) => substr(preg_replace('/[^a-z]/', '', (string) $v), 0, 12), array_intersect_key($state, array_flip(['v', 'c', 's', 'r'])));
                    $this->WriteAttributeString('ViewState', json_encode($clean));
                }
                break;
            case 'ResetCmp': // Vergleichszeitraum (Energie, JAZ) aus der Kachel neu starten
                $this->ResetComparison();
                break;
            case 'Dhw': // Kachel fordert die Warmwasser-Auswertung an
                $this->UpdateVisualizationValue(json_encode(['dhw' => $this->GetDhwView()]));
                break;
            case 'Refri': // Kachel fordert den Kältekreis-Verlauf an
                $this->UpdateVisualizationValue(json_encode(['refriHist' => json_decode($this->ReadAttributeString('RefHist'), true) ?: []]));
                break;
            case 'Ctl': // Bedienung aus dem Reiter "Steuerung"
                $result = $this->HandleControlAction(json_decode((string) $Value, true) ?: []);
                $this->ControlTick(time());
                $this->UpdateVisualizationValue(json_encode(['ctlResult' => $result]));
                $this->UpdateVisualizationValue($this->GetFullUpdateMessage());
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
            'st'   => $this->DayStarts($day)
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
            if (@$this->GetIDForIdent('Total15PeakToday')) {
                $this->SetValue('Total15PeakToday', 0);
            }
            foreach (['DefrostCountToday', 'DefrostElecToday', 'DefrostHeatToday'] as $ident) {
                $this->SetValue($ident, 0);
            }
            $carry = ['wasRun' => $day['wasRun'] ?? false, 'runSince' => $day['runSince'] ?? $now];
            $day = null;
            $this->UpdateMonitor(true);
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
                $history[] = [$start, $kW, $temperature];
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
            'month'   => $this->GetPowerMonth($peaks, $now)
        ];
    }

    /** Woche: Stundenmittel der letzten 7 Tage aus den 15-min-Werten [[Stundenbeginn, Ø kW, max kW, °C]] */
    private function GetPowerWeek(array $history, int $now): array
    {
        $hours = [];
        foreach ($history as [$start, $kW, $temp]) {
            if ($start < $now - 7 * 86400) {
                continue;
            }
            $h = intdiv($start, 3600) * 3600;
            $hours[$h]['kw'][] = $kW;
            if ($temp !== null) {
                $hours[$h]['t'][] = $temp;
            }
        }
        ksort($hours);
        $result = [];
        foreach ($hours as $h => $v) {
            $result[] = [$h, round(array_sum($v['kw']) / count($v['kw']), 3), max($v['kw']),
                         isset($v['t']) ? round(array_sum($v['t']) / count($v['t']), 1) : null];
        }
        return $result;
    }

    /** Monat: je Tag (31 Tage) [Datum, Ø kW (Tagesenergie ÷ Stunden), 15-min-Spitze kW, Zeitpunkt Spitze, Ø °C] */
    private function GetPowerMonth(array $peaks, int $now): array
    {
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
                         $peaks[$date][0] ?? null, $peaks[$date][1] ?? null, $d['t'] ?? null];
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
        return [
            'date'   => $date,
            'start'  => $start,
            'end'    => $end,
            'series' => $series,
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
                'df'    => isset($b['df']) ? (int) $b['df'] : null,
                'c'     => $e === null ? null : round($e * $this->ReadPropertyFloat('PricePerKWh'), 2),
                'st'    => isset($b['st']) ? (int) $b['st'] : null,
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
        $copOwn = ($running && $qHyd !== null && $qHyd > 0 && $pElec !== null && $pElec > 0.1) ? min(15, $qHyd / $pElec) : null;

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
                'seasonMode' => $this->ReadPropertyInteger('SeasonMode'), 'winterBelow' => $this->ReadPropertyFloat('SeasonWinterBelow'),
                'summerAbove' => $this->ReadPropertyFloat('SeasonSummerAbove'), 'ecoWinter' => $this->ReadPropertyFloat('EcoWinter'),
                'ecoTransition' => $this->ReadPropertyFloat('EcoTransition'),
                'heatPrograms' => json_decode($this->ReadPropertyString('HeatPrograms'), true) ?: [],
                'dhwPrograms' => json_decode($this->ReadPropertyString('DHWPrograms'), true) ?: [],
                'roomHyst' => $this->ReadPropertyFloat('RoomHysteresis'), 'roomMin' => $this->ReadPropertyInteger('RoomMinMinutes'),
                'roomMode' => 'any', 'roomExcluded' => [],
                'maxPeak' => $this->ReadPropertyFloat('MaxPeak15'), 'margin' => $this->ReadPropertyFloat('PowerMargin'),
                'minHP' => $this->ReadPropertyFloat('MinHPPower'), 'hpMax' => $this->ReadPropertyFloat('HPMaxPower'),
                'pvStart' => 1.5, 'pvStop' => 0.5, 'pvStartMin' => 5, 'pvStopMin' => 10, 'pvMinRun' => 30,
                'pvOffRoom' => 2.0, 'pvOffDHW' => 10.0, 'pvOffBuffer' => 0.0, 'monOn' => true
            ];
            $this->settingsCache = (json_decode($this->ReadAttributeString('Settings'), true) ?: []) + $defaults;
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
            } elseif (in_array($key, ['ctlOn', 'roomsOn', 'limitOn', 'pvOn', 'monOn'], true)) {
                $settings[$key] = (bool) $value;
            } elseif ($key === 'seasonMode') {
                $settings[$key] = (int) $value === 0 ? 0 : 1;
            } elseif ($key === 'roomMode') {
                $settings[$key] = $value === 'avg' ? 'avg' : 'any';
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
                    if ($key === 'heatPrograms') {
                        $clean['Season'] = isset(self::SEASONS[$row['Season'] ?? '']) ? $row['Season'] : 'winter';
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
        foreach ($this->Programs('heatPrograms') as $row) {
            if (($row['Season'] ?? '') === $season && self::InWindow($row, $ts)) {
                return ['kind' => 'comfort', 'temp' => round((float) $row['Temp'], 1)];
            }
        }
        if ($season === 'summer') {
            return ['kind' => 'off', 'temp' => null];
        }
        return ['kind' => 'eco', 'temp' => (float) $this->Setting($season === 'winter' ? 'ecoWinter' : 'ecoTransition')];
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
                return ['kind' => 'on', 'temp' => min((float) $row['Temp'], $this->ReadAttributeFloat('DHWMax'))];
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
    private function WriteTo(string $property, float $value, float $tolerance = 0.01, int $refresh = 1800): void
    {
        $id = $this->ReadPropertyInteger($property);
        if ($id <= 0 || !IPS_VariableExists($id)) {
            return;
        }
        $writes = json_decode($this->ReadAttributeString('CtlWrites'), true) ?: [];
        $last = $writes[$property] ?? null;
        if ($last !== null && abs($last[0] - $value) < $tolerance && time() - $last[1] < $refresh) {
            return;
        }
        $ok = @RequestAction($id, $value);
        $this->SendDebug('Schreiben', $property . ' = ' . $value . ($ok === false ? ' (fehlgeschlagen)' : ''), 0);
        if ($ok === false) {
            $this->LogMessage('Schreiben fehlgeschlagen: ' . $property . ' = ' . $value . ' (Variable ' . $id . ')', KL_WARNING);
        }
        $writes[$property] = [$value, time()];
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

        // gleitendes 24-h-Mittel der Außentemperatur (für die automatische Saison)
        $outdoor = $this->Value('OutdoorTemp');
        if ($outdoor !== null) {
            $avg = $this->ReadAttributeFloat('TempAvg');
            $this->WriteAttributeFloat('TempAvg', $avg <= -900 ? $outdoor : $avg + ($outdoor - $avg) / 1440);
        }

        $season = $this->CurrentSeason($state);
        $program = $this->HeatProgramAt($season, $now);
        $target = $state['mode'] === 'const' ? (float) $state['constTemp'] : $program['temp'];

        // Räume immer auswerten (Durchschnitt/Abweichung in der Kachel), wirksam nur bei aktiver Raumanforderung
        $roomDemand = $this->EvaluateRooms($state, $target, $now);
        $demand = true;
        if ($rooms) {
            $demand = $roomDemand;
            if (@$this->GetIDForIdent('HeatDemand') && $this->GetValue('HeatDemand') !== $demand) {
                $this->SetValue('HeatDemand', $demand);
            }
        }

        if ($control) {
            // ---- Heizkreis 1 ----
            if ($state['mode'] === 'frost' || ($state['mode'] === 'auto' && $program['kind'] === 'off') || !$demand) {
                $this->WriteTo('WriteHC1Mode', 0);                    // Standby = Frostschutz
            } else {
                $this->WriteTo('WriteHC1Room', max(10, min(30, $target)));
                $this->WriteTo('WriteHC1Mode', 4);                    // Konstant mit Normal-Raumtemperatur
            }
            // ---- Warmwasser ----
            $boost = $state['boost'];
            if ($boost && $boost['until'] <= $now) {
                $state['boost'] = $boost = null;
                $state['dhwReturn'] = true;                          // nach dem Aufheizen an Programm/Hoval zurückgeben
            }
            $dhw = $this->DHWProgramAt($now);
            if ($boost) {
                $this->WriteTo('WriteDHWTemp', min($boost['temp'], $this->ReadAttributeFloat('DHWMax')));
                $this->WriteTo('WriteDHWMode', 4);                    // Dauernd Normalbetrieb
            } elseif ($dhw !== null) {
                if ($dhw['kind'] === 'on') {
                    $this->WriteTo('WriteDHWTemp', $dhw['temp']);
                    $this->WriteTo('WriteDHWMode', 4);
                } else {
                    $this->WriteTo('WriteDHWMode', 0);                // Standby außerhalb der Ladezeiten
                }
            } elseif (!empty($state['dhwReturn'])) {
                $this->WriteTo('WriteDHWMode', 1);                    // keine Programme: zurück an Hoval Woche 1
            }
            $state['dhwReturn'] = false;
            $state['ctlActive'] = true;
        } elseif ($rooms) {
            // nur Raumanforderung: zwischen Hoval-Programm und Standby umschalten
            $this->WriteTo('WriteHC1Mode', $demand ? 1 : 0);
            $state['roomsActive'] = true;
        }
        if (!$control && !empty($state['ctlActive'])) {
            // Heizungssteuerung wurde deaktiviert: Warmwasser (und Heizkreis, falls keine Raumanforderung) an Hoval zurückgeben
            if (!$rooms) {
                $this->WriteTo('WriteHC1Mode', 1, 0.01, 0);
            }
            $this->WriteTo('WriteDHWMode', 1, 0.01, 0);
            $state['ctlActive'] = false;
        }
        if (!$control && !$rooms && !empty($state['roomsActive'])) {
            // Raumanforderung wurde deaktiviert: Heizkreis wieder an Hoval Woche 1
            $this->WriteTo('WriteHC1Mode', 1, 0.01, 0);
            $state['roomsActive'] = false;
        }

        $this->SmartGridTick($state, $now);
        $this->SetCtlState($state);
    }

    /** Bedienung aus der Kachel; liefert ['ok' => bool, 'msg' => Text] */
    private function HandleControlAction(array $action): array
    {
        $a = $action['a'] ?? '';
        $fmt = fn ($v) => number_format((float) $v, 1, ',', '');

        // ---- passwortgeschützt: Entsperren, Funktionen, Einstellungen, Räume, max. Warmwassertemperatur ----
        if (in_array($a, ['unlock', 'feature', 'settings', 'room', 'maxTemp'], true)) {
            $error = $this->CheckPassword($action['pw'] ?? '');
            if ($error !== null) {
                return ['ok' => false, 'msg' => $error, 'locked' => true];
            }
            switch ($a) {
                case 'unlock':
                    return ['ok' => true, 'msg' => 'Entsperrt', 'unlocked' => true];
                case 'feature':
                    $names = ['ctlOn' => 'Heizungssteuerung', 'roomsOn' => 'Raumanforderung', 'limitOn' => 'Leistungsbegrenzung', 'pvOn' => 'PV-Überschuss'];
                    $key = $action['f'] ?? '';
                    if (!isset($names[$key])) {
                        return ['ok' => false, 'msg' => 'Unbekannte Funktion'];
                    }
                    $this->SaveSettings([$key => !empty($action['v'])]);
                    $this->MaintainControlVariables();
                    return ['ok' => true, 'msg' => $names[$key] . (!empty($action['v']) ? ' aktiviert' : ' deaktiviert')];
                case 'settings':
                    $error = $this->SaveSettings(array_intersect_key((array) ($action['values'] ?? []),
                        self::SETTING_LIMITS + array_flip(['seasonMode', 'roomMode', 'heatPrograms', 'dhwPrograms'])));
                    return $error === null ? ['ok' => true, 'msg' => 'Einstellungen gespeichert'] : ['ok' => false, 'msg' => $error];
                case 'room':
                    $excluded = array_values(array_diff($this->Setting('roomExcluded'), [(string) ($action['name'] ?? '')]));
                    if (empty($action['v'])) {
                        $excluded[] = (string) $action['name'];
                    }
                    $this->SaveSettings(['roomExcluded' => $excluded]);
                    return ['ok' => true, 'msg' => ($action['name'] ?? '') . (empty($action['v']) ? ' wird nicht berücksichtigt' : ' wird berücksichtigt')];
                case 'maxTemp':
                    $value = round(max(30, min(70, (float) ($action['v'] ?? 55))) * 2) / 2;
                    $this->WriteAttributeFloat('DHWMax', $value);
                    return ['ok' => true, 'msg' => 'Max. Warmwassertemperatur: ' . $fmt($value) . ' °C'];
            }
        }

        // ---- tägliche Bedienung (ohne Passwort), nur bei aktiver Heizungssteuerung ----
        if (!$this->Setting('ctlOn')) {
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
        $editable = array_intersect_key($settings, self::SETTING_LIMITS + array_flip(['seasonMode', 'roomMode', 'heatPrograms', 'dhwPrograms']));
        return [
            'ctl'      => (bool) $settings['ctlOn'],
            'rooms'    => (bool) $settings['roomsOn'],
            'limit'    => $limit,
            'pv'       => (bool) $settings['pvOn'],
            'pwSet'    => $this->ReadPropertyString('ControlPassword') !== '',
            'settings' => $editable,
            'heat'     => [
                'mode'       => $state['mode'],
                'constTemp'  => $state['constTemp'],
                'season'     => $season,
                'seasonAuto' => (int) $settings['seasonMode'] === 1,
                'tAvg'       => $avg <= -900 ? null : round($avg, 1),
                'prog'       => $program + ['until' => $this->NextChange(fn ($ts) => $this->HeatProgramAt($season, $ts), $now)],
                'hc1'        => $this->Value('HC1Mode') !== null ? $this->Format('HC1Mode') : null,
                'roomSet'    => $this->Value('RoomSetpoint') !== null ? $this->Format('RoomSetpoint') : null
            ],
            'dhw'      => [
                'boost'   => $state['boost'],
                'max'     => $this->ReadAttributeFloat('DHWMax'),
                'prog'    => $dhw === null ? null : $dhw + ['until' => $this->NextChange(fn ($ts) => $this->DHWProgramAt($ts), $now)],
                'status'  => $this->Value('DHWStatus') !== null ? $this->Format('DHWStatus') : null,
                'top'     => $this->Value('StorageTop'),
                'set'     => $this->Value('DHWSetpoint')
            ],
            'demand'    => $state['demand'],
            'roomList'  => $state['roomList'] ?? [],
            'roomStats' => $state['roomStats'] ?? null,
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
