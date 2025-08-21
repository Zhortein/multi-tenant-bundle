<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Observability\Event;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Observability\Event\TenantRlsAppliedEvent;

/**
 * @covers \Zhortein\MultiTenantBundle\Observability\Event\TenantRlsAppliedEvent
 */
final class TenantRlsAppliedEventTest extends TestCase
{
    public function testSuccessfulRlsEvent(): void
    {
        $tenantId = '123';
        $event = new TenantRlsAppliedEvent($tenantId, true);

        $this->assertSame($tenantId, $event->getTenantId());
        $this->assertTrue($event->isSuccess());
        $this->assertNull($event->getErrorMessage());
    }

    public function testFailedRlsEvent(): void
    {
        $tenantId = '456';
        $errorMessage = 'Connection failed';
        $event = new TenantRlsAppliedEvent($tenantId, false, $errorMessage);

        $this->assertSame($tenantId, $event->getTenantId());
        $this->assertFalse($event->isSuccess());
        $this->assertSame($errorMessage, $event->getErrorMessage());
    }

    public function testEventIsSymfonyEvent(): void
    {
        $event = new TenantRlsAppliedEvent('123', true);

        $this->assertInstanceOf(\Symfony\Contracts\EventDispatcher\Event::class, $event);
    }
}
