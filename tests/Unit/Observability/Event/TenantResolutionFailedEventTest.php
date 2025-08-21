<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Observability\Event;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Observability\Event\TenantResolutionFailedEvent;

/**
 * @covers \Zhortein\MultiTenantBundle\Observability\Event\TenantResolutionFailedEvent
 */
final class TenantResolutionFailedEventTest extends TestCase
{
    public function testEventCreation(): void
    {
        $resolver = 'header';
        $reason = 'no_tenant_found';
        $context = ['request_uri' => '/api/test'];

        $event = new TenantResolutionFailedEvent($resolver, $reason, $context);

        $this->assertSame($resolver, $event->getResolver());
        $this->assertSame($reason, $event->getReason());
        $this->assertSame($context, $event->getContext());
    }

    public function testEventCreationWithoutContext(): void
    {
        $resolver = 'path';
        $reason = 'invalid_format';

        $event = new TenantResolutionFailedEvent($resolver, $reason);

        $this->assertSame($resolver, $event->getResolver());
        $this->assertSame($reason, $event->getReason());
        $this->assertSame([], $event->getContext());
    }

    public function testEventIsSymfonyEvent(): void
    {
        $event = new TenantResolutionFailedEvent('test', 'reason');

        $this->assertInstanceOf(\Symfony\Contracts\EventDispatcher\Event::class, $event);
    }
}
