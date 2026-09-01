<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Decorator;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\TraceableAdapter;
use Symfony\Contracts\Cache\NamespacedPoolInterface;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Decorator\TenantAwareCacheAdapterDecorator;
use Zhortein\MultiTenantBundle\Decorator\TenantCacheException;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class TenantAwareCacheAdapterDecoratorTest extends TestCase
{
    public function testItRetainsTheSymfonyAdapterContractAndIsolatesTenantNamespaces(): void
    {
        $context = new TenantContext();
        $decorator = new TenantAwareCacheAdapterDecorator(new ArrayAdapter(), $context);

        self::assertInstanceOf(AdapterInterface::class, $decorator);
        self::assertInstanceOf(NamespacedPoolInterface::class, $decorator);
        self::assertInstanceOf(TraceableAdapter::class, new TraceableAdapter($decorator));

        $context->setTenant((new TestTenant())->setId(1));
        $tenantOneItem = $decorator->getItem('shared-key')->set('tenant-one');
        self::assertTrue($decorator->save($tenantOneItem));
        self::assertSame('tenant-one', $decorator->getItem('shared-key')->get());

        $context->setTenant((new TestTenant())->setId(2));
        self::assertFalse($decorator->getItem('shared-key')->isHit());
        $tenantTwoItem = $decorator->getItem('shared-key')->set('tenant-two');
        self::assertTrue($decorator->save($tenantTwoItem));
        self::assertSame('tenant-two', $decorator->getItem('shared-key')->get());
        self::assertTrue($decorator->clear());
        self::assertFalse($decorator->getItem('shared-key')->isHit());

        $context->setTenant((new TestTenant())->setId(1));
        self::assertSame('tenant-one', $decorator->getItem('shared-key')->get());
    }

    public function testItComposesConsumerSubNamespacesInsideTenantIsolation(): void
    {
        $context = new TenantContext();
        $decorator = new TenantAwareCacheAdapterDecorator(new ArrayAdapter(), $context);
        $subNamespace = $decorator->withSubNamespace('consumer_');

        self::assertNotSame($decorator, $subNamespace);

        $context->setTenant((new TestTenant())->setId(1));
        self::assertTrue($subNamespace->save($subNamespace->getItem('key')->set('tenant-one')));
        self::assertFalse($decorator->getItem('key')->isHit());

        $context->setTenant((new TestTenant())->setId(2));
        self::assertFalse($subNamespace->getItem('key')->isHit());

        $context->setTenant((new TestTenant())->setId(1));
        self::assertSame('tenant-one', $subNamespace->getItem('key')->get());
    }

    public function testItFailsClosedWithoutTenantContext(): void
    {
        $decorator = new TenantAwareCacheAdapterDecorator(new ArrayAdapter(), new TenantContext());

        $this->expectException(TenantCacheException::class);
        $decorator->getItem('tenant_1_shared-key');
    }
}
