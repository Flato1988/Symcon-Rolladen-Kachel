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
→ gemeinsamer Rücklauf. Der **Puffer erscheint nur, wenn eine Puffertemperatur eingetragen ist** – sonst führt
der Heizungszweig direkt zur FBH. Die Ventilstellung kommt aus dem
**Status (WFA, 18723)** (4 = Warmwasser mit WP, 1 = Heizen mit WP), dann aus dem Status Wärmeerzeuger
(43 = Handbetrieb Warmwasser); sonst gilt die Grundstellung **Heizung**. Animationen: Rohre mit Glanzlinie, weichem
Schimmer und wandernden Wasserperlen; Umwälzpumpe mit drehendem Laufrad; Y-Ventil mit Zeiger, der beim Umschalten dreht. Die FBH-Schlange zeigt einen Farbverlauf von rot (Vorlauf) nach blau (Rücklauf)
und ist animiert, solange sie durchströmt wird (ohne Puffer: Ventil auf Heizung; mit Puffer: Heizkreispumpe an).

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

**Abtauungen:** erkannt über den **Status Wärmeerzeuger (18722) = 8 „Abtauvorgang“** (in der Hoval-Liste nicht
enthalten, von der Anlage gemeldet). Nur falls der Status nicht eingetragen ist, gilt ersatzweise: Volumenstrom > 0 und
Vorlauf mindestens 0,5 K kälter als Rücklauf. Gezählt werden Anzahl (heute/gesamt), Dauer der letzten Abtauung,
**Strom** während der Abtauung (externer Zähler, sonst Hoval) und die dem Heizwasser **entzogene Wärme**.
In der Kachel wird der Lüfter während der Abtauung blau.

**Verlauf (Umschalter „Anlage | Verlauf“ in der Kachel):** je Tag gemessener **Stromverbrauch** und **erbrachte Wärme**
(Balken) und **Außentemperatur Ø** (Linie), 14 oder 30 Tage; antippen zeigt Min/Max-Temperatur, Tagesarbeitszahl und
Abtauungen. Strom: externer Zähler (sonst Hoval 25613); Wärme: Hoval-Wärmezähler (sonst hydraulisch aufsummiert).
Die Tageswerte werden zusätzlich als Variablen (Vortag) ins Archiv geschrieben.

**Auswertung (dritte Ansicht „Auswertung“ in der Kachel):** je Tag dauerhaft gespeichert (ca. 3 Jahre) und als
**Tage / Wochen / Monate** vergleichbar (‹ › blättert zurück):
- Strom gesamt, aufgeteilt in **Heizen**, **Warmwasser** (Strom, solange das Y-Ventil auf Warmwasser steht) und
  **Abtauen** (Strom während erkannter Abtauungen) – gestapelte Balken
- **Ø Außentemperatur** (mit Min/Max) und **Ø Luftfeuchtigkeit** (optionaler Datenpunkt) als Linien
- **Anzahl Abtauungen** (❄ über den Balken), Wärme und Arbeitszahl
- Antippen eines Balkens: Detailkarte mit allen Werten, Anteilen in % und Vergleich zum Vortag / zur Vorwoche / zum Vormonat
Die Daten werden erst beim Öffnen bzw. Blättern beim Modul angefordert (keine großen Datenmengen im Minutentakt).

**Leistung (vierte Ansicht „Leistung“):** Rundanzeige der aktuellen Leistung (externer Zähler, sonst Hoval),
Leistung Ø 15 min, Außentemperatur, Verbrauch und Kosten heute (Strompreis in der Instanz), Spitze heute;
24-h-Diagramm der 15-min-Leistung (Tagesspitze rot) mit Außentemperatur und die höchste 15-min-Leistung je Tag der
letzten 7 Tage. Die Viertelstunden laufen synchron zur Uhr (:00/:15/:30/:45), Ø kW = Energie der Viertelstunde × 4.
Archiv: „Leistung Ø 15 min“ und „Spitze 15 min je Tag“. Reiter **24 h | Woche | Monat**: Woche = Stundenmittel der letzten 7 Tage,
Monat = je Tag Ø-Leistung und 15-min-Spitze (31 Tage).

**Gemessene Werte:** Verbrauch, Kosten, Auswertung und Energievergleich verwenden den externen Zähler. Ist nur dessen
Leistung eingetragen (kein kWh-Zählerstand), summiert das Modul die gemessene Leistung selbst zu Energie auf; erst ohne
externen Zähler wird der Hoval-Energiezähler (25613) verwendet.

