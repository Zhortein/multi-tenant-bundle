<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\EventListener\StopWorkerOnMessageLimitListener;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineTenantConnectionRouter;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionMode;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Message\MultiDatabaseGlobalMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Message\MultiDatabaseTenantMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger\MultiDatabaseMessengerProbe;
use Zhortein\MultiTenantBundle\Tests\Fixtures\MultiDatabaseMessengerKernel;

#[Group('rls')]
#[Group('multi_database_messenger')]
final class MessengerMultiDatabaseLifecycleTest extends TestCase
{
    private MultiDatabaseMessengerKernel $kernel;

    private ContainerInterface $container;

    private MessageBusInterface $bus;

    private InMemoryTransport $transport;

    private TenantContextInterface $context;

    private EntityManagerInterface $entityManager;

    private DoctrineTenantConnectionRouter $router;

    private MultiDatabaseMessengerProbe $probe;

    /** @var list<WorkerMessageFailedEvent> */
    private array $failures = [];

    protected function setUp(): void
    {
        if ('1' !== ($_SERVER['TEST_DATABASE_REQUIRED'] ?? null)) {
            self::markTestSkipped('The mandatory PostgreSQL recipe executes this test.');
        }

        foreach (['messenger_tenant_a_test' => ['1', 'A-only'], 'messenger_tenant_b_test' => ['2', 'B-only'], 'messenger_global_test' => ['global', 'GLOBAL-only']] as $database => [$tenantId, $marker]) {
            $this->seedDatabase($database, $tenantId, $marker);
        }

        $this->kernel = new MultiDatabaseMessengerKernel('messenger_multi_db', true);
        $this->kernel->boot();
        $this->container = $this->kernel->getContainer()->get('test.service_container');
        $this->bus = $this->service('messenger.bus.default', MessageBusInterface::class);
        $this->transport = $this->service('messenger.transport.multi_db_async', InMemoryTransport::class);
        $this->context = $this->service(TenantContextInterface::class, TenantContextInterface::class);
        $this->entityManager = $this->service('doctrine.orm.entity_manager', EntityManagerInterface::class);
        $this->router = $this->service(DoctrineTenantConnectionRouter::class, DoctrineTenantConnectionRouter::class);
        $this->probe = $this->service(MultiDatabaseMessengerProbe::class, MultiDatabaseMessengerProbe::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
        restore_exception_handler();
    }

    public function testWorkerKeepsAglobalBfailureGlobalAIsolatedAcrossRealPostgresqlConnections(): void
    {
        $tenantA = (new TestTenant())->setId(1)->setSlug('tenant-a')->setName('Tenant A');
        $tenantB = (new TestTenant())->setId(2)->setSlug('tenant-b')->setName('Tenant B');

        $this->dispatchTenant($tenantA, new MultiDatabaseTenantMessage('A-1'));
        $this->consumeOne();
        self::assertSame([], array_map(static fn (WorkerMessageFailedEvent $event): string => $event->getThrowable()::class.': '.$event->getThrowable()->getMessage(), $this->failures));
        $this->assertObservation(0, 'A-1', MultiDatabaseTenantMessage::class, 'tenant', 'tenant-a', 'messenger_tenant_a_test', 'A-only');
        $this->assertRestoredState();

        $this->bus->dispatch(new MultiDatabaseGlobalMessage('global-1'));
        $this->consumeOne();
        $this->assertObservation(1, 'global-1', MultiDatabaseGlobalMessage::class, 'global', null, 'messenger_global_test', 'GLOBAL-only');
        $this->assertRestoredState();

        $this->dispatchTenant($tenantB, new MultiDatabaseTenantMessage('B-1'));
        $this->consumeOne();
        $this->assertObservation(2, 'B-1', MultiDatabaseTenantMessage::class, 'tenant', 'tenant-b', 'messenger_tenant_b_test', 'B-only');
        $this->assertRestoredState();

        $this->dispatchTenant($tenantB, new MultiDatabaseTenantMessage('B-failure', true));
        $this->consumeOne();
        $this->assertObservation(3, 'B-failure', MultiDatabaseTenantMessage::class, 'tenant', 'tenant-b', 'messenger_tenant_b_test', 'B-only');
        self::assertCount(1, $this->failures);
        self::assertFalse($this->failures[0]->willRetry());
        $failure = $this->failures[0]->getThrowable();
        self::assertInstanceOf(HandlerFailedException::class, $failure);
        self::assertSame('deliberate B handler failure', $failure->getPrevious()?->getMessage());
        self::assertCount(1, $this->transport->getRejected());
        self::assertCount(4, $this->probe->observations, 'The failed envelope must not be handled twice.');
        $this->assertRestoredState();

        $this->bus->dispatch(new MultiDatabaseGlobalMessage('global-2'));
        $this->consumeOne();
        $this->assertObservation(4, 'global-2', MultiDatabaseGlobalMessage::class, 'global', null, 'messenger_global_test', 'GLOBAL-only');
        $this->assertRestoredState();

        $this->dispatchTenant($tenantA, new MultiDatabaseTenantMessage('A-2'));
        $this->consumeOne();
        $this->assertObservation(5, 'A-2', MultiDatabaseTenantMessage::class, 'tenant', 'tenant-a', 'messenger_tenant_a_test', 'A-only');
        $this->assertRestoredState();

        self::assertSame([1, 2, 3, 4, 5, 6], array_column($this->probe->observations, 'order'));
        self::assertCount(5, $this->transport->getAcknowledged());
        self::assertCount(1, $this->transport->getRejected());
        self::assertSame(0, $this->openConnections('messenger_tenant_a_test'));
        self::assertSame(0, $this->openConnections('messenger_tenant_b_test'));
    }

    private function dispatchTenant(TestTenant $tenant, MultiDatabaseTenantMessage $message): void
    {
        $this->context->setTenant($tenant);
        $envelope = $this->bus->dispatch($message);
        self::assertSame((string) $tenant->getId(), $envelope->last(TenantStamp::class)?->getTenantId());
        $this->context->clear();
        $this->assertRestoredState();
    }

    private function consumeOne(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new StopWorkerOnMessageLimitListener(1));
        $dispatcher->addListener(WorkerMessageFailedEvent::class, function (WorkerMessageFailedEvent $event): void {
            $this->failures[] = $event;
        });
        $worker = new Worker(['multi_db_async' => $this->transport], $this->bus, $dispatcher);
        $worker->run(['sleep' => 0]);
    }

