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
- Vorlagen, z. B. für Früh- und Spätschicht: Weckzeiten, Wochentage und Aktionsauswahl speichern und mit einem Klick laden.
- Auswahl der auszuführenden Aktionen aus der zentralen Aktionsliste; Heizungsaktionen mit Zeitversatz in Minuten.
- Eigene Kachel für die Kachel-Visualisierung mit wählbarer Darstellung der Weckzeit (Standard, Digital, Klappzahlen).
- Keine Variablen, Ereignisse oder Skripte in der Objektstruktur; die Einstellungen werden in der Instanz gespeichert.

## 2. Voraussetzungen

- IP-Symcon ab Version 9.1
- Eine Instanz [WakeUpActions](../WakeUpActions/README.md) mit den gewünschten Aktionen

## 3. Installation

- Library im Module Control (Konsole → Kern Instanzen → **Modules** → **+**) mit `https://github.com/Loerdy/symcon-wakeupmanager` hinzufügen, siehe [Installation](../README.md#installation).
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
| Vorlagen | Speichert die aktuellen Einstellungen unter einem Namen (z. B. „Frühschicht“) bzw. lädt, überschreibt oder löscht die ausgewählte Vorlage (Überschreiben und Löschen mit Rückfrage), siehe [Vorlagen](#vorlagen). |
| Lampen, Rollläden, Heizung, Mediaplayer, Sonstiges, Skripte | Je Gruppe eine Liste der zentralen Aktionen mit **Ausführen** (an/aus); bei Heizung zusätzlich **Zeitversatz**, bei Mediaplayer zusätzlich **Fading** (an/aus), **Vor der Weckzeit** (an/aus) und **Abspielzeit**. Die Spalte **Bedingung** zeigt, ob für die Aktion in WakeUpActions eine Bedingung festgelegt ist. Leere Gruppen werden ausgeblendet. |
| Ausgewählte Aktionen jetzt ausführen | Startet alle mit **Ausführen** markierten Aktionen sofort einmal, unabhängig von Aktiv-Schalter, Weckzeit und Zeitversatz – zum Testen neu eingerichteter Aktionen oder wenn man schon vor dem Wecker wach ist (dann den Wecker bei Bedarf ausschalten). Bedingungen der Aktionen gelten auch hier. Das Ergebnis (Anzahl gestarteter, wegen einer Bedingung übersprungener bzw. fehlgeschlagener Aktionen oder der Grund, warum nichts gestartet wurde) erscheint in einem Hinweisfenster. Das reguläre Wecken wird davon nicht beeinflusst. Derselbe Button steht in der großen Ansicht der Kachel. |

Nach Auswahl der zentralen Instanz einmal **Übernehmen**, danach erscheinen die Aktionen zur Auswahl. Alle Eingaben werden vom Modul geprüft; ungültige Werte werden abgelehnt.

Aktionen der Gruppen Lampen, Rollläden, Mediaplayer, Sonstiges und Skripte werden **zur Weckzeit** ausgeführt. Nur bei **Heizung** wird pro Wecker ein **Zeitversatz** in Minuten festgelegt (−1440 bis 1440): negativ = vorher (z. B. −60), 0 = zur Weckzeit, positiv = danach. Ein negativer Zeitversatz kann auf den Vortag fallen: Bei Weckzeit 06:30 an einem Montag und −480 Minuten wird die Aktion am Sonntag um 22:30 ausgeführt. Maßgeblich ist, ob der Tag der **Weckzeit** aktiv ist.

Bei **Mediaplayer** mit Lautstärke-Variable wird pro Wecker **Fading** ein- oder ausgeschaltet (Standard: ein): Mit Fading wird vor dem Start die Startlautstärke gesetzt und die Lautstärke danach während der in WakeUpActions festgelegten Fading-Dauer bis zur Ziellautstärke erhöht; ohne Fading wird vor dem Start gleich die Ziellautstärke gesetzt. Mit **Vor der Weckzeit** beginnen Wiedergabe und Fading um die Fading-Dauer früher, sodass die Ziellautstärke zur Weckzeit erreicht ist, siehe [Fading](#fading). Außerdem wird pro Wecker eine **Abspielzeit** in Minuten festgelegt (0 bis 720, 0 = nicht ausschalten). Nach dieser Zeit wird die in WakeUpActions festgelegte **Ausschalt-Aktion** ausgeführt, siehe [Ausschalten von Mediaplayern](#ausschalten-von-mediaplayern). Ohne Ausschalt-Aktion hat die Abspielzeit keine Wirkung; die Kachel zeigt sie dann nicht an.

Wird eine zentrale Aktion gelöscht, wird sie in den Weckern nicht mehr ausgeführt. Die bisherige Auswahl bleibt in der Liste „Sonstiges“ als rot markierte Zeile „Nicht mehr vorhanden“ stehen und kann dort gelöscht werden. Ist die zentrale Instanz nicht verfügbar, bleibt die Auswahl des Weckers unverändert erhalten.

Bei einem Update von einer frühen Entwicklungsversion werden Aktiv-Schalter, Weckzeit, Wochentage und Aktionsauswahl automatisch übernommen.

## 5. Kachel

Der Wecker bringt eine eigene Kachel für die Kachel-Visualisierung mit. Sie benötigt keine Statusvariablen.

- **Normale Größe:** Icon und nächste Weckzeit in einem gerahmten Feld, darunter der Tag („Heute“, „Morgen“ oder Wochentag; in der 1×1-Kachel in Kurzform wie „Mo“, ab der nächsten Größe ausgeschrieben). Antippen des Icons schaltet den Wecker ein bzw. aus. Ist der Wecker aus, erscheinen das Icon abgeblendet und `--:--` ohne weitere Beschriftung; bei einem Fehler der entsprechende Hinweis.
- **Maximiert bzw. ausreichend groß** (ab ca. 500 × 400 Pixel, im Hochformat ab ca. 340 × 600 Pixel; auf dem Handy im Querformat auch dann, wenn die Kachel fast die ganze Bildschirmbreite einnimmt): Einstellungen mit Aktiv-Schalter, Weckzeit, Wochentagen, der Option für ein getrenntes Wochenende (dann je ein Block Mo–Fr und Sa–So mit eigener Weckzeit) und der Aktionsauswahl je Gruppe (jeweils mit Icon: Lampen, Rollläden, Heizung, Mediaplayer, Sonstiges, Skripte). Rechts über den Aktionen schaltet **Nur aktive anzeigen** bzw. **Alle anzeigen** zwischen den ausgewählten und allen Aktionen um; die Wahl wird je Wecker gespeichert und gilt auf allen Geräten. Bei Mediaplayer mit Lautstärke-Variable zusätzlich die Haken **Fading** und **Vor der Weckzeit**, mit Ausschalt-Aktion die Abspielzeit (Stepper wie beim Zeitversatz, 0 = nicht ausschalten). Bei Heizung zusätzlich mit Zeitversatz (bei schmaler Ansicht in eigener Zeile unter dem Namen; Stepper: **−**/**+** ändern in 5-Minuten-Schritten, gespeichert wird nach einer kurzen Pause; genaue Werte per Eingabe) und der sich daraus ergebenden Startzeit, bezogen auf das nächste Wecken (bzw. die heutige Weckzeit, wenn kein nächstes Wecken ansteht), gegebenenfalls mit „(Vortag)“ oder „(Folgetag)“. Nicht ausgewählte Heizungsaktionen zeigen `--:--`. Darunter startet **Ausgewählte Aktionen jetzt ausführen** die ausgewählten Aktionen sofort; das Ergebnis erscheint einige Sekunden neben dem Button.
- **Vorlagen:** In der großen Ansicht lassen sich Vorlagen laden, mit den aktuellen Einstellungen überschreiben, löschen (beides zweimal klicken) und die aktuellen Einstellungen als neue Vorlage speichern. Bei ausreichender Breite stehen die Vorlagen rechts neben den Einstellungen, sonst (z. B. auf dem Handy) darunter.
- **Darstellung der Weckzeit:** In der großen Ansicht wählt der Benutzer, wie die kleine Kachel die Weckzeit zeigt: **Standard** (Schrift der Visualisierung), **Digital** (7-Segment-Anzeige) oder **Klappzahlen** (wie ein Radiowecker; ändert sich die Zeit, klappen die betroffenen Ziffern um). Alle Darstellungen verwenden die Farben des Visualisierungs-Designs und kommen ohne externe Schriften aus. Bei aktivierter Einstellung „Bewegung reduzieren“ des Geräts wird ohne Animation umgeschaltet.

Änderungen in der Kachel werden vom Modul geprüft und sofort gespeichert, genau wie im unteren Bereich der Konsole. Ungültige Eingaben werden abgelehnt und die Kachel zeigt wieder den gespeicherten Stand. Nicht mehr vorhandene Aktionen werden nur in der Konsole angezeigt und dort gelöscht.

### Vorlagen

Eine Vorlage speichert Weckzeit, die Option „eigene Weckzeit für das Wochenende“ samt Wochenend-Weckzeit, die aktiven Wochentage und die Aktionsauswahl mit den Zeitversätzen der Heizung. Nicht gespeichert werden der Aktiv-Schalter, die Darstellung der Weckzeit, der Filter „Nur aktive anzeigen“ sowie Icon und zentrale Aktionsinstanz; das Laden einer Vorlage schaltet den Wecker also nicht ein oder aus.

- Bis zu 20 Vorlagen je Wecker, Namen mit 1 bis 40 Zeichen. Wird unter einem vorhandenen Namen gespeichert (Groß-/Kleinschreibung egal), wird diese Vorlage überschrieben.
- Eine Vorlage ist eine Kopie der Einstellungen und nicht mit dem Wecker verknüpft: Nach dem Laden sind die Werte normale Einstellungen des Weckers. Löschen oder Überschreiben einer Vorlage ändert die aktuellen Einstellungen nicht.
- Laden wirkt wie eine Einstellungsänderung: Zeitpunkte, die dadurch in der Vergangenheit liegen, werden nicht nachgeholt, und am selben Tag bereits ausgeführte Aktionen laufen nicht erneut.
- Enthält eine Vorlage Aktionen, die es in WakeUpActions nicht mehr gibt, erscheinen sie nach dem Laden wie gewohnt als „Nicht mehr vorhanden“.

## 6. Verhalten

- Es gibt genau einen Timer pro Wecker, der immer auf den nächsten fälligen Zeitpunkt (nächste Aktion oder nächste Weckzeit) gesetzt wird. Er wird nach jeder Konfigurationsänderung, beim Start von IP-Symcon und nach jedem Auslösen neu berechnet. Es findet kein Polling statt.
- Damit die Kachel nach Mitternacht „Heute“ bzw. „Morgen“ richtig anzeigt, löst der Timer zusätzlich um Mitternacht aus, wenn sich die Tagesangabe dadurch ändert (zu Beginn des Vortags und des Wecktags). Dabei werden keine Aktionen ausgeführt.
- Fällige Aktionen werden nacheinander gestartet, nachdem sie als ausgeführt gespeichert wurden. IP-Symcon führt sie parallel aus; als „fehlgeschlagen“ gilt deshalb ein nicht möglicher Start, nicht ein Fehler während der Ausführung.
- Die Zeitberechnung erfolgt in der lokalen Zeitzone von IP-Symcon. Der Zeitversatz ist eine echte Zeitspanne: Bei der Zeitumstellung liegt eine Aktion mit −60 Minuten immer genau eine Stunde vor der Weckzeit.
- Fällt die Weckzeit in die bei der Umstellung auf Sommerzeit ausgelassene Stunde (z. B. 02:30), wird an diesem Tag zur nächsten gültigen Uhrzeit (03:30) geweckt. Bei der Umstellung auf Winterzeit wird eine doppelt vorhandene Uhrzeit nur einmal ausgelöst (erstes Auftreten).
- Jede Aktion wird pro Weckvorgang höchstens einmal ausgeführt – auch bei verspätetem Timer, Neustart oder erneutem Übernehmen der Konfiguration. Ein Weckvorgang ist das Wecken an einem Tag, einschließlich der Heizungsaktionen davor und danach. Wird die Weckzeit, der Zeitversatz oder die zentrale Definition einer bereits ausgeführten Aktion geändert, wird sie an diesem Tag nicht erneut ausgeführt. Beispiel: Die Heizung (−60 Minuten) lief um 05:30 für 06:30; wird die Weckzeit danach auf 06:45 verschoben, heizt sie um 05:45 nicht noch einmal.
- **Verpasste Zeitpunkte:** Noch nicht verarbeitete Zeitpunkte, die höchstens 5 Minuten zurückliegen, werden nachgeholt (z. B. kurzer Neustart, verzögerter Timer). Ältere Zeitpunkte werden nicht nachgeholt; das wird im Meldungsfenster als Warnung protokolliert.
- **Einstellungsänderungen holen nichts nach:** Wird die Weckzeit auf eine Uhrzeit in der Vergangenheit gestellt (oder ein Wochentag bzw. ein Zeitversatz so geändert, dass ein Zeitpunkt bereits vorbei ist), gilt die Änderung erst ab dem nächsten Zeitpunkt in der Zukunft. Was nach der bisherigen Einstellung bereits fällig war, wird noch ausgeführt. So wird nach dem Wecken auch durch Umstellen auf eine frühere Uhrzeit nicht erneut geweckt.
- Beim (Re-)Aktivieren des Weckers wird nichts nachgeholt, auch nicht innerhalb der 5 Minuten. Zeitpunkte während der Deaktivierung entfallen.
- Gespeicherte Auswahleinträge, die nicht gelesen werden können (z. B. ohne ID oder mit ungültigem Zeitversatz), werden beim Wecken ignoriert, im Debug protokolliert und in der Konsole mit ihrer Anzahl als Hinweis angezeigt, aber nicht gelöscht. Änderungen an der Auswahl betreffen nur die jeweils geänderte Aktion.
- Hat eine Aktion eine Bedingung (festgelegt in [WakeUpActions](../WakeUpActions/README.md#bedingungen)), wird sie im Moment der Ausführung geprüft. Ist sie nicht erfüllt, wird die Aktion für diesen Weckvorgang übersprungen und nicht nachgeholt. Kann sie nicht ausgewertet werden, wird die Aktion übersprungen und eine Warnung ins Meldungsfenster geschrieben. In der Kachel sind solche Aktionen mit „Mit Bedingung“ gekennzeichnet.
- Schlägt eine Aktion fehl, werden die übrigen Aktionen trotzdem ausgeführt. Der Fehler erscheint im Debug und als Warnung im Meldungsfenster.

### Ausschalten von Mediaplayern

- Das Ausschalten wird geplant, wenn der Wecker eine Mediaplayer-Aktion tatsächlich gestartet hat, eine Abspielzeit größer 0 eingestellt ist und die Aktion in WakeUpActions eine Ausschalt-Aktion hat. Wurde die Startaktion wegen ihrer Bedingung übersprungen, wird auch nichts ausgeschaltet.
- Der Ausschaltzeitpunkt (Start plus Abspielzeit) wird beim Start festgelegt. Eine danach geänderte Abspielzeit gilt erst ab dem nächsten Wecken.
- Das geplante Ausschalten bleibt bestehen, wenn der Wecker danach deaktiviert oder die Aktion abgewählt wird, und auch über einen Neustart von IP-Symcon hinweg. Ein verpasster Ausschaltzeitpunkt wird nachgeholt, ohne die 5-Minuten-Grenze für verpasste Aktionen.
- **Jüngster Start gewinnt:** Startet ein anderer Wecker dieselbe Mediaplayer-Aktion später, schaltet der frühere Wecker sie nicht aus; es gilt die Abspielzeit des späteren Weckers. Ebenso entfällt das Ausschalten, wenn die Aktion danach über **Ausgewählte Aktionen jetzt ausführen** gestartet wird (in diesem Wecker wird das geplante Ausschalten dann gleich verworfen); dieser Button plant selbst kein Ausschalten. Abgestimmt wird je zentraler Aktion: Zwei verschiedene Aktionen, die dasselbe Gerät steuern, kennt das Modul nicht als zusammengehörig.
- Ist die zentrale Instanz beim Ausschalten nicht erreichbar, erscheint eine Warnung im Meldungsfenster.
- **Laufender Weckvorgang** (nur große bzw. maximierte Ansicht der Kachel): Solange ein von diesem Wecker gestarteter Mediaplayer ein geplantes Ausschalten oder ein laufendes Fading hat, erscheint oben ein eigener Bereich mit Ausschaltzeitpunkt und Restzeit bzw. Ende des Fadings. **+15 min** verschiebt das Ausschalten um 15 Minuten (mehrfach möglich), **Nicht ausschalten** streicht es; die Wiedergabe läuft dann weiter, bis sie anderweitig beendet wird. **Weckvorgang beenden** führt ohne Rückfrage sofort die Ausschalt-Aktion aller dort aufgeführten Mediaplayer aus und beendet das Fading; andere Aktionen (Lampen, Rollläden, Heizung) bleiben unberührt. Wird die Aktion über **Ausgewählte Aktionen jetzt ausführen** neu gestartet, verschwindet sie aus dem Bereich. Startet ein anderer Wecker dieselbe Aktion später, bleibt sie bis zum ursprünglichen Ausschaltzeitpunkt sichtbar, ausgeschaltet wird sie dann aber nicht (jüngster Start gewinnt).
- **Meldungen:** Start (mit geplantem Ausschaltzeitpunkt), Ausschalten, übersprungenes Ausschalten sowie Änderungen über die Kachel (verschoben, nicht ausschalten, Weckvorgang beendet) erscheinen als Meldung im Meldungsfenster bzw. in der Logdatei von IP-Symcon. Ein Start direkt am Gerät ist für das Modul nicht erkennbar und verhindert ein geplantes Ausschalten nicht.

### Fading

- Lautstärke und Fading gelten nur für tatsächlich gestartete Mediaplayer; wird die Startaktion wegen ihrer Bedingung übersprungen, wird auch keine Lautstärke gesetzt. Die Lautstärke wird über die Aktion der Variable gesetzt (bei Integer-Variablen gerundet).
- Die Lautstärke steigt linear von der Start- zur Ziellautstärke (oder sinkt, wenn das Ziel niedriger ist). Zwischen zwei Schritten liegen mindestens 15 Sekunden; bei Integer-Variablen ändert jeder Schritt die Lautstärke um mindestens eine Stufe. Beispiel: 10 → 30 in 10 Minuten = 20 Schritte, alle 30 Sekunden. Jeder Schritt ist ein Zeitpunkt für den einen Timer des Weckers; es gibt kein Polling.
- **Von Hand geändert:** Weicht die Lautstärke vor einem Schritt vom zuletzt gesetzten Wert ab (z. B. von Hand leiser gestellt oder durch einen anderen Wecker bzw. „Ausgewählte Aktionen jetzt ausführen“ neu gestartet), endet das Fading. Vor dem ersten Schritt wird das nicht geprüft, da manche Geräte beim Start der Wiedergabe ihre Lautstärke selbst setzen. Geräte, deren Lautstärke-Variable den gesetzten Wert nicht übernimmt, beenden das Fading deshalb nach dem ersten Schritt.
- **Neustart:** Verpasste Schritte werden nicht einzeln nachgeholt; nach dem Neustart wird die Lautstärke gesetzt, die zum aktuellen Zeitpunkt passt. Ist das Fading schon vorbei, wird nichts mehr gesetzt.
- Wird der Mediaplayer ausgeschaltet, endet auch das Fading. Wie das Ausschalten läuft das Fading weiter, wenn der Wecker deaktiviert wird.
- **Vor der Weckzeit:** Wiedergabe und Fading beginnen um die Fading-Dauer früher (z. B. 06:20 bei Weckzeit 06:30 und 10 Minuten Fading), die Ziellautstärke ist zur Weckzeit erreicht. Die Abspielzeit zählt dann ab der Weckzeit, nicht ab dem Start (60 Minuten = Ausschalten um 07:30). Das gilt nur mit Fading; ohne Fading startet der Mediaplayer zur Weckzeit. Wie bei der Heizung kann der frühere Start auf den Vortag fallen; maßgeblich ist, ob der Tag der Weckzeit aktiv ist.
- **Ausgewählte Aktionen jetzt ausführen** setzt vor dem Start die Startlautstärke, aber ohne Fading.
- Kann die Lautstärke vor dem Start nicht gesetzt werden, wird die Wiedergabe trotzdem gestartet und eine Warnung ins Meldungsfenster geschrieben.

## 7. Statuscodes

| Code | Bedeutung |
| --- | --- |
| 102 | Wecker ist aktiv |
| 104 | Wecker ist deaktiviert |
| 201 | Die Weckzeit ist ungültig |
| 202 | Es ist kein Wochentag aktiviert |
| 203 | Die Aktionsauswahl ist nicht lesbar |
| 204 | Die zentralen Aktionen sind nicht verfügbar (Instanz gelöscht, falscher Typ oder Liste nicht lesbar). Der Wecker läuft weiter und zeigt die nächste Weckzeit an; nur die Aktionen können nicht ausgeführt werden. |
| 205 | Die gespeicherten Wochentage sind nicht lesbar (bleiben unverändert erhalten) |

Bei 203, 204 und 205 erscheint zusätzlich ein Eintrag im Meldungsfenster, und zwar einmal beim Wechsel in diesen Status, nicht bei jeder weiteren Bedienung.

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
| `SetSelectedOnly` | `bool` (Kachel: nur ausgewählte Aktionen anzeigen) | `IPS_RequestAction(12345, 'SetSelectedOnly', true);` |
| `SaveTemplate`, `LoadTemplate`, `DeleteTemplate` | Name der Vorlage | `IPS_RequestAction(12345, 'LoadTemplate', 'Frühschicht');` |
| `RunActions` | `true` (Fehler als Exception; `"console"` bzw. `"tile"` nutzen Konsole und Kachel für ihre Rückmeldung) | `IPS_RequestAction(12345, 'RunActions', true);` |
