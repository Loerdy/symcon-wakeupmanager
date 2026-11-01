<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Button "Ausgewählte Aktionen jetzt ausführen": startet die aktiven Aktionen einmal, ohne Protokoll, Zeitfenster
// oder Timer der Weckroutine zu verändern.
class RunActionsTest extends WakeUpManagerTestCase
{
    public function testRunsActiveActionsEvenWhenAlarmIsInactive(): void
    {
        $manager = $this->createManager($this->createCatalog(), [
            'Active'    => false,
            'Selection' => [['ID' => 'H', 'Active' => true, 'Offset' => -60], ['ID' => 'L', 'Active' => true, 'Offset' => 0]]
        ]);

        $manager->RequestAction('RunActions', true);

        // Reihenfolge wie in der Konsole: Lampen vor Heizung
        $this->assertSame(['Light', 'Heat'], $this->runs());
    }

    public function testSkipsInactiveSelections(): void
    {
        $manager = $this->createManager($this->createCatalog(), [
            'Selection' => [['ID' => 'L', 'Active' => false, 'Offset' => 0], ['ID' => 'H', 'Active' => true, 'Offset' => 0]]
        ]);

        $manager->RequestAction('RunActions', true);

        $this->assertSame(['Heat'], $this->runs());
    }

    public function testDoesNotChangeWakeUpState(): void
    {
        $manager = $this->createManager($this->createCatalog(), [
            'Active'     => true,
            'WakeUpTime' => $this->clock(6, 30),
            'Weekdays'   => [1, 2, 3, 4, 5, 6, 7],
            'Selection'  => [['ID' => 'L', 'Active' => true, 'Offset' => 0]]
        ]);
        $this->writeAttributes($manager, ['LastProcessed' => 12345, 'Executed' => '{"2026-11-04":["L"]}']);
        $timer = $this->call($manager, 'GetTimerInterval', 'WakeUpTimer');

        $manager->RequestAction('RunActions', true);

        $this->assertSame(['Light'], $this->runs());
        $this->assertSame(12345, $this->call($manager, 'ReadAttributeInteger', 'LastProcessed'));
        $this->assertSame('{"2026-11-04":["L"]}', $this->call($manager, 'ReadAttributeString', 'Executed'));
        $this->assertSame($timer, $this->call($manager, 'GetTimerInterval', 'WakeUpTimer'));
    }

    public function testNoSelectedActionIsReported(): void
    {
        $manager = $this->createManager($this->createCatalog(), ['Selection' => [['ID' => 'L', 'Active' => false, 'Offset' => 0]]]);

        $this->expectExceptionMessage('No actions are selected');
        $manager->RequestAction('RunActions', true);
    }

    public function testUnavailableCatalogIsReported(): void
    {
        $manager = $this->createManager(0, ['Selection' => [['ID' => 'L', 'Active' => true, 'Offset' => 0]]]);

        $this->expectExceptionMessage('The central actions are not available');
        $manager->RequestAction('RunActions', true);
    }

    public function testUnreadableSelectionIsReported(): void
    {
        $manager = $this->createManager($this->createCatalog(), ['Selection' => '{broken']);

        $this->expectExceptionMessage('The action selection cannot be read');
        $manager->RequestAction('RunActions', true);
    }

    public function testTileReceivesResultNotice(): void
    {
        $manager = $this->createManager($this->createCatalog(), ['Selection' => [['ID' => 'L', 'Active' => true, 'Offset' => 0]]]);

        $manager->RequestAction('RunActions', 'tile');

        $this->assertSame(['Light'], $this->runs());
        $this->assertSame(['notice' => '1 action(s) started', 'error' => false], $this->lastTileNotice($manager));
    }

    public function testTileReceivesErrorNoticeInsteadOfException(): void
    {
        $manager = $this->createManager($this->createCatalog(), ['Selection' => []]);

        $manager->RequestAction('RunActions', 'tile');

        $this->assertSame(['notice' => 'No actions are selected', 'error' => true], $this->lastTileNotice($manager));
    }

    public function testConsoleReceivesErrorAsPopupInsteadOfException(): void
    {
        $manager = $this->createManager($this->createCatalog(), ['Selection' => []]);

        // Ergebnis erscheint per UpdateFormField im PopupAlert; keine Exception, die die Konsole nicht anzeigt
        $manager->RequestAction('RunActions', 'console');

        $this->assertSame([], $this->runs());
    }

    private function lastTileNotice(WakeUpManager $manager): ?array
    {
        $notice = null;
        foreach ($manager->getMessages() as $message) {
            // 10541: Nachricht an die Visualisierung (UpdateVisualizationValue)
            $data = $message['Message'] === 10541 ? json_decode($message['Data'][0], true) : null;
            if (is_array($data) && isset($data['notice'])) {
                $notice = $data;
            }
        }
        return $notice;
    }
}
