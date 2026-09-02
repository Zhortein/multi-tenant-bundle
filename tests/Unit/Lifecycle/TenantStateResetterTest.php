<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Lifecycle;

use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Service\ResetInterface;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\DependencyInjection\TenantScope;
use Zhortein\MultiTenantBundle\Exception\TenantStateResetException;
use Zhortein\MultiTenantBundle\Lifecycle\TenantStateResetter;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

final class TenantStateResetterTest extends TestCase
{
    public function testContextAndDerivedTenantScopeAreResetTogetherIdempotently(): void
    {
        $participants = new \ArrayIterator();
        $context = new TenantContext(derivedStateResetters: $participants);
        $scope = new TenantScope($context);
        $participants->append($scope);
        $context->setTenant((new TestTenant())->setId(1));
        $scope->get('service', static fn (): object => new \stdClass());
        $resetter = new TenantStateResetter($context);

        $resetter->reset();
        $resetter->reset();

        self::assertNull($context->getTenant());
        self::assertNull($scope->getCurrentTenant());
    }

    public function testAllParticipantsRunWhenOneResetFailsAndOnlySafeFailureTypesEscape(): void
    {
        $probe = (object) ['wasReset' => false];
        $failing = new class implements ResetInterface {
            public function reset(): void
            {
                throw new \RuntimeException('sensitive backend detail');
            }
        };
        $later = new class($probe) implements ResetInterface {
            public function __construct(private readonly object $probe)
            {
            }

            public function reset(): void
            {
                $this->probe->wasReset = true;
            }
        };

        $context = new TenantContext(derivedStateResetters: [$failing, $later]);

        try {
            (new TenantStateResetter($context))->reset();
            self::fail('The reset failure must be public.');
        } catch (TenantStateResetException $exception) {
            self::assertSame([\RuntimeException::class], $exception->getFailureTypes());
            self::assertStringNotContainsString('sensitive backend detail', $exception->getMessage());
        }

        self::assertTrue($probe->wasReset);
        self::assertNull($context->getTenant());
    }
}
