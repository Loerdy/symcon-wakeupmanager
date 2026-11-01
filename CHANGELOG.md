# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Geändert

- Kachel (kompakte Ansicht): Die Abstände zwischen Icon, Weckzeit und Wochentag wachsen jetzt mit der Kachelgröße. Das Icon für den Aktiv-Schalter ist größer und reagiert beim Darüberfahren mit der Maus, damit es als Schalter erkennbar ist. Der Inhalt sitzt etwas höher.

### Behoben

- Kachel (kompakte Ansicht): Unterlängen im Wochentag (z. B. das „g“ in „Morgen“) werden nicht mehr abgeschnitten.
- Kachel: Die Tagesangabe („Heute“, „Morgen“, Wochentag) wird um Mitternacht aktualisiert und zeigt nach Mitternacht nicht mehr den Stand vom Vortag.
- Eine Einstellungsänderung holt keine Zeitpunkte mehr nach, die erst durch die Änderung in der Vergangenheit liegen. Bisher hing es von der letzten Verarbeitung ab, ob eine gerade vergangene Weckzeit nachgeholt wurde; nach dem Wecken konnte ein Umstellen auf eine frühere Uhrzeit erneut wecken.
- Änderungen an der Aktionsauswahl (Konsole, Kachel, `IPS_RequestAction`) betreffen nur noch die geänderte Aktion. Bisher gingen dabei beschädigte oder nicht zuordenbare gespeicherte Einträge verloren. Unveränderte Werte werden nicht mehr neu gespeichert.
- War die Weckroutine beim Auslösen des Timers kurzzeitig belegt, versucht der Wecker es nach einer Sekunde erneut. Bisher wartete er auf den nächsten geplanten Zeitpunkt, und fällige Aktionen konnten ausfallen.

## [1.0.0] - 2026-10-31

Erstes Release (Beta).

### Hinzugefügt

- Library `WakeUpManager` mit den Modulen `WakeUpManager` (Wecker) und `WakeUpActions` (zentrale Aktionen), Präfix `WUM`, Mindestversion IP-Symcon 9.1.
- Englische Grundtexte mit vollständiger deutscher Übersetzung für beide Module und die Kachel.
- **WakeUpActions:** zentrale Aktionen in festen Gruppen (Lampen, Rollläden, Heizung, Sonstiges, Skripte) mit frei wählbarer IP-Symcon-Aktion und automatisch vergebener, fester ID.
- **WakeUpManager:** globaler Aktiv-Schalter, Weckzeit, aktive Wochentage und optional eigene Weckzeit für das Wochenende (Sa–So).
- Auswahl der zentralen Aktionen pro Wecker; Heizungsaktionen mit Zeitversatz in Minuten relativ zur Weckzeit, alle anderen Aktionen zur Weckzeit.
- Einstellungen werden nach Prüfung sofort gespeichert (Konsole, Kachel und `IPS_RequestAction`), ohne Selbstkonfiguration der Instanz.
- Ein einzelner Timer auf den nächsten fälligen Zeitpunkt; Neuberechnung nach Konfigurationsänderung, IP-Symcon-Start, Änderung der zentralen Aktionen und jedem Auslösen. Zeitberechnung in der lokalen Zeitzone inklusive Sommer-/Winterzeit und Vorlauf über Mitternacht.
- Jede Aktion wird pro Weckvorgang höchstens einmal ausgeführt; verpasste Zeitpunkte werden nur innerhalb von 5 Minuten nachgeholt. Der Fehler einer Aktion verhindert die übrigen nicht.
- Kachel (HTML-SDK): in normaler Größe die nächste Weckzeit mit Tag und wählbarem Icon, das den Wecker beim Antippen ein- bzw. ausschaltet; maximiert die Einstellungen inklusive berechneter Startzeit der Heizungsaktionen.
- Wählbare Darstellung der Weckzeit in der Kachel: Standard, Digital (7-Segment-Anzeige) oder Klappzahlen mit Umklapp-Animation. Farben und Abstände folgen dem Design der Visualisierung.

[Unreleased]: https://github.com/Loerdy/symcon-wakeupmanager/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/Loerdy/symcon-wakeupmanager/releases/tag/v1.0.0
