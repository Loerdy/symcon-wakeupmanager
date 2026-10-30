# symcon-wakeupmanager

IP-Symcon-Library **WakeUpManager**: Wecker mit Weckroutine (z. B. Licht, Rollladen, Radio, Heizung).

Eine Instanz des Moduls **WakeUpManager** entspricht einem Wecker. Zur Weckzeit bzw. mit einem einstellbaren Zeitversatz davor oder danach werden frei wählbare IP-Symcon-Aktionen ausgeführt. Das Modul steuert keine Geräte direkt und hat keine Abhängigkeiten zu anderen Modulen.

> Status: Version 0.1 (Grundgerüst). Die HTML-Kachel und optionale Statusvariablen folgen in späteren Versionen.

## Voraussetzungen

- IP-Symcon ab Version 8.1 (das Modul verwendet die Basisklasse `IPSModuleStrict`)

## Installation

Im Module Control die Repository-URL hinzufügen:

```
https://github.com/Loerdy/symcon-wakeupmanager
```

Anschließend eine Instanz **WakeUpManager** anlegen.

## Konfiguration

| Einstellung | Beschreibung |
| --- | --- |
| Wecker aktiv | Globaler Ein/Aus-Schalter. Neue Instanzen sind deaktiviert. |
| Weckzeit | Uhrzeit des Weckens (Standard 06:30). |
| Aktive Wochentage | Wochentage, an denen geweckt wird (Standard Mo–Fr). |
| Aktionen | Liste der auszuführenden Aktionen. |

Jede Aktion besteht aus:

- **Aktiv**: Aktion ein- oder ausschalten, ohne sie zu löschen.
- **Bezeichnung**: Freier Name, erscheint in Debug- und Fehlermeldungen.
- **Zeitversatz** in Minuten relativ zur Weckzeit (−1440 bis 1440): negativ = vorher (z. B. Heizung −60), 0 = zur Weckzeit, positiv = danach.
- **Aktion**: Beliebige IP-Symcon-Aktion inklusive Ziel (Auswahl über das Standard-Aktionsformular).

Ein negativer Zeitversatz kann auf den Vortag fallen: Bei Weckzeit 06:30 an einem Montag und −480 Minuten wird die Aktion am Sonntag um 22:30 ausgeführt. Maßgeblich ist, ob der Tag der **Weckzeit** aktiv ist.

## Verhalten

- Es gibt genau einen Timer, der immer auf den nächsten fälligen Zeitpunkt (nächste Aktion oder nächste Weckzeit) gesetzt wird. Er wird nach jeder Konfigurationsänderung, beim Start von IP-Symcon und nach jedem Auslösen neu berechnet. Es findet kein Polling statt.
- Die Zeitberechnung erfolgt in der lokalen Zeitzone von IP-Symcon. Der Zeitversatz ist eine echte Zeitspanne: Bei der Zeitumstellung liegt eine Aktion mit −60 Minuten immer genau eine Stunde vor der Weckzeit.
- Fällt die Weckzeit in die bei der Umstellung auf Sommerzeit ausgelassene Stunde (z. B. 02:30), wird an diesem Tag zur nächsten gültigen Uhrzeit (03:30) geweckt. Bei der Umstellung auf Winterzeit wird eine doppelt vorhandene Uhrzeit nur einmal ausgelöst (erstes Auftreten).
- Jede Aktion wird pro Weckvorgang höchstens einmal ausgeführt – auch bei verspätetem Timer, Neustart oder erneutem Übernehmen der Konfiguration. Wird nur der Zeitversatz einer bereits ausgeführten Aktion geändert, wird sie im selben Weckvorgang nicht erneut ausgeführt.
- **Verpasste Zeitpunkte:** Noch nicht verarbeitete Zeitpunkte, die höchstens 5 Minuten zurückliegen, werden nachgeholt (z. B. kurzer Neustart, verzögerter Timer, Weckzeit wurde gerade auf eine Uhrzeit wenige Minuten in der Vergangenheit gestellt). Ältere Zeitpunkte werden nicht nachgeholt; das wird im Meldungsfenster als Warnung protokolliert.
- Beim (Re-)Aktivieren des Weckers wird nichts nachgeholt, auch nicht innerhalb der 5 Minuten. Zeitpunkte während der Deaktivierung entfallen.
- Schlägt eine Aktion fehl, werden die übrigen Aktionen trotzdem ausgeführt. Der Fehler erscheint im Debug und als Warnung im Meldungsfenster.

## Statuscodes

| Code | Bedeutung |
| --- | --- |
| 102 | Wecker ist aktiv |
| 104 | Wecker ist deaktiviert |
| 201 | Die Weckzeit ist ungültig |
| 202 | Es ist kein Wochentag aktiviert |
| 203 | Die Aktionsliste ist nicht lesbar |

## Lizenz

MIT, siehe [LICENSE](LICENSE).
