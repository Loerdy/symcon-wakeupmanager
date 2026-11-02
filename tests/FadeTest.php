<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Startlautstärke und Fading von Mediaplayern. Die Lautstärke-Variable hat ein Aktionsskript, das jeden gesetzten Wert
// in den Ablauf schreibt ("Volume <Wert>"). Weckzeiten liegen relativ zu jetzt (echte Uhrzeit).
class FadeTest extends WakeUpManagerTestCase
{
    private int $volume;
    private int $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $seconds = time() - (new DateTimeImmutable('today'))->getTimestamp();
        if ($seconds < 900 || $seconds > 86400 - 900) {
            $this->markTestSkipped('Too close to midnight for wake-up times relative to now');
        }
        $this->volume = $this->createVolumeVariable(VARIABLETYPE_INTEGER);
        $this->catalog = $this->createMediaCatalog();
    }

    public function testStartVolumeIsSetBeforeStartAndFadingIsPlanned(): void
    {
        $manager = $this->startedManager();

        $this->assertSame(['Volume 10', 'Radio'], $this->runs());
        $fade = $this->fades($manager)['M'];
        // 10 → 30 in 10 Minuten: 20 Stufen, alle 30 Sekunden
        $this->assertSame(20, $fade['Steps']);
        $this->assertLessThanOrEqual(30 * 1000, $this->call($manager, 'GetTimerInterval', 'WakeUpTimer'));
    }

    public function testStepsRaiseTheVolumeUntilTheTarget(): void
    {
        $manager = $this->startedManager();
        $this->runs();

        $this->shiftBegin($manager, 30);
        $manager->RequestAction('ProcessTimer', 0);
        $this->assertSame(['Volume 11'], $this->runs());

        // Zur Hälfte der Dauer
        $this->shiftBegin($manager, 270);
        $manager->RequestAction('ProcessTimer', 0);
        $this->assertSame(['Volume 20'], $this->runs());

        $this->shiftBegin($manager, 300);
        $manager->RequestAction('ProcessTimer', 0);
        $this->assertSame(['Volume 30'], $this->runs());
        $this->assertSame([], $this->fades($manager));
    }

    public function testWithoutFadingTheTargetVolumeIsSetDirectly(): void
    {
        $manager = $this->startedManager(false);

        $this->assertSame(['Volume 30', 'Radio'], $this->runs());
        $this->assertSame([], $this->fades($manager));
    }

    public function testManualVolumeChangeStopsFading(): void
    {
        $manager = $this->startedManager();
        $this->shiftBegin($manager, 30);
        $manager->RequestAction('ProcessTimer', 0);
        $this->runs();

        SetValue($this->volume, 25);
        $this->shiftBegin($manager, 30);
        $manager->RequestAction('ProcessTimer', 0);

        $this->assertSame([], $this->runs());
        $this->assertSame([], $this->fades($manager));
    }

    public function testFirstStepIgnoresVolumeSetByTheDeviceOnStart(): void
    {
        $manager = $this->startedManager();
        $this->runs();

        // Manche Geräte setzen beim Start der Wiedergabe ihre eigene Lautstärke
        SetValue($this->volume, 50);
        $this->shiftBegin($manager, 30);
        $manager->RequestAction('ProcessTimer', 0);

        $this->assertSame(['Volume 11'], $this->runs());
    }

    public function testRestartSetsTheCurrentVolumeWithoutCatchingUpSteps(): void
    {
        $manager = $this->startedManager();
        $this->runs();

        $this->shiftBegin($manager, 450);
        $manager->MessageSink(time(), 0, IPS_KERNELSTARTED, []);

        $this->assertSame(['Volume 25'], $this->runs());
    }

    public function testFadingAlreadyOverAfterRestartSetsNothing(): void
    {
        $manager = $this->startedManager();
        $this->runs();

        $this->shiftBegin($manager, 3600);
        $manager->MessageSink(time(), 0, IPS_KERNELSTARTED, []);

        $this->assertSame([], $this->runs());
        $this->assertSame([], $this->fades($manager));
    }

    public function testSwitchOffEndsFading(): void
    {
        $manager = $this->startedManager(true, 30);
        $this->runs();

        $this->writeAttributes($manager, ['PendingStops' => ['M' => time() - 1]]);
        $this->shiftBegin($manager, 30);
        $manager->RequestAction('ProcessTimer', 0);

        $this->assertSame(['Stop'], $this->runs());
        $this->assertSame([], $this->fades($manager));
    }

    public function testFloatVariableFadesEvery15Seconds(): void
    {
        $this->volume = $this->createVolumeVariable(VARIABLETYPE_FLOAT);
        $this->catalog = $this->createMediaCatalog(['StartVolume' => 0.1, 'TargetVolume' => 0.4]);
        $manager = $this->startedManager();

        $this->assertSame(['Volume 0.1', 'Radio'], $this->runs());
        $this->assertSame(40, $this->fades($manager)['M']['Steps']);
    }

    public function testRunActionsButtonSetsStartVolumeWithoutFading(): void
    {
        $manager = $this->createManager($this->catalog, ['Selection' => [['ID' => 'M', 'Active' => true, 'Offset' => 0]]]);

        $manager->RequestAction('RunActions', true);

        $this->assertSame(['Volume 10', 'Radio'], $this->runs());
        $this->assertSame([], $this->fades($manager));
    }

    public function testSkippedStartSetsNoVolume(): void
    {
        $isDay = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
        SetValue($isDay, true);
        $condition = [['id' => 0, 'parentID' => 0, 'operation' => 0, 'rules' => ['variable' => [['id' => 0, 'variableID' => $isDay, 'comparison' => 0, 'value' => false, 'type' => 0]]]]];
        $this->catalog = $this->createMediaCatalog(['Condition' => json_encode($condition)]);
        $manager = $this->startedManager();

        $this->assertSame([], $this->runs());
        $this->assertSame([], $this->fades($manager));
    }

    public function testFadingSettingIsValidatedAndShownInTile(): void
    {
        $manager = $this->createManager($this->catalog);
        $this->assertTrue($this->tileRow($manager)['fade']);

        $manager->RequestAction('SetAction', json_encode(['ID' => 'M', 'Fade' => false]));
        $this->assertFalse($this->tileRow($manager)['fade']);

        try {
            $manager->RequestAction('SetAction', json_encode(['ID' => 'M', 'Fade' => 'no']));
            $this->fail('Value accepted');
        } catch (Exception $e) {
            $this->assertSame('Invalid value', $e->getMessage());
        }
    }

    public function testFadingBeforeWakeUpStartsEarlierByTheFadingDuration(): void
    {
        // Weckzeit in 8 Minuten, Fading 10 Minuten: Start vor 2 Minuten fällig (wird beim Start nachgeholt)
        $wakeUp = time() + 8 * 60;
        $manager = $this->wakeUpManager($wakeUp, ['Fade' => true, 'FadeEarly' => true, 'RunTime' => 30]);

        $this->assertSame(['Volume 10', 'Radio'], $this->runs());
        $this->assertArrayHasKey('M', $this->fades($manager));
        // Abspielzeit ab der Weckzeit
        $this->assertSame($wakeUp + 30 * 60, json_decode($this->call($manager, 'ReadAttributeString', 'PendingStops'), true)['M']);
    }

    public function testFadingAtWakeUpDoesNotStartEarlier(): void
    {
        $manager = $this->wakeUpManager(time() + 8 * 60, ['Fade' => true, 'FadeEarly' => false]);

        $this->assertSame([], $this->runs());
        $this->assertSame([], $this->fades($manager));
    }

    public function testFadingBeforeWakeUpNeedsFading(): void
    {
        // Ohne Fading startet der Mediaplayer zur Weckzeit, auch wenn "vor der Weckzeit" gesetzt ist
        $this->wakeUpManager(time() + 8 * 60, ['Fade' => false, 'FadeEarly' => true]);

        $this->assertSame([], $this->runs());
    }

    public function testFadingBeforeWakeUpIsValidatedAndShownInTile(): void
    {
        $manager = $this->createManager($this->catalog);
        $this->assertFalse($this->tileRow($manager)['fadeEarly']);

        $manager->RequestAction('SetAction', json_encode(['ID' => 'M', 'FadeEarly' => true]));
        $this->assertTrue($this->tileRow($manager)['fadeEarly']);

        try {
            $manager->RequestAction('SetAction', json_encode(['ID' => 'M', 'FadeEarly' => 1]));
            $this->fail('Value accepted');
        } catch (Exception $e) {
            $this->assertSame('Invalid value', $e->getMessage());
        }
    }

    public function testFadingIsOnlyShownWithVolumeVariable(): void
    {
        $this->catalog = $this->createMediaCatalog(['VolumeControl' => 0]);
        $manager = $this->createManager($this->catalog);

        $this->assertArrayNotHasKey('fade', $this->tileRow($manager));
    }

    private function createVolumeVariable(int $type): int
    {
        $variable = IPS_CreateVariable($type);
        $script = IPS_CreateScript(0);
        IPS_SetScriptContent($script, '<?php SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]); $GLOBALS["WUM_TEST_RUNS"][] = "Volume " . $_IPS["VALUE"];');
        IPS_SetVariableCustomAction($variable, $script);
        return $variable;
    }

    // Wecker, der die Mediaplayer-Aktion beim Start (Nachholen innerhalb der Toleranz) gerade ausgeführt hat
    private function startedManager(bool $fade = true, int $runTime = 0): WakeUpManager
    {
        $time = time() - 120;
        return $this->createManager($this->catalog, [
            'Active'        => true,
            'WakeUpTime'    => $this->clock((int) date('G', $time), (int) date('i', $time), (int) date('s', $time)),
            'Weekdays'      => [1, 2, 3, 4, 5, 6, 7],
            'Selection'     => [['ID' => 'M', 'Active' => true, 'Offset' => 0, 'RunTime' => $runTime, 'Fade' => $fade]],
            'LastProcessed' => time() - 600
        ]);
    }

    // Wecker mit Weckzeit $wakeUp, der beim Start Zeitpunkte der letzten 10 Minuten nachholt
    private function wakeUpManager(int $wakeUp, array $selection): WakeUpManager
    {
        return $this->createManager($this->catalog, [
            'Active'        => true,
            'WakeUpTime'    => $this->clock((int) date('G', $wakeUp), (int) date('i', $wakeUp), (int) date('s', $wakeUp)),
            'Weekdays'      => [1, 2, 3, 4, 5, 6, 7],
            'Selection'     => [$selection + ['ID' => 'M', 'Active' => true, 'Offset' => 0, 'RunTime' => 0]],
            'LastProcessed' => time() - 600
        ]);
    }

    private function createMediaCatalog(array $change = []): int
    {
        $id = IPS_CreateInstance(self::ACTIONS_MODULE);
        IPS_SetProperty($id, 'ActionsMedia', json_encode([$change + [
            'ID'             => 'M',
            'Name'           => 'Radio',
            'Action'         => json_encode(['actionID' => self::RECORD_ACTION, 'parameters' => ['name' => 'Radio']]),
            'Condition'      => '',
            'VolumeControl'  => 1,
            'VolumeVariable' => $this->volume,
            'StartVolume'    => 10,
            'TargetVolume'   => 30,
            'FadeDuration'   => 10,
            'SwitchOff'      => 1,
            'StopAction'     => json_encode(['actionID' => self::RECORD_ACTION, 'parameters' => ['name' => 'Stop']])
        ]]));
        IPS_ApplyChanges($id);
        return $id;
    }

    private function fades(WakeUpManager $manager): array
    {
        return json_decode($this->call($manager, 'ReadAttributeString', 'Fades'), true);
    }

    // Beginn des Fadings um $seconds in die Vergangenheit verschieben (simuliert verstrichene Zeit)
    private function shiftBegin(WakeUpManager $manager, int $seconds): void
    {
        $fades = $this->fades($manager);
        foreach ($fades as &$fade) {
            $fade['Begin'] -= $seconds;
        }
        $this->writeAttributes($manager, ['Fades' => $fades]);
    }

    private function tileRow(WakeUpManager $manager): array
    {
        return json_decode($this->call($manager, 'GetTileState'), true)['groups'][0]['actions'][0];
    }
}
