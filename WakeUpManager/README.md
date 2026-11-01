# WakeUpManager

Ein Wecker pro Instanz. Zur Weckzeit werden die ausgewählten Aktionen aus [WakeUpActions](../WakeUpActions/README.md) ausgeführt, Heizungsaktionen auf Wunsch mit Zeitversatz davor oder danach. Die Kachel zeigt die nächste Weckzeit und in der großen Ansicht die Einstellungen.

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Kachel](#5-kachel)
6. [Verhalten](#6-verhalten)
7. [Statuscodes](#7-statuscodes)
8. [PHP-Befehlsreferenz](#8-php-befehlsreferenz)

## 1. Funktionsumfang

- Weckzeit, aktive Wochentage und globaler Ein/Aus-Schalter.
- Optional eigene Weckzeit für das Wochenende (Sa–So).
- Auswahl der auszuführenden Aktionen aus der zentralen Aktionsliste; Heizungsaktionen mit Zeitversatz in Minuten.
- Eigene Kachel für die Kachel-Visualisierung mit wählbarer Darstellung der Weckzeit (Standard, Digital, Klappzahlen).
- Keine Variablen, Ereignisse oder Skripte in der Objektstruktur; die Einstellungen werden in der Instanz gespeichert.

## 2. Voraussetzungen

- IP-Symcon ab Version 9.1
- Eine Instanz [WakeUpActions](../WakeUpActions/README.md) mit den gewünschten Aktionen

## 3. Installation

- Library über den Module Store oder im Module Control mit `https://github.com/Loerdy/symcon-wakeupmanager` hinzufügen.
- Unter „Instanz hinzufügen“ das Modul **WakeUpManager** (Hersteller „WakeUpManager“) anlegen, eine Instanz pro Wecker.

## 4. Konfiguration

**Einrichtung** (oberer Bereich, wirksam mit **Übernehmen**):

| Einstellung | Beschreibung |
| --- | --- |
| Zentrale Aktionen | Die WakeUpActions-Instanz, aus der Aktionen ausgewählt werden. |
| Kachel-Icon | Icon in der kleinen Kachel (Standard: Wecker). Leer = kein Icon. |

**Einstellungen** (unterer Bereich, werden **sofort gespeichert**; dieselben Einstellungen sind auch in der Kachel bedienbar):

| Einstellung | Beschreibung |
| --- | --- |
| Wecker aktiv | Globaler Ein/Aus-Schalter. Neue Instanzen sind deaktiviert. |
| Weckzeit | Uhrzeit des Weckens (Standard 06:30), gespeichert über **Weckzeit speichern**. |
| Eigene Weckzeit für das Wochenende | Wenn eingeschaltet, gilt die Weckzeit nur für Mo–Fr und Sa/So erhalten eine eigene Weckzeit. Beim ersten Einschalten wird die bisherige Weckzeit übernommen. |
| Weckzeit Wochenende | Weckzeit für Sa/So bei eingeschalteter Option, gespeichert über **Weckzeit Wochenende speichern**. |
| Aktive Wochentage | Wochentage, an denen geweckt wird (Standard Mo–Fr), auch bei getrenntem Wochenende einzeln wählbar. |
| Lampen, Rollläden, Heizung, Sonstiges, Skripte | Je Gruppe eine Liste der zentralen Aktionen mit **Ausführen** (an/aus); bei Heizung zusätzlich **Zeitversatz**. Leere Gruppen werden ausgeblendet. |

Nach Auswahl der zentralen Instanz einmal **Übernehmen**, danach erscheinen die Aktionen zur Auswahl. Alle Eingaben werden vom Modul geprüft; ungültige Werte werden abgelehnt.

Aktionen der Gruppen Lampen, Rollläden, Sonstiges und Skripte werden **zur Weckzeit** ausgeführt. Nur bei **Heizung** wird pro Wecker ein **Zeitversatz** in Minuten festgelegt (−1440 bis 1440): negativ = vorher (z. B. −60), 0 = zur Weckzeit, positiv = danach. Ein negativer Zeitversatz kann auf den Vortag fallen: Bei Weckzeit 06:30 an einem Montag und −480 Minuten wird die Aktion am Sonntag um 22:30 ausgeführt. Maßgeblich ist, ob der Tag der **Weckzeit** aktiv ist.

Wird eine zentrale Aktion gelöscht, wird sie in den Weckern nicht mehr ausgeführt. Die bisherige Auswahl bleibt in der Liste „Sonstiges“ als rot markierte Zeile „Nicht mehr vorhanden“ stehen und kann dort gelöscht werden. Ist die zentrale Instanz nicht verfügbar, bleibt die Auswahl des Weckers unverändert erhalten.

Bei einem Update von einer frühen Entwicklungsversion werden Aktiv-Schalter, Weckzeit, Wochentage und Aktionsauswahl automatisch übernommen.

## 5. Kachel

Der Wecker bringt eine eigene Kachel für die Kachel-Visualisierung mit. Sie benötigt keine Statusvariablen.

- **Normale Größe:** Icon und nächste Weckzeit in einem gerahmten Feld, darunter der Tag („Heute“, „Morgen“ oder Wochentag; in der 1×1-Kachel in Kurzform wie „Mo“, ab der nächsten Größe ausgeschrieben). Antippen des Icons schaltet den Wecker ein bzw. aus. Ist der Wecker aus, erscheinen das Icon abgeblendet und `--:--` ohne weitere Beschriftung; bei einem Fehler der entsprechende Hinweis.
- **Maximiert bzw. ausreichend groß** (ab ca. 500 × 400 Pixel, im Hochformat ab ca. 340 × 600 Pixel): Einstellungen mit Aktiv-Schalter, Weckzeit, Wochentagen, der Option für ein getrenntes Wochenende (dann je ein Block Mo–Fr und Sa–So mit eigener Weckzeit) und der Aktionsauswahl je Gruppe. Bei Heizung zusätzlich mit Zeitversatz und der sich daraus ergebenden Startzeit, bezogen auf das nächste Wecken (bzw. die heutige Weckzeit, wenn kein nächstes Wecken ansteht), gegebenenfalls mit „(Vortag)“ oder „(Folgetag)“. Nicht ausgewählte Heizungsaktionen zeigen `--:--`.
- **Darstellung der Weckzeit:** In der großen Ansicht wählt der Benutzer, wie die kleine Kachel die Weckzeit zeigt: **Standard** (Schrift der Visualisierung), **Digital** (7-Segment-Anzeige) oder **Klappzahlen** (wie ein Radiowecker; ändert sich die Zeit, klappen die betroffenen Ziffern um). Alle Darstellungen verwenden die Farben des Visualisierungs-Designs und kommen ohne externe Schriften aus. Bei aktivierter Einstellung „Bewegung reduzieren“ des Geräts wird ohne Animation umgeschaltet.

Änderungen in der Kachel werden vom Modul geprüft und sofort gespeichert, genau wie im unteren Bereich der Konsole. Ungültige Eingaben werden abgelehnt und die Kachel zeigt wieder den gespeicherten Stand. Nicht mehr vorhandene Aktionen werden nur in der Konsole angezeigt und dort gelöscht.

## 6. Verhalten

- Es gibt genau einen Timer pro Wecker, der immer auf den nächsten fälligen Zeitpunkt (nächste Aktion oder nächste Weckzeit) gesetzt wird. Er wird nach jeder Konfigurationsänderung, beim Start von IP-Symcon und nach jedem Auslösen neu berechnet. Es findet kein Polling statt.
- Damit die Kachel nach Mitternacht „Heute“ bzw. „Morgen“ richtig anzeigt, löst der Timer zusätzlich um Mitternacht aus, wenn sich die Tagesangabe dadurch ändert (zu Beginn des Vortags und des Wecktags). Dabei werden keine Aktionen ausgeführt.
- Die Aktionen werden nacheinander ausgeführt, nachdem sie als ausgeführt gespeichert wurden. Einstellungen lassen sich auch ändern, während lang laufende Aktionen noch laufen.
- Die Zeitberechnung erfolgt in der lokalen Zeitzone von IP-Symcon. Der Zeitversatz ist eine echte Zeitspanne: Bei der Zeitumstellung liegt eine Aktion mit −60 Minuten immer genau eine Stunde vor der Weckzeit.
- Fällt die Weckzeit in die bei der Umstellung auf Sommerzeit ausgelassene Stunde (z. B. 02:30), wird an diesem Tag zur nächsten gültigen Uhrzeit (03:30) geweckt. Bei der Umstellung auf Winterzeit wird eine doppelt vorhandene Uhrzeit nur einmal ausgelöst (erstes Auftreten).
- Jede Aktion wird pro Weckvorgang höchstens einmal ausgeführt – auch bei verspätetem Timer, Neustart oder erneutem Übernehmen der Konfiguration. Wird nur der Zeitversatz oder die zentrale Definition einer bereits ausgeführten Aktion geändert, wird sie im selben Weckvorgang nicht erneut ausgeführt.
- **Verpasste Zeitpunkte:** Noch nicht verarbeitete Zeitpunkte, die höchstens 5 Minuten zurückliegen, werden nachgeholt (z. B. kurzer Neustart, verzögerter Timer, Weckzeit wurde gerade auf eine Uhrzeit wenige Minuten in der Vergangenheit gestellt). Ältere Zeitpunkte werden nicht nachgeholt; das wird im Meldungsfenster als Warnung protokolliert.
- Beim (Re-)Aktivieren des Weckers wird nichts nachgeholt, auch nicht innerhalb der 5 Minuten. Zeitpunkte während der Deaktivierung entfallen.
- Schlägt eine Aktion fehl, werden die übrigen Aktionen trotzdem ausgeführt. Der Fehler erscheint im Debug und als Warnung im Meldungsfenster.

## 7. Statuscodes

| Code | Bedeutung |
| --- | --- |
| 102 | Wecker ist aktiv |
| 104 | Wecker ist deaktiviert |
| 201 | Die Weckzeit ist ungültig |
| 202 | Es ist kein Wochentag aktiviert |
| 203 | Die Aktionsauswahl ist nicht lesbar |
| 204 | Die zentralen Aktionen sind nicht verfügbar (Instanz gelöscht, falscher Typ oder Liste nicht lesbar) |
| 205 | Die gespeicherten Wochentage sind nicht lesbar (bleiben unverändert erhalten) |

## 8. PHP-Befehlsreferenz

Das Modul stellt keine eigenen `WUM_*`-Befehle bereit. Die Einstellungen lassen sich aus Skripten über `IPS_RequestAction` ändern; die Werte werden wie in Kachel und Konsole geprüft, ungültige Werte führen zu einem Fehler.

| Ident | Wert | Beispiel |
| --- | --- | --- |
| `SetActive` | `bool` | `IPS_RequestAction(12345, 'SetActive', true);` |
| `SetWakeUpTime` | Uhrzeit `"HH:MM"` | `IPS_RequestAction(12345, 'SetWakeUpTime', '06:45');` |
| `SetSeparateWeekend` | `bool` | `IPS_RequestAction(12345, 'SetSeparateWeekend', true);` |
| `SetWakeUpTimeWeekend` | Uhrzeit `"HH:MM"` | `IPS_RequestAction(12345, 'SetWakeUpTimeWeekend', '08:30');` |
| `SetWeekday` | JSON `{"Day": 1–7, "Active": bool}` (1 = Montag) | `IPS_RequestAction(12345, 'SetWeekday', json_encode(['Day' => 6, 'Active' => true]));` |
| `SetDisplayStyle` | `"standard"`, `"digital"` oder `"flip"` | `IPS_RequestAction(12345, 'SetDisplayStyle', 'flip');` |
