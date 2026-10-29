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

Übersicht für eine Wärmepumpe mit nur zwei Werten: **Zählerstand Bezug (kWh)** und **Außentemperatur (°C)**.

**Kachel:**
- Kennzahlen: Leistung (Ø 15 min), Außentemperatur, Verbrauch + Kosten heute, Spitze 15 min heute (mit Uhrzeit)
- Diagramm der letzten 24 h: Leistung je Viertelstunde (Balken, Tagesspitze rot) und Außentemperatur (Linie);
  antippen/überfahren zeigt die Werte der Viertelstunde
- Höchste 15-min-Leistung je Tag der letzten 7 Tage (bei niedrigen Kacheln ausgeblendet)

**Berechnung:** Ø kW einer Viertelstunde = Zählerzuwachs (kWh) × 4. Die Viertelstunden laufen synchron zur Uhr
(:00, :15, :30, :45). Lücken > 2 h (Symcon aus) und Zählertausch werden übersprungen.
Hinweis: Die Genauigkeit hängt von der Auflösung des Zählers ab (z.B. 0,1 kWh je Viertelstunde = 0,4 kW Stufen).

**Großansicht / Archiv:** Leistung, Außentemperatur und „Spitze 15 min je Tag“ werden als Verlauf,
„Verbrauch gesamt“ und „Kosten gesamt“ als Zähler aufgezeichnet (Summen je Tag/Woche/Monat/Jahr im Diagramm).
Dazu Verbrauch heute, Kosten heute / Monat / Jahr, Spitze heute (Uhrzeit) und gestern.

**Konfiguration:** Zählerstand-Variable (kWh), Außentemperatur-Variable, Strompreis (€/kWh).
