<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Tenant;
use App\Message\TenantMessage;
use App\Message\UnclassifiedMessage;
use App\Messenger\ReproductionProbe;
use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Exception\ValidationFailedException;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Worker;
use Symfony\Component\Validator\Constraints\NotBlank;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;

final class Rc9CompositionReproductionTest extends KernelTestCase
{
    public function testApplicationValidationRemovesTenantProtection(): void
    {
        self::assertSame('9af00b6903803b627dd5b08119bcb5ab49d7a713', InstalledVersions::getReference('zhortein/multi-tenant-bundle'));
        $databaseUrl = (string) ($_SERVER['DATABASE_URL'] ?? '');
        self::assertNotSame('', $databaseUrl, 'Use a new, isolated PostgreSQL database for this reproducer.');
        self::bootKernel(['environment' => 'repro_'.substr(hash('sha256', $databaseUrl), 0, 12)]);
        $container = self::getContainer();
        $chain = $container->getParameter('reproduction.messenger.bus.default');
        fwrite(STDOUT, json_encode(['symfony' => InstalledVersions::getPrettyVersion('symfony/framework-bundle'), 'compiled_chain' => $chain], JSON_PRETTY_PRINT).PHP_EOL);
        self::assertContains('messenger.middleware.validation', $chain);
        self::assertNotContains(TenantSendingMiddleware::class, $chain);
        self::assertNotContains(TenantWorkerMiddleware::class, $chain);
        $bus = $container->get('messenger.bus.default');
        $connection = $container->get(Connection::class);
        fwrite(STDOUT, 'PostgreSQL '.$connection->getServerVersion().PHP_EOL);
        $transport = $container->get('messenger.transport.scheduler_persistent');
        $transport->setup();

        $bus->dispatch(new UnclassifiedMessage(), [new TransportNamesStamp(['scheduler_persistent'])]);
        self::assertSame(1, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));
        fwrite(STDOUT, "RC9 defect reproduced: an unclassified message reached the Doctrine persistent transport.\n");
        foreach ($transport->get() as $envelope) {
            self::assertInstanceOf(UnclassifiedMessage::class, $envelope->getMessage());
            $transport->ack($envelope);
        }

        try {
            $bus->dispatch(new class {
                #[NotBlank]
                public string $value = '';
            }, [new TransportNamesStamp(['scheduler_persistent'])]);
            self::fail('The application validation middleware must still execute.');
        } catch (ValidationFailedException $exception) {
            self::assertCount(1, $exception->getViolations());
        }
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM messenger_messages'));

        $context = $container->get(TenantContextInterface::class);
        $context->setTenant(new Tenant('A', 'tenant-a'));
        $bus->dispatch(new TenantMessage(), [new TenantStamp('A'), new TransportNamesStamp(['scheduler_persistent'])]);
        $context->reset();
        $failures = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        $dispatcher->addListener(WorkerMessageFailedEvent::class, static function ($event) use (&$failures): void { $failures[] = $event->getThrowable()->getMessage(); });
        (new Worker(['scheduler_persistent' => $transport], $bus, $dispatcher))->run(['sleep' => 0]);
        self::assertSame([], $failures);
        self::assertSame([null], $container->get(ReproductionProbe::class)->tenants);
        fwrite(STDOUT, "RC9 defect reproduced: after real deserialization, the tenant A handler ran with NONE.\n");
    }
}
