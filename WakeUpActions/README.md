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

- Je Gruppe eine eigene Liste: **Lampen, Rollläden, Heizung, Sonstiges, Skripte**.
- Jede Aktion besteht aus einer **Bezeichnung** (z. B. „Schlafzimmerlicht an“) und einer beliebigen **IP-Symcon-Aktion** inklusive Ziel.
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
| Lampen, Rollläden, Heizung, Sonstiges, Skripte | Je Gruppe die Aktionen mit **Bezeichnung** und **Aktion**. Hinzufügen, Bearbeiten, Löschen und Umsortieren direkt in der Liste; wirksam mit **Übernehmen**. |

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
