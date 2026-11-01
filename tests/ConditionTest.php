<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Optionale Bedingung je zentraler Aktion (SelectCondition), geprüft im Moment der Ausführung mit IPS_IsConditionPassing.
class ConditionTest extends WakeUpManagerTestCase
{
    private int $isDay;

    protected function setUp(): void
    {
        parent::setUp();
        // Wie "Ist es Tag" des Location Control
        $this->isDay = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
        SetValue($this->isDay, false);
    }

    public function testActionRunsWhenConditionIsMet(): void
    {
        $manager = $this->createManager($this->createConditionalCatalog($this->nightOnly()), $this->bothSelected());

        $manager->RequestAction('RunActions', true);

        $this->assertSame(['Light', 'Heat'], $this->runs());
    }

    public function testActionIsSkippedWhenConditionIsNotMet(): void
    {
        SetValue($this->isDay, true);
        $manager = $this->createManager($this->createConditionalCatalog($this->nightOnly()), $this->bothSelected());

        $manager->RequestAction('RunActions', 'tile');

        $this->assertSame(['Heat'], $this->runs());
        $this->assertSame(['notice' => '1 action(s) started, 1 skipped (condition not met)', 'error' => false], $this->lastTileNotice($manager));
        // Nicht erfüllte Bedingung ist kein Fehler: keine Meldung im Meldungsfenster
        $this->assertSame([], $this->logs($manager));
    }

    public function testUnevaluableConditionSkipsActionWithWarning(): void
    {
        $broken = $this->condition(999999, false);
        $manager = $this->createManager($this->createConditionalCatalog($broken), $this->bothSelected());

        $manager->RequestAction('RunActions', true);

        $this->assertSame(['Heat'], $this->runs());
        $this->assertSame(['The condition of a wake-up action cannot be evaluated, action skipped: Light'], $this->logs($manager));
    }

    public function testEmptyConditionMeansAlways(): void
    {
        foreach (['', '[]', null] as $empty) {
            $manager = $this->createManager($this->createConditionalCatalog($empty), $this->bothSelected());
            $manager->RequestAction('RunActions', true);
            $this->assertSame(['Light', 'Heat'], $this->runs(), var_export($empty, true));
        }
    }

    public function testConditionAsArrayIsAccepted(): void
    {
        // Je nach Speicherung kann der Wert bereits dekodiert in der Listenzeile stehen
        SetValue($this->isDay, true);
        $manager = $this->createManager($this->createConditionalCatalog(json_decode($this->nightOnly(), true)), $this->bothSelected());

        $manager->RequestAction('RunActions', true);

        $this->assertSame(['Heat'], $this->runs());
    }

    public function testConditionIsCheckedAtWakeUpAndSkippedActionIsNotRepeated(): void
    {
        $this->skipNearMidnight();
        SetValue($this->isDay, true);
        $time = time() - 120;
        $manager = $this->createManager($this->createConditionalCatalog($this->nightOnly()), $this->bothSelected() + [
            'Active'        => true,
            'Weekdays'      => [1, 2, 3, 4, 5, 6, 7],
            'WakeUpTime'    => $this->clock((int) date('G', $time), (int) date('i', $time), (int) date('s', $time)),
            'LastProcessed' => time() - 600
        ]);
        $this->assertSame(['Heat'], $this->runs());

        // Übersprungen zählt als erledigt: auch wenn die Bedingung später erfüllt ist, kein Nachholen
        SetValue($this->isDay, false);
        $manager->ApplyChanges();
        $this->assertSame([], $this->runs());
    }

    public function testConditionalActionsAreMarkedInTileAndConsole(): void
    {
        $manager = $this->createManager($this->createConditionalCatalog($this->nightOnly()), $this->bothSelected());

        $groups = array_column(json_decode($this->call($manager, 'GetTileState'), true)['groups'], 'actions', 'key');
        $this->assertTrue($groups['light'][0]['condition']);
        $this->assertFalse($groups['heating'][0]['condition']);

        $form = array_column(json_decode($manager->GetConfigurationForm(), true)['actions'], null, 'name');
        $this->assertSame('yes', $form['ActionsLight']['values'][0]['Condition']);
        $this->assertSame('', $form['ActionsHeating']['values'][0]['Condition']);
    }

    // Zentrale Aktionen: Lampe "L" mit Bedingung, Heizung "H" ohne
    private function createConditionalCatalog(mixed $condition): int
    {
        $id = IPS_CreateInstance(self::ACTIONS_MODULE);
        IPS_SetProperty($id, 'ActionsLight', json_encode([$this->catalogRow('L', 'Light') + ['Condition' => $condition]]));
        IPS_SetProperty($id, 'ActionsHeating', json_encode([$this->catalogRow('H', 'Heat')]));
        IPS_ApplyChanges($id);
        return $id;
    }

    private function nightOnly(): string
    {
        return $this->condition($this->isDay, false);
    }

    // Format von SelectCondition: eine Bedingung (UND) mit einer Variablenregel "gleich"
    private function condition(int $variableID, mixed $value): string
    {
        return json_encode([[
            'id'        => 0,
            'parentID'  => 0,
            'operation' => 0,
            'rules'     => ['variable' => [['id' => 0, 'variableID' => $variableID, 'comparison' => 0, 'value' => $value, 'type' => 0]]]
        ]]);
    }

    private function bothSelected(): array
    {
        return ['Selection' => [['ID' => 'L', 'Active' => true, 'Offset' => 0], ['ID' => 'H', 'Active' => true, 'Offset' => 0]]];
    }

    private function logs(WakeUpManager $manager): array
    {
        $id = (new ReflectionProperty($manager, 'InstanceID'))->getValue($manager);
        return array_column(IPS\LogServer::getLogMessages((string) $id), 'Message');
    }

    private function lastTileNotice(WakeUpManager $manager): ?array
    {
        $notice = null;
        foreach ($manager->getMessages() as $message) {
            $data = $message['Message'] === 10541 ? json_decode($message['Data'][0], true) : null;
            if (is_array($data) && isset($data['notice'])) {
                $notice = $data;
            }
        }
        return $notice;
    }

    private function skipNearMidnight(): void
    {
        $seconds = time() - (new DateTimeImmutable('today'))->getTimestamp();
        if ($seconds < 900 || $seconds > 86400 - 900) {
            $this->markTestSkipped('Too close to midnight for wake-up times relative to now');
        }
    }
}
