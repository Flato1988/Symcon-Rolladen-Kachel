# RollladenKachel (IP-Symcon 7+, HTML-SDK)

Kachel im Stil der Original-Rollladen-Kachel mit
- vertikalem Positions-Slider (wie im Original),
- Rollladen-Grafik, die die aktuelle Position zeigt: **Klick = öffnen/schließen**, **Ziehen = Position setzen**,
- Buttons **Auf / Stopp / Zu** (Stopp nur, wenn „Taster kurzzeit“ konfiguriert ist),
- Live-Aktualisierung, auch bei Bedienung über den physischen Taster.

## Installation
1. Ordner `RollladenKachel` (mit `library.json`) nach `<Symcon>/modules/` kopieren
   oder als Git-Repo in der Modulverwaltung einbinden.
2. Instanz „RollladenKachel“ anlegen.
3. Konfigurieren:
   - **Bezeichnung**: Titel der Kachel (setzt den Instanznamen, den Symcon als Titel anzeigt).
   - **Position absolut – Befehl**: Variable, auf die geschrieben wird (z.B. KNX 6/2/5, DPT 5).
   - **Status aktuelle Position**: Rückmeldung des Aktors (z.B. KNX 6/2/6, DPT 5). Wird für die Anzeige
     verwendet – leer lassen, wenn die Befehlsvariable selbst die Rückmeldung bekommt.
   - **Kurzzeitbetrieb/Stopp**: optional (z.B. KNX 6/2/1, DPT 1), wird für „Stopp“ ausgelöst.
   - Wertebereich: Standard 0 = offen, 100 = geschlossen (bei Bedarf tauschen).
4. Instanz in der Kachel-Visualisierung hinzufügen.

## Hinweis
Damit sich die Kachel bei Bedienung über den Wandtaster aktualisiert, muss die
Positionsvariable die Rückmeldung des Aktors erhalten (bei KNX: Status-GA „Position“
als hörende Adresse eintragen bzw. Rückmeldeobjekt aktivieren).

## PHP-Befehle
`RLK_Open($id)`, `RLK_Close($id)`, `RLK_Toggle($id)`, `RLK_Stop($id)`, `RLK_SetPosition($id, 50)`

## Vollbildansicht
Die Vollbildansicht der Kachel-Visualisierung zeigt kein HTML, sondern die Variablen unterhalb
der Instanz. Das Modul legt dafür automatisch an:
- **Position (0 % = offen)** – Slider, folgt dem Status und setzt die Position
- **Steuerung** – Auf / Stopp / Zu

---

# LichtKachel

Kachel im Stil der Original-Licht-Kachel:
- **Runder Schieberegler** (270°) zum Dimmen, zeigt immer den aktuellen Dimmwert an
- **Taste in der Mitte**: aus → **100 %**, an → **aus**
- Live-Aktualisierung bei Bedienung über Wandtaster / Zentral-Aus
- Vollbildansicht: Variablen **Schalten** und **Helligkeit** unterhalb der Instanz

## Konfiguration (Beispiel Hauptlicht Wohnzimmer)
| Feld | Gruppenadresse |
|---|---|
| Dimmen absolut – Befehl | 6/3/2 (DPT 5) |
| Status Dimmwert – Rückmeldung | 6/3/4 (DPT 5) |
| Schalten (zum Ausschalten) | 6/3/0 (DPT 1) |
| Diagnosetext (optional) | 6/3/13 (DPT 16) |
| Wirkleistung (optional) | 6/3/7 (DPT 14, W) |

## Verbrauch und Stromkosten
Ist die Wirkleistung konfiguriert, legt das Modul an (sichtbar in der Vollbildansicht):
- **Wirkleistung** (W) – im Archiv als Verlauf aufgezeichnet
- **Verbrauch heute**, **Kosten heute / diesen Monat / dieses Jahr**
- **Verbrauch gesamt** und **Kosten gesamt** – im Archiv als **Zähler** aufgezeichnet.
  Im Diagramm dieser Variablen zeigt Symcon die Summe je Stunde/Tag/Woche/Monat/Jahr.

