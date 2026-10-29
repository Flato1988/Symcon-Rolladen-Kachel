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
