<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Ausschalten gestarteter Mediaplayer nach der am Wecker eingestellten Abspielzeit. Die Abstimmung zwischen den
// Weckern (jüngster Start gewinnt) übernimmt WakeUpActions. Weckzeiten liegen relativ zu jetzt (echte Uhrzeit).
class MediaStopTest extends WakeUpManagerTestCase
{
    private int $catalog;
    private int $isDay;

    protected function setUp(): void
    {
        parent::setUp();
        $seconds = time() - (new DateTimeImmutable('today'))->getTimestamp();
        if ($seconds < 900 || $seconds > 86400 - 900) {
            $this->markTestSkipped('Too close to midnight for wake-up times relative to now');
        }
        $this->isDay = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
        SetValue($this->isDay, false);
        $this->catalog = $this->createMediaCatalog();
    }

    public function testStartedMediaPlayerIsSwitchedOffAfterRunTime(): void
    {
        $manager = $this->startedManager(30);
        $this->assertSame(['Radio'], $this->runs());

        $planned = $this->pendingStops($manager);
        $this->assertEqualsWithDelta(time() + 30 * 60, $planned['M'], 5);
        // Timer läuft spätestens bis zum Ausschalten
        $this->assertLessThanOrEqual(30 * 60 * 1000, $this->call($manager, 'GetTimerInterval', 'WakeUpTimer'));

        $this->makeDue($manager);
        $this->assertSame(['Stop'], $this->runs());
        $this->assertSame([], $this->pendingStops($manager));
    }

    public function testNoSwitchOffWithoutRunTime(): void
    {
        $manager = $this->startedManager(0);
        $this->assertSame(['Radio'], $this->runs());
        $this->assertSame([], $this->pendingStops($manager));
    }

    public function testNoSwitchOffWithoutSwitchOffAction(): void
    {
        $this->catalog = $this->createMediaCatalog(['SwitchOff' => 0]);
        $manager = $this->startedManager(30);
        $this->assertSame(['Radio'], $this->runs());
        $this->assertSame([], $this->pendingStops($manager));
    }

    public function testSkippedStartIsNotSwitchedOff(): void
    {
        SetValue($this->isDay, true);
        // Format von SelectCondition: nur nachts ("Ist es Tag" = false)
        $condition = [['id' => 0, 'parentID' => 0, 'operation' => 0, 'rules' => ['variable' => [['id' => 0, 'variableID' => $this->isDay, 'comparison' => 0, 'value' => false, 'type' => 0]]]]];
        $this->catalog = $this->createMediaCatalog(['Condition' => json_encode($condition)]);
        $manager = $this->startedManager(30);

        $this->assertSame([], $this->runs());
        $this->assertSame([], $this->pendingStops($manager));
    }

    public function testNewerAlarmWins(): void
    {
        $older = $this->startedManager(30);
        $newer = $this->startedManager(60);
        $this->runs();

        // Der ältere Wecker schaltet nicht aus, was der neuere gestartet hat
        $this->makeDue($older);
        $this->assertSame([], $this->runs());

        $this->makeDue($newer);
        $this->assertSame(['Stop'], $this->runs());
    }

    public function testManualStartPreventsPlannedSwitchOff(): void
    {
        $manager = $this->startedManager(30);
        $manager->RequestAction('RunActions', true);
        $this->assertSame(['Radio', 'Radio'], $this->runs());
        // Der Start von Hand plant selbst kein Ausschalten, das eigene geplante Ausschalten entfällt
        $this->assertSame([], $this->pendingStops($manager));
        $this->assertSame([], json_decode($this->call($manager, 'GetTileState'), true)['running']);
    }

    public function testManualStartInAnotherAlarmPreventsPlannedSwitchOff(): void
    {
        $manager = $this->startedManager(30);
        $other = $this->startedManager(0);
        $other->RequestAction('RunActions', true);
        $this->runs();
        $this->assertCount(1, $this->pendingStops($manager));

        $this->makeDue($manager);
        $this->assertSame([], $this->runs());
    }

    public function testSwitchOffSurvivesDeactivatingTheAlarm(): void
    {
        $manager = $this->startedManager(30);
        $this->runs();

        $manager->RequestAction('SetActive', false);
        $this->assertCount(1, $this->pendingStops($manager));
        $this->assertGreaterThan(0, $this->call($manager, 'GetTimerInterval', 'WakeUpTimer'));

        $this->makeDue($manager);
        $this->assertSame(['Stop'], $this->runs());
        $this->assertSame(0, $this->call($manager, 'GetTimerInterval', 'WakeUpTimer'));
    }

