<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Lifecycle;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Exception\TenantContextTransitionException;
use Zhortein\MultiTenantBundle\Lifecycle\TenantExecutionBoundary;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetterInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class TenantExecutionBoundaryTest extends TestCase
{
    public function testSuccessiveSchedulerStyleExecutionsStartAndEndAtNone(): void
    {
        $context = new TenantContext();
        $boundary = new TenantExecutionBoundary($context);
        $tenantA = (new TestTenant())->setId(1);
        $tenantB = (new TestTenant())->setId(2);

        $context->setTenant($tenantB);
        self::assertSame('tenant-a', $boundary->run(function () use ($context, $tenantA): string {
            self::assertNull($context->getTenant());
            $context->setTenant($tenantA);

            return 'tenant-a';
        }));
        self::assertNull($context->getTenant());

        try {
            $boundary->run(function () use ($context, $tenantB): never {
                self::assertNull($context->getTenant());
                $context->setTenant($tenantB);
                throw new \RuntimeException('scheduled failure');
            });
            self::fail('The operation failure must be preserved.');
        } catch (\RuntimeException $exception) {
            self::assertSame('scheduled failure', $exception->getMessage());
        }

        self::assertNull($context->getTenant());
        self::assertNull($boundary->run(static fn (): mixed => null));
        self::assertNull($context->getTenant());
    }

    public function testScheduledFailureRemainsPrimaryWhenCleanupAlsoFails(): void
    {
        $context = new TenantContext();
        $operationFailure = new \RuntimeException('scheduled detail');
        $cleanupFailure = new \LogicException('cleanup detail');
        $resetter = new class($context, $cleanupFailure) implements TenantStateResetterInterface {
            private int $calls = 0;

            public function __construct(private readonly TenantContext $context, private readonly \Throwable $cleanupFailure)
            {
            }

            public function reset(): void
            {
                $this->context->reset();
                if (2 === ++$this->calls) {
                    throw $this->cleanupFailure;
                }
            }
        };

        try {
            (new TenantExecutionBoundary($context, $resetter))->run(static function () use ($operationFailure): never {
                throw $operationFailure;
            });
            self::fail('The combined operation and cleanup failure must remain observable.');
        } catch (TenantContextTransitionException $exception) {
            self::assertSame($operationFailure, $exception->getPrevious());
            self::assertSame($cleanupFailure, $exception->getCleanupFailure());
            self::assertStringNotContainsString('cleanup detail', $exception->getMessage());
        }

        self::assertNull($context->getTenant());
    }
}
