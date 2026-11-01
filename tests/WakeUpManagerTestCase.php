<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Gemeinsame Hilfen: Library laden, zentrale Aktionen anlegen, private Methoden aufrufen.
abstract class WakeUpManagerTestCase extends TestCase
{
    protected const MANAGER_MODULE = '{108BD31A-69AA-02D1-78A0-F0D88EE74B28}';
    protected const ACTIONS_MODULE = '{4E963960-1F66-A246-3BC9-BA42E525A6F7}';

    // Testaktion aus tests/actions; protokolliert $_IPS['name'] in $GLOBALS['WUM_TEST_RUNS'].
    protected const RECORD_ACTION = 'wakeupmanager.test.record';

    protected function setUp(): void
    {
        parent::setUp();
        date_default_timezone_set('Europe/Berlin');
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        IPS\ActionPool::loadActions(__DIR__ . '/actions');
        $GLOBALS['WUM_TEST_RUNS'] = [];
    }

    // Zentrale Aktionen mit "L" (Lampen) und "H" (Heizung)
    protected function createCatalog(): int
    {
        $id = IPS_CreateInstance(self::ACTIONS_MODULE);
        IPS_SetProperty($id, 'ActionsLight', json_encode([$this->catalogRow('L', 'Light')]));
        IPS_SetProperty($id, 'ActionsHeating', json_encode([$this->catalogRow('H', 'Heat')]));
        IPS_ApplyChanges($id);
        return $id;
    }

    protected function catalogRow(string $id, string $name): array
    {
        return ['ID' => $id, 'Name' => $name, 'Action' => json_encode(['actionID' => self::RECORD_ACTION, 'parameters' => ['name' => $name]])];
    }

    // Wecker mit gesetzten Attributen. Die Eigenschaft wird erst mit ApplyChanges wirksam; das zweite ApplyChanges
    // (optional) berechnet mit den Attributen neu, sonst erfolgt die erste Neuberechnung durch den Test selbst.
    protected function createManager(int $catalogID, array $attributes = [], bool $apply = true): WakeUpManager
    {
        $id = IPS_CreateInstance(self::MANAGER_MODULE);
        IPS_SetProperty($id, 'ActionInstance', $catalogID);
        IPS_ApplyChanges($id);
        $module = IPS\InstanceManager::getInstanceInterface($id);
        $this->writeAttributes($module, $attributes);
        if ($apply) {
            IPS_ApplyChanges($id);
        }
        return $module;
    }

    protected function writeAttributes(WakeUpManager $module, array $attributes): void
    {
        foreach ($attributes as $name => $value) {
            $method = match (true) {
                is_bool($value) => 'WriteAttributeBoolean',
                is_int($value)  => 'WriteAttributeInteger',
                default         => 'WriteAttributeString'
            };
            $this->call($module, $method, $name, is_array($value) ? json_encode($value) : $value);
        }
    }

    protected function call(object $object, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
    }

    protected function runs(): array
    {
        $runs = $GLOBALS['WUM_TEST_RUNS'];
        $GLOBALS['WUM_TEST_RUNS'] = [];
        return $runs;
    }

    protected function timestamp(string $local): int
    {
        return (new DateTimeImmutable($local, new DateTimeZone('Europe/Berlin')))->getTimestamp();
    }

    protected function clock(int $hour, int $minute, int $second = 0): array
    {
        return ['hour' => $hour, 'minute' => $minute, 'second' => $second];
    }
}
