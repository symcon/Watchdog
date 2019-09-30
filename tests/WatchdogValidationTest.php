<?php

declare(strict_types=1);
include_once __DIR__ . '/stubs/Validator.php';
class WatchdogValidationTest extends TestCaseSymconValidation
{
    public function testValidateWatchdog(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }
    public function testValidateWatchdogModule(): void
    {
        $this->validateModule(__DIR__ . '/../Watchdog');
    }
}