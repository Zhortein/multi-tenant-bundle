<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Doctrine\GlobalDoctrineScope;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionMode;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;
use Zhortein\MultiTenantBundle\Doctrine\TenantContextSynchronizerInterface;
use Zhortein\MultiTenantBundle\Doctrine\TenantDoctrineFilter;
use Zhortein\MultiTenantBundle\Exception\GlobalDoctrineScopeException;

final class GlobalDoctrineScopeTest extends TestCase
{
    public function testEnabledFilterIsSuspendedAndRestoredAroundReturnedValue(): void
    {
        [$scope, $filters] = $this->scope(true);
        $filters->expects(self::once())->method('suspend')->with('tenant_filter');
        $filters->expects(self::once())->method('restore')->with('tenant_filter');

        self::assertSame('result', $scope->run(static fn (): string => 'result'));
    }

    public function testDisabledFilterRemainsDisabled(): void
    {
        [$scope, $filters] = $this->scope(false);
        $filters->expects(self::never())->method('suspend');
        $filters->expects(self::never())->method('restore');
        $scope->run(static fn (): null => null);
    }

    public function testSuspendAndRestorePreserveTheSameDoctrineFilterIncludingParameters(): void
    {
        [$scope, $filters] = $this->scope(true);
        $filter = $this->createStub(TenantDoctrineFilter::class);
        $filters->expects(self::once())->method('suspend')->willReturn($filter);
        $filters->expects(self::once())->method('restore')->willReturn($filter);
        $scope->run(static fn (): null => null);
    }

    public function testCallbackExceptionIsPropagatedAfterRestoration(): void
    {
        [$scope, $filters] = $this->scope(true);
        $filters->expects(self::once())->method('restore');
        $this->expectExceptionMessage('callback failed');
        $scope->run(static function (): never {
            throw new \RuntimeException('callback failed');
        });
    }

    public function testSuspensionFailureIsExplicit(): void
    {
        [$scope, $filters] = $this->scope(true);
        $filters->method('suspend')->willThrowException(new \RuntimeException('failure'));
        $this->expectException(GlobalDoctrineScopeException::class);
        $scope->run(static fn (): null => null);
    }

    public function testRestorationFailureIsExplicit(): void
    {
        [$scope, $filters] = $this->scope(true);
        $filters->method('restore')->willThrowException(new \RuntimeException('failure'));
        $this->expectException(GlobalDoctrineScopeException::class);
        $scope->run(static fn (): null => null);
    }

    public function testCallbackFailureRemainsPreviousWhenRestorationAlsoFails(): void
    {
        [$scope, $filters] = $this->scope(true);
        $callbackFailure = new \RuntimeException('callback failed');
        $restorationFailure = new \RuntimeException('restore failed');
        $filters->method('restore')->willThrowException($restorationFailure);

        try {
            $scope->run(static function () use ($callbackFailure): never {
                throw $callbackFailure;
            });
            self::fail('The combined failure was not reported.');
        } catch (GlobalDoctrineScopeException $exception) {
            self::assertSame($callbackFailure, $exception->getPrevious());
            self::assertSame($restorationFailure, $exception->getRestorationFailure());
        }
    }

    public function testPreviouslySuspendedManagersAreRestoredAfterLaterSuspensionFailure(): void
    {
        $first = $this->filters(true);
        $second = $this->filters(true);
        $first->expects(self::once())->method('restore');
        $second->method('suspend')->willThrowException(new \RuntimeException('second failed'));
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn([
            'first' => $this->manager($first),
            'second' => $this->manager($second),
        ]);

        $this->expectException(GlobalDoctrineScopeException::class);
        (new GlobalDoctrineScope($registry))->run(static fn (): null => null);
    }

    public function testNestedScopeIsRejectedAndOuterScopeIsRestored(): void
    {
        [$scope, $filters] = $this->scope(true);
        $filters->expects(self::once())->method('restore');
        $this->expectException(GlobalDoctrineScopeException::class);
        $scope->run(static fn (): mixed => $scope->run(static fn (): null => null));
    }

