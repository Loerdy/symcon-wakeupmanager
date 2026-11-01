<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Vorlagen je Wecker: Weckzeiten, Wochentage und Aktionsauswahl speichern und wieder laden (z. B. Früh-/Spätschicht).
class TemplateTest extends WakeUpManagerTestCase
{
    private WakeUpManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->createManager($this->createCatalog(), $this->early());
    }

    public function testSavedTemplateRestoresSettings(): void
    {
        $this->manager->RequestAction('SaveTemplate', 'Early shift');
        $this->writeAttributes($this->manager, $this->late());

        $this->manager->RequestAction('LoadTemplate', 'Early shift');

        foreach ($this->early() as $name => $value) {
            $expected = is_array($value) ? json_encode($value) : $value;
            $this->assertSame($expected, $this->attribute($name), $name);
        }
    }

    public function testLoadingKeepsActiveSwitchAndDisplayStyle(): void
    {
        $this->manager->RequestAction('SaveTemplate', 'Early shift');
        $this->writeAttributes($this->manager, ['Active' => true, 'DisplayStyle' => 'flip']);

        $this->manager->RequestAction('LoadTemplate', 'Early shift');

        $this->assertTrue($this->call($this->manager, 'ReadAttributeBoolean', 'Active'));
        $this->assertSame('flip', $this->attribute('DisplayStyle'));
    }

    public function testTemplateKeepsCorruptSelectionRowsUnchanged(): void
    {
        $raw = '[{"ID":"L","Active":true,"Offset":0},{"Name":"no ID"},{}]';
        $this->writeAttributes($this->manager, ['Selection' => $raw]);
        $this->manager->RequestAction('SaveTemplate', 'Early shift');
        $this->writeAttributes($this->manager, ['Selection' => '[]']);

        $this->manager->RequestAction('LoadTemplate', 'Early shift');

        $this->assertSame($raw, $this->attribute('Selection'));
    }

    public function testSavingWithSameNameOverwrites(): void
    {
        $this->manager->RequestAction('SaveTemplate', 'Early shift');
        $this->writeAttributes($this->manager, $this->late());
        $this->manager->RequestAction('SaveTemplate', ' early SHIFT ');

        $this->assertSame(['early SHIFT'], $this->templateNames());
        $this->writeAttributes($this->manager, $this->early());
        $this->manager->RequestAction('LoadTemplate', 'Early shift');
        $this->assertSame(json_encode($this->late()['WakeUpTime']), $this->attribute('WakeUpTime'));
    }

    public function testTemplatesAreListedInOrderAndCanBeDeleted(): void
    {
        $this->manager->RequestAction('SaveTemplate', 'Early shift');
        $this->manager->RequestAction('SaveTemplate', 'Late shift');
        $this->assertSame(['Early shift', 'Late shift'], $this->templateNames());

        $this->manager->RequestAction('DeleteTemplate', 'Early shift');
        $this->assertSame(['Late shift'], $this->templateNames());
    }

    public function testInvalidNamesAreRejected(): void
    {
        foreach (['', '   ', str_repeat('x', 41)] as $name) {
            try {
                $this->manager->RequestAction('SaveTemplate', $name);
                $this->fail('Name accepted: ' . var_export($name, true));
            } catch (Exception $e) {
                $this->assertSame('Please enter a name with 1 to 40 characters', $e->getMessage());
            }
        }
        $this->assertSame([], $this->templateNames());
    }

    public function testUnknownTemplateIsReported(): void
    {
        $this->expectExceptionMessage('The template does not exist');
        $this->manager->RequestAction('LoadTemplate', 'Night shift');
    }

    public function testNumberOfTemplatesIsLimited(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->manager->RequestAction('SaveTemplate', 'Template ' . $i);
        }
        // Überschreiben bleibt möglich
        $this->manager->RequestAction('SaveTemplate', 'Template 20');

        $this->expectExceptionMessage('No more than 20 templates can be saved');
        $this->manager->RequestAction('SaveTemplate', 'Template 21');
    }

    public function testUnreadableTemplatesAreKeptAndNotOverwritten(): void
    {
        $this->writeAttributes($this->manager, ['Templates' => '{broken']);

        try {
            $this->manager->RequestAction('SaveTemplate', 'Early shift');
            $this->fail('Save into unreadable templates was accepted');
        } catch (Exception $e) {
            $this->assertSame('The saved templates cannot be read', $e->getMessage());
        }
        $this->assertSame('{broken', $this->attribute('Templates'));
    }

    public function testLoadingDoesNotCatchUpPastWakeUpTime(): void
    {
        $seconds = time() - (new DateTimeImmutable('today'))->getTimestamp();
        if ($seconds < 900 || $seconds > 86400 - 900) {
            $this->markTestSkipped('Too close to midnight for wake-up times relative to now');
        }
        $past = time() - 120;
        $this->writeAttributes($this->manager, [
            'Active'     => true,
            'WakeUpTime' => $this->clock((int) date('G', $past), (int) date('i', $past), (int) date('s', $past)),
            'Weekdays'   => [1, 2, 3, 4, 5, 6, 7]
        ]);
        $this->manager->RequestAction('SaveTemplate', 'Now');
        $this->writeAttributes($this->manager, ['WakeUpTime' => $this->clock(3, 0), 'LastProcessed' => time() - 3600]);
        $this->manager->ApplyChanges();
        $this->runs();

        $this->manager->RequestAction('LoadTemplate', 'Now');

        $this->assertSame([], $this->runs());
    }

    public function testTileAndConsoleReceiveFeedbackInsteadOfExceptions(): void
    {
        $this->manager->RequestAction('SaveTemplate', json_encode(['Name' => 'Early shift', 'Source' => 'tile']));
        $this->assertSame(['notice' => 'Template "Early shift" saved', 'error' => false], $this->lastTileNotice());

        $this->manager->RequestAction('LoadTemplate', json_encode(['Name' => 'Missing', 'Source' => 'tile']));
        $this->assertSame(['notice' => 'The template does not exist', 'error' => true], $this->lastTileNotice());

        // Konsole: Rückmeldung per Popup, keine Exception
        $this->manager->RequestAction('DeleteTemplate', json_encode(['Name' => 'Missing', 'Source' => 'console']));
        $this->assertSame(['Early shift'], $this->templateNames());
    }

    public function testTileReceivesUpdatedTemplateListAfterSaveAndDelete(): void
    {
        $this->manager->RequestAction('SaveTemplate', json_encode(['Name' => 'Early shift', 'Source' => 'tile']));
        $this->assertSame(['Early shift'], $this->lastTileState()['templates']);

        $this->manager->RequestAction('DeleteTemplate', json_encode(['Name' => 'Early shift', 'Source' => 'tile']));
        $this->assertSame([], $this->lastTileState()['templates']);
    }

    public function testOverwriteSelectedTemplateFromConsole(): void
    {
        $this->manager->RequestAction('SaveTemplate', 'Early shift');
        $this->writeAttributes($this->manager, $this->late());

        // Button "Überschreiben" sendet den in der Auswahlliste gewählten Namen
        $this->manager->RequestAction('SaveTemplate', json_encode(['Name' => 'Early shift', 'Source' => 'console']));

        $this->assertSame(['Early shift'], $this->templateNames());
        $this->writeAttributes($this->manager, $this->early());
        $this->manager->RequestAction('LoadTemplate', 'Early shift');
        $this->assertSame(json_encode($this->late()['WakeUpTime']), $this->attribute('WakeUpTime'));
    }

    public function testEmptyTemplateListHasPlaceholderMatchingItsValue(): void
    {
        // Ohne Eintrag zum Wert "" meldet die Konsole "Aktueller Wert ist nicht verfügbar"
        $select = $this->findElement(json_decode($this->manager->GetConfigurationForm(), true)['actions'], 'TemplateSelect');

        $this->assertSame([['caption' => 'No templates saved', 'value' => '']], $select['options']);
        $this->assertSame('', $select['value']);
        $this->assertFalse($select['enabled']);
    }

    public function testTemplatesAreOfferedInTileAndConsole(): void
    {
        $this->manager->RequestAction('SaveTemplate', 'Early shift');
        $this->manager->RequestAction('SaveTemplate', 'Late shift');

        $this->assertSame(['Early shift', 'Late shift'], json_decode($this->call($this->manager, 'GetTileState'), true)['templates']);

        $form = json_decode($this->manager->GetConfigurationForm(), true);
        $select = $this->findElement($form['actions'], 'TemplateSelect');
        $this->assertSame([['caption' => 'Early shift', 'value' => 'Early shift'], ['caption' => 'Late shift', 'value' => 'Late shift']], $select['options']);
    }

    private function early(): array
    {
        return [
            'WakeUpTime'        => $this->clock(4, 45),
            'SeparateWeekend'   => true,
            'WakeUpTimeWeekend' => $this->clock(8, 0),
            'Weekdays'          => [1, 2, 3, 4, 5],
            'Selection'         => [['ID' => 'L', 'Active' => true, 'Offset' => 0], ['ID' => 'H', 'Active' => true, 'Offset' => -45]]
        ];
    }

    private function late(): array
    {
        return [
            'WakeUpTime'        => $this->clock(11, 30),
            'SeparateWeekend'   => false,
            'WakeUpTimeWeekend' => $this->clock(9, 0),
            'Weekdays'          => [2, 3, 4, 5, 6],
            'Selection'         => [['ID' => 'L', 'Active' => false, 'Offset' => 0]]
        ];
    }

    private function attribute(string $name): mixed
    {
        return $name === 'SeparateWeekend'
            ? $this->call($this->manager, 'ReadAttributeBoolean', $name)
            : $this->call($this->manager, 'ReadAttributeString', $name);
    }

    private function templateNames(): array
    {
        return array_column(json_decode($this->attribute('Templates'), true), 'Name');
    }

    private function lastTileNotice(): ?array
    {
        $notice = null;
        foreach ($this->manager->getMessages() as $message) {
            $data = $message['Message'] === 10541 ? json_decode($message['Data'][0], true) : null;
            if (is_array($data) && isset($data['notice'])) {
                $notice = $data;
            }
        }
        return $notice;
    }

    // Letzter vollständiger Kachel-Zustand (UpdateVisualizationValue ohne "notice")
    private function lastTileState(): ?array
    {
        $state = null;
        foreach ($this->manager->getMessages() as $message) {
            $data = $message['Message'] === 10541 ? json_decode($message['Data'][0], true) : null;
            if (is_array($data) && array_key_exists('templates', $data)) {
                $state = $data;
            }
        }
        return $state;
    }

    private function findElement(array $elements, string $name): ?array
    {
        foreach ($elements as $element) {
            if (($element['name'] ?? '') === $name) {
                return $element;
            }
            $found = isset($element['items']) ? $this->findElement($element['items'], $name) : null;
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }
}
