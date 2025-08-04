<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Event;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Event\TenantDatabaseSwitchEvent;

/**
 * @covers \Zhortein\MultiTenantBundle\Event\TenantDatabaseSwitchEvent
 */
final class TenantDatabaseSwitchEventTest extends TestCase
{
    public function testEventCreation(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $previousTenant = $this->createMock(TenantInterface::class);
        $connectionParams = ['host' => 'localhost', 'dbname' => 'tenant_db'];

        $event = new TenantDatabaseSwitchEvent($tenant, $connectionParams, $previousTenant);

        $this->assertSame($tenant, $event->getTenant());
        $this->assertSame($connectionParams, $event->getConnectionParameters());
        $this->assertSame($previousTenant, $event->getPreviousTenant());
    }

    public function testEventCreationWithoutPreviousTenant(): void
    {
        $tenant = $this->createMock(TenantInterface::class);
        $connectionParams = ['host' => 'localhost', 'dbname' => 'tenant_db'];

        $event = new TenantDatabaseSwitchEvent($tenant, $connectionParams);

        $this->assertSame($tenant, $event->getTenant());
        $this->assertSame($connectionParams, $event->getConnectionParameters());
        $this->assertNull($event->getPreviousTenant());
    }

    public function testEventConstants(): void
    {
        $this->assertSame('tenant.database.before_switch', TenantDatabaseSwitchEvent::BEFORE_SWITCH);
        $this->assertSame('tenant.database.after_switch', TenantDatabaseSwitchEvent::AFTER_SWITCH);
    }
}
