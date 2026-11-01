<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Fehler, die den Betrieb blockieren, erscheinen einmal im Meldungsfenster beim Wechsel in den Fehlerstatus,
// nicht bei jeder weiteren Bedienung oder Neuberechnung.
class LoggingTest extends WakeUpManagerTestCase
{
    private const CATALOG_UNAVAILABLE = 'The central actions of the alarm clock are not available';
    private const WEEKDAYS_UNREADABLE = 'The weekdays of the alarm clock cannot be read';
    private const SELECTION_UNREADABLE = 'The action selection of the alarm clock cannot be read';

    public function testUnavailableCatalogIsLoggedOnce(): void
    {
        $catalog = $this->createCatalog();
        $manager = $this->createManager($catalog, ['Active' => true, 'Weekdays' => [1, 2, 3, 4, 5, 6, 7]]);
        IPS_DeleteInstance($catalog);

        $manager->RequestAction('SetWakeUpTime', '06:45');
        $manager->RequestAction('SetWakeUpTime', '07:00');
        $manager->ApplyChanges();

        $this->assertSame(1, $this->countLogs($manager, self::CATALOG_UNAVAILABLE));
        $this->assertSame(204, $this->call($manager, 'GetStatus'));
    }

    public function testUnreadableWeekdaysAreLoggedOnce(): void
    {
        $manager = $this->createManager($this->createCatalog(), ['Active' => true, 'Weekdays' => 'not a list']);

        $manager->ApplyChanges();
        $manager->RequestAction('SetWakeUpTime', '06:45');

        $this->assertSame(1, $this->countLogs($manager, self::WEEKDAYS_UNREADABLE));
        $this->assertSame(205, $this->call($manager, 'GetStatus'));
    }

    public function testUnreadableSelectionIsLoggedOnce(): void
    {
        $manager = $this->createManager($this->createCatalog(), ['Active' => true, 'Weekdays' => [1, 2, 3, 4, 5, 6, 7], 'Selection' => '{broken']);

        $manager->ApplyChanges();
        $manager->RequestAction('SetWakeUpTime', '06:45');

        $this->assertSame(1, $this->countLogs($manager, self::SELECTION_UNREADABLE));
        $this->assertSame(203, $this->call($manager, 'GetStatus'));
    }

    public function testErrorIsLoggedAgainAfterRecovery(): void
    {
        $manager = $this->createManager($this->createCatalog(), ['Active' => true, 'Weekdays' => 'not a list']);

        $this->writeAttributes($manager, ['Weekdays' => '[1]']);
        $manager->ApplyChanges();
        $this->assertSame(IS_ACTIVE, $this->call($manager, 'GetStatus'));

        $this->writeAttributes($manager, ['Weekdays' => 'not a list']);
        $manager->ApplyChanges();

        $this->assertSame(2, $this->countLogs($manager, self::WEEKDAYS_UNREADABLE));
    }

    private function countLogs(WakeUpManager $manager, string $message): int
    {
        $id = (new ReflectionProperty($manager, 'InstanceID'))->getValue($manager);
        return count(array_filter(IPS\LogServer::getLogMessages((string) $id), static fn (array $log): bool => $log['Message'] === $message));
    }
}
