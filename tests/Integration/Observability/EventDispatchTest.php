<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration\Observability;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextEndedEvent;
use Zhortein\MultiTenantBundle\Observability\Event\TenantContextStartedEvent;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

/**
 * @covers \Zhortein\MultiTenantBundle\Context\TenantContext
 */
final class EventDispatchTest extends TestCase
{
    private EventDispatcher $eventDispatcher;
    private TenantContext $tenantContext;
    /** @var array<object> */
    private array $dispatchedEvents = [];

    protected function setUp(): void
    {
        $this->eventDispatcher = new EventDispatcher();
        $this->tenantContext = new TenantContext($this->eventDispatcher);
        $this->dispatchedEvents = [];

        // Capture all dispatched events
        $this->eventDispatcher->addListener(TenantContextStartedEvent::class, function (TenantContextStartedEvent $event) {
            $this->dispatchedEvents[] = $event;
        });

        $this->eventDispatcher->addListener(TenantContextEndedEvent::class, function (TenantContextEndedEvent $event) {
            $this->dispatchedEvents[] = $event;
        });
    }

    public function testSetTenantDispatchesStartedEvent(): void
    {
        $tenant = new TestTenant();
        $tenant->setId(123);
        $tenant->setSlug('test-tenant');

        $this->tenantContext->setTenant($tenant);

        $this->assertCount(1, $this->dispatchedEvents);
        $this->assertInstanceOf(TenantContextStartedEvent::class, $this->dispatchedEvents[0]);
        $this->assertSame('123', $this->dispatchedEvents[0]->getTenantId());
    }

    public function testSetTenantDispatchesEndedEventForPreviousTenant(): void
    {
        $tenant1 = new TestTenant();
        $tenant1->setId(123);
        $tenant1->setSlug('tenant-1');

        $tenant2 = new TestTenant();
        $tenant2->setId(456);
        $tenant2->setSlug('tenant-2');

        $this->tenantContext->setTenant($tenant1);
        $this->dispatchedEvents = []; // Reset events

        $this->tenantContext->setTenant($tenant2);

        $this->assertCount(2, $this->dispatchedEvents);

        // First event should be context ended for previous tenant
        $this->assertInstanceOf(TenantContextEndedEvent::class, $this->dispatchedEvents[0]);
        $this->assertSame('123', $this->dispatchedEvents[0]->getTenantId());

        // Second event should be context started for new tenant
        $this->assertInstanceOf(TenantContextStartedEvent::class, $this->dispatchedEvents[1]);
        $this->assertSame('456', $this->dispatchedEvents[1]->getTenantId());
    }

    public function testClearDispatchesEndedEvent(): void
    {
        $tenant = new TestTenant();
        $tenant->setId(123);
        $tenant->setSlug('test-tenant');

        $this->tenantContext->setTenant($tenant);
        $this->dispatchedEvents = []; // Reset events

        $this->tenantContext->clear();

        $this->assertCount(1, $this->dispatchedEvents);
        $this->assertInstanceOf(TenantContextEndedEvent::class, $this->dispatchedEvents[0]);
        $this->assertSame('123', $this->dispatchedEvents[0]->getTenantId());
    }

    public function testClearWithoutTenantDoesNotDispatchEvent(): void
    {
        $this->tenantContext->clear();

        $this->assertCount(0, $this->dispatchedEvents);
    }

    public function testWithoutEventDispatcherDoesNotThrow(): void
    {
        $tenantContext = new TenantContext();
        $tenant = new TestTenant();
        $tenant->setId(123);
        $tenant->setSlug('test-tenant');

        $this->expectNotToPerformAssertions();

        $tenantContext->setTenant($tenant);
        $tenantContext->clear();
    }
}
