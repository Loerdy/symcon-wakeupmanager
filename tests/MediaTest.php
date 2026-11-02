<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Gruppe "Mediaplayer" in WakeUpActions: Startaktion wie bei den übrigen Gruppen, dazu Lautstärke, Fading-Dauer und
// Ausschalt-Aktion als zentrale Werte für die Wecker.
class MediaTest extends WakeUpManagerTestCase
{
    private int $volume;

    protected function setUp(): void
    {
        parent::setUp();
        $this->volume = IPS_CreateVariable(VARIABLETYPE_INTEGER);
    }

    public function testMediaActionRunsLikeOtherActions(): void
    {
        $manager = $this->createManager($this->createMediaCatalog($this->mediaRow()), ['Selection' => [['ID' => 'M', 'Active' => true, 'Offset' => 0]]]);

        $manager->RequestAction('RunActions', true);

        $this->assertSame(['Radio'], $this->runs());
        $this->assertSame('Radio', $this->formElement($manager, 'ActionsMedia')['values'][0]['Name']);
        $this->assertSame('media', json_decode($this->call($manager, 'GetTileState'), true)['groups'][0]['key']);
    }

    public function testCatalogContainsMediaSettings(): void
    {
        $media = $this->loadMedia($this->mediaRow());

        $this->assertSame(['variable' => $this->volume, 'start' => 10.0, 'target' => 30.5, 'duration' => 15], $media['volume']);
        $this->assertSame(['actionID' => self::RECORD_ACTION, 'parameters' => ['name' => 'Stop']], $media['stop']);
    }

    public function testWithoutVolumeVariableAndStopActionBothAreUnused(): void
    {
        $media = $this->loadMedia(['VolumeVariable' => 0, 'StopAction' => ''] + $this->mediaRow());

        $this->assertNull($media['volume']);
        $this->assertNull($media['stop']);
    }

    public function testDisabledVolumeAndSwitchOffAreNotUsedButKept(): void
    {
        $row = ['VolumeControl' => 0, 'SwitchOff' => 0] + $this->mediaRow();
        $catalogID = $this->createMediaCatalog($row);

        $media = $this->call($this->createManager($catalogID), 'LoadCatalog', $catalogID)['M']['media'];

        $this->assertSame(['volume' => null, 'stop' => null], $media);
        // Die Werte unter der abgeschalteten Option bleiben in der zentralen Liste erhalten
        $this->assertSame([$row], json_decode(IPS_GetProperty($catalogID, 'ActionsMedia'), true));
    }

    public function testRowsWithoutOptionFieldsUseVariableAndStopAction(): void
    {
        $row = $this->mediaRow();
        unset($row['VolumeControl'], $row['SwitchOff']);
        $media = $this->loadMedia($row);

        $this->assertSame($this->volume, $media['volume']['variable']);
        $this->assertNotNull($media['stop']);
    }

    public function testVolumeEnabledWithoutVariableIsNotUsed(): void
    {
        // Die Konsole speichert die Zeile auch ohne gewählte Variable
        $this->assertNull($this->loadMedia(['VolumeVariable' => 0] + $this->mediaRow())['volume']);
    }

    public function testInvalidVolumeSettingsAreNotUsed(): void
    {
        foreach ([['StartVolume' => 'loud'], ['TargetVolume' => null], ['FadeDuration' => 0], ['FadeDuration' => 121], ['FadeDuration' => 2.5], ['VolumeVariable' => 'abc']] as $change) {
            $this->assertNull($this->loadMedia($change + $this->mediaRow())['volume'], json_encode($change));
        }
    }

    public function testMediaRowsWithoutNewFieldsAreReadable(): void
    {
        // Nur Name und Aktion, z. B. von Hand angelegt oder aus einer anderen Gruppe übertragen
        $media = $this->loadMedia(['ID' => 'M', 'Name' => 'Radio', 'Action' => json_encode(['actionID' => self::RECORD_ACTION, 'parameters' => ['name' => 'Radio']])]);

        $this->assertSame(['volume' => null, 'stop' => null], $media);
    }

    public function testOtherGroupsHaveNoMediaSettings(): void
    {
        $catalogID = $this->createCatalog();
        $catalog = $this->call($this->createManager($catalogID), 'LoadCatalog', $catalogID);

        $this->assertArrayNotHasKey('media', $catalog['L']);
        $this->assertArrayNotHasKey('media', $catalog['H']);
    }

    private function mediaRow(): array
    {
        return [
            'ID'             => 'M',
            'Name'           => 'Radio',
            'Action'         => json_encode(['actionID' => self::RECORD_ACTION, 'parameters' => ['name' => 'Radio']]),
            'Condition'      => '',
            'VolumeControl'  => 1,
            'VolumeVariable' => $this->volume,
            'StartVolume'    => 10,
            'TargetVolume'   => 30.5,
            'FadeDuration'   => 15,
            'SwitchOff'      => 1,
            'StopAction'     => json_encode(['actionID' => self::RECORD_ACTION, 'parameters' => ['name' => 'Stop']])
        ];
    }

    private function createMediaCatalog(array $row): int
    {
        $id = IPS_CreateInstance(self::ACTIONS_MODULE);
        IPS_SetProperty($id, 'ActionsMedia', json_encode([$row]));
        IPS_ApplyChanges($id);
        $this->assertSame(IS_ACTIVE, IPS_GetInstance($id)['InstanceStatus']);
        return $id;
    }

    private function loadMedia(array $row): array
    {
        $catalogID = $this->createMediaCatalog($row);
        $manager = $this->createManager($catalogID);
        return $this->call($manager, 'LoadCatalog', $catalogID)['M']['media'];
    }

    private function formElement(WakeUpManager $manager, string $name): array
    {
        $form = json_decode($manager->GetConfigurationForm(), true);
        foreach ($form['actions'] as $element) {
            if (($element['name'] ?? '') === $name) {
                return $element;
            }
        }
        $this->fail('Form element ' . $name . ' not found');
    }
}
