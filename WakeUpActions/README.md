# WakeUpActions

Zentrale Liste der Aktionen, aus denen die Wecker ([WakeUpManager](../WakeUpManager/README.md)) auswählen. In der Regel genügt eine Instanz pro System.

## Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Konfiguration](#4-konfiguration)
5. [Statuscodes](#5-statuscodes)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)

## 1. Funktionsumfang

- Je Gruppe eine eigene Liste: **Lampen, Rollläden, Heizung, Mediaplayer, Sonstiges, Skripte**.
- Jede Aktion besteht aus einer **Bezeichnung** (z. B. „Schlafzimmerlicht an“), einer beliebigen **IP-Symcon-Aktion** inklusive Ziel und optional einer **Bedingung**, unter der sie ausgeführt wird.
- Jede Aktion erhält beim Anlegen automatisch eine feste ID. Umbenennen, Umsortieren oder das Ändern der Aktion wirkt sich deshalb sofort auf alle Wecker aus, ohne dass die Auswahl in den Weckern verloren geht.
- Das Modul legt keine Variablen an und steuert keine Geräte selbst; die Aktionen werden von den Weckern ausgeführt.

## 2. Voraussetzungen

- IP-Symcon ab Version 9.1

## 3. Installation

- Library im Module Control (Konsole → Kern Instanzen → **Modules** → **+**) mit `https://github.com/Loerdy/symcon-wakeupmanager` hinzufügen, siehe [Installation](../README.md#installation).
- Unter „Instanz hinzufügen“ das Modul **WakeUpActions** (Hersteller „WakeUpManager“) anlegen.

## 4. Konfiguration

| Liste | Beschreibung |
| --- | --- |
| Lampen, Rollläden, Heizung, Mediaplayer, Sonstiges, Skripte | Je Gruppe die Aktionen mit **Bezeichnung** und **Aktion**. Hinzufügen, Bearbeiten, Löschen und Umsortieren direkt in der Liste; wirksam mit **Übernehmen**. Die Bezeichnung ist ein Pflichtfeld; Aktionen aus früheren Versionen ohne Bezeichnung erscheinen in den Weckern als „Zentrale Aktion N“, bis sie beim nächsten Bearbeiten einen Namen erhalten. |

### Mediaplayer

Aktionen der Gruppe **Mediaplayer** haben im Bearbeitungsfenster zusätzliche Felder. Alle Werte gelten zentral für alle Wecker, die die Aktion auswählen:

| Feld | Beschreibung |
| --- | --- |
| Startaktion | Startet die Wiedergabe, z. B. einen Radiosender. |
| Lautstärke | **Lautstärke nicht setzen** oder **Lautstärke über eine Variable setzen**. Nur bei der zweiten Option erscheinen: |
| – Lautstärke-Variable | Integer- oder Float-Variable mit Aktion, über die die Lautstärke gesetzt wird (unabhängig vom Gerät). |
| – Startlautstärke, Ziellautstärke | In der Einheit der Variable, z. B. 0–100 oder 0–1. |
| – Fading-Dauer | 1 bis 120 Minuten, in denen die Lautstärke von der Start- zur Ziellautstärke steigt. |
| Ausschalten | **Keine Ausschalt-Aktion** oder **Mit Ausschalt-Aktion**; bei der zweiten Option erscheint die **Ausschalt-Aktion**, z. B. Wiedergabe stoppen. |

Felder unter einer nicht gewählten Option werden nicht geprüft und nicht verwendet; ihre Werte bleiben aber gespeichert, falls die Option später wieder gewählt wird.

In dieser Entwicklungsversion führen die Wecker nur die Startaktion aus. Lautstärke, Fading und Ausschalten nach einer am Wecker eingestellten Abspielzeit folgen in den nächsten Schritten.

Mediaplayer, die bisher unter „Sonstiges“ angelegt sind, werden unter „Mediaplayer“ neu angelegt (siehe unten); die Auswahl in den Weckern muss dann neu getroffen werden.

### Bedingungen

Im Bearbeitungsfenster einer Aktion lässt sich optional eine **Bedingung** festlegen (Variablenvergleiche, Uhrzeit, Datum, Wochentag; mit UND/ODER verknüpfbar). Die Aktion wird nur ausgeführt, wenn die Bedingung im Moment der Ausführung erfüllt ist. Die Bedingung gilt für alle Wecker, die die Aktion auswählen, und auch für „Ausgewählte Aktionen jetzt ausführen“. Beispiele:

- **Nur bei Dunkelheit:** Variable „Ist es Tag“ des Location Control = Nein. Wann „Tag“ beginnt, wird dort über „Tagesbeginn“/„Tagesende“ festgelegt (Sonnenaufgang oder eine Dämmerungsstufe).
- **Mit Helligkeitssensor:** Helligkeitsvariable eines beliebigen Sensors kleiner als ein Schwellwert.
- **Nicht doppelt schalten:** Wandlicht Bad nur, wenn das Deckenlicht Bad aus ist.

Ist die Bedingung nicht erfüllt, wird die Aktion für diesen Weckvorgang übersprungen und nicht nachgeholt (Hinweis im Debug). Kann die Bedingung nicht ausgewertet werden (z. B. weil eine verwendete Variable gelöscht wurde), wird die Aktion ebenfalls übersprungen und eine Warnung ins Meldungsfenster geschrieben. Ohne Bedingung wird die Aktion immer ausgeführt.

Um eine Aktion in eine andere Gruppe zu verschieben, wird sie dort neu angelegt; die Auswahl in den Weckern muss dann neu getroffen werden. Nur Aktionen der Gruppe **Heizung** können in den Weckern einen Zeitversatz zur Weckzeit erhalten.

Wird eine Aktion gelöscht, wird sie in den Weckern nicht mehr ausgeführt; dort bleibt sie bis zum Löschen als „Nicht mehr vorhanden“ sichtbar.

Bei einem Update von einer frühen Entwicklungsversion mit einer gemeinsamen Aktionsliste werden die Einträge automatisch und mit ihrer ID in die Gruppenlisten übernommen (ohne Gruppe: „Sonstiges“).

## 5. Statuscodes

| Code | Bedeutung |
| --- | --- |
| 102 | Aktionsliste ist bereit |
| 201 | Die Aktionsliste ist fehlerhaft (nicht lesbar oder Einträge ohne eindeutige ID) |
| 202 | Die bisherige gemeinsame Aktionsliste konnte nicht in die Gruppen übernommen werden (bleibt unverändert erhalten) |

## 6. PHP-Befehlsreferenz

Das Modul stellt keine eigenen PHP-Befehle bereit.
