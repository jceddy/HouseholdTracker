<?php

declare(strict_types=1);

namespace HouseholdTracker\Tests;

use HouseholdTracker\Maintenance\MaintenanceGate;
use PHPUnit\Framework\TestCase;

final class MaintenanceGateTest extends TestCase
{
    public function testEmptyDeployedVersionFailsOpen(): void
    {
        self::assertNull(MaintenanceGate::check(''));
    }
}
