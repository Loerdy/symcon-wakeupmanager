# symcon-wakeupmanager

IP-Symcon-Library **WakeUpManager**: Wecker mit Weckroutine (z. B. Licht, Rollladen, Radio, Heizung) und eigener Kachel für die Kachel-Visualisierung.

Die Module steuern keine Geräte direkt und haben keine Abhängigkeiten zu anderen Modulen; ausgeführt werden Standard-Aktionen von IP-Symcon.

## Module

| Modul | Beschreibung |
| --- | --- |
| [WakeUpActions](WakeUpActions/README.md) | Zentrale Liste der verfügbaren Aktionen in festen Gruppen (Lampen, Rollläden, Heizung, Sonstiges, Skripte), einmal pro System. |
| [WakeUpManager](WakeUpManager/README.md) | Ein Wecker pro Instanz mit Weckzeit, Wochentagen, optional eigener Weckzeit am Wochenende und Auswahl aus den zentralen Aktionen; Kachel mit nächster Weckzeit und Einstellungen. |

## Voraussetzungen

- IP-Symcon ab Version 9.1

Die Module sind auf Deutsch und Englisch verfügbar (Sprache der Konsole bzw. Visualisierung).

## Installation

Über den Module Store (Suche nach „WakeUpManager“) oder im Module Control mit der Repository-URL:

```
https://github.com/Loerdy/symcon-wakeupmanager
```

1. Eine Instanz **WakeUpActions** anlegen und dort die Aktionen eintragen.
2. Für jeden Wecker eine Instanz **WakeUpManager** anlegen und darin die WakeUpActions-Instanz auswählen.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
