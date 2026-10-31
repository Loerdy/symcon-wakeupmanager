# symcon-wakeupmanager

IP-Symcon-Library **WakeUpManager**: Wecker mit Weckroutine (z. B. Licht, Rollladen, Radio, Heizung).

Die Library enthält zwei Module:

- **WakeUpActions**: zentrale Liste der verfügbaren Aktionen (einmal pro System).
- **WakeUpManager**: ein Wecker pro Instanz. Jeder Wecker wählt aus den zentralen Aktionen aus, welche zur Weckzeit bzw. mit welchem Zeitversatz davor oder danach ausgeführt werden.

Die Module steuern keine Geräte direkt und haben keine Abhängigkeiten zu anderen Modulen; ausgeführt werden Standard-Aktionen von IP-Symcon.

> Status: Version 0.1 (in Entwicklung). Optionale Statusvariablen folgen in späteren Versionen.

## Voraussetzungen

- IP-Symcon ab Version 9.1

Die Module sind auf Deutsch und Englisch verfügbar (Sprache der Konsole bzw. Visualisierung).

## Installation

Im Module Control die Repository-URL hinzufügen:

```
https://github.com/Loerdy/symcon-wakeupmanager
```

1. Eine Instanz **WakeUpActions** anlegen und dort die Aktionen eintragen.
2. Für jeden Wecker eine Instanz **WakeUpManager** anlegen und darin die WakeUpActions-Instanz auswählen.

## Zentrale Aktionen (WakeUpActions)

Für jede Gruppe gibt es eine eigene Liste: **Lampen, Rollläden, Heizung, Sonstiges, Skripte**. Jede Aktion besteht aus einer **Bezeichnung** (z. B. „Schlafzimmerlicht an“) und einer beliebigen **IP-Symcon-Aktion** inklusive Ziel. Um eine Aktion in eine andere Gruppe zu verschieben, wird sie dort neu angelegt; die Auswahl in den Weckern muss dann neu getroffen werden.

Aktionen aus der früheren gemeinsamen Liste werden beim Modul-Update automatisch und mit ihrer ID in die Gruppenlisten übernommen (ohne Gruppe: „Sonstiges“). Kann die frühere Liste nicht gelesen werden, bleibt sie unverändert erhalten und die Instanz zeigt Status 202. Jede Aktion erhält beim Anlegen automatisch eine feste ID. Umbenennen, Umsortieren oder das Ändern der Aktion wirkt sich deshalb sofort auf alle Wecker aus, ohne dass die Auswahl in den Weckern verloren geht.

## Wecker (WakeUpManager)

**Einrichtung** (oberer Bereich, wirksam mit **Übernehmen**):

| Einstellung | Beschreibung |
| --- | --- |
| Zentrale Aktionen | Die WakeUpActions-Instanz, aus der Aktionen ausgewählt werden. |
| Kachel-Icon | Icon in der kleinen Kachel (Standard: Wecker). Leer = kein Icon. |

**Einstellungen** (unterer Bereich, werden **sofort gespeichert** – dieselben Einstellungen sind später auch in der Kachel bedienbar):

| Einstellung | Beschreibung |
| --- | --- |
| Wecker aktiv | Globaler Ein/Aus-Schalter. Neue Instanzen sind deaktiviert. |
| Weckzeit | Uhrzeit des Weckens (Standard 06:30), gespeichert über **Weckzeit speichern**. |
| Eigene Weckzeit für das Wochenende | Wenn eingeschaltet, gilt die Weckzeit nur für Mo–Fr und Sa/So erhalten eine eigene Weckzeit. Beim ersten Einschalten wird die bisherige Weckzeit übernommen. |
| Weckzeit Wochenende | Weckzeit für Sa/So bei eingeschalteter Option, gespeichert über **Weckzeit Wochenende speichern**. |
| Aktive Wochentage | Wochentage, an denen geweckt wird (Standard Mo–Fr), auch bei getrenntem Wochenende einzeln wählbar. |
| Lampen, Rollläden, Heizung, Sonstiges, Skripte | Je Gruppe eine Liste der zentralen Aktionen mit **Ausführen** (an/aus); bei Heizung zusätzlich **Zeitversatz**. Leere Gruppen werden ausgeblendet. |

Nach Auswahl der zentralen Instanz einmal **Übernehmen**, danach erscheinen die Aktionen zur Auswahl. Alle Eingaben werden vom Modul geprüft; ungültige Werte werden abgelehnt.

Bei einem Update von einer früheren Entwicklungsversion werden Aktiv-Schalter, Weckzeit, Wochentage und Aktionsauswahl automatisch übernommen.

Aktionen der Gruppen Lampen, Rollläden, Sonstiges und Skripte werden **zur Weckzeit** ausgeführt. Nur bei **Heizung** wird pro Wecker ein **Zeitversatz** in Minuten festgelegt (−1440 bis 1440): negativ = vorher (z. B. −60), 0 = zur Weckzeit, positiv = danach. Ein negativer Zeitversatz kann auf den Vortag fallen: Bei Weckzeit 06:30 an einem Montag und −480 Minuten wird die Aktion am Sonntag um 22:30 ausgeführt. Maßgeblich ist, ob der Tag der **Weckzeit** aktiv ist.

