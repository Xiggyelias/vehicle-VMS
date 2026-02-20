<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DriverPlacementRegressionTest extends TestCase
{
    public function testDriverSubmitTargetsDriversTableOnly(): void
    {
        $dashboardPath = dirname(__DIR__, 2) . '/user-dashboard.php';
        $source = file_get_contents($dashboardPath);

        $this->assertIsString($source);
        $this->assertStringContainsString("fetch('driver_operations.php'", $source);
        $this->assertStringContainsString("const tbody = document.getElementById('driversTableBody');", $source);
        $this->assertStringNotContainsString("document.querySelector('table.table tbody')", $source);

        $start = strpos($source, 'function handleDriverSubmit(event)');
        $end = strpos($source, 'function deleteDriver(', $start ?: 0);
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);

        $driverSubmitBlock = substr($source, (int)$start, (int)$end - (int)$start);
        $this->assertStringContainsString('driversTableBody', $driverSubmitBlock);
        $this->assertStringNotContainsString('vehiclesTableBody', $driverSubmitBlock);
    }

    public function testDriverEndpointWritesOnlyAuthorizedDriverTable(): void
    {
        $driverOpsPath = dirname(__DIR__, 2) . '/driver_operations.php';
        $source = file_get_contents($driverOpsPath);

        $this->assertIsString($source);
        $this->assertStringContainsString('INSERT INTO authorized_driver', $source);
        $this->assertStringNotContainsString('INSERT INTO vehicles', $source);
        $this->assertStringNotContainsString('UPDATE vehicles', $source);
    }
}