    /** @param class-string $messageClass */
    private function assertObservation(int $index, string $step, string $messageClass, string $classification, ?string $tenant, string $database, string $data): void
    {
        self::assertArrayHasKey($index, $this->probe->observations);
        $observation = $this->probe->observations[$index];
        self::assertSame($step, $observation['step']);
        self::assertSame($messageClass, $observation['message_class']);
        self::assertSame($classification, $observation['classification']);
        self::assertSame($tenant, $observation['tenant']);
        self::assertSame($database, $observation['connection_database']);
        self::assertSame($data, $observation['data']);
        self::assertSame([], $observation['identity_map_before']);
        self::assertSame(['Zhortein\\MultiTenantBundle\\Tests\\Fixtures\\Entity\\LifecycleRecord#1'], $observation['identity_map_after']);
        self::assertIsInt($observation['connection_backend']);
    }

    private function assertRestoredState(): void
    {
        self::assertNull($this->context->getTenant());
        self::assertSame(TenantConnectionMode::NONE, $this->router->state()->mode);
        self::assertSame('messenger_global_test', $this->entityManager->getConnection()->fetchOne('SELECT current_database()'));
        $filters = $this->entityManager->getFilters();
        self::assertTrue($filters->isEnabled('tenant_filter'));
        $filter = $filters->getFilter('tenant_filter');
        self::assertSame("'none'", $filter->getParameter('tenant_context_mode'));
        self::assertSame("'__NO_TENANT__'", $filter->getParameter('tenant_id'));
        self::assertSame([], $this->entityManager->getUnitOfWork()->getIdentityMap());
        self::assertSame([], $this->entityManager->getUnitOfWork()->getScheduledEntityInsertions());
        self::assertSame([], $this->entityManager->getUnitOfWork()->getScheduledEntityUpdates());
        self::assertSame([], $this->entityManager->getUnitOfWork()->getScheduledEntityDeletions());
        self::assertSame(0, $this->openConnections('messenger_tenant_a_test'));
        self::assertSame(0, $this->openConnections('messenger_tenant_b_test'));
    }

    private function seedDatabase(string $database, string $tenantId, string $marker): void
    {
        $connection = DriverManager::getConnection($this->parameters($database));
        $connection->executeStatement('DROP TABLE IF EXISTS lifecycle_records');
        $connection->executeStatement('DROP TABLE IF EXISTS test_tenants');
        $connection->executeStatement('CREATE TABLE test_tenants (id INTEGER PRIMARY KEY, slug VARCHAR(255) UNIQUE NOT NULL, name VARCHAR(255) NOT NULL, active BOOLEAN NOT NULL, createdat TIMESTAMP NOT NULL)');
        $connection->executeStatement('CREATE TABLE lifecycle_records (id INTEGER PRIMARY KEY, tenant_id VARCHAR(64) NOT NULL, name VARCHAR(64) NOT NULL)');
        $connection->insert('test_tenants', ['id' => 1, 'slug' => 'tenant-a', 'name' => 'Tenant A', 'active' => true, 'createdat' => '2026-01-01 00:00:00']);
        $connection->insert('test_tenants', ['id' => 2, 'slug' => 'tenant-b', 'name' => 'Tenant B', 'active' => true, 'createdat' => '2026-01-01 00:00:00']);
        $connection->insert('lifecycle_records', ['id' => 1, 'tenant_id' => $tenantId, 'name' => $marker]);
        $connection->close();
    }

    private function openConnections(string $database): int
    {
        return (int) $this->entityManager->getConnection()->fetchOne('SELECT count(*) FROM pg_stat_activity WHERE datname = ?', [$database]);
    }

    /** @return array<string, mixed> */
    private function parameters(string $database): array
    {
        return [
            'driver' => 'pdo_pgsql',
            'host' => (string) ($_SERVER['TEST_DATABASE_HOST'] ?? 'postgres'),
            'port' => (int) ($_SERVER['TEST_DATABASE_PORT'] ?? 5432),
            'dbname' => $database,
            'user' => (string) ($_SERVER['TEST_DATABASE_USER'] ?? 'test_user'),
            'password' => (string) ($_SERVER['TEST_DATABASE_PASSWORD'] ?? ''),
            'serverVersion' => (string) ($_SERVER['TEST_DATABASE_SERVER_VERSION'] ?? '16'),
        ];
    }

    /** @template T of object
     * @param class-string<T> $type
     *
     * @return T
     */
    private function service(string $id, string $type): object
    {
        $service = $this->container->get($id);
        self::assertInstanceOf($type, $service);

        return $service;
    }
}
