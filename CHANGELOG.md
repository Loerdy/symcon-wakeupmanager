# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Hinzugefügt

- Grundgerüst der Library `WakeUpManager` mit den Modulen `WakeUpManager` (Wecker) und `WakeUpActions` (zentrale Aktionen), Präfix `WUM`, Mindestversion IP-Symcon 9.1.
- Wecker-Konfiguration: globaler Aktiv-Schalter, Weckzeit und aktive Wochentage.
- Zentrale Aktionsliste mit frei wählbarer IP-Symcon-Aktion und automatisch vergebener, fester ID.
- Auswahl der zentralen Aktionen pro Wecker mit eigenem Zeitversatz in Minuten relativ zur Weckzeit.
- Ein einzelner Timer auf den nächsten fälligen Zeitpunkt; Neuberechnung nach Konfigurationsänderung, IP-Symcon-Start und jedem Auslösen.
- Jede Aktion wird pro Weckvorgang höchstens einmal ausgeführt; verpasste Zeitpunkte werden nur innerhalb von 5 Minuten nachgeholt.
- Vorläufige Test-Kachel (HTML-SDK), die Größe und Ladeverhalten der Kachel anzeigt und ins Debug schreibt; wird durch die eigentliche Wecker-Kachel ersetzt.