**Kältekreis (Reiter „Kältekreis“, erscheint sobald Kältekreis-Werte eingetragen sind; Gateway-Software ab V2.38):**
Schema mit Ventilator/Verdampfer, Expansionsventil, Verdichter und Kondensator (animiert, solange der Verdichter läuft).
Register (S32, 2 Register ab „high“, Funktion 3): 31902 Ansaugluft, 31904 Sauggas, 31906 Heißgas, 31892/31894 WP-Vor-/Rücklauf,
31920 Drehzahl Ist (Verdichter), 31910 Drehzahl WQP (bei Luft-WP nicht belegt – eine Lüfterdrehzahl gibt es per Modbus nicht) – Faktor 0,1; **31912 Überhitzung, 31914 Niederdruck,
31916 Hochdruck – Faktor 0,01** (laut Messwerten). Aus den Drücken werden über die Dampfdruckkurve des Kältemittels (R32, R410A,
R290 wählbar) Verdampfungs- und Kondensationstemperatur berechnet, dazu Druckverhältnis, „Luft − Verdampfung“ (Vereisungsanzeige)
und Kondensator-Grädigkeit. Verlauf der letzten 6 Stunden (Drücke / Temperaturen / Überhitzung mit Sollbereich 3–10 K),
Abtauungen blau und Verdichter-Stillstand grau hinterlegt; die Überhitzung wird nur bei laufendem Verdichter gezeichnet.

**Steuerung (Reiter „Steuerung“) – schreibt in die Wärmepumpe:**
In der Instanz werden nur die **Variablen** und das **Passwort** eingetragen. Aktivieren/Deaktivieren und alle
Einstellungen (Zeitprogramme, Saison, Grenzwerte, Räume, PV) erfolgen in der Kachel: Passwort eingeben → „Entsperren“
(bleibt 10 min entsperrt). Das Passwort wird bei jeder Änderung im Modul geprüft und nie an die Kachel gesendet.
Einstellungen aus älteren Versionen (Instanz) werden beim Update übernommen.

Benötigt Symcon-Modbus-Geräte mit Funktion 6 (Write Single Register), Temperaturen mit Faktor 0,1:
1478 Betriebswahl HK1, 1481 Normal-Raumtemperatur HK1, 1496 Betriebswahl WW, 1497 Normal-WW-Temperatur,
27536 Verfügbare el. Leistung gedämpft, 27546 Auslöser Smart Grid, 27545 Smart Grid über Systembus,
27528 / 27509 / 28839 Smart-Grid-Offsets Raum HK1 / Warmwasser / Puffer. Geschrieben wird nur bei Änderung (Auffrischung alle 30 min).

- **Heizungssteuerung:** Automatik (Zeitprogramm), Frostschutz (1478 = Standby) oder Dauerbetrieb mit Raum-Soll.
  Zeitprogramme mit Komfortzeiten je Saison **Winter / Übergang / Sommer** (außerhalb: Absenktemperatur, Sommer: Heizung aus).
  Saison manuell oder automatisch nach Ø 24 h der Außentemperatur. Im Automatik-/Dauerbetrieb wird HK1 auf „Konstant“ (4)
  gestellt und die Normal-Raumtemperatur geschrieben.
- **Warmwasser:** Ladezeiten mit Temperatur (außerhalb Standby; ohne Ladezeiten regelt Hoval), Taste „x Stunden auf y °C
  aufheizen“. Die **maximale Warmwassertemperatur** begrenzt alle Werte und ist nur mit **Passwort** änderbar.
- **Räume / Raumanforderung:** beliebig viele Raum-Istwerte, Soll aus eigener Variable oder Programmtemperatur (+ Offset).
  Jeder Raum kann in der Kachel auf **„nicht berücksichtigt“** geschaltet werden. Angezeigt werden **Ø Ist, Ø Soll und die
  Abweichung in % (und K)** der berücksichtigten Räume. Verfahren wählbar: **„ein Raum zu kalt“** oder **„Durchschnitt zu kalt“**
  (jeweils Soll − Hysterese, mit Mindestzeit zwischen Wechseln). Keine Anforderung → HK1 Standby.
- **Leistungsbegrenzung:** Haus- und WP-Leistung werden über die laufende Viertelstunde aufsummiert.
  Der WP wird über 27536 nur so viel erlaubt, dass Haus + WP die eingestellte 15-min-Spitze nicht überschreiten
  (Budget = Grenze × ¼ h, verteilt auf die Restzeit, abzüglich aktueller Hausleistung). Ablauf laut Hoval-Anwendungsfall 6:
  27546 = 2 (Systembus), 27545 = 1 (Vorzugsbetrieb) → 27536 wirksam; 27545 = 0 → Normalbetrieb.