    public function testSwitchOffIsCaughtUpAfterRestart(): void
    {
        $manager = $this->startedManager(30);
        $this->runs();

        // Weit über die Toleranz für verpasste Aktionen hinaus
        $this->writeAttributes($manager, ['PendingStops' => ['M' => time() - 3 * 3600]]);
        $manager->MessageSink(time(), 0, IPS_KERNELSTARTED, []);

        $this->assertSame(['Stop'], $this->runs());
    }

    // Start, Ausschalten und übersprungenes Ausschalten stehen im Meldungsfenster (ohne Debug-Fenster nachvollziehbar)
    public function testStartAndSwitchOffAreLogged(): void
    {
        $manager = $this->startedManager(30);
        $planned = date('d.m.Y H:i:s', $this->pendingStops($manager)['M']);
        $this->assertSame(['Media player started: Radio, switch-off planned for ' . $planned], $this->logs($this->instanceID($manager)));

        $this->makeDue($manager);
        $owner = IPS_GetName($this->instanceID($manager)) . ' (#' . $this->instanceID($manager) . ')';
        $this->assertSame(['Media player switched off: Radio (alarm clock ' . $owner . ')'], $this->logs($this->catalog));
    }

    public function testSkippedSwitchOffIsLogged(): void
    {
        $manager = $this->startedManager(30);
        $this->startedManager(0)->RequestAction('RunActions', true);
        $this->makeDue($manager);

        $owner = IPS_GetName($this->instanceID($manager)) . ' (#' . $this->instanceID($manager) . ')';
        $this->assertSame(['Switch-off of Radio by ' . $owner . ' skipped, started later by manual start'], $this->logs($this->catalog));
    }

    public function testStartWithoutSwitchOffIsLogged(): void
    {
        $manager = $this->startedManager(0);
        $this->assertSame(['Media player started: Radio'], $this->logs($this->instanceID($manager)));
    }

    // Laufender Weckvorgang in der maximierten Kachel: Anzeige, "+15 min", "Nicht ausschalten", "Weckvorgang beenden"
    public function testTileShowsRunningMediaPlayer(): void
    {
        $manager = $this->startedManager(30);
        $stop = $this->pendingStops($manager)['M'];

        $running = json_decode($this->call($manager, 'GetTileState'), true)['running'];
        $this->assertSame([['id' => 'M', 'name' => 'Radio', 'stop' => $stop, 'stopTime' => date('H:i', $stop)]], $running);
    }

    public function testExtendStopPostponesBy15Minutes(): void
    {
        $manager = $this->startedManager(30);
        $stop = $this->pendingStops($manager)['M'];

        $manager->RequestAction('ExtendStop', 'M');
        $manager->RequestAction('ExtendStop', 'M');

        $this->assertSame(['M' => $stop + 1800], $this->pendingStops($manager));
        $this->assertContains('Switch-off of Radio postponed to ' . date('d.m.Y H:i:s', $stop + 1800), $this->logs($this->instanceID($manager)));
    }

    public function testCancelStopKeepsPlayerRunning(): void
    {
        $manager = $this->startedManager(30);
        $this->runs();

        $manager->RequestAction('CancelStop', 'M');

        $this->assertSame([], $this->pendingStops($manager));
        $this->assertSame([], json_decode($this->call($manager, 'GetTileState'), true)['running']);
        $this->assertContains('Switch-off of Radio cancelled', $this->logs($this->instanceID($manager)));
        $this->assertSame([], $this->runs());
    }

    public function testChangeOfStopThatIsNoLongerPlannedIsIgnored(): void
    {
        $manager = $this->startedManager(0);
        $manager->RequestAction('ExtendStop', 'M');
        $manager->RequestAction('CancelStop', 'X');
        $this->assertSame([], $this->pendingStops($manager));

        try {
            $manager->RequestAction('ExtendStop', '');
            $this->fail('Empty ID accepted');
        } catch (Exception $e) {
            $this->assertSame('Invalid value', $e->getMessage());
        }
    }

    public function testEndWakeUpSwitchesOffImmediately(): void
    {
        $manager = $this->startedManager(30);
        $this->runs();

        $manager->RequestAction('EndWakeUp', '');

        $this->assertSame(['Stop'], $this->runs());
        $this->assertSame([], $this->pendingStops($manager));
        $this->assertSame([], json_decode($this->call($manager, 'GetTileState'), true)['running']);
        $this->assertContains('Wake-up routine ended by hand', $this->logs($this->instanceID($manager)));

        // Nichts mehr laufend: kein weiteres Ausschalten
        $manager->RequestAction('EndWakeUp', '');
        $this->assertSame([], $this->runs());
    }

