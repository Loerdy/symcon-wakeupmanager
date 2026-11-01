<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Änderungen an der Aktionsauswahl betreffen nur die Zeile der geänderten Aktion; beschädigte oder unbekannte
// Zeilen bleiben unverändert erhalten.
class SelectionTest extends WakeUpManagerTestCase
{
    private const CORRUPT_ROWS = [
        ['ID' => 'x', 'Active' => true, 'Offset' => 2000],
        ['Name' => 'entry without ID'],
        'not an object',
        ['ID' => 'Z', 'Active' => 'yes', 'Offset' => 0, 'Note' => 'kept']
    ];

    private WakeUpManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->createManager($this->createCatalog());
    }

    public function testCorruptRowsSurviveEditingAnotherAction(): void
    {
        $this->setSelection([['ID' => 'L', 'Active' => false, 'Offset' => 0], ...self::CORRUPT_ROWS]);

        $this->manager->RequestAction('SetAction', json_encode(['ID' => 'L', 'Active' => true]));

        $this->assertSame([['ID' => 'L', 'Active' => true, 'Offset' => 0], ...self::CORRUPT_ROWS], $this->selection());
    }

    public function testCorruptRowsSurviveDeletingAnotherAction(): void
    {
        $this->setSelection([['ID' => 'gone', 'Active' => true, 'Offset' => 0], ...self::CORRUPT_ROWS]);

        $this->manager->RequestAction('DeleteAction', 'gone');

        $this->assertSame(self::CORRUPT_ROWS, $this->selection());
    }

    public function testNewSelectionIsAppended(): void
    {
        $this->setSelection(self::CORRUPT_ROWS);

        $this->manager->RequestAction('SetAction', json_encode(['ID' => 'H', 'Offset' => -30]));

        $this->assertSame([...self::CORRUPT_ROWS, ['ID' => 'H', 'Active' => false, 'Offset' => -30]], $this->selection());
    }

    public function testEditingAnInvalidRowReplacesItsValues(): void
    {
        // Bewusste Bedienung der betroffenen Aktion: ungültiger Zeitversatz wird durch einen gültigen ersetzt
        $this->setSelection([['ID' => 'H', 'Active' => 'yes', 'Offset' => 2000, 'Note' => 'kept']]);

        $this->manager->RequestAction('SetAction', json_encode(['ID' => 'H', 'Active' => true]));

        $this->assertSame([['ID' => 'H', 'Active' => true, 'Offset' => 0, 'Note' => 'kept']], $this->selection());
    }

    public function testOnlyFirstRowOfDuplicateIdIsEdited(): void
    {
        $this->setSelection([['ID' => 'L', 'Active' => false, 'Offset' => 0], ['ID' => 'L', 'Active' => false, 'Offset' => 5]]);

        $this->manager->RequestAction('SetAction', json_encode(['ID' => 'L', 'Active' => true]));

        $this->assertSame([['ID' => 'L', 'Active' => true, 'Offset' => 0], ['ID' => 'L', 'Active' => false, 'Offset' => 5]], $this->selection());
    }

    public function testDeleteRemovesAllRowsOfThatId(): void
    {
        $this->setSelection([['ID' => 'gone', 'Active' => true, 'Offset' => 0], ['ID' => 'L', 'Active' => true, 'Offset' => 0], ['ID' => 'gone', 'Active' => false, 'Offset' => 0]]);

        $this->manager->RequestAction('DeleteAction', 'gone');

        $this->assertSame([['ID' => 'L', 'Active' => true, 'Offset' => 0]], $this->selection());
    }

    public function testEmptyJsonObjectsAreKept(): void
    {
        $raw = '[{"ID":"L","Active":false,"Offset":0},{},{"ID":"y","Data":{}}]';
        $this->writeAttributes($this->manager, ['Selection' => $raw]);

        $this->manager->RequestAction('SetAction', json_encode(['ID' => 'L', 'Active' => true]));

        $this->assertSame('[{"ID":"L","Active":true,"Offset":0},{},{"ID":"y","Data":{}}]', $this->rawSelection());
    }

    public function testUnchangedValueDoesNotRewriteStoredData(): void
    {
        $raw = '[ {"ID": "L", "Active": true, "Offset": 0} ]';
        $this->writeAttributes($this->manager, ['Selection' => $raw]);

        $this->manager->RequestAction('SetAction', json_encode(['ID' => 'L', 'Active' => true]));
        $this->manager->RequestAction('DeleteAction', 'not selected');

        $this->assertSame($raw, $this->rawSelection());
    }

    public function testUnreadableSelectionIsRejectedAndKept(): void
    {
        $this->writeAttributes($this->manager, ['Selection' => '{"broken": true']);

        try {
            $this->manager->RequestAction('SetAction', json_encode(['ID' => 'L', 'Active' => true]));
            $this->fail('Change of an unreadable selection was accepted');
        } catch (Exception $e) {
            $this->assertSame('The action selection cannot be read', $e->getMessage());
        }
        $this->assertSame('{"broken": true', $this->rawSelection());
    }

    public function testRestartKeepsCorruptRows(): void
    {
        $this->setSelection([['ID' => 'L', 'Active' => true, 'Offset' => 0], ...self::CORRUPT_ROWS]);
        $before = $this->rawSelection();

        $this->writeAttributes($this->manager, ['Active' => true]);
        $this->manager->ApplyChanges();

        $this->assertSame($before, $this->rawSelection());
    }

    private function setSelection(array $rows): void
    {
        $this->writeAttributes($this->manager, ['Selection' => json_encode($rows)]);
    }

    private function selection(): array
    {
        return json_decode($this->rawSelection(), true);
    }

    private function rawSelection(): string
    {
        return $this->call($this->manager, 'ReadAttributeString', 'Selection');
    }
}
