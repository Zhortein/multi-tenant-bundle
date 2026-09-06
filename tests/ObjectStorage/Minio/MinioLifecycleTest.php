<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\ObjectStorage\Minio;

use ObjectStorageConsumer\Handler;
use ObjectStorageConsumer\Kernel;
use ObjectStorageConsumer\StorageMessage;
use ObjectStorageConsumer\Tenant;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Worker;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;

final class MinioLifecycleTest extends MinioTestCase
{
    public function testProductionKernelPersistentWorkerAndSerializedReferenceWithRealMinio(): void
    {
        putenv('MTB_OBJECT_ENABLED=1');
        putenv('MTB_OBJECT_BUCKET='.$this->buckets['shared']);
        putenv('MTB_OBJECT_ACCESS_KEY='.self::ACCESS);
        putenv('MTB_OBJECT_SECRET_KEY='.self::SECRET);
        $kernel = new Kernel('prod_minio_'.bin2hex(random_bytes(6)), false);
        try {
            $kernel->boot();
            $container = $kernel->getContainer();
            $context = $container->get(TenantContextInterface::class);
            $storage = $container->get(TenantObjectStorageInterface::class);
            $registry = $container->get(InMemoryTenantRegistry::class);
            $a = new Tenant('A');
            $b = new Tenant('B');
            $registry->addTenant($a);
            $registry->addTenant($b);
            $context->setTenant($a);
            // Simulated JSON-column persistence before Messenger serialization.
            $aRef = StoredObjectReference::fromJson($storage->allocate()->toJson());
            $context->setTenant($b);
            $bRef = StoredObjectReference::fromJson($storage->allocate()->toJson());
            $bus = $container->get(MessageBusInterface::class);
            foreach ([[$a, $aRef, false], [$b, $bRef, true], [$a, $aRef, false]] as [$tenant, $reference, $fail]) {
                $context->setTenant($tenant);
                $bus->dispatch(new StorageMessage($reference, $fail));
            }
            $dispatcher = new EventDispatcher();
            $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(3));
            $transport = $container->get('messenger.transport.async');
            (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
            self::assertSame(['A', 'B', 'A'], $container->get(Handler::class)->observed);
            self::assertCount(2, $transport->getAcknowledged());
            self::assertCount(1, $transport->getRejected());
            self::assertNull($context->getTenant());
            $context->setTenant($a);
            self::assertSame('worker-A', $storage->read($aRef));
            $context->setTenant($b);
            self::assertSame('worker-B', $storage->read($bRef));
            $context->clear();
            self::assertNull($context->getTenant());
            $context->setTenant($a);
            self::assertSame('worker-A', $storage->read($aRef));
            self::assertSame($storage, $container->get(TenantObjectStorageInterface::class));
            $container->get('services_resetter')->reset();
            self::assertNull($context->getTenant());
        } finally {
            $kernel->shutdown();
            restore_exception_handler();
            foreach (['MTB_OBJECT_ENABLED', 'MTB_OBJECT_BUCKET', 'MTB_OBJECT_ACCESS_KEY', 'MTB_OBJECT_SECRET_KEY'] as $name) {
                putenv($name);
            }
        }
    }
}
