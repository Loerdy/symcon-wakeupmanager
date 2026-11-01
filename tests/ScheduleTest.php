<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Zeitberechnung mit festen Zeitpunkten (Europe/Berlin): Zeitumstellung, Mitternacht, Wochentage, Nachholen.
class ScheduleTest extends WakeUpManagerTestCase
{
    private WakeUpManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->createManager($this->createCatalog());
    }

    public function testSkippedHourOnSpringForwardMovesToNextValidTime(): void
    {
        $config = $this->config($this->clock(2, 30));
        $occurrences = $this->call($this->manager, 'GetOccurrences', $config, $this->timestamp('2026-03-29 00:00'), $this->timestamp('2026-03-29 23:59'));
        $this->assertSame([$this->timestamp('2026-03-29 03:30')], $occurrences);
    }

    public function testRepeatedHourOnFallBackTriggersOnlyOnce(): void
    {
        $config = $this->config($this->clock(2, 30));
        $occurrences = $this->call($this->manager, 'GetOccurrences', $config, $this->timestamp('2026-10-25 00:00'), $this->timestamp('2026-10-25 23:59'));
        $this->assertSame([$this->timestamp('2026-10-25 02:30:00+02:00')], $occurrences);
    }

    public function testOffsetIsRealDurationAcrossDaylightSavingChange(): void
    {
        $config = $this->config($this->clock(6, 30), [], [['key' => 'H', 'label' => 'Heat', 'offset' => -480 * 60]]);
        $events = $this->call($this->manager, 'GetActionEvents', $config, $this->timestamp('2026-03-28 12:00'), $this->timestamp('2026-03-28 23:59'));
        $this->assertCount(1, $events);
        // 06:30 Sommerzeit minus 8 Stunden = 21:30 Winterzeit am Vortag
        $this->assertSame($this->timestamp('2026-03-28 21:30'), $events[0]['time']);
        $this->assertSame($this->timestamp('2026-03-29 06:30'), $events[0]['occurrence']);
    }

    public function testLeadTimeFallsOnDisabledPreviousDay(): void
    {
        // Nur Montag aktiv; Heizung −480 am Sonntag 22:30
        $config = $this->config($this->clock(6, 30), [1], [['key' => 'H', 'label' => 'Heat', 'offset' => -480 * 60]]);
        $events = $this->call($this->manager, 'GetActionEvents', $config, $this->timestamp('2026-11-01 20:00'), $this->timestamp('2026-11-01 23:00'));
        $this->assertCount(1, $events);
        $this->assertSame($this->timestamp('2026-11-01 22:30'), $events[0]['time']);
        $this->assertSame($this->timestamp('2026-11-02 06:30'), $events[0]['occurrence']);
    }

    public function testDisabledWeekdaysHaveNoOccurrence(): void
    {
        $config = $this->config($this->clock(6, 30), [1, 2, 3, 4, 5]);
        $occurrences = $this->call($this->manager, 'GetOccurrences', $config, $this->timestamp('2026-10-31 00:00'), $this->timestamp('2026-11-01 23:59'));
        $this->assertSame([], $occurrences);
    }

    public function testSeparateWeekendTime(): void
    {
        $config = $this->config($this->clock(6, 30));
        $config['times'][6] = $this->clock(8, 0);
        $config['times'][7] = $this->clock(8, 0);
        $points = $this->call($this->manager, 'GetNextPoints', $config, $this->timestamp('2026-10-30 07:00'));
        $this->assertSame($this->timestamp('2026-10-31 08:00'), $points['wakeUp']);
    }

    public function testNextPointIsMidnightBeforeWakeDay(): void
    {
        $config = $this->config($this->clock(6, 30));
        $points = $this->call($this->manager, 'GetNextPoints', $config, $this->timestamp('2026-11-03 23:00'));
        $this->assertSame($this->timestamp('2026-11-04 00:00'), $points['next']);
        $this->assertSame($this->timestamp('2026-11-04 06:30'), $points['wakeUp']);
    }

    public function testNextPointIsWakeUpOnWakeDay(): void
    {
        $config = $this->config($this->clock(6, 30));
        $points = $this->call($this->manager, 'GetNextPoints', $config, $this->timestamp('2026-11-04 00:00'));
        $this->assertSame($this->timestamp('2026-11-04 06:30'), $points['next']);
    }

    public function testSaturdayAlarmRecalculatesOnlyBeforeFridayAndSaturday(): void
    {
        $config = $this->config($this->clock(8, 0), [6]);
        $points = $this->call($this->manager, 'GetNextPoints', $config, $this->timestamp('2026-11-02 20:00'));
        $this->assertSame($this->timestamp('2026-11-06 00:00'), $points['next']);
        $points = $this->call($this->manager, 'GetNextPoints', $config, $this->timestamp('2026-11-06 00:00'));
        $this->assertSame($this->timestamp('2026-11-07 00:00'), $points['next']);
    }

    public function testNextPointIsHeatingBeforeMidnight(): void
    {
        $config = $this->config($this->clock(6, 30), [], [['key' => 'H', 'label' => 'Heat', 'offset' => -480 * 60]]);
        $points = $this->call($this->manager, 'GetNextPoints', $config, $this->timestamp('2026-11-03 22:00'));
        $this->assertSame($this->timestamp('2026-11-03 22:30'), $points['next']);
    }

    public function testNextPointAcrossFallBack(): void
    {
        $config = $this->config($this->clock(6, 30));
        $points = $this->call($this->manager, 'GetNextPoints', $config, $this->timestamp('2026-10-25 01:00'));
        $this->assertSame($this->timestamp('2026-10-25 06:30'), $points['next']);
    }

    public function testNoWeekdayMeansNoNextPoint(): void
    {
        $config = $this->config($this->clock(6, 30));
        $config['weekdays'] = [];
        $points = $this->call($this->manager, 'GetNextPoints', $config, $this->timestamp('2026-11-03 22:00'));
        $this->assertNull($points['next']);
        $this->assertNull($points['wakeUp']);
    }

    public function testDueActionIsTakenOnlyOnce(): void
    {
        $wake = $this->timestamp('2026-11-04 06:30');
        $config = $this->config($this->clock(6, 30), [], [['key' => 'L', 'label' => 'Light', 'offset' => 0]]);
        $this->writeAttributes($this->manager, ['LastProcessed' => $wake - 10]);

        $this->assertSame(['Light'], $this->labels($this->call($this->manager, 'TakeDueActions', $config, $wake + 1)));
        $this->assertSame([], $this->labels($this->call($this->manager, 'TakeDueActions', $config, $wake + 2)));
    }

    public function testMissedActionIsCaughtUpWithinGracePeriod(): void
    {
        $wake = $this->timestamp('2026-11-04 06:30');
        $config = $this->config($this->clock(6, 30), [], [['key' => 'L', 'label' => 'Light', 'offset' => 0]]);
        $this->writeAttributes($this->manager, ['LastProcessed' => $wake - 600]);

        $this->assertSame(['Light'], $this->labels($this->call($this->manager, 'TakeDueActions', $config, $wake + 240)));
    }

    public function testMissedActionIsNotCaughtUpAfterGracePeriod(): void
    {
        $wake = $this->timestamp('2026-11-04 06:30');
        $config = $this->config($this->clock(6, 30), [], [['key' => 'L', 'label' => 'Light', 'offset' => 0]]);
        $this->writeAttributes($this->manager, ['LastProcessed' => $wake - 600]);

        $this->assertSame([], $this->labels($this->call($this->manager, 'TakeDueActions', $config, $wake + 360)));
    }

    public function testNothingIsCaughtUpAfterActivation(): void
    {
        $wake = $this->timestamp('2026-11-04 06:30');
        $config = $this->config($this->clock(6, 30), [], [['key' => 'L', 'label' => 'Light', 'offset' => 0]]);
        $this->writeAttributes($this->manager, ['LastProcessed' => -1]);

        $this->assertSame([], $this->labels($this->call($this->manager, 'TakeDueActions', $config, $wake + 10)));
    }

    public function testMovingWakeUpTimeDoesNotRepeatExecutedHeating(): void
    {
        // Heizung −60 lief um 05:30 für 06:30; danach Weckzeit auf 06:45 verschoben -> Heizung um 05:45 nicht erneut
        $heat = [['key' => 'H', 'label' => 'Heat', 'offset' => -3600]];
        $this->writeAttributes($this->manager, ['LastProcessed' => $this->timestamp('2026-11-04 05:29:50')]);
        $this->assertSame(['Heat'], $this->labels($this->call($this->manager, 'TakeDueActions', $this->config($this->clock(6, 30), [], $heat), $this->timestamp('2026-11-04 05:30:01'))));

        $moved = $this->config($this->clock(6, 45), [], $heat);
        $this->assertSame([], $this->labels($this->call($this->manager, 'TakeDueActions', $moved, $this->timestamp('2026-11-04 05:45:01'))));
    }

    public function testMovingWakeUpTimeAfterWakingDoesNotWakeAgain(): void
    {
        $light = [['key' => 'L', 'label' => 'Light', 'offset' => 0]];
        $this->writeAttributes($this->manager, ['LastProcessed' => $this->timestamp('2026-11-04 06:29:50')]);
        $this->assertSame(['Light'], $this->labels($this->call($this->manager, 'TakeDueActions', $this->config($this->clock(6, 30), [], $light), $this->timestamp('2026-11-04 06:30:01'))));

        $moved = $this->config($this->clock(6, 45), [], $light);
        $this->assertSame([], $this->labels($this->call($this->manager, 'TakeDueActions', $moved, $this->timestamp('2026-11-04 06:45:01'))));
    }

    public function testExecutedActionsRunAgainOnTheNextWakeDay(): void
    {
        $heat = [['key' => 'H', 'label' => 'Heat', 'offset' => -3600]];
        $config = $this->config($this->clock(6, 30), [], $heat);
        $this->writeAttributes($this->manager, ['LastProcessed' => $this->timestamp('2026-11-04 05:29:50')]);
        $this->assertSame(['Heat'], $this->labels($this->call($this->manager, 'TakeDueActions', $config, $this->timestamp('2026-11-04 05:30:01'))));

        $this->writeAttributes($this->manager, ['LastProcessed' => $this->timestamp('2026-11-05 05:29:50')]);
        $this->assertSame(['Heat'], $this->labels($this->call($this->manager, 'TakeDueActions', $config, $this->timestamp('2026-11-05 05:30:01'))));
    }

    public function testLeadTimeOnPreviousDayBelongsToTheWakeDay(): void
    {
        // Heizung −480 lief Montag 22:30 für Dienstag 06:30; Weckzeit auf 07:00 verschoben -> nicht um 23:00 erneut
        $heat = [['key' => 'H', 'label' => 'Heat', 'offset' => -480 * 60]];
        $this->writeAttributes($this->manager, ['LastProcessed' => $this->timestamp('2026-11-02 22:29:50')]);
        $this->assertSame(['Heat'], $this->labels($this->call($this->manager, 'TakeDueActions', $this->config($this->clock(6, 30), [], $heat), $this->timestamp('2026-11-02 22:30:01'))));

        $moved = $this->config($this->clock(7, 0), [], $heat);
        $this->assertSame([], $this->labels($this->call($this->manager, 'TakeDueActions', $moved, $this->timestamp('2026-11-02 23:00:01'))));
    }

    public function testExecutionLogInPreviousFormatIsRespected(): void
    {
        // Protokoll vor dem Update: Schlüssel = Weckzeitpunkt als Unix-Zeitstempel
        $this->writeAttributes($this->manager, [
            'LastProcessed' => $this->timestamp('2026-11-04 05:30:01'),
            'Executed'      => json_encode([(string) $this->timestamp('2026-11-04 06:30') => ['H']])
        ]);

        $moved = $this->config($this->clock(6, 45), [], [['key' => 'H', 'label' => 'Heat', 'offset' => -3600]]);
        $this->assertSame([], $this->labels($this->call($this->manager, 'TakeDueActions', $moved, $this->timestamp('2026-11-04 05:45:01'))));
    }

    // Konfiguration wie LoadConfiguration(); ohne Wochentage alle Tage aktiv.
    private function config(array $time, array $weekdays = [], array $actions = []): array
    {
        $days = $weekdays === [] ? [1, 2, 3, 4, 5, 6, 7] : $weekdays;
        return [
            'times'    => array_fill(1, 7, $time),
            'weekdays' => array_fill_keys($days, true),
            'catalog'  => [
                'L' => ['name' => 'Light', 'group' => 'light', 'actionID' => self::RECORD_ACTION, 'parameters' => ['name' => 'Light']],
                'H' => ['name' => 'Heat', 'group' => 'heating', 'actionID' => self::RECORD_ACTION, 'parameters' => ['name' => 'Heat']]
            ],
            'actions'  => $actions
        ];
    }

    private function labels(array $due): array
    {
        return array_column($due, 'label');
    }
}