- **PV-Überschuss (optional):** Überschuss = Einspeisung + aktuelle WP-Leistung (Netzzähler mit + Bezug / − Einspeisung oder
  Überschuss positiv, W oder kW). Liegt er für die eingestellte Zeit über der Einschaltschwelle, werden die Offsets
  (Raum HK1, Warmwasser, Puffer) geschrieben und der Vorzugsbetrieb (27545 = 1) gesetzt (Hoval-Anwendungsfall 8);
  nach Mindestlaufzeit und Ausschaltverzögerung zurück auf Offsets 0 / Normalbetrieb. Mit Leistungsbegrenzung bleibt 27536 wirksam.
  Ohne PV-Überschuss werden die Offsets auf 0 gehalten. Voraussetzung: Smart-Grid-Grundeinstellung durch den Hoval-Service.

Beim Deaktivieren werden HK1 und Warmwasser wieder auf Hoval „Woche 1“ gestellt und 27545 auf 0 (Normalbetrieb).

**Steuerung – Unterseiten:** Übersicht (Status aller Funktionen, antippen öffnet die Unterseite, Funktionsschalter mit Passwort),
Heizung, Warmwasser, Räume, Leistung und PV. Die zugehörigen Einstellungen (Zeitprogramme, Saison, Ladezeiten, Verfahren,
Grenzwerte, PV-Schwellen) stehen direkt auf der jeweiligen Unterseite unter „⚙ …“ und lassen sich dort nach dem Entsperren ändern.
Nicht berücksichtigte Räume werden ausgegraut.

**Verdichterstarts:** aus dem Hoval-Zähler Schaltzyklen (1518, U32, Funktion 3, in Symcon Datentyp DWord), sonst selbst
erkannt. Anzeige der Starts heute und Ø Laufzeit je Start (Kältekreis), Starts je Zeitraum in der Auswertung und Prüfung in der Überwachung.

**Heizung – Zeitprogramm:** Komfort- und Absenktemperatur je Saison (Winter/Übergang/Sommer) zentral einstellbar; jede Zeit
wählt nur noch die Stufe Komfort / Absenkung / Eigene (eigene Temperatur). Saison-Reiter, Wochenübersicht (Balken je Wochentag),
neue Zeiten schließen an die letzte an. Anzeige Ø 24 h Außentemperatur, erkannte und aktive Saison. Komfort/Absenkung auch per
Antippen der Raumanzeige im Anlagenschema (bei aktiver Heizungssteuerung). Im Schema: Raum Ø Ist, Soll und Abweichung.
Heizungsmenü: Verlauf Raum Ø Soll/Ist, Vorlauf und Außen (24 h / 7 Tage).
**Rückkontrolle:** Betriebswahl HK1/WW und Raumtemperatur werden mit den gelesenen Hoval-Werten (1478, 1496, 1493) verglichen und
bei Abweichung erneut geschrieben; übernimmt Hoval die Vorgabe dauerhaft nicht, erscheint ein Hinweis mit Ursache.
**COP berechnet:** nur bei laufendem Verdichter (Drehzahl 31920, sonst Modulation), Abtauung ausgenommen; im Stillstand 0.

**Warmwassersteuerung (eigener Schalter):** unabhängig von der Heizungssteuerung aktivierbar (beim Update wird der bisherige
Zustand übernommen). Ladezeiten mit Stufe Komfort / Aufheizen / Eigene; Komfort- und Aufheiztemperatur zentral einstellbar,
begrenzt durch die max. Warmwassertemperatur. Wochenübersicht der Ladezeiten. Verlauf Speicher oben / unten und Hoval-Soll
(24 h / 7 Tage, ab Version 4.4 gesammelt).

**Leistung – Temperaturen:** Im Leistungsdiagramm (Tag/Woche/Monat) zusätzlich je Viertelstunde bzw. Stunde/Tag gemittelt:
Außen, Raum Ø Ist/Soll (Räume der Steuerung), Vorlauf Ist/Soll (18725/18724), Rücklauf Ist (1535) und Rücklauf-Soll
(= VL-Soll − Soll-Spreizung aus der Instanz, da Hoval keinen RL-Sollwert liefert). Jede Linie ist per Antippen ein-/ausblendbar;
die Auswahl wird gemerkt. Die Temperaturen werden ab Version 4.1 gesammelt.

**Leistung – Tagesauswahl:** Im Zeitraum „Tag“ blättert man mit ‹ › durch die 15-min-Werte der letzten 31 Tage
(mit Energie und Kosten des Tages). Antippen eines Tages in „Höchste 15-min-Leistung je Tag“ oder eines Balkens in
der Monatsansicht öffnet diesen Tag.

