<?php

declare(strict_types=1);

include_once __DIR__ . '/WakeUpManagerTestCase.php';

// Konfigurationsformulare: Pflichtfeld "Bezeichnung" in WakeUpActions, Hinweis auf ungültige Auswahleinträge im Wecker.
class FormTest extends WakeUpManagerTestCase
{
    private const LISTS = ['ActionsLight', 'ActionsShutter', 'ActionsHeating', 'ActionsMedia', 'ActionsOther', 'ActionsScript'];

    public function testActionNameIsRequiredInEveryGroup(): void
    {
        $form = json_decode(file_get_contents(__DIR__ . '/../WakeUpActions/form.json'), true);
        $lists = array_column(array_filter($form['elements'], static fn (array $element): bool => ($element['type'] ?? '') === 'List'), null, 'name');
        $this->assertSame(self::LISTS, array_keys($lists));

        foreach ($lists as $name => $list) {
            // Bearbeitungsformular wie von der Konsole ausgewertet (PHP-Code als Zeilen)
            $fields = array_column(eval(implode("\n", $list['form'])), null, 'name');
            $validate = $fields['Name']['validate'] ?? null;
            $this->assertIsString($validate, $name . ': Name has no validate');

            // Ausdruck muss sowohl als Teil- als auch als Gesamttreffer wirken
            foreach (['/' . $validate . '/', '/^(?:' . $validate . ')$/'] as $pattern) {
                $this->assertSame(1, preg_match($pattern, 'Bedroom light'), $name);
                $this->assertSame(1, preg_match($pattern, ' Bad '), $name);
                $this->assertSame(0, preg_match($pattern, ''), $name);
                $this->assertSame(0, preg_match($pattern, '   '), $name);
            }
        }
    }

    public function testUnnamedExistingActionKeepsFallbackName(): void
    {
        $catalog = IPS_CreateInstance(self::ACTIONS_MODULE);
        IPS_SetProperty($catalog, 'ActionsLight', json_encode([['ID' => 'L', 'Name' => '', 'Action' => '{"actionID":"x","parameters":[]}']]));
        IPS_ApplyChanges($catalog);
        $manager = $this->createManager($catalog);

        $this->assertSame(IS_ACTIVE, IPS_GetInstance($catalog)['InstanceStatus']);
        $this->assertSame('Central action 1', $this->formElement($manager, 'ActionsLight')['values'][0]['Name']);
    }

    public function testInvalidSelectionRowsAreHinted(): void
    {
        $manager = $this->createManager($this->createCatalog(), [
            'Selection' => [['ID' => 'L', 'Active' => true, 'Offset' => 0], ['Name' => 'no ID'], ['ID' => 'H', 'Active' => true, 'Offset' => 5000]]
        ]);

        $hint = $this->formElement($manager, 'ActionsHint');
        $this->assertTrue($hint['visible']);
        $this->assertSame('2 entries of the stored action selection are invalid and will be ignored. They are kept unchanged.', $hint['caption']);
    }

    public function testValidSelectionHasNoHint(): void
    {
        $manager = $this->createManager($this->createCatalog(), ['Selection' => [['ID' => 'L', 'Active' => true, 'Offset' => 0]]]);

        $this->assertFalse($this->formElement($manager, 'ActionsHint')['visible']);
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