Der Verbrauch wird minütlich und bei jeder Leistungsänderung aus der Wirkleistung berechnet
(Lücken > 15 min, z.B. Symcon-Neustart, werden nicht hochgerechnet).
Der Strompreis (€/kWh) wird in der Instanz hinterlegt; eine Änderung gilt ab diesem Zeitpunkt.

## PHP-Befehle
`LTK_SwitchLight($id, true|false)`, `LTK_SetLevel($id, 50)`, `LTK_Toggle($id)`

---

# WeihnachtsbeleuchtungKachel

Dämmerungsschaltung für Außenbeleuchtung (z.B. Lichterketten) mit mehreren Ausgängen.

**Logik:** Automatik aktiv **und** Uhrzeit „Einschalten ab“ erreicht **und** Lux < Schaltschwelle
→ alle Ausgänge EIN (einmal pro Tag). AUS nach Ablauf der **maximalen Leuchtdauer**
oder wenn die Automatik deaktiviert wird. Wird die Automatik während der Leuchtzeit
aus- und wieder eingeschaltet, leuchtet die Beleuchtung bis zur ursprünglichen Ausschaltzeit weiter.

**Kachel:** Kreis mit Lichterkette – grau = aus, grün mit gelbem Schein = an,
blass = Automatik aus. Klick auf den Kreis schaltet die Automatik ein/aus. Darunter der Status.

**Großansicht:** Automatik aktiv (Schalter), Beleuchtung, Lux-Balken mit Schaltschwelle,
Schaltschwelle, Einschalten ab, maximale Leuchtdauer, Ausschalten um, Helligkeit aktuell, Status.

**Konfiguration:** Helligkeitsvariable (Lux) des Bewegungsmelders und beliebig viele Ausgänge
(Name + Schaltvariable, z.B. Beleuchtung Süd, Beleuchtung Nord).
Startwerte: Schwelle 30 lx, ab 16:00, max. 6 h.

## PHP-Befehle
`XMS_Evaluate($id)`, `XMS_SwitchOutputs($id, true|false)`

---

# WaermepumpeKachel

Übersicht für eine Wärmepumpe mit nur zwei Werten: **Bezug der Wärmepumpe** (Zähler direkt vor der WP)
und **Außentemperatur (°C)**. Der Bezugswert kann sein: **aktuelle Leistung in W**, **in kW** oder ein **Zählerstand in kWh**.

**Kachel:**
- **Leistung aktuell** als Rundanzeige (Skala bis zur höchsten Leistung der letzten Tage), mit „in Betrieb“ / „Stillstand“
- Kennzahlen: Leistung (Ø 15 min), Außentemperatur, Verbrauch + Kosten heute, Spitze heute (Ø 15 min, mit Uhrzeit)
- Diagramm der letzten 24 h: Leistung je Viertelstunde (Balken, Tagesspitze rot) und Außentemperatur (Linie);
  antippen/überfahren zeigt die Werte der Viertelstunde
- Höchste 15-min-Leistung je Tag der letzten 7 Tage (bei niedrigen Kacheln ausgeblendet)

**Berechnung:**
- Leistung (W/kW): Verbrauch = Leistung × Zeit, aufsummiert bei jedem neuen Messwert und jede Minute.
- Zählerstand (kWh): Verbrauch = Zuwachs; aktuelle Leistung = Zuwachs / Zeit zwischen zwei Zählerständen.
- Ø 15 min = Energie der Viertelstunde × 4, synchron zur Uhr (:00, :15, :30, :45) – wie beim Netzbetreiber.
- Lücken (Symcon aus) werden nicht hochgerechnet. Wird die Art des Bezugswerts geändert, beginnt die Auswertung neu
  (manuell: Button „Auswertung zurücksetzen“).

**Großansicht / Archiv:** Leistung aktuell, Leistung Ø 15 min, Außentemperatur und „Spitze 15 min je Tag“ als Verlauf,
„Verbrauch gesamt“ und „Kosten gesamt“ als Zähler (Summen je Tag/Woche/Monat/Jahr im Diagramm).
Dazu Verbrauch heute, Kosten heute / Monat / Jahr, Spitze heute (Uhrzeit) und gestern.

**Konfiguration:** Bezugsvariable + Art des Werts (W / kW / kWh), Außentemperatur-Variable, Strompreis (€/kWh).

---

# HovalWaermepumpeKachel