    public function testSuccessiveRunsDoNotLeakRunningState(): void
    {
        [$scope, $filters] = $this->scope(true);
        $filters->expects(self::exactly(2))->method('suspend');
        $filters->expects(self::exactly(2))->method('restore');
        $scope->run(static fn (): null => null);
        $scope->run(static fn (): null => null);
    }

    public function testScopeAndResetAreSafeWithoutAnInstalledManagerRegistry(): void
    {
        $scope = new GlobalDoctrineScope(null);

        self::assertSame('result', $scope->run(static fn (): string => 'result'));
        $scope->reset();
        self::assertFalse($scope->isActive());
    }

    public function testAllEntityManagersAreScopedAndRestored(): void
    {
        $first = $this->filters(true);
        $second = $this->filters(true);
        $first->expects(self::once())->method('suspend');
        $first->expects(self::once())->method('restore');
        $second->expects(self::once())->method('suspend');
        $second->expects(self::once())->method('restore');
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn([
            'first' => $this->manager($first),
            'second' => $this->manager($second),
        ]);
        (new GlobalDoctrineScope($registry))->run(static fn (): null => null);
    }

    public function testResetDuringActiveScopeClosesGlobalAuthorizationAndCannotRestorePreviousState(): void
    {
        $suspended = false;
        $filters = $this->getMockBuilder(FilterCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isEnabled', 'isSuspended', 'suspend', 'restore'])
            ->getMock();
        $filter = $this->createStub(TenantDoctrineFilter::class);
        $filters->method('isEnabled')->willReturn(true);
        $filters->method('isSuspended')->willReturnCallback(static function () use (&$suspended): bool {
            return $suspended;
        });
        $filters->expects(self::once())->method('suspend')->willReturnCallback(static function () use (&$suspended, $filter): TenantDoctrineFilter {
            $suspended = true;

            return $filter;
        });
        $filters->expects(self::once())->method('restore')->willReturnCallback(static function () use (&$suspended, $filter): TenantDoctrineFilter {
            $suspended = false;

            return $filter;
        });
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $this->manager($filters)]);
        $synchronizer = new class implements TenantContextSynchronizerInterface {
            private TenantConnectionState $state;

            public function __construct()
            {
                $this->state = TenantConnectionState::none();
            }

            public function currentState(): TenantConnectionState
            {
                return $this->state;
            }

            public function transition(TenantConnectionState $current, TenantConnectionState $target): void
            {
                $this->state = $target;
            }

            public function reset(): void
            {
                $this->state = TenantConnectionState::none();
            }
        };
        $scope = new GlobalDoctrineScope($registry, synchronizer: $synchronizer);

        try {
            $scope->run(function () use ($scope, &$suspended): void {
                self::assertTrue($scope->isActive());
                self::assertTrue($suspended);
                $scope->reset();
                self::assertFalse($scope->isActive());
                self::assertFalse($suspended);
            });
            self::fail('An invalidated global scope must not return normally.');
        } catch (GlobalDoctrineScopeException $exception) {
            self::assertStringContainsString('invalidated', $exception->getMessage());
        }

        self::assertFalse($scope->isActive());
        self::assertSame(TenantConnectionMode::NONE, $synchronizer->currentState()->mode);
    }

    /** @return array{GlobalDoctrineScope, FilterCollection&\PHPUnit\Framework\MockObject\MockObject} */
    private function scope(bool $enabled): array
    {
        $filters = $this->filters($enabled);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagers')->willReturn(['default' => $this->manager($filters)]);

        return [new GlobalDoctrineScope($registry), $filters];
    }

    private function filters(bool $enabled): FilterCollection
    {
        $filters = $this->getMockBuilder(FilterCollection::class)->disableOriginalConstructor()->onlyMethods(['isEnabled', 'suspend', 'restore'])->getMock();
        $filters->method('isEnabled')->with('tenant_filter')->willReturn($enabled);

        return $filters;
    }

    private function manager(FilterCollection $filters): EntityManagerInterface
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getFilters')->willReturn($filters);

        return $manager;
    }
}
