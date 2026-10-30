<?php

declare(strict_types=1);

class WakeUpManager extends IPSModuleStrict
{
    // Verpasste Zeitpunkte werden nur innerhalb dieser Toleranz (Sekunden) nachgeholt.
    private const GRACE_PERIOD = 300;

    // Maximaler Zeitversatz einer Aktion in Minuten (vorher bzw. nachher).
    private const MAX_OFFSET = 1440;

    private const TIMER = 'WakeUpTimer';

    private const ACTIONS_MODULE_ID = '{4E963960-1F66-A246-3BC9-BA42E525A6F7}';

    // Schlüssel entspricht dem ISO-8601-Wochentag (DateTime::format('N')).
    private const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday'
    ];

    // Eigenschaft je Gruppe, gleichnamig in WakeUpActions (Aktionen) und hier (Auswahl); die Gruppenschlüssel
    // werden nicht übersetzt. Nur Aktionen der Gruppe "heating" haben einen Zeitversatz.
    private const GROUP_PROPERTIES = [
        'light'   => 'ActionsLight',
        'shutter' => 'ActionsShutter',
        'heating' => 'ActionsHeating',
        'other'   => 'ActionsOther',
        'script'  => 'ActionsScript'
    ];
    private const OFFSET_GROUP = 'heating';

    // Frühere gemeinsame Auswahlliste; wird per Migrate übernommen.
    private const LEGACY_PROPERTY = 'Actions';

    private const STATUS_INVALID_TIME = 201;
    private const STATUS_NO_WEEKDAY = 202;
    private const STATUS_INVALID_ACTIONS = 203;
    private const STATUS_CATALOG_UNAVAILABLE = 204;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyString('WakeUpTime', '{"hour":6,"minute":30,"second":0}');
        foreach (self::WEEKDAYS as $day => $name) {
            $this->RegisterPropertyBoolean($name, $day <= 5);
        }
        $this->RegisterPropertyInteger('ActionInstance', 0);
        // Auswahl aus den zentralen Aktionen je Gruppe: [{"ID": "<ID der zentralen Aktion>", "Active": bool, "Offset": Minuten}, ...]
        foreach (self::GROUP_PROPERTIES as $property) {
            $this->RegisterPropertyString($property, '[]');
        }
        $this->RegisterPropertyString(self::LEGACY_PROPERTY, '[]');

        // Ende des zuletzt abgearbeiteten Zeitfensters (Unix-Zeitstempel); -1 = Wecker war deaktiviert, nichts nachholen.
        $this->RegisterAttributeInteger('LastProcessed', -1);
        // Bereits ausgeführte Aktionen je Weckvorgang: {"<Weckzeitpunkt>": ["<Aktions-ID>", ...]}
        $this->RegisterAttributeString('Executed', '{}');

        $this->RegisterTimer(self::TIMER, 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'ProcessTimer\', 0);');

        // HTML-Kachel auch im maximierten Zustand verwenden.
        $this->SetVisualizationType(INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN);
    }

    // Übernimmt die frühere gemeinsame Auswahlliste nach "ActionsOther". Die Zuordnung zur Gruppe erfolgt über die ID,
    // daher ist die Gruppe der Speicherstelle unerheblich; beim nächsten Speichern des Formulars wird neu verteilt.
    public function Migrate(string $JSONData): string
    {
        parent::Migrate($JSONData);

        $data = json_decode($JSONData, true);
        if (!is_array($data) || !isset($data['configuration']) || !is_array($data['configuration'])) {
            return '';
        }
        $configuration = $data['configuration'];

        $legacy = $this->DecodeList($configuration[self::LEGACY_PROPERTY] ?? '[]');
        if ($legacy === null || count($legacy) === 0) {
            // Nicht lesbare Altdaten bleiben unverändert erhalten (Status 203).
            return '';
        }
        foreach (self::GROUP_PROPERTIES as $property) {
            $current = $this->DecodeList($configuration[$property] ?? '[]');
            if ($current === null || count($current) > 0) {
                return '';
            }
        }

        $encoded = json_encode($legacy);
        if ($encoded === false || json_decode($encoded, true) !== $legacy) {
            return '';
        }
        $data['configuration'][self::GROUP_PROPERTIES['other']] = $encoded;
        $data['configuration'][self::LEGACY_PROPERTY] = '[]';

        $result = json_encode($data);
        return $result === false ? '' : $result;
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        foreach ($this->GetReferenceList() as $reference) {
            $this->UnregisterReference($reference);
        }
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                if ($message === IM_CHANGESTATUS || $message === IM_CHANGESETTINGS) {
                    $this->UnregisterMessage($senderID, $message);
                }
            }
        }
        $actionInstance = $this->ReadPropertyInteger('ActionInstance');
        if ($actionInstance > 0) {
            $this->RegisterReference($actionInstance);
            // Nach Neuladen (z. B. Modul-Update) oder Änderung der zentralen Aktionen neu berechnen.
            if (IPS_InstanceExists($actionInstance)) {
                $this->RegisterMessage($actionInstance, IM_CHANGESTATUS);
                $this->RegisterMessage($actionInstance, IM_CHANGESETTINGS);
            }
        }

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->Process();

        // Die Aktionsliste hängt von der gewählten zentralen Instanz ab.
        $this->ReloadForm();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->Process();
            return;
        }
        if (($Message === IM_CHANGESTATUS || $Message === IM_CHANGESETTINGS)
            && $SenderID === $this->ReadPropertyInteger('ActionInstance')
            && IPS_GetKernelRunlevel() === KR_READY) {
            $this->Process();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'ProcessTimer':
                $this->Process();
                break;
            case 'TileProbe':
                // Vorläufig: Messwerte der Test-Kachel nur protokollieren.
                if (is_string($Value)) {
                    $this->SendDebug('TileProbe', substr($Value, 0, 500), 0);
                }
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    public function GetConfigurationForm(): string
    {
        $content = file_get_contents(__DIR__ . '/form.json');
        $form = is_string($content) ? json_decode($content, true) : null;
        if (!is_array($form) || !isset($form['elements']) || !is_array($form['elements'])) {
            $this->SendDebug('Form', 'form.json nicht lesbar', 0);
            return is_string($content) ? $content : '{}';
        }

        $actionInstance = $this->ReadPropertyInteger('ActionInstance');
        $catalog = $actionInstance > 0 ? $this->LoadCatalog($actionInstance) : null;
        $selection = $this->LoadSelection();

        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'ActionsHint') {
                if ($actionInstance <= 0) {
                    $element['caption'] = 'Zentrale Aktionen auswählen und übernehmen, danach erscheinen hier die Aktionen zur Auswahl.';
                    $element['visible'] = true;
                } elseif ($catalog === null) {
                    $element['caption'] = 'Die zentralen Aktionen sind nicht verfügbar. Die bisherige Auswahl bleibt erhalten.';
                    $element['visible'] = true;
                } elseif ($selection === null) {
                    $element['caption'] = 'Die gespeicherte Aktionsauswahl ist nicht lesbar und wird nicht überschrieben.';
                    $element['visible'] = true;
                }
            }
            $group = array_search($element['name'] ?? '', self::GROUP_PROPERTIES, true);
            if ($group !== false) {
                if ($catalog === null || $selection === null) {
                    // Gespeicherte Auswahl unverändert anzeigen und nicht bearbeitbar machen, damit sie nicht verloren geht.
                    $element['enabled'] = false;
                    $element['visible'] = $actionInstance > 0;
                } else {
                    $rows = $this->BuildSelectionRows($group, $catalog, $selection);
                    $element['loadValuesFromConfiguration'] = false;
                    $element['values'] = $rows;
                    $element['visible'] = count($rows) > 0;
                }
            }
        }
        unset($element);

        return json_encode($form);
    }

    public function GetVisualizationTile(): string
    {
        $this->SendDebug('Tile', 'GetVisualizationTile aufgerufen', 0);
        $html = file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            $this->SendDebug('Tile', 'module.html nicht lesbar', 0);
            return '';
        }
        return $html;
    }

    // Eine Zeile je zentraler Aktion der Gruppe in zentraler Reihenfolge. Auswahlen, deren zentrale Aktion nicht
    // mehr existiert, bleiben löschbar am Ende der Gruppe "Sonstiges" erhalten.
    private function BuildSelectionRows(string $group, array $catalog, array $selection): array
    {
        $rows = [];
        foreach ($catalog as $id => $action) {
            if ($action['group'] !== $group) {
                continue;
            }
            $row = [
                'ID'        => (string) $id,
                'Name'      => $action['name'],
                'Active'    => $selection[$id]['Active'] ?? false,
                'deletable' => false
            ];
            if ($group === self::OFFSET_GROUP) {
                $row['Offset'] = $selection[$id]['Offset'] ?? 0;
            }
            $rows[] = $row;
        }
        if ($group === 'other') {
            foreach ($selection as $id => $entry) {
                if (!isset($catalog[$id])) {
                    $rows[] = [
                        'ID'        => (string) $id,
                        'Name'      => 'Nicht mehr vorhanden',
                        'Active'    => $entry['Active'],
                        'rowColor'  => '#FFC0C0',
                        'deletable' => true
                    ];
                }
            }
        }
        return $rows;
    }

    // Liefert die Liste oder null, wenn der Wert keine JSON-Liste ist.
    private function DecodeList(mixed $value): ?array
    {
        $list = is_string($value) ? json_decode($value, true) : $value;
        return is_array($list) && array_is_list($list) ? $list : null;
    }

    private function Process(): void
    {
        $semaphore = 'WUM_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($semaphore, 5000)) {
            $this->SendDebug('Process', 'Semaphore konnte nicht gesetzt werden', 0);
            $this->LogMessage('Weckroutine konnte nicht verarbeitet werden (Semaphore belegt)', KL_WARNING);
            return;
        }

        try {
            $now = time();

            if (!$this->ReadPropertyBoolean('Active')) {
                $this->ResetState();
                $this->SetTimerInterval(self::TIMER, 0);
                $this->SetStatus(IS_INACTIVE);
                return;
            }

            $config = $this->LoadConfiguration();
            if ($config === null) {
                $this->SetTimerInterval(self::TIMER, 0);
                return;
            }
            $this->SetStatus($config['catalog'] === null && $this->ReadPropertyInteger('ActionInstance') > 0 ? self::STATUS_CATALOG_UNAVAILABLE : IS_ACTIVE);

            $this->ExecuteDueActions($config, $now);
            $this->ScheduleNext($config, $now);
        } finally {
            IPS_SemaphoreLeave($semaphore);
        }
    }

    private function LoadConfiguration(): ?array
    {
        $time = json_decode($this->ReadPropertyString('WakeUpTime'), true);
        if (!is_array($time) || !$this->IsValidTimePart($time, 'hour', 23) || !$this->IsValidTimePart($time, 'minute', 59) || !$this->IsValidTimePart($time, 'second', 59)) {
            $this->SendDebug('Configuration', 'Ungültige Weckzeit', 0);
            $this->SetStatus(self::STATUS_INVALID_TIME);
            return null;
        }

        $weekdays = [];
        foreach (self::WEEKDAYS as $day => $name) {
            if ($this->ReadPropertyBoolean($name)) {
                $weekdays[$day] = true;
            }
        }
        if (count($weekdays) === 0) {
            $this->SendDebug('Configuration', 'Kein Wochentag aktiviert', 0);
            $this->SetStatus(self::STATUS_NO_WEEKDAY);
            return null;
        }

        $selection = $this->LoadSelection();
        if ($selection === null) {
            $this->LogMessage('Die Aktionsauswahl des Weckers ist nicht lesbar', KL_ERROR);
            $this->SetStatus(self::STATUS_INVALID_ACTIONS);
            return null;
        }

        // Ohne zentrale Aktionen wird weiter geplant, damit ein vorübergehender Fehler den Wecker nicht dauerhaft anhält.
        $actionInstance = $this->ReadPropertyInteger('ActionInstance');
        $catalog = $actionInstance > 0 ? $this->LoadCatalog($actionInstance) : null;
        if ($actionInstance > 0 && $catalog === null) {
            $this->LogMessage('Die zentralen Aktionen des Weckers sind nicht verfügbar', KL_WARNING);
        }

        $actions = [];
        foreach ($selection as $id => $entry) {
            if (!$entry['Active']) {
                continue;
            }
            if ($catalog !== null && !isset($catalog[$id])) {
                $this->SendDebug('Configuration', 'Ausgewählte Aktion ' . $id . ' existiert nicht mehr', 0);
                continue;
            }
            // Nur Heizungsaktionen haben einen Zeitversatz. Ohne zentrale Aktionen ist die Gruppe unbekannt; die
            // Aktion ist dann ohnehin nicht ausführbar, geplant wird mit dem gespeicherten Versatz.
            $group = $catalog[$id]['group'] ?? null;
            $offset = ($group === null || $group === self::OFFSET_GROUP) ? $entry['Offset'] : 0;
            $actions[] = [
                'key'    => (string) $id,
                'label'  => $catalog[$id]['name'] ?? (string) $id,
                'offset' => $offset * 60
            ];
        }

        return [
            'hour'     => $time['hour'],
            'minute'   => $time['minute'],
            'second'   => $time['second'],
            'weekdays' => $weekdays,
            'catalog'  => $catalog,
            'actions'  => $actions
        ];
    }

    private function IsValidTimePart(array $time, string $key, int $maximum): bool
    {
        return isset($time[$key]) && is_int($time[$key]) && $time[$key] >= 0 && $time[$key] <= $maximum;
    }

    // Liefert die eigene Auswahl je Aktions-ID aus allen Gruppenlisten (und einer nicht migrierten Altliste)
    // oder null, wenn gespeicherte Daten nicht lesbar sind. Fehlerhafte Einträge werden einzeln übersprungen.
    private function LoadSelection(): ?array
    {
        $rows = [];
        foreach ([...array_values(self::GROUP_PROPERTIES), self::LEGACY_PROPERTY] as $property) {
            $list = $this->DecodeList($this->ReadPropertyString($property));
            if ($list === null) {
                $this->SendDebug('Configuration', 'Aktionsauswahl ' . $property . ' ist nicht lesbar', 0);
                return null;
            }
            array_push($rows, ...$list);
        }

        $selection = [];
        foreach ($rows as $index => $row) {
            $id = is_array($row) ? ($row['ID'] ?? null) : null;
            if (!is_string($id) || $id === '') {
                $this->SendDebug('Configuration', 'Auswahl ' . ($index + 1) . ': fehlende ID', 0);
                continue;
            }
            if (isset($selection[$id])) {
                // Doppelte IDs können nur bis zum nächsten Speichern des Formulars auftreten; der erste Eintrag gilt.
                continue;
            }
            $offset = $row['Offset'] ?? 0;
            if (is_float($offset) && floor($offset) === $offset) {
                $offset = (int) $offset;
            }
            if (!is_int($offset) || abs($offset) > self::MAX_OFFSET) {
                $this->SendDebug('Configuration', 'Auswahl ' . ($index + 1) . ': ungültiger Zeitversatz', 0);
                continue;
            }
            $selection[$id] = [
                'Active' => ($row['Active'] ?? false) === true,
                'Offset' => $offset
            ];
        }
        return $selection;
    }

    // Liefert die zentralen Aktionen je ID oder null, wenn die Instanz ungültig oder ihre Liste nicht lesbar ist.
    private function LoadCatalog(int $instanceID): ?array
    {
        if ($this->CallCatalog(static fn () => IPS_InstanceExists($instanceID)) !== true) {
            $this->SendDebug('Catalog', 'Instanz ' . $instanceID . ' existiert nicht', 0);
            return null;
        }
        $instance = $this->CallCatalog(static fn () => IPS_GetInstance($instanceID));
        if (!is_array($instance) || ($instance['ModuleInfo']['ModuleID'] ?? '') !== self::ACTIONS_MODULE_ID) {
            $this->SendDebug('Catalog', 'Instanz ' . $instanceID . ' ist keine WakeUpActions-Instanz', 0);
            return null;
        }
        $rows = [];
        foreach (self::GROUP_PROPERTIES as $group => $property) {
            $raw = $this->CallCatalog(static fn () => IPS_GetProperty($instanceID, $property));
            $list = is_string($raw) ? $this->DecodeList($raw) : null;
            if ($list === null) {
                $this->SendDebug('Catalog', 'Zentrale Aktionsliste ' . $property . ' ist nicht lesbar', 0);
                return null;
            }
            foreach ($list as $row) {
                $rows[] = [$group, $row];
            }
        }

        $catalog = [];
        foreach ($rows as $index => [$group, $row]) {
            $label = 'Zentrale Aktion ' . ($index + 1);
            $id = is_array($row) ? ($row['ID'] ?? null) : null;
            if (!is_string($id) || $id === '' || isset($catalog[$id])) {
                $this->SendDebug('Catalog', $label . ': fehlende oder doppelte ID', 0);
                continue;
            }
            $name = is_string($row['Name'] ?? null) && $row['Name'] !== '' ? $row['Name'] : $label;

            // Der Wert von SelectAction ist JSON-kodiert; je nach Speicherung kann er bereits als Array vorliegen.
            $action = $row['Action'] ?? null;
            if (is_string($action)) {
                $action = json_decode($action, true);
            }
            $valid = is_array($action) && is_string($action['actionID'] ?? null) && $action['actionID'] !== '' && is_array($action['parameters'] ?? []);
            if (!$valid) {
                $this->SendDebug('Catalog', $name . ': keine gültige Aktion ausgewählt', 0);
            }
            $catalog[$id] = [
                'name'       => $name,
                'group'      => $group,
                'actionID'   => $valid ? $action['actionID'] : null,
                'parameters' => $valid ? ($action['parameters'] ?? []) : []
            ];
        }
        return $catalog;
    }

    // Zugriff auf die zentrale Instanz. Während diese neu geladen wird (z. B. Modul-Update), melden die
    // IPS-Funktionen Warnungen; diese würden sonst z. B. das Konfigurationsformular unbrauchbar machen.
    private function CallCatalog(callable $call): mixed
    {
        $error = null;
        set_error_handler(static function (int $number, string $message) use (&$error): bool {
            $error = $message;
            return true;
        });
        try {
            $result = $call();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        } finally {
            restore_error_handler();
        }

        if ($error !== null) {
            $this->SendDebug('Catalog', 'Zentrale Instanz nicht erreichbar: ' . $error, 0);
            return null;
        }
        return $result;
    }

    private function ExecuteDueActions(array $config, int $now): void
    {
        $lastProcessed = $this->ReadAttributeInteger('LastProcessed');
        $windowStart = $lastProcessed < 0 ? $now : max($lastProcessed, $now - self::GRACE_PERIOD);

        if ($lastProcessed > 0 && $lastProcessed < $windowStart) {
            // Nicht in die Vergangenheit unbegrenzt suchen; nur zur Protokollierung übersprungener Aktionen.
            $skippedFrom = max($lastProcessed, $now - 8 * 86400);
            $skipped = $this->GetActionEvents($config, $skippedFrom, $windowStart);
            if (count($skipped) > 0) {
                foreach ($skipped as $event) {
                    $this->SendDebug('Skipped', $event['action']['label'] . ' war fällig um ' . $this->FormatTime($event['time']), 0);
                }
                $this->LogMessage(count($skipped) . ' verpasste Aktion(en) wurden nicht nachgeholt', KL_WARNING);
            }
        }

        $events = $this->GetActionEvents($config, $windowStart, $now);

        $executed = $this->ReadExecuted();
        $originalExecuted = $executed;
        $due = [];
        foreach ($events as $event) {
            $occurrence = (string) $event['occurrence'];
            $key = $event['action']['key'];
            if (in_array($key, $executed[$occurrence] ?? [], true)) {
                $this->SendDebug('Execute', $event['action']['label'] . ' wurde in diesem Weckvorgang bereits ausgeführt', 0);
                continue;
            }
            $executed[$occurrence][] = $key;
            $due[] = $event;
        }

        // Einträge entfernen, deren Weckvorgang keine fälligen Aktionen mehr haben kann.
        foreach (array_keys($executed) as $occurrence) {
            if ((int) $occurrence < $now - self::MAX_OFFSET * 60) {
                unset($executed[$occurrence]);
            }
        }

        // Zustand vor dem Ausführen speichern, damit jede Aktion höchstens einmal ausgeführt wird.
        if ($executed !== $originalExecuted) {
            $this->WriteAttributeString('Executed', json_encode((object) $executed));
        }
        if ($now > $lastProcessed) {
            $this->WriteAttributeInteger('LastProcessed', $now);
        }

        foreach ($due as $event) {
            $this->RunAction($event['action'], $config['catalog']);
        }
    }

    private function RunAction(array $action, ?array $catalog): void
    {
        $definition = $catalog[$action['key']] ?? null;
        if ($definition === null || $definition['actionID'] === null) {
            $this->SendDebug('Execute', $action['label'] . ' nicht ausführbar (zentrale Aktion nicht verfügbar)', 0);
            $this->LogMessage('Weckaktion nicht ausführbar: ' . $action['label'], KL_WARNING);
            return;
        }

        try {
            if (IPS_RunAction($definition['actionID'], $definition['parameters'])) {
                $this->SendDebug('Execute', $action['label'] . ' ausgeführt', 0);
            } else {
                $this->SendDebug('Execute', $action['label'] . ' fehlgeschlagen', 0);
                $this->LogMessage('Weckaktion fehlgeschlagen: ' . $action['label'], KL_WARNING);
            }
        } catch (Throwable $e) {
            $this->SendDebug('Execute', $action['label'] . ' fehlgeschlagen: ' . $e->getMessage(), 0);
            $this->LogMessage('Weckaktion fehlgeschlagen: ' . $action['label'], KL_WARNING);
        }
    }

    private function ScheduleNext(array $config, int $now): void
    {
        $range = self::MAX_OFFSET * 60;
        $next = null;
        $nextWakeUp = null;
        foreach ($this->GetOccurrences($config, $now - $range, $now + 8 * 86400 + $range) as $occurrence) {
            if ($occurrence > $now && ($nextWakeUp === null || $occurrence < $nextWakeUp)) {
                $nextWakeUp = $occurrence;
            }
            $times = [$occurrence];
            foreach ($config['actions'] as $action) {
                $times[] = $occurrence + $action['offset'];
            }
            foreach ($times as $time) {
                if ($time > $now && ($next === null || $time < $next)) {
                    $next = $time;
                }
            }
        }

        if ($next === null) {
            $this->SendDebug('Schedule', 'Kein nächster Zeitpunkt gefunden', 0);
            $this->SetTimerInterval(self::TIMER, 0);
            return;
        }

        $interval = max(100, (int) ceil(($next - microtime(true)) * 1000));
        $this->SetTimerInterval(self::TIMER, $interval);
        $this->SendDebug('Schedule', 'Nächster Zeitpunkt: ' . $this->FormatTime($next) . ', nächste Weckzeit: ' . ($nextWakeUp === null ? '-' : $this->FormatTime($nextWakeUp)), 0);
    }

    // Liefert alle Aktionszeitpunkte im Intervall ($from, $to], sortiert nach Zeit und Listenreihenfolge.
    private function GetActionEvents(array $config, int $from, int $to): array
    {
        if ($to <= $from || count($config['actions']) === 0) {
            return [];
        }

        $range = self::MAX_OFFSET * 60;
        $events = [];
        foreach ($this->GetOccurrences($config, $from - $range, $to + $range) as $occurrence) {
            foreach ($config['actions'] as $order => $action) {
                $time = $occurrence + $action['offset'];
                if ($time > $from && $time <= $to) {
                    $events[] = ['time' => $time, 'order' => $order, 'occurrence' => $occurrence, 'action' => $action];
                }
            }
        }
        usort($events, static function (array $a, array $b): int {
            return [$a['time'], $a['order']] <=> [$b['time'], $b['order']];
        });
        return $events;
    }

    // Liefert alle Weckzeitpunkte im Intervall [$from, $to] in der lokalen Zeitzone.
    private function GetOccurrences(array $config, int $from, int $to): array
    {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $day = (new DateTimeImmutable('@' . $from))->setTimezone($timezone)->setTime(0, 0)->modify('-1 day');
        $end = (new DateTimeImmutable('@' . $to))->setTimezone($timezone)->setTime(0, 0)->modify('+1 day');

        $occurrences = [];
        while ($day <= $end) {
            if (isset($config['weekdays'][(int) $day->format('N')])) {
                // Nicht existierende Uhrzeiten (Zeitumstellung) verschiebt PHP auf die nächste gültige Uhrzeit.
                $occurrence = $day->setTime($config['hour'], $config['minute'], $config['second'])->getTimestamp();
                if ($occurrence >= $from && $occurrence <= $to) {
                    $occurrences[] = $occurrence;
                }
            }
            $day = $day->modify('+1 day')->setTime(0, 0);
        }
        return $occurrences;
    }

    private function ReadExecuted(): array
    {
        $executed = json_decode($this->ReadAttributeString('Executed'), true);
        if (!is_array($executed)) {
            // Reine Verwaltungsdaten ohne Benutzerinhalt; ein Neuaufbau ist sicher, da LastProcessed
            // eine erneute Ausführung bereits abgearbeiteter Zeitpunkte verhindert.
            $this->SendDebug('State', 'Ausführungsprotokoll nicht lesbar, wird neu aufgebaut', 0);
            return [];
        }
        $result = [];
        foreach ($executed as $occurrence => $keys) {
            if (is_array($keys)) {
                $result[(string) $occurrence] = array_values(array_filter($keys, 'is_string'));
            }
        }
        return $result;
    }

    // Beim Deaktivieren wird das Zeitfenster verworfen, damit beim Reaktivieren nichts nachgeholt wird.
    private function ResetState(): void
    {
        if ($this->ReadAttributeInteger('LastProcessed') !== -1) {
            $this->WriteAttributeInteger('LastProcessed', -1);
        }
        if ($this->ReadAttributeString('Executed') !== '{}') {
            $this->WriteAttributeString('Executed', '{}');
        }
    }

    private function FormatTime(int $timestamp): string
    {
        return date('d.m.Y H:i:s', $timestamp);
    }
}
