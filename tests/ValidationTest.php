<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class ValidationTest extends TestCaseSymconValidation
{
    public function testValidateLibrary(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateWakeUpManager(): void
    {
        $this->validateModule(__DIR__ . '/../WakeUpManager');
    }

    public function testValidateWakeUpActions(): void
    {
        $this->validateModule(__DIR__ . '/../WakeUpActions');
    }
}
