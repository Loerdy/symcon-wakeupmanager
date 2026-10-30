<?php

declare(strict_types=1);

class WakeUpManager extends IPSModuleStrict
{
    // Verpasste Zeitpunkte werden nur innerhalb dieser Toleranz (Sekunden) nachgeholt.
    private const GRACE_PERIOD = 300;

    // Maximaler Zeitversatz einer Aktion in Minuten (vorher bzw. nachher).
    private const MAX_OFFSET = 1440;

    private const TIMER = 'WakeUpTimer';

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

    private const STATUS_INVALID_TIME = 201;
    private const STATUS_NO_WEEKDAY = 202;
    private const STATUS_INVALID_ACTIONS = 203;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', false);
        $this->RegisterPropertyString('WakeUpTime', '{"hour":6,"minute":30,"second":0}');
        foreach (self::WEEKDAYS as $day => $name) {
            $this->RegisterPropertyBoolean($name, $day <= 5);
        }
        $this->RegisterPropertyString('Actions', '[]');

        // Ende des zuletzt abgearbeiteten Zeitfensters (Unix-Zeitstempel); -1 = Wecker war deaktiviert, nichts nachholen.
        $this->RegisterAttributeInteger('LastProcessed', -1);
        // Bereits ausgeführte Aktionen je Weckvorgang: {"<Weckzeitpunkt>": ["<Aktionsschlüssel>", ...]}
        $this->RegisterAttributeString('Executed', '{}');

        $this->RegisterTimer(self::TIMER, 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'ProcessTimer\', 0);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->Process();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->Process();
        }
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'ProcessTimer':
                $this->Process();
                break;
            default:
                throw new Exception('Invalid Ident');
        }
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
            $this->SetStatus(IS_ACTIVE);

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

        $rows = json_decode($this->ReadPropertyString('Actions'), true);
        if (!is_array($rows) || !array_is_list($rows)) {
            $this->SendDebug('Configuration', 'Aktionsliste ist nicht lesbar', 0);
            $this->LogMessage('Die Aktionsliste des Weckers ist nicht lesbar', KL_ERROR);
            $this->SetStatus(self::STATUS_INVALID_ACTIONS);
            return null;
        }

        return [
            'hour'     => $time['hour'],
            'minute'   => $time['minute'],
            'second'   => $time['second'],
            'weekdays' => $weekdays,
            'actions'  => $this->ParseActions($rows)
        ];
    }

    private function IsValidTimePart(array $time, string $key, int $maximum): bool
    {
        return isset($time[$key]) && is_int($time[$key]) && $time[$key] >= 0 && $time[$key] <= $maximum;
    }

    // Fehlerhafte Einträge werden einzeln übersprungen, damit die übrigen Aktionen ausgeführt werden.
    private function ParseActions(array $rows): array
    {
        $actions = [];
        $keyCount = [];
        foreach ($rows as $index => $row) {
            $label = 'Aktion ' . ($index + 1);
            if (!is_array($row)) {
                $this->SendDebug('Configuration', $label . ': ungültiger Eintrag', 0);
                continue;
            }
            if (isset($row['Name']) && is_string($row['Name']) && $row['Name'] !== '') {
                $label .= ' (' . $row['Name'] . ')';
            }
            if (isset($row['Active']) && $row['Active'] === false) {
                continue;
            }

            $offset = $row['Offset'] ?? 0;
            if (is_float($offset) && floor($offset) === $offset) {
                $offset = (int) $offset;
            }
            if (!is_int($offset) || abs($offset) > self::MAX_OFFSET) {
                $this->SendDebug('Configuration', $label . ': ungültiger Zeitversatz', 0);
                continue;
            }

            // Der Wert von SelectAction ist JSON-kodiert; je nach Speicherung kann er bereits als Array vorliegen.
            $action = $row['Action'] ?? null;
            if (is_string($action)) {
                $action = json_decode($action, true);
            }
            if (!is_array($action) || !isset($action['actionID']) || !is_string($action['actionID']) || $action['actionID'] === '') {
                $this->SendDebug('Configuration', $label . ': keine Aktion ausgewählt', 0);
                continue;
            }
            $parameters = $action['parameters'] ?? [];
            if (!is_array($parameters)) {
                $this->SendDebug('Configuration', $label . ': ungültige Aktionsparameter', 0);
                continue;
            }

            // Der Schlüssel hängt nicht vom Zeitversatz ab, damit eine bereits ausgeführte Aktion nach einer
            // Änderung des Zeitversatzes im selben Weckvorgang nicht erneut ausgeführt wird.
            $hash = md5($action['actionID'] . json_encode($parameters));
            $keyCount[$hash] = ($keyCount[$hash] ?? 0) + 1;

            $actions[] = [
                'key'        => $hash . '#' . $keyCount[$hash],
                'label'      => $label,
                'offset'     => $offset * 60,
                'actionID'   => $action['actionID'],
                'parameters' => $parameters
            ];
        }
        return $actions;
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
            $this->RunAction($event['action']);
        }
    }

    private function RunAction(array $action): void
    {
        try {
            if (IPS_RunAction($action['actionID'], $action['parameters'])) {
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