Wird eine zentrale Aktion gelöscht, wird sie in den Weckern nicht mehr ausgeführt. Die bisherige Auswahl bleibt in der Liste „Sonstiges“ als rot markierte Zeile „Nicht mehr vorhanden“ stehen und kann dort gelöscht werden. Ist die zentrale Instanz nicht verfügbar, bleibt die Auswahl des Weckers unverändert erhalten.

## Verhalten

- Es gibt genau einen Timer, der immer auf den nächsten fälligen Zeitpunkt (nächste Aktion oder nächste Weckzeit) gesetzt wird. Er wird nach jeder Konfigurationsänderung, beim Start von IP-Symcon und nach jedem Auslösen neu berechnet. Es findet kein Polling statt.
- Die Zeitberechnung erfolgt in der lokalen Zeitzone von IP-Symcon. Der Zeitversatz ist eine echte Zeitspanne: Bei der Zeitumstellung liegt eine Aktion mit −60 Minuten immer genau eine Stunde vor der Weckzeit.
- Fällt die Weckzeit in die bei der Umstellung auf Sommerzeit ausgelassene Stunde (z. B. 02:30), wird an diesem Tag zur nächsten gültigen Uhrzeit (03:30) geweckt. Bei der Umstellung auf Winterzeit wird eine doppelt vorhandene Uhrzeit nur einmal ausgelöst (erstes Auftreten).
- Jede Aktion wird pro Weckvorgang höchstens einmal ausgeführt – auch bei verspätetem Timer, Neustart oder erneutem Übernehmen der Konfiguration. Wird nur der Zeitversatz oder die zentrale Definition einer bereits ausgeführten Aktion geändert, wird sie im selben Weckvorgang nicht erneut ausgeführt.
- **Verpasste Zeitpunkte:** Noch nicht verarbeitete Zeitpunkte, die höchstens 5 Minuten zurückliegen, werden nachgeholt (z. B. kurzer Neustart, verzögerter Timer, Weckzeit wurde gerade auf eine Uhrzeit wenige Minuten in der Vergangenheit gestellt). Ältere Zeitpunkte werden nicht nachgeholt; das wird im Meldungsfenster als Warnung protokolliert.
- Beim (Re-)Aktivieren des Weckers wird nichts nachgeholt, auch nicht innerhalb der 5 Minuten. Zeitpunkte während der Deaktivierung entfallen.
- Schlägt eine Aktion fehl, werden die übrigen Aktionen trotzdem ausgeführt. Der Fehler erscheint im Debug und als Warnung im Meldungsfenster.

## Kachel

Der Wecker bringt eine eigene Kachel für die Kachel-Visualisierung mit. Sie benötigt keine Statusvariablen.

- **Normale Größe:** Icon und nächste Weckzeit mit Tag („Heute“, „Morgen“ oder ausgeschriebener Wochentag). Antippen des Icons schaltet den Wecker ein bzw. aus. Ist der Wecker aus, erscheinen das Icon abgeblendet und `--:--` ohne weitere Beschriftung; bei einem Fehler der entsprechende Hinweis.
- **Maximiert bzw. ausreichend groß** (ab ca. 500 × 400 Pixel, im Hochformat ab ca. 340 × 600 Pixel): Einstellungen mit Aktiv-Schalter, Weckzeit, Wochentagen, der Option für ein getrenntes Wochenende (dann je ein Block Mo–Fr und Sa–So mit eigener Weckzeit) und der Aktionsauswahl je Gruppe. Bei Heizung zusätzlich mit Zeitversatz und der sich daraus ergebenden Startzeit, bezogen auf das nächste Wecken (bzw. die heutige Weckzeit, wenn kein nächstes Wecken ansteht), gegebenenfalls mit „(Vortag)“ oder „(Folgetag)“. Nicht ausgewählte Heizungsaktionen zeigen `--:--`.

Änderungen in der Kachel werden vom Modul geprüft und sofort gespeichert, genau wie im unteren Bereich der Konsole. Ungültige Eingaben werden abgelehnt und die Kachel zeigt wieder den gespeicherten Stand. Nicht mehr vorhandene Aktionen werden nur in der Konsole angezeigt und dort gelöscht.

## Statuscodes

**WakeUpManager**

| Code | Bedeutung |
| --- | --- |
| 102 | Wecker ist aktiv |
| 104 | Wecker ist deaktiviert |
| 201 | Die Weckzeit ist ungültig |
| 202 | Es ist kein Wochentag aktiviert |
| 203 | Die Aktionsauswahl ist nicht lesbar |
| 204 | Die zentralen Aktionen sind nicht verfügbar (Instanz gelöscht, falscher Typ oder Liste nicht lesbar) |
| 205 | Die gespeicherten Wochentage sind nicht lesbar (bleiben unverändert erhalten) |

**WakeUpActions**

| Code | Bedeutung |
| --- | --- |
| 102 | Aktionsliste ist bereit |
| 201 | Die Aktionsliste ist fehlerhaft (nicht lesbar oder Einträge ohne eindeutige ID) |
| 202 | Die bisherige gemeinsame Aktionsliste konnte nicht in die Gruppen übernommen werden (bleibt unverändert erhalten) |

## Lizenz

MIT, siehe [LICENSE](LICENSE).
