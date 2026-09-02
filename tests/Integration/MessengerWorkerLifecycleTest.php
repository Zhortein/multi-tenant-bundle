<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;
use Zhortein\MultiTenantBundle\Registry\InMemoryTenantRegistry;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class MessengerWorkerLifecycleTest extends TestCase
{
    public function testRealWorkerNeverRestoresStaleStateAcrossSuccessGlobalInvalidAndFailure(): void
    {
        $context = new TenantContext();
        $tenantA = (new TestTenant())->setId(1)->setSlug('tenant-a');
        $tenantB = (new TestTenant())->setId(2)->setSlug('tenant-b');
        $registry = new InMemoryTenantRegistry([$tenantA, $tenantB]);
        $observed = [];
        $locator = new HandlersLocator([
            WorkerSuccessMessage::class => [static function () use ($context, &$observed): void {
                $observed[] = $context->getTenant()?->getSlug();
            }],
            WorkerGlobalLifecycleMessage::class => [static function () use ($context, &$observed): void {
                $observed[] = $context->getTenant()?->getSlug();
            }],
            WorkerFailureMessage::class => [static function () use ($context, &$observed): void {
                $observed[] = $context->getTenant()?->getSlug();
                throw new \RuntimeException('controlled handler failure');
            }],
        ]);
        $bus = new MessageBus([
            new TenantWorkerMiddleware($context, $registry),
            new HandleMessageMiddleware($locator),
        ]);
        $transport = new InMemoryTransport();
        $transport->send(new Envelope(new WorkerSuccessMessage(), [new TenantStamp('2')]));
        $transport->send(new Envelope(new WorkerGlobalLifecycleMessage()));
        $transport->send(new Envelope(new \stdClass()));
        $transport->send(new Envelope(new WorkerFailureMessage(), [new TenantStamp('2')]));
        $context->setTenant($tenantA);

        $this->runWorker($transport, $bus, 4);

        self::assertSame(['tenant-b', null, 'tenant-b'], $observed);
        self::assertNull($context->getTenant());
        self::assertCount(2, $transport->getAcknowledged());
        self::assertCount(2, $transport->getRejected());

        // A stopped worker object is not reusable, but the same process,
        // transport, bus and context are. A fresh Worker must still start NONE.
        $transport->send(new Envelope(new WorkerSuccessMessage(), [new TenantStamp('1')]));
        $context->setTenant($tenantB);
        $this->runWorker($transport, $bus, 1);

        self::assertSame(['tenant-b', null, 'tenant-b', 'tenant-a'], $observed);
        self::assertNull($context->getTenant());
    }

    private function runWorker(InMemoryTransport $transport, MessageBus $bus, int $limit): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener($limit));
        (new Worker(['async' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
    }
}

final class WorkerSuccessMessage implements TenantAwareMessageInterface
{
}

final class WorkerFailureMessage implements TenantAwareMessageInterface
{
}

final class WorkerGlobalLifecycleMessage implements GlobalMessageInterface
{
}