    public function testEndWakeUpRespectsNewerStart(): void
    {
        $older = $this->startedManager(30);
        $this->startedManager(60);
        $this->runs();

        $older->RequestAction('EndWakeUp', '');
        $this->assertSame([], $this->runs());
    }

    public function testRunTimeChangeAppliesFromNextWakeUp(): void
    {
        $manager = $this->startedManager(30);
        $planned = $this->pendingStops($manager);

        $manager->RequestAction('SetAction', json_encode(['ID' => 'M', 'RunTime' => 90]));

        $this->assertSame($planned, $this->pendingStops($manager));
        $this->assertSame(90, json_decode($this->call($manager, 'GetTileState'), true)['groups'][0]['actions'][0]['runTime']);
    }

    public function testInvalidRunTimeIsRejected(): void
    {
        $manager = $this->startedManager(30);
        foreach ([-1, 721, 2.5, 'abc'] as $value) {
            try {
                $manager->RequestAction('SetAction', json_encode(['ID' => 'M', 'RunTime' => $value]));
                $this->fail('Value accepted: ' . var_export($value, true));
            } catch (Exception $e) {
                $this->assertSame('Invalid value', $e->getMessage());
            }
        }
    }

    public function testRunTimeIsOnlyShownWithSwitchOffAction(): void
    {
        $this->catalog = $this->createMediaCatalog(['SwitchOff' => 0]);
        $manager = $this->createManager($this->catalog);

        $row = json_decode($this->call($manager, 'GetTileState'), true)['groups'][0]['actions'][0];
        $this->assertArrayNotHasKey('runTime', $row);
    }

    public function testCoordinationRejectsInvalidRequests(): void
    {
        foreach ([['MediaStop', '{"ID":"M"}'], ['MediaStop', '{"ID":"","Owner":1}'], ['MediaStarted', '{"ID":"M","Owner":-1}'], ['MediaStop', 'abc']] as [$ident, $value]) {
            try {
                IPS_RequestAction($this->catalog, $ident, $value);
                $this->fail('Value accepted: ' . $value);
            } catch (Exception $e) {
                $this->assertSame('Invalid value', $e->getMessage());
            }
        }
        $this->assertSame([], $this->runs());
    }

    // Wecker, der die Mediaplayer-Aktion beim Start (Nachholen innerhalb der Toleranz) gerade ausgeführt hat
    private function startedManager(int $runTime): WakeUpManager
    {
        $time = time() - 120;
        return $this->createManager($this->catalog, [
            'Active'        => true,
            'WakeUpTime'    => $this->clock((int) date('G', $time), (int) date('i', $time), (int) date('s', $time)),
            'Weekdays'      => [1, 2, 3, 4, 5, 6, 7],
            'Selection'     => [['ID' => 'M', 'Active' => true, 'Offset' => 0, 'RunTime' => $runTime]],
            'LastProcessed' => time() - 600
        ]);
    }

    private function createMediaCatalog(array $change = []): int
    {
        $id = IPS_CreateInstance(self::ACTIONS_MODULE);
        IPS_SetProperty($id, 'ActionsMedia', json_encode([$change + [
            'ID'            => 'M',
            'Name'          => 'Radio',
            'Action'        => json_encode(['actionID' => self::RECORD_ACTION, 'parameters' => ['name' => 'Radio']]),
            'Condition'     => '',
            'VolumeControl' => 0,
            'SwitchOff'     => 1,
            'StopAction'    => json_encode(['actionID' => self::RECORD_ACTION, 'parameters' => ['name' => 'Stop']])
        ]]));
        IPS_ApplyChanges($id);
        return $id;
    }

    private function pendingStops(WakeUpManager $manager): array
    {
        return json_decode($this->call($manager, 'ReadAttributeString', 'PendingStops'), true);
    }

    private function instanceID(WakeUpManager $manager): int
    {
        return (new ReflectionProperty($manager, 'InstanceID'))->getValue($manager);
    }

    // Meldungen einer Instanz (Stufe KL_MESSAGE)
    private function logs(int $id): array
    {
        $logs = array_filter(IPS\LogServer::getLogMessages((string) $id), static fn (array $log): bool => $log['Type'] === KL_MESSAGE);
        return array_values(array_map(static fn (array $log): string => $log['Message'], $logs));
    }

    // Geplantes Ausschalten auf jetzt vorziehen und den Timer auslösen
    private function makeDue(WakeUpManager $manager): void
    {
        $this->writeAttributes($manager, ['PendingStops' => array_map(static fn (): int => time() - 1, $this->pendingStops($manager))]);
        $manager->RequestAction('ProcessTimer', 0);
    }
}
