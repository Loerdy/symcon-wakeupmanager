# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Hinzugefügt

- Grundgerüst der Library `WakeUpManager` mit den Modulen `WakeUpManager` (Wecker) und `WakeUpActions` (zentrale Aktionen), Präfix `WUM`, Mindestversion IP-Symcon 9.1.
- Wecker-Konfiguration: globaler Aktiv-Schalter, Weckzeit und aktive Wochentage.
- Zentrale Aktionsliste mit frei wählbarer IP-Symcon-Aktion und automatisch vergebener, fester ID.
- Der Wecker berechnet sich neu, sobald die zentralen Aktionen geändert oder (z. B. nach einem Modul-Update) neu geladen wurden. Beim Start von IP-Symcon erfolgt die Anmeldung dafür erst, wenn der Kernel bereit ist.
- Englische Grundtexte mit vollständiger deutscher Übersetzung (`locale.json`) für beide Module und die Kachel.
- Aktiv-Schalter, Weckzeit, Wochentage und Aktionsauswahl werden vom Modul nach Prüfung sofort gespeichert (Attribute statt Eigenschaften, Store-konform ohne Selbstkonfiguration); bestehende Einstellungen werden beim Update per Migration übernommen.
- Gruppen für zentrale Aktionen (Lampen, Rollläden, Heizung, Sonstiges, Skripte) mit je einer eigenen Liste, in WakeUpActions und im Wecker. Bestehende Einträge werden beim Update per Migration verlustfrei übernommen.
- Auswahl der zentralen Aktionen pro Wecker; nur Heizungsaktionen haben einen eigenen Zeitversatz in Minuten relativ zur Weckzeit, alle anderen laufen zur Weckzeit.
- Ein einzelner Timer auf den nächsten fälligen Zeitpunkt; Neuberechnung nach Konfigurationsänderung, IP-Symcon-Start und jedem Auslösen.
- Jede Aktion wird pro Weckvorgang höchstens einmal ausgeführt; verpasste Zeitpunkte werden nur innerhalb von 5 Minuten nachgeholt.
- Vorläufige Test-Kachel (HTML-SDK), die Größe und Ladeverhalten der Kachel anzeigt und ins Debug schreibt; wird durch die eigentliche Wecker-Kachel ersetzt.
