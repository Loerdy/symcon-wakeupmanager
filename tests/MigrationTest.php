<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Übernahme früherer Eigenschaften in Attribute bzw. Gruppenlisten; vorhandene oder unlesbare Daten bleiben erhalten.
class MigrationTest extends WakeUpManagerTestCase
{
    public function testManagerMovesLegacySettingsToAttributes(): void
    {
        $manager = $this->createManager(0);
        $result = json_decode($manager->Migrate(json_encode([
            'configuration' => [
                'Active'       => true,
                'WakeUpTime'   => '{"hour":7,"minute":15,"second":0}',
                'Monday'       => true,
                'Tuesday'      => false,
                'Saturday'     => true,
                'ActionsLight' => '[{"ID":"a","Active":true,"Offset":0}]',
                'Actions'      => '[{"ID":"a","Active":false},{"ID":"b","Active":true,"Offset":-30}]'
            ],
            'attributes'    => new stdClass()
        ])), true);

        $this->assertTrue($result['attributes']['Active']);
        $this->assertSame('{"hour":7,"minute":15,"second":0}', $result['attributes']['WakeUpTime']);
        // Fehlende Wochentage behalten den früheren Standard (Mo–Fr aktiv)
        $this->assertSame('[1,3,4,5,6]', $result['attributes']['Weekdays']);
        // Erster Eintrag je ID gilt
        $this->assertSame([['ID' => 'a', 'Active' => true, 'Offset' => 0], ['ID' => 'b', 'Active' => true, 'Offset' => -30]], json_decode($result['attributes']['Selection'], true));
    }

    public function testManagerKeepsExistingAttributes(): void
    {
        $manager = $this->createManager(0);
        $result = $manager->Migrate(json_encode([
            'configuration' => ['Active' => true, 'WakeUpTime' => '{"hour":7,"minute":15,"second":0}', 'Monday' => true, 'ActionsLight' => '[]'],
            'attributes'    => ['Active' => false, 'WakeUpTime' => '{"hour":6,"minute":0,"second":0}', 'Weekdays' => '[2]', 'Selection' => '[]']
        ]));

        $this->assertSame('', $result);
    }

    public function testManagerKeepsUnreadableSelection(): void
    {
        $manager = $this->createManager(0);
        $result = json_decode($manager->Migrate(json_encode([
            'configuration' => ['ActionsLight' => 'not a list'],
            'attributes'    => new stdClass()
        ])), true);

        $this->assertSame('not a list', $result['attributes']['Selection']);
    }

    public function testActionsDistributeLegacyListToGroups(): void
    {
        $catalog = IPS\InstanceManager::getInstanceInterface(IPS_CreateInstance(self::ACTIONS_MODULE));
        $legacy = [
            ['ID' => 'a', 'Name' => 'Lamp', 'Group' => 'light'],
            ['ID' => 'b', 'Name' => 'Boiler', 'Group' => 'heating'],
            ['ID' => 'c', 'Name' => 'Unknown', 'Group' => 'garden']
        ];
        $result = json_decode($catalog->Migrate(json_encode([
            'configuration' => ['Actions' => json_encode($legacy), 'ActionsLight' => '[]', 'ActionsHeating' => '[]', 'ActionsOther' => '[]', 'ActionsShutter' => '[]', 'ActionsScript' => '[]'],
            'attributes'    => new stdClass()
        ])), true);

        $this->assertSame([$legacy[0]], json_decode($result['configuration']['ActionsLight'], true));
        $this->assertSame([$legacy[1]], json_decode($result['configuration']['ActionsHeating'], true));
        // Unbekannte Gruppe landet in "Sonstiges"
        $this->assertSame([$legacy[2]], json_decode($result['configuration']['ActionsOther'], true));
        $this->assertSame('[]', $result['configuration']['Actions']);
    }

    public function testActionsDoNotOverwriteFilledGroups(): void
    {
        $catalog = IPS\InstanceManager::getInstanceInterface(IPS_CreateInstance(self::ACTIONS_MODULE));
        $result = $catalog->Migrate(json_encode([
            'configuration' => ['Actions' => '[{"ID":"a","Group":"light"}]', 'ActionsLight' => '[{"ID":"x"}]'],
            'attributes'    => new stdClass()
        ]));

        $this->assertSame('', $result);
    }
}
