<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineTenantConnectionLifecycle;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineTenantConnectionRouter;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineTenantContextSynchronizer;
use Zhortein\MultiTenantBundle\Doctrine\GlobalDoctrineScope;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionMode;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionParametersProviderInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter;
use Zhortein\MultiTenantBundle\Doctrine\TenantRoutingDriverMiddleware;
use Zhortein\MultiTenantBundle\Exception\TenantConnectionConfigurationException;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\LifecycleRecord;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class DoctrineTenantConnectionLifecycleTest extends TestCase
{
    /** @var list<string> */
    private array $databaseFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->databaseFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAglobalBfailureGlobalASequenceNeverReusesPreviousPhysicalConnection(): void
    {
        $tenantA = (new TestTenant())->setId(1)->setSlug('a');
        $tenantB = (new TestTenant())->setId(2)->setSlug('b');
        $provider = $this->provider(['a' => $this->database('A'), 'b' => $this->database('B')], $this->database('GLOBAL'));
        $router = new DoctrineTenantConnectionRouter($provider);
        $connection = $this->routedConnection($router);
        $lifecycle = new DoctrineTenantConnectionLifecycle($this->registry($connection), $provider, $router);

        self::assertSame('GLOBAL', $this->marker($connection));
        $this->move($lifecycle, TenantConnectionState::none(), TenantConnectionState::tenant($tenantA));
        self::assertSame('A', $this->marker($connection));
        $this->move($lifecycle, TenantConnectionState::tenant($tenantA), TenantConnectionState::global());
        self::assertSame('GLOBAL', $this->marker($connection));
        $this->move($lifecycle, TenantConnectionState::global(), TenantConnectionState::tenant($tenantB));
        self::assertSame('B', $this->marker($connection));

        $failed = $lifecycle->prepare(TenantConnectionState::tenant($tenantB), TenantConnectionState::global());
        $failed->activate();
        self::assertSame('GLOBAL', $this->marker($connection));
        $failed->restore();
        $failed->cleanup();
        self::assertSame('B', $this->marker($connection));

        $this->move($lifecycle, TenantConnectionState::tenant($tenantB), TenantConnectionState::global());
        self::assertSame('GLOBAL', $this->marker($connection));
        $this->move($lifecycle, TenantConnectionState::global(), TenantConnectionState::tenant($tenantA));
        self::assertSame('A', $this->marker($connection));
    }

    public function testUnknownTenantLeavesPreviousStateAndConnectionUntouched(): void
    {
        $tenantA = (new TestTenant())->setId(1)->setSlug('a');
        $unknown = (new TestTenant())->setId(9)->setSlug('unknown');
        $provider = $this->provider(['a' => $this->database('A')], $this->database('GLOBAL'));
        $router = new DoctrineTenantConnectionRouter($provider);
        $connection = $this->routedConnection($router);
        $lifecycle = new DoctrineTenantConnectionLifecycle($this->registry($connection), $provider, $router);
        $this->move($lifecycle, TenantConnectionState::none(), TenantConnectionState::tenant($tenantA));

        try {
            $lifecycle->prepare(TenantConnectionState::tenant($tenantA), TenantConnectionState::tenant($unknown));
            self::fail('Unknown tenant preparation should fail.');
        } catch (TenantConnectionConfigurationException) {
            self::assertSame(TenantConnectionMode::TENANT, $router->state()->mode);
            self::assertSame('A', $this->marker($connection));
        }
    }

    public function testMissingGlobalConnectionFailsBeforeChangingTenantState(): void
    {
        $tenantA = (new TestTenant())->setId(1)->setSlug('a');
        $provider = $this->provider(['a' => $this->database('A')], null);
        $router = new DoctrineTenantConnectionRouter($provider);
        $connection = $this->routedConnection($router);
        $lifecycle = new DoctrineTenantConnectionLifecycle($this->registry($connection), $provider, $router);
        $this->move($lifecycle, TenantConnectionState::none(), TenantConnectionState::tenant($tenantA));

        try {
            $lifecycle->prepare(TenantConnectionState::tenant($tenantA), TenantConnectionState::global());
            self::fail('A global operation without an explicit global connection must fail.');
        } catch (TenantConnectionConfigurationException $exception) {
            self::assertSame('No connection is configured for global or no-context operations.', $exception->getMessage());
            self::assertSame(TenantConnectionMode::TENANT, $router->state()->mode);
            self::assertSame('A', $this->marker($connection));
        }
    }

    public function testContextFilterConnectionDataAndIdentityMapAreAtomicAcrossRequiredSequence(): void
    {
        $tenantA = (new TestTenant())->setId(1)->setSlug('a');
        $tenantB = (new TestTenant())->setId(2)->setSlug('b');
        $provider = $this->provider(['a' => $this->database('A'), 'b' => $this->database('B')], $this->database('GLOBAL'));
        $router = new DoctrineTenantConnectionRouter($provider);
        $connection = $this->routedConnection($router);
        $configuration = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__).'/Fixtures/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $configuration->enableNativeLazyObjects(true);
        }
        $configuration->addFilter('tenant_filter', TenantDoctrineFilter::class);
        $entityManager = new EntityManager($connection, $configuration);
        $filter = $entityManager->getFilters()->enable('tenant_filter');
        $filter->setParameter('tenant_context_mode', 'none');
        $filter->setParameter('tenant_id', '__NO_TENANT__');
        $registry = $this->registryForManager($entityManager);
        $lifecycle = new DoctrineTenantConnectionLifecycle($registry, $provider, $router);
        $synchronizer = new DoctrineTenantContextSynchronizer($registry, $lifecycle);
        $context = new TenantContext(null, $synchronizer);
        $globalScope = new GlobalDoctrineScope($registry, $context, $synchronizer);

        $context->setTenant($tenantA);
        self::assertSame($tenantA, $context->getTenant());
        self::assertSame('A', $this->marker($connection));
        self::assertSame("'1'", $filter->getParameter('tenant_id'));
        $recordA = $entityManager->getRepository(LifecycleRecord::class)->findOneBy([]);
        self::assertInstanceOf(LifecycleRecord::class, $recordA);
        self::assertSame('A-data', $recordA->getName());
        self::assertTrue($entityManager->contains($recordA));

        $globalScope->run(function () use ($context, $tenantA, $connection, $entityManager, $recordA): void {
            self::assertSame($tenantA, $context->getTenant());
            self::assertSame('GLOBAL', $this->marker($connection));
            self::assertFalse($entityManager->contains($recordA));
            self::assertSame('GLOBAL-data', $entityManager->getRepository(LifecycleRecord::class)->findOneBy([])?->getName());
        });
        self::assertSame('A', $this->marker($connection));

        $context->setTenant($tenantB);
        self::assertSame('B', $this->marker($connection));
        self::assertSame('B-data', $entityManager->getRepository(LifecycleRecord::class)->findOneBy([])?->getName());
        try {
            $globalScope->run(function () use ($connection): never {
                self::assertSame('GLOBAL', $this->marker($connection));
                throw new \RuntimeException('handler failed');
            });
            self::fail('The handler failure should propagate.');
        } catch (\RuntimeException $exception) {
            self::assertSame('handler failed', $exception->getMessage());
            self::assertSame($tenantB, $context->getTenant());
            self::assertSame('B', $this->marker($connection));
        }

        $globalScope->run(fn (): string => $this->marker($connection));
        $context->setTenant($tenantA);
        self::assertSame('A', $this->marker($connection));
        self::assertSame('A-data', $entityManager->getRepository(LifecycleRecord::class)->findOneBy([])?->getName());
    }

    private function database(string $marker): string
    {
        $file = tempnam(sys_get_temp_dir(), 'tenant-lifecycle-');
        self::assertIsString($file);
        $this->databaseFiles[] = $file;
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
        $connection->executeStatement('CREATE TABLE probe (marker VARCHAR(32) NOT NULL)');
        $connection->insert('probe', ['marker' => $marker]);
        $connection->executeStatement('CREATE TABLE lifecycle_records (id INTEGER PRIMARY KEY AUTOINCREMENT, tenant_id VARCHAR(64) NOT NULL, name VARCHAR(64) NOT NULL)');
        $tenantId = match ($marker) {
            'A' => '1',
            'B' => '2',
            default => 'global',
        };
        $connection->insert('lifecycle_records', ['tenant_id' => $tenantId, 'name' => $marker.'-data']);
        $connection->close();

        return $file;
    }

    /** @param array<string, string> $tenantFiles */
    private function provider(array $tenantFiles, ?string $globalFile): TenantConnectionParametersProviderInterface
    {
        return new class($tenantFiles, $globalFile) implements TenantConnectionParametersProviderInterface {
            /** @param array<string, string> $tenantFiles */
            public function __construct(private readonly array $tenantFiles, private readonly ?string $globalFile)
            {
            }

            public function parametersFor(TenantConnectionState $state): array
            {
                if (TenantConnectionMode::TENANT !== $state->mode) {
                    if (null === $this->globalFile) {
                        throw new TenantConnectionConfigurationException('No connection is configured for global or no-context operations.');
                    }

                    return ['driver' => 'pdo_sqlite', 'path' => $this->globalFile];
                }
                $slug = $state->tenant?->getSlug();
                if (null === $slug || !isset($this->tenantFiles[$slug])) {
                    throw new TenantConnectionConfigurationException('No connection is configured for the requested tenant.');
                }

                return ['driver' => 'pdo_sqlite', 'path' => $this->tenantFiles[$slug]];
            }
        };
    }

    private function routedConnection(DoctrineTenantConnectionRouter $router): Connection
    {
        $configuration = new Configuration();
        $configuration->setMiddlewares([new TenantRoutingDriverMiddleware($router)]);

        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);
    }

    private function registry(Connection $connection): ManagerRegistry
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $manager]);
        $registry->method('getConnections')->willReturn([]);

        return $registry;
    }

    private function registryForManager(EntityManagerInterface $manager): ManagerRegistry
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $manager]);
        $registry->method('getConnections')->willReturn([]);

        return $registry;
    }

    private function marker(Connection $connection): string
    {
        return (string) $connection->fetchOne('SELECT marker FROM probe');
    }

    private function move(DoctrineTenantConnectionLifecycle $lifecycle, TenantConnectionState $current, TenantConnectionState $target): void
    {
        $transition = $lifecycle->prepare($current, $target);
        $transition->activate();
        $transition->cleanup();
    }
}
