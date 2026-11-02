# symcon-wakeupmanager

IP-Symcon-Library **WakeUpManager**: Wecker mit Weckroutine (z. B. Licht, Rollladen, Radio, Heizung) und eigener Kachel für die Kachel-Visualisierung.

Die Module steuern keine Geräte direkt und haben keine Abhängigkeiten zu anderen Modulen; ausgeführt werden Standard-Aktionen von IP-Symcon.

## Module

| Modul | Beschreibung |
| --- | --- |
| [WakeUpActions](WakeUpActions/README.md) | Zentrale Liste der verfügbaren Aktionen in festen Gruppen (Lampen, Rollläden, Heizung, Mediaplayer, Sonstiges, Skripte), einmal pro System. |
| [WakeUpManager](WakeUpManager/README.md) | Ein Wecker pro Instanz mit Weckzeit, Wochentagen, optional eigener Weckzeit am Wochenende und Auswahl aus den zentralen Aktionen; Kachel mit nächster Weckzeit und Einstellungen. |

## Voraussetzungen

- IP-Symcon ab Version 9.1

Die Module sind auf Deutsch und Englisch verfügbar (Sprache der Konsole bzw. Visualisierung).

## Installation

Die Library wird über das Module Control von IP-Symcon installiert:

1. In der Konsole in der logischen Baumansicht unter **Kern Instanzen** die Instanz **Modules** öffnen.
2. Auf **+** klicken und die Repository-URL eintragen:

   ```
   https://github.com/Loerdy/symcon-wakeupmanager
   ```

3. Eine Instanz **WakeUpActions** anlegen („Instanz hinzufügen“, Suche nach „WakeUpActions“ oder „Weckaktionen“) und dort die Aktionen eintragen.
4. Für jeden Wecker eine Instanz **WakeUpManager** anlegen (Suche nach „WakeUpManager“ oder „Wecker“) und darin die WakeUpActions-Instanz auswählen.

Updates: In der Instanz **Modules** auf **Auf Aktualisierung prüfen** klicken und das angebotene Update installieren. Über das Zahnrad neben der Library lässt sich der Branch wechseln.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
