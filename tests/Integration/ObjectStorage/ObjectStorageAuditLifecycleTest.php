<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration\ObjectStorage;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetterInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\LogicalObjectIdentity;
use Zhortein\MultiTenantBundle\ObjectStorage\ObjectObservationState;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageAuditInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\AuditBackend;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\ObjectStorageKernel;

final class ObjectStorageAuditLifecycleTest extends TestCase
{
    public function testCompiledLazyInventorySameFacadeAndBothResetBoundaries(): void
    {
        $before = AuditBackend::$constructions;
        $kernel = new ObjectStorageKernel('audit_'.bin2hex(random_bytes(6)), false);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $context = $container->get(TenantContextInterface::class);
            $audit = $container->get(TenantObjectStorageAuditInterface::class);
            $storage = $container->get(TenantObjectStorageInterface::class);
            self::assertSame($audit, $storage);
            $a = (new TestTenant())->setId(1)->setSlug('same');
            $b = (new TestTenant())->setId(2)->setSlug('same');
            foreach ([$a, $b, $a] as $tenant) {
                $context->setTenant($tenant);
                self::assertSame(['shared_v1'], array_column($audit->inventoryLocations()->locations, 'locationId'));
                self::assertSame($before, AuditBackend::$constructions);
            }
            $reference = $storage->allocate();
            $identity = LogicalObjectIdentity::forReference($reference, str_repeat('c', 64));
            $audit->writeWithIdentity($reference, 'A', $identity);
            self::assertSame($before + 1, AuditBackend::$constructions);
            $context->setTenant($b);
            self::assertSame([], $audit->auditList($audit->auditScope('shared_v1'))->observations);
            $context->setTenant($a);
            self::assertEquals($identity, $audit->observe($reference)->identity);
            foreach ([$container->get(TenantStateResetterInterface::class), $kernel->getContainer()->get('services_resetter')] as $resetter) {
                $resetter->reset();
                self::assertNull($context->getTenant());
                try {
                    $audit->inventoryLocations();
                    self::fail('Reset must clear tenant context.');
                } catch (ObjectStorageException $e) {
                    self::assertSame(ObjectStorageError::MISSING_CONTEXT, $e->reason);
                }
                $context->setTenant($a);
                self::assertSame(ObjectObservationState::VERIFIED, $audit->observe($reference)->state);
            }
            $backend = $container->get(AuditBackend::class);
            $backend->duringIo = static function () use ($context, $a, $b): void {
                $context->setTenant($b);
                $context->setTenant($a);
            };
            $this->expectException(ObjectStorageException::class);
            $audit->observe($reference); // A/B/A within I/O is invalidated by the epoch, even though A is current again.
        } finally {
            $kernel->shutdown();
            restore_exception_handler();
        }
    }
}