**Auswertung – Kosten:** Kosten heute / gestern / Woche / Monat / letzter Monat / 365 Tage sowie die Ø Kosten je Tag nach
Ø Außentemperatur (2-K-Bereiche, Erfahrungswerte aus vollständigen Tagen) mit ≈ Kosten je Monat und Arbeitszahl.
In der Energie-Ansicht stehen zusätzlich die Kosten je Tag/Woche/Monat.

**Auswertung – Überwachung (abschaltbar):** vergleicht die letzten 3 vollständigen Tage mit den eigenen Erfahrungswerten
bei ähnlicher Außentemperatur (±2,5 K, sonst Regression): Arbeitszahl, Strom Heizen, Strom Warmwasser, Abtauungen,
Verdichterstarts sowie Ø Überhitzung, Ø Luft − Verdampfung und Ø Heißgas (Anzeichen für Kältemittelverlust).
Lernphase 10–14 Tage je Kennzahl; die Kältekreis-Tageswerte werden ab Version 3.5 gesammelt.
Auffälligkeiten stehen in den Variablen „Überwachung: Auffälligkeit“ (~Alert) und „Überwachung: Meldung“ und im Meldungsprotokoll.

**Anlage:** Spreizung (Vorlauf − Rücklauf) und elektrische Leistung (gemessen, sonst Hoval) im Schema.

**Vergleich zurücksetzen:** unter der Vergleichstabelle (Anlage) „↺ Zähler zurücksetzen“ – startet den Vergleich von
el. Energie (Hoval und gemessen) und JAZ ab jetzt neu (mit Rückfrage), wie der Button in der Instanz.

**Ansicht merken:** Reiter, Steuerungs-Unterseite, Auswertungsbereich und Leistungszeitraum werden im Modul gespeichert,
damit die Kachel nach einem Neuladen (z.B. in der iOS-App) auf derselben Ansicht steht.

**Diagnose:** Instanz → „Diagnosezeile in der Kachel anzeigen“ blendet unten eine Zeile mit Ladezähler, Fenster- und
Kachelhöhe, Anzahl Nachrichten/Tipps und letztem JS-Fehler ein (Fehlersuche in Apps).

**Warmwasser-Ladungen (Auswertung → Warmwasser):** jede Ladung (Y-Ventil auf Warmwasser bei laufender WP) wird erfasst:
Dauer, Speichertemperatur oben vorher/nachher, Temperaturanstieg, Strom und Ø Außentemperatur. Kennzahl ist die
**Aufheizzeit je K** (min/K), dargestellt über der Außentemperatur mit Erfahrungskurve. Die Überwachung meldet
„Warmwasser-Aufheizzeit je K“, wenn der Median der letzten 3 Ladungen deutlich über dem Erfahrungswert bei ähnlicher
Außentemperatur liegt (Hinweis auf verkalkten Wärmetauscher, zu wenig Volumenstrom, Fühlerproblem); geprüft wird direkt nach jeder Ladung.
Die Spreizung (VL − RL) steht im Wärmepumpen-Symbol, im Betrieb farbig bewertet (grün 2,5–8 K).

**Kältekreis-Bewertung:** Überhitzung (3–10 K), Druckverhältnis (bis 6), Luft − Verdampfung (bis 10 K),
Kondensator (bis 6 K) und Heißgas (bis 95 °C) werden bei laufendem Verdichter **grün** (Norm), orange (auffällig)
oder rot hinterlegt.

**Eigene Klartexte:** In der Instanz unter „Klartexte (Status, Betriebsarten, Störungen)“ kann je Datenpunkt
und Wert ein eigener Text hinterlegt werden (z.B. Status Wärmeerzeuger · 1 · „Betriebsbereit“). Eigene Texte haben
Vorrang vor den Hoval-Texten; der Button „Hoval-Standardtexte als Vorlage laden“ füllt die Tabelle mit den Texten
aus der Hoval-Liste zum Anpassen.

**Fühler-Offsets:** für jeden Temperaturfühler eine Korrektur in K (wird zum Messwert addiert);
gilt für Anzeige und alle Berechnungen.

**Großansicht / Archiv:** eigene **Anzeige-Variablen** für alle konfigurierten Datenpunkte (reine Anzeige ohne
Schieberegler; Temperaturen inkl. Offset; Status/Betriebsarten/Fehler mit Klartext-Profil aus Hoval-Texten und eigenen Texten)
sowie die berechneten Werte
(COP/JAZ berechnet, alle Abweichungen, thermische Leistung hydraulisch, Wärme/Strom im Vergleichszeitraum),
alle im Archiv aufgezeichnet.