Anlagenschema und Auswertung für Hoval-Wärmepumpen mit WFA-Funktionsautomat (z.B. **UltraSource**, Belaria Pro).
Die Datenpunkte entsprechen der **Hoval-Modbus-Liste** (Registeradresse steht im Konfigurationsformular);
Status, Betriebswahl, Fehlercode usw. werden mit den **Klartexten aus der Hoval-Liste** angezeigt.

**Schema:** Wärmepumpe (Status WFA, VL-Soll, Modulation, Betriebsstunden) → Vorlauf → **Y-Ventil** →
**WW-Speicher** (oben SF1 / unten SF2, Soll) oder **Pufferspeicher** (PF1/PF2 bzw. WPF) → **FBH** (Heizkreis 1: VL, Raum-Soll)
→ gemeinsamer Rücklauf. Die Ventilstellung kommt aus einer Ventil-Variable oder automatisch aus dem
**Status (WFA, 18723)**: 4 = Warmwasser mit WP, 1 = Heizen mit WP.

**Vergleich Pumpe ↔ eigene Messung/Berechnung** (Abweichung = (Pumpe − eigene) ÷ eigene × 100;
grün < 10 %, orange < 25 %, rot ab 25 %):

| Zeile | Pumpe (Hoval) | Eigene |
|---|---|---|
| El. Leistung | 25611 | externer Zähler (W/kW) |
| El. Energie | Δ 25613 | Δ externer Zähler – im Vergleichszeitraum |
| Therm. Leistung | 25612 | Q = V̇ · 0,997 kg/l · 4,18 kJ/(kg·K) · (VL − RL) |
| COP | 27490 | Q hydraulisch ÷ el. Leistung (Zähler, sonst Hoval) |
| JAZ | 27467 | Wärme (Δ 27484 + 27488, sonst aufsummiertes Q) ÷ Strom (Δ Zähler, sonst Δ 25613) im Vergleichszeitraum |

Der **Vergleichszeitraum** beginnt beim Einrichten und kann in der Instanz neu gestartet werden
(z.B. jährlich für eine echte Jahresarbeitszahl).

**Abtauungen:** erkannt über eine optionale Abtau-Variable oder automatisch, wenn bei laufendem Volumenstrom
der Vorlauf mindestens 0,5 K (einstellbar) kälter als der Rücklauf ist (Kältekreis läuft umgekehrt);
Ende mit Hysterese, sobald der Vorlauf wieder ≥ Rücklauf ist. Gezählt werden Anzahl (heute/gesamt),
Dauer der letzten Abtauung, **Strom** während der Abtauung (externer Zähler, sonst Hoval) und die dem
Heizwasser **entzogene Wärme**.

**Verlauf (Umschalter „Anlage | Verlauf“ in der Kachel):** je Tag gemessener **Stromverbrauch** und **erbrachte Wärme**
(Balken) und **Außentemperatur Ø** (Linie), 14 oder 30 Tage; antippen zeigt Min/Max-Temperatur, Tagesarbeitszahl und
Abtauungen. Strom: externer Zähler (sonst Hoval 25613); Wärme: Hoval-Wärmezähler (sonst hydraulisch aufsummiert).
Die Tageswerte werden zusätzlich als Variablen (Vortag) ins Archiv geschrieben.

**Eigene Klartexte:** In der Instanz unter „Klartexte (Status, Betriebsarten, Störungen)“ kann je Datenpunkt
und Wert ein eigener Text hinterlegt werden (z.B. Status Wärmeerzeuger · 1 · „Betriebsbereit“). Eigene Texte haben
Vorrang vor den Hoval-Texten; der Button „Hoval-Standardtexte als Vorlage laden“ füllt die Tabelle mit den Texten
aus der Hoval-Liste zum Anpassen.

**Fühler-Offsets:** für jeden Temperaturfühler eine Korrektur in K (wird zum Messwert addiert);
gilt für Anzeige und alle Berechnungen.

**Großansicht / Archiv:** Links auf alle konfigurierten Datenpunkte sowie die berechneten Werte
(COP/JAZ berechnet, alle Abweichungen, thermische Leistung hydraulisch, Wärme/Strom im Vergleichszeitraum),
alle im Archiv aufgezeichnet.
