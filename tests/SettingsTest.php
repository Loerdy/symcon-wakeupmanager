<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Abläufe über RequestAction und ApplyChanges. Die Modullogik nutzt die echte Uhrzeit, Weckzeiten liegen deshalb
// relativ zu jetzt; kurz vor bzw. nach Mitternacht werden die Tests übersprungen.
class SettingsTest extends WakeUpManagerTestCase
{
    private int $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $seconds = time() - (new DateTimeImmutable('today'))->getTimestamp();
        if ($seconds < 900 || $seconds > 86400 - 900) {
            $this->markTestSkipped('Too close to midnight for wake-up times relative to now');
        }
        $this->catalog = $this->createCatalog();
    }

    public function testRestartCatchesUpOnce(): void
    {
        $manager = $this->createActiveManager(-120, ['LastProcessed' => time() - 600]);
        $this->assertSame(['Light', 'Heat'], $this->runs());

        $manager->ApplyChanges();
        $this->assertSame([], $this->runs());
    }

    public function testRestartAfterGracePeriodDoesNotCatchUp(): void
    {
        $this->createActiveManager(-400, ['LastProcessed' => time() - 900]);
        $this->assertSame([], $this->runs());
    }

    public function testWakeUpTimeInThePastIsNotCaughtUp(): void
    {
        $manager = $this->createActiveManager(3600, ['LastProcessed' => time() - 3600], false);
        $manager->RequestAction('SetWakeUpTime', $this->relative(-120));
        $this->assertSame([], $this->runs());
    }

    public function testMovingWakeUpTimeEarlierAfterWakingDoesNotWakeAgain(): void
    {
        $manager = $this->createActiveManager(-90, ['LastProcessed' => time() - 200]);
        $this->assertSame(['Light', 'Heat'], $this->runs());
        // Wie bei pünktlichem Timer: zuletzt zur Weckzeit verarbeitet
        $this->writeAttributes($manager, ['LastProcessed' => time() - 90]);

        $manager->RequestAction('SetWakeUpTime', $this->relative(-45));
        $this->assertSame([], $this->runs());
    }

    public function testActionsDueUnderPreviousSettingAreStillExecuted(): void
    {
        $manager = $this->createActiveManager(-30, ['LastProcessed' => time() - 60], false);
        $manager->RequestAction('SetWeekday', json_encode(['Day' => (int) date('N', strtotime('+2 days')), 'Active' => false]));
        $this->assertSame(['Light', 'Heat'], $this->runs());
    }

    public function testEnablingTodayAfterWakeUpTimeDoesNotCatchUp(): void
    {
        $manager = $this->createActiveManager(-60, ['LastProcessed' => time() - 3600, 'Weekdays' => '[]']);
        // 202 = kein Wochentag aktiviert
        $this->assertSame(202, $this->call($manager, 'GetStatus'));

        $manager->RequestAction('SetWeekday', json_encode(['Day' => (int) date('N'), 'Active' => true]));
        $this->assertSame([], $this->runs());
    }

    public function testTimerIsSetWhenActiveAndStoppedWhenInactive(): void
    {
        $manager = $this->createActiveManager(3600);
        $this->assertGreaterThan(0, $this->call($manager, 'GetTimerInterval', 'WakeUpTimer'));

        $manager->RequestAction('SetActive', false);
        $this->assertSame(0, $this->call($manager, 'GetTimerInterval', 'WakeUpTimer'));
        $this->assertSame(IS_INACTIVE, $this->call($manager, 'GetStatus'));
    }

    public function testInvalidValuesAreRejectedAndNotStored(): void
    {
        $manager = $this->createActiveManager(3600);
        $stored = $this->call($manager, 'ReadAttributeString', 'WakeUpTime');

        foreach (['25:00', '6:3', 'abc', ''] as $value) {
            try {
                $manager->RequestAction('SetWakeUpTime', $value);
                $this->fail('Value accepted: ' . $value);
            } catch (Exception $e) {
                $this->assertSame('Invalid value', $e->getMessage());
            }
        }
        $this->assertSame($stored, $this->call($manager, 'ReadAttributeString', 'WakeUpTime'));
    }

    public function testSelectedOnlyFilterIsStoredPerAlarm(): void
    {
        $manager = $this->createActiveManager(3600);
        $other = $this->createActiveManager(3600);

        // Die Kachel sendet JSON, Skripte bool
        $manager->RequestAction('SetSelectedOnly', 'true');
        $this->assertTrue($this->call($manager, 'ReadAttributeBoolean', 'SelectedOnly'));
        $this->assertTrue(json_decode($this->call($manager, 'GetTileState'), true)['selectedOnly']);
        $this->assertFalse(json_decode($this->call($other, 'GetTileState'), true)['selectedOnly']);

        foreach (['1', 'yes', 1, null] as $value) {
            try {
                $manager->RequestAction('SetSelectedOnly', $value);
                $this->fail('Value accepted: ' . var_export($value, true));
            } catch (Exception $e) {
                $this->assertSame('Invalid value', $e->getMessage());
            }
        }
        $this->assertTrue($this->call($manager, 'ReadAttributeBoolean', 'SelectedOnly'));

        $manager->RequestAction('SetSelectedOnly', false);
        $this->assertFalse($this->call($manager, 'ReadAttributeBoolean', 'SelectedOnly'));
    }

    // Aktiver Wecker, alle Wochentage, Lampe und Heizung (Zeitversatz 0) ausgewählt, Weckzeit $offset Sekunden ab jetzt.
    private function createActiveManager(int $offset, array $attributes = [], bool $apply = true): WakeUpManager
    {
        $time = time() + $offset;
        return $this->createManager($this->catalog, $attributes + [
            'Active'     => true,
            'WakeUpTime' => $this->clock((int) date('G', $time), (int) date('i', $time), (int) date('s', $time)),
            'Weekdays'   => [1, 2, 3, 4, 5, 6, 7],
            'Selection'  => [['ID' => 'L', 'Active' => true, 'Offset' => 0], ['ID' => 'H', 'Active' => true, 'Offset' => 0]]
        ], $apply);
    }

    private function relative(int $offset): string
    {
        return date('H:i:s', time() + $offset);
    }
}
