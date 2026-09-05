<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration\ObjectStorage;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetterInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\GlobalStorageMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\InstrumentedBackend;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\ObjectStorageKernel;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\StorageMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\StorageMessageHandler;

final class ObjectStorageLifecycleTest extends TestCase
{
    public function testSameCompiledKernelAndWorkerHandleAToBToNoneToAAfterSuccessAndFailure(): void
    {
        $kernel = new ObjectStorageKernel('object_'.bin2hex(random_bytes(6)), false);
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $context = $container->get(TenantContextInterface::class);
            $storage = $container->get(TenantObjectStorageInterface::class);
            $backend = $container->get(InstrumentedBackend::class);
            $registry = $container->get(InMemoryTenantRegistry::class);
            $a = (new TestTenant())->setId(1)->setSlug('same-slug');
            $b = (new TestTenant())->setId(2)->setSlug('same-slug');
            $registry->addTenant($a);
            $registry->addTenant($b);
            $context->setTenant($a);
            $aRef = $storage->allocate();
            $context->setTenant($b);
            $bRef = $storage->allocate();
            $bus = $container->get(MessageBusInterface::class);
            $context->setTenant($a);
            $bus->dispatch(new StorageMessage($aRef));
            $context->setTenant($b);
            $bus->dispatch(new StorageMessage($bRef, true));
            $bus->dispatch(new GlobalStorageMessage());
            $context->setTenant($a);
            $bus->dispatch(new StorageMessage($aRef));
            $transport = $container->get('messenger.transport.async');
            $dispatcher = new EventDispatcher();
            $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(4));
            (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
            self::assertSame([1, 2, null, 1], $container->get(StorageMessageHandler::class)->observed);
            self::assertCount(3, $transport->getAcknowledged());
            self::assertCount(1, $transport->getRejected());
            self::assertNull($context->getTenant());
            self::assertSame(['write', 'write', 'write'], array_column($backend->calls, 0));
            $context->setTenant($a);
            self::assertSame('worker-1', $storage->read($aRef));
            $context->setTenant($b);
            self::assertSame('worker-2', $storage->read($bRef));
            // Lifecycle reset invalidates logical context before any fallible derived cleanup.
            $container->get(TenantStateResetterInterface::class)->reset();
            $container->get(TenantStateResetterInterface::class)->reset();
            self::assertNull($context->getTenant());
            $calls = $backend->calls;
            try {
                $storage->exists($aRef);
                self::fail('A reused facade must not retain the previous tenant.');
            } catch (ObjectStorageException $e) {
                self::assertSame(ObjectStorageError::MISSING_CONTEXT, $e->reason);
            }
            self::assertSame($calls, $backend->calls);
            $context->setTenant($a);
            $kernel->getContainer()->get('services_resetter')->reset();
            self::assertNull($context->getTenant());
            self::assertSame($storage, $container->get(TenantObjectStorageInterface::class));
        } finally {
            $kernel->shutdown();
            restore_exception_handler();
        }
    }
}
