<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Observability\Event;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent;

/**
 * @covers \Zhortein\MultiTenantBundle\Observability\Event\TenantResolvedEvent
 */
final class TenantResolvedEventTest extends TestCase
{
    public function testEventCreation(): void
    {
        $resolver = 'subdomain';
        $tenantId = '123';

        $event = new TenantResolvedEvent($resolver, $tenantId);

        $this->assertSame($resolver, $event->getResolver());
        $this->assertSame($tenantId, $event->getTenantId());
    }

    public function testEventIsSymfonyEvent(): void
    {
        $event = new TenantResolvedEvent('test', '123');

        $this->assertInstanceOf(\Symfony\Contracts\EventDispatcher\Event::class, $event);
    }
}