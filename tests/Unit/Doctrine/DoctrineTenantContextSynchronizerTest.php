<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\ORM\UnitOfWork;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineTenantContextSynchronizer;
use Zhortein\MultiTenantBundle\Doctrine\NoOpTenantConnectionLifecycle;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionLifecycleInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionTransitionInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter;
use Zhortein\MultiTenantBundle\Exception\DirtyEntityManagerException;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;
use Zhortein\MultiTenantBundle\Exception\TenantStateResetException;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class DoctrineTenantContextSynchronizerTest extends TestCase
{
    public function testCleanManagerIsConfiguredAndClearedAfterActivation(): void
    {
        $transition = $this->transition();
        $manager = $this->manager($this->unitOfWork());
        $manager->expects(self::once())->method('clear');
        $lifecycle = $this->lifecycle($transition);

        $this->synchronizer(['default' => $manager], $lifecycle)->transition(
            TenantConnectionState::none(),
            TenantConnectionState::tenant((new TestTenant())->setId(42)),
        );

        self::assertSame(['activate', 'cleanup'], $transition->calls);
    }

    /** @param non-empty-string $scheduledMethod */
    #[DataProvider('dirtySchedules')]
    public function testDirtyManagerRefusesTransitionWithoutPreparingConnection(string $scheduledMethod): void
    {
        $unitOfWork = $this->unitOfWork($scheduledMethod);
        $manager = $this->manager($unitOfWork);
        $manager->expects(self::never())->method('clear');
        $lifecycle = $this->createMock(TenantConnectionLifecycleInterface::class);
        $lifecycle->expects(self::never())->method('prepare');

        $this->expectException(DirtyEntityManagerException::class);
        $this->synchronizer(['dirty' => $manager], $lifecycle)->transition(
            TenantConnectionState::none(),
            TenantConnectionState::tenant((new TestTenant())->setId(7)),
        );
    }

    /** @return iterable<string, array{non-empty-string}> */
    public static function dirtySchedules(): iterable
    {
        yield 'insertion' => ['getScheduledEntityInsertions'];
        yield 'modification' => ['getScheduledEntityUpdates'];
        yield 'deletion' => ['getScheduledEntityDeletions'];
        yield 'collection update' => ['getScheduledCollectionUpdates'];
        yield 'collection deletion' => ['getScheduledCollectionDeletions'];
    }

    public function testOneDirtyManagerPreventsAllManagersFromBeingCleared(): void
    {
        $clean = $this->manager($this->unitOfWork());
        $dirtyUnitOfWork = $this->unitOfWork('getScheduledEntityUpdates');
        $dirty = $this->manager($dirtyUnitOfWork);
        $clean->expects(self::never())->method('clear');
        $dirty->expects(self::never())->method('clear');

        $this->expectException(DirtyEntityManagerException::class);
        $this->synchronizer(['clean' => $clean, 'dirty' => $dirty], $this->createStub(TenantConnectionLifecycleInterface::class))->transition(
            TenantConnectionState::none(),
            TenantConnectionState::tenant((new TestTenant())->setId(7)),
        );
    }

    public function testActivationFailureRestoresAndCleansPreparedTransition(): void
    {
        $transition = $this->failingTransition('activate');
        $manager = $this->manager($this->unitOfWork());
        $manager->expects(self::never())->method('clear');

        try {
            $this->synchronizer(['default' => $manager], $this->lifecycle($transition))->transition(
                TenantConnectionState::none(),
                TenantConnectionState::tenant((new TestTenant())->setId(7)),
            );
            self::fail('The activation failure must abort the context transition.');
        } catch (TenantContextTransitionException $exception) {
            self::assertSame('activate failed', $exception->getPrevious()?->getMessage());
            self::assertNull($exception->getRestorationFailure());
            self::assertSame(['activate', 'restore', 'cleanup'], $transition->calls);
        }
    }

    public function testRestorationFailureIsExposedWithoutMaskingActivationFailure(): void
    {
        $transition = $this->failingTransition('activate', true);
        $manager = $this->manager($this->unitOfWork());
        $manager->expects(self::never())->method('clear');

        try {
            $this->synchronizer(['default' => $manager], $this->lifecycle($transition))->transition(
                TenantConnectionState::none(),
                TenantConnectionState::tenant((new TestTenant())->setId(7)),
            );
            self::fail('The activation failure must abort the context transition.');
        } catch (TenantContextTransitionException $exception) {
            self::assertSame('activate failed', $exception->getPrevious()?->getMessage());
            self::assertSame('restore failed', $exception->getRestorationFailure()?->getMessage());
            self::assertSame(['activate', 'restore', 'cleanup'], $transition->calls);
        }
    }

    public function testCleanupFailureRollsBackSuccessfulActivation(): void
    {
        $transition = $this->failingTransition('cleanup');
        $manager = $this->manager($this->unitOfWork());
        $manager->expects(self::once())->method('clear');

        try {
            $this->synchronizer(['default' => $manager], $this->lifecycle($transition))->transition(
                TenantConnectionState::none(),
                TenantConnectionState::tenant((new TestTenant())->setId(7)),
            );
            self::fail('A failed preparation cleanup must roll back the transition.');
        } catch (TenantContextTransitionException $exception) {
            self::assertSame('cleanup failed', $exception->getPrevious()?->getMessage());
            self::assertSame('cleanup failed', $exception->getCleanupFailure()?->getMessage());
            self::assertSame(['activate', 'cleanup', 'restore'], $transition->calls);
        }
    }

    public function testResetInvalidatesStateAndIsIdempotentForCleanManagers(): void
    {
        $unitOfWork = $this->unitOfWork();
        $manager = $this->manager($unitOfWork);
        $manager->method('isOpen')->willReturn(true);
        $manager->method('getConnection')->willReturn(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]));
        $manager->expects(self::exactly(3))->method('clear');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $manager]);
        $registry->expects(self::never())->method('resetManager');
        $synchronizer = new DoctrineTenantContextSynchronizer($registry, new NoOpTenantConnectionLifecycle());

        $synchronizer->transition(
            TenantConnectionState::none(),
            TenantConnectionState::tenant((new TestTenant())->setId(42)),
        );
        $synchronizer->reset();
        $synchronizer->reset();

        self::assertSame('none', $synchronizer->currentState()->mode->value);
        $filter = $manager->getFilters()->getFilter('tenant_filter');
        self::assertSame("'none'", $filter->getParameter('tenant_context_mode'));
        self::assertSame("'__NO_TENANT__'", $filter->getParameter('tenant_id'));
    }

    public function testSynchronizerRemainsResettableWithoutAnInstalledManagerRegistry(): void
    {
        $synchronizer = new DoctrineTenantContextSynchronizer(null, new NoOpTenantConnectionLifecycle());

        $synchronizer->transition(
            TenantConnectionState::none(),
            TenantConnectionState::tenant((new TestTenant())->setId(42)),
        );
        $synchronizer->reset();
        $synchronizer->reset();

        self::assertSame('none', $synchronizer->currentState()->mode->value);
    }

    public function testLegacyConnectionLifecycleReceivesThePreviousStateDuringReset(): void
    {
        $lifecycle = new class implements TenantConnectionLifecycleInterface {
            /** @var list<string> */
            public array $currentModes = [];

            public function prepare(TenantConnectionState $current, TenantConnectionState $target): TenantConnectionTransitionInterface
            {
                $this->currentModes[] = $current->mode->value;

                return new class implements TenantConnectionTransitionInterface {
                    public function activate(): void
                    {
                    }

                    public function restore(): void
                    {
                    }

                    public function cleanup(): void
                    {
                    }
                };
            }
        };
        $synchronizer = $this->synchronizer([], $lifecycle);
        $synchronizer->transition(
            TenantConnectionState::none(),
            TenantConnectionState::tenant((new TestTenant())->setId(42)),
        );

        $synchronizer->reset();

        self::assertSame(['none', 'tenant'], $lifecycle->currentModes);
        self::assertSame('none', $synchronizer->currentState()->mode->value);
    }

    /** @param non-empty-string $scheduledMethod */
    #[DataProvider('dirtySchedules')]
    public function testDirtyManagerIsNeverFlushedAndIsQuarantinedDuringReset(string $scheduledMethod): void
    {
        $manager = $this->manager($this->unitOfWork($scheduledMethod));
        $manager->method('isOpen')->willReturn(true);
        $manager->expects(self::never())->method('flush');
        $manager->expects(self::once())->method('close');
        $connection = $this->getMockBuilder(\Doctrine\DBAL\Connection::class)->disableOriginalConstructor()->getMock();
        $connection->expects(self::once())->method('close');
        $manager->method('getConnection')->willReturn($connection);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['dirty' => $manager]);
        $registry->expects(self::once())->method('resetManager')->with('dirty')->willReturn($manager);
        $synchronizer = new DoctrineTenantContextSynchronizer($registry, new NoOpTenantConnectionLifecycle());

        try {
            $synchronizer->reset();
            self::fail('A dirty UnitOfWork must be reported after quarantine.');
        } catch (TenantStateResetException $exception) {
            self::assertContains(DirtyEntityManagerException::class, $exception->getFailureTypes());
        }

        self::assertSame('none', $synchronizer->currentState()->mode->value);
    }

    private function unitOfWork(?string $dirtyMethod = null): UnitOfWork
    {
        $unitOfWork = $this->createStub(UnitOfWork::class);
        foreach ([
            'getScheduledEntityInsertions',
            'getScheduledEntityUpdates',
            'getScheduledEntityDeletions',
            'getScheduledCollectionUpdates',
            'getScheduledCollectionDeletions',
        ] as $method) {
            $unitOfWork->method($method)->willReturn($method === $dirtyMethod ? [new \stdClass()] : []);
        }

        return $unitOfWork;
    }

    private function manager(UnitOfWork $unitOfWork): EntityManagerInterface
    {
        $manager = $this->createMock(EntityManagerInterface::class);
        $filters = $this->getMockBuilder(FilterCollection::class)->disableOriginalConstructor()->getMock();
        $filter = new TenantDoctrineFilter($manager);
        $filters->method('isEnabled')->with('tenant_filter')->willReturn(true);
        $filters->method('getFilter')->with('tenant_filter')->willReturn($filter);
        $manager->method('getUnitOfWork')->willReturn($unitOfWork);
        $manager->method('getFilters')->willReturn($filters);

        return $manager;
    }

    /** @param array<string, EntityManagerInterface> $managers */
    private function synchronizer(array $managers, TenantConnectionLifecycleInterface $lifecycle): DoctrineTenantContextSynchronizer
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn($managers);

        return new DoctrineTenantContextSynchronizer($registry, $lifecycle);
    }

    private function lifecycle(TenantConnectionTransitionInterface $transition): TenantConnectionLifecycleInterface
    {
        return new class($transition) implements TenantConnectionLifecycleInterface {
            public function __construct(private readonly TenantConnectionTransitionInterface $transition)
            {
            }

            public function prepare(TenantConnectionState $current, TenantConnectionState $target): TenantConnectionTransitionInterface
            {
                return $this->transition;
            }
        };
    }

    /** @return TenantConnectionTransitionInterface&object{calls: list<string>} */
    private function transition(): TenantConnectionTransitionInterface
    {
        return new class implements TenantConnectionTransitionInterface {
            /** @var list<string> */
            public array $calls = [];

            public function activate(): void
            {
                $this->calls[] = 'activate';
            }

            public function restore(): void
            {
                $this->calls[] = 'restore';
            }

            public function cleanup(): void
            {
                $this->calls[] = 'cleanup';
            }
        };
    }

    /** @return TenantConnectionTransitionInterface&object{calls: list<string>} */
    private function failingTransition(string $failurePoint, bool $restoreFails = false): TenantConnectionTransitionInterface
    {
        return new class($failurePoint, $restoreFails) implements TenantConnectionTransitionInterface {
            /** @var list<string> */
            public array $calls = [];

            public function __construct(private readonly string $failurePoint, private readonly bool $restoreFails)
            {
            }

            public function activate(): void
            {
                $this->calls[] = 'activate';
                if ('activate' === $this->failurePoint) {
                    throw new \RuntimeException('activate failed');
                }
            }

            public function restore(): void
            {
                $this->calls[] = 'restore';
                if ($this->restoreFails) {
                    throw new \RuntimeException('restore failed');
                }
            }

            public function cleanup(): void
            {
                $this->calls[] = 'cleanup';
                if ('cleanup' === $this->failurePoint) {
                    throw new \RuntimeException('cleanup failed');
                }
            }
        };
    }
}
