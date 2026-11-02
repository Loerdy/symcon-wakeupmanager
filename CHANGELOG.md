# Changelog

Alle nennenswerten Änderungen an diesem Projekt werden in dieser Datei dokumentiert.

Das Format basiert auf [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), die Versionierung folgt [Semantic Versioning](https://semver.org/lang/de/).

## [Unreleased]

### Geändert

- Kachel (große Ansicht): Die Aktionsgruppen zeigen ein passendes Icon (Lampe, Rollladen, Thermometer, Noten, Stift).
- Kachel: Neue Schaltfläche **Nur aktive anzeigen** bzw. **Alle anzeigen** rechts über den Aktionen, um nur die ausgewählten Aktionen anzuzeigen. Die Wahl wird je Wecker gespeichert und gilt auf allen Geräten, auch per `IPS_RequestAction` (`SetSelectedOnly`).
- Kachel: Der Zeitversatz der Heizung wird über einen Stepper (− Wert min +) eingestellt; die Schaltflächen ändern in 5-Minuten-Schritten, genaue Werte lassen sich weiterhin eintippen. Ist die Ansicht zu schmal (z. B. Handy hochkant), steht der Zeitversatz in einer eigenen Zeile unter dem Namen, statt den Namen abzuschneiden.
- Kachel: Auf dem Handy im Querformat zeigt die maximierte Kachel jetzt die Einstellungen statt nur der Weckzeit.
- Kachel: Schaltflächen, Namensfeld und Auswahlliste der Vorlagen sind gleich hoch.
- Kachel (breite Ansicht): Die Vorlagen-Spalte schließt direkt an die Einstellungen an und ist breiter, damit das Namensfeld vollständig lesbar ist und die Schaltflächen nebeneinander passen.

## [1.3.1] - 2026-11-01

Beta.

### Behoben

- Kachel (Safari, z. B. iPhone/iPad): Der Button „Speichern“ der Vorlagen wurde abgeschnitten, und die Vorlagen-Spalte ragte über den rechten Rand. Die Auswahlliste steht jetzt immer in eigener Zeile.
- Kachel: Ohne gespeicherte Vorlagen zeigt die Auswahlliste „Keine Vorlagen gespeichert“ statt eines leeren Feldes.

## [1.3.0] - 2026-11-01

Beta.

### Hinzugefügt

- **Vorlagen je Wecker**, z. B. für Früh- und Spätschicht: Weckzeiten, Wochentage und Aktionsauswahl unter einem Namen speichern, laden, mit den aktuellen Einstellungen überschreiben und löschen – in der Konsole und in der großen Ansicht der Kachel, auch per `IPS_RequestAction` (`SaveTemplate`, `LoadTemplate`, `DeleteTemplate`). Aktiv-Schalter und Darstellung bleiben beim Laden unverändert.

### Behoben

- Scheiterte das Speichern einer Einstellung genau in dem Moment, in dem Aktionen fällig waren, wurden diese zwar als ausgeführt vermerkt, aber nicht gestartet.

## [1.2.0] - 2026-11-01

Beta.

### Hinzugefügt

- **Bedingungen für Aktionen:** In WakeUpActions lässt sich je Aktion optional eine Bedingung festlegen (z. B. nur bei Dunkelheit über „Ist es Tag“ des Location Control, nur unterhalb einer Helligkeit oder nur, wenn ein anderes Licht aus ist). Die Aktion läuft nur, wenn die Bedingung im Moment der Ausführung erfüllt ist; sonst wird sie für diesen Weckvorgang übersprungen. Nicht auswertbare Bedingungen führen zum Überspringen mit Warnung im Meldungsfenster. Gilt auch für „Ausgewählte Aktionen jetzt ausführen“, dessen Rückmeldung übersprungene Aktionen mitzählt. Konsole und Kachel des Weckers kennzeichnen bedingte Aktionen.

### Geändert

- **WakeUpActions:** Die Bezeichnung einer Aktion ist ein Pflichtfeld. Bestehende Aktionen ohne Bezeichnung bleiben gültig und erscheinen weiter als „Zentrale Aktion N“, bis sie beim nächsten Bearbeiten einen Namen erhalten.
- **WakeUpManager:** Enthält die gespeicherte Aktionsauswahl ungültige Einträge, zeigt die Konsole einen Hinweis mit deren Anzahl. Die Einträge bleiben unverändert erhalten.

## [1.1.0] - 2026-11-01

Beta.

### Hinzugefügt

- Button „Ausgewählte Aktionen jetzt ausführen“ in der Konsole und in der großen Ansicht der Kachel: startet die ausgewählten Aktionen sofort, ohne das reguläre Wecken zu beeinflussen, und meldet, wie viele Aktionen gestartet wurden bzw. warum keine. Auch per `IPS_RequestAction($id, 'RunActions', true)`.

### Geändert

- Kachel (kompakte Ansicht): Die Abstände zwischen Icon, Weckzeit und Wochentag wachsen jetzt mit der Kachelgröße. Das Icon für den Aktiv-Schalter ist größer und reagiert beim Darüberfahren mit der Maus, damit es als Schalter erkennbar ist. Der Inhalt sitzt etwas höher.
- Kachel: Das Aktiv-Icon lässt sich per Tastatur (Tab, Enter, Leertaste) bedienen und zeigt beim Fokus einen Rahmen. Der Tooltip wird zuverlässig übersetzt.
- „Instanz hinzufügen“: Die Module sind auch unter „Wecker“ bzw. „Weckaktionen“ (englisch „Alarm Clock“ bzw. „Wake-up Actions“) zu finden.
- Kachel: Fallback-Farben für die Design-Variablen der Visualisierung, damit die Kachel lesbar bleibt, falls eine künftige Version eine Variable nicht mehr setzt. Das Aussehen ändert sich nicht.
- README: Installation Schritt für Schritt über das Module Control; der Hinweis auf den Module Store entfällt bis zur Veröffentlichung dort.
- Fehler wie nicht verfügbare zentrale Aktionen oder unlesbare Einstellungen erscheinen nur noch einmal beim Wechsel in den Fehlerstatus im Meldungsfenster, nicht bei jeder Bedienung.

### Behoben

- Kachel (kompakte Ansicht): Unterlängen im Wochentag (z. B. das „g“ in „Morgen“) werden nicht mehr abgeschnitten.
- Kachel: Die Tagesangabe („Heute“, „Morgen“, Wochentag) wird um Mitternacht aktualisiert und zeigt nach Mitternacht nicht mehr den Stand vom Vortag.
- Eine Einstellungsänderung holt keine Zeitpunkte mehr nach, die erst durch die Änderung in der Vergangenheit liegen. Bisher hing es von der letzten Verarbeitung ab, ob eine gerade vergangene Weckzeit nachgeholt wurde; nach dem Wecken konnte ein Umstellen auf eine frühere Uhrzeit erneut wecken.
- Änderungen an der Aktionsauswahl (Konsole, Kachel, `IPS_RequestAction`) betreffen nur noch die geänderte Aktion. Bisher gingen dabei beschädigte oder nicht zuordenbare gespeicherte Einträge verloren. Unveränderte Werte werden nicht mehr neu gespeichert.
- Wird die Weckzeit verschoben, nachdem Aktionen für diesen Tag bereits gelaufen sind (z. B. die vorgeheizte Heizung), werden sie nicht erneut ausgeführt. Bisher galt die verschobene Weckzeit als neuer Weckvorgang.
- Das Konfigurationsformular öffnet auch, wenn gespeicherte Texte ungültige Zeichen (ungültiges UTF-8) enthalten.
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

[Unreleased]: https://github.com/Loerdy/symcon-wakeupmanager/compare/v1.3.1...HEAD
[1.3.1]: https://github.com/Loerdy/symcon-wakeupmanager/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/Loerdy/symcon-wakeupmanager/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/Loerdy/symcon-wakeupmanager/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/Loerdy/symcon-wakeupmanager/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/Loerdy/symcon-wakeupmanager/releases/tag/v1.0.0
