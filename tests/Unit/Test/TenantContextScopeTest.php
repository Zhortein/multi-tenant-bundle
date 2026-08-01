<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Test;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Context\TenantContext;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Test\TenantContextScope;

final class TenantContextScopeTest extends TestCase
{
    public function testItRestoresEmptyAndPreviousContextsAcrossSequentialScopes(): void
    {
        $context = new TenantContext();
        $scope = new TenantContextScope($context);
        $tenantA = new ScopeTenant('a');
        $tenantB = new ScopeTenant('b');

        self::assertSame('a', $scope->run($tenantA, static fn (): string|int => $context->getTenant()?->getId() ?? 'missing'));
        self::assertNull($context->getTenant());

        $context->setTenant($tenantA);
        self::assertSame('b', $scope->run($tenantB, static fn (): string|int => $context->getTenant()?->getId() ?? 'missing'));
        self::assertSame($tenantA, $context->getTenant());

        self::assertSame('a', $scope->run($tenantA, static fn (): string|int => $context->getTenant()?->getId() ?? 'missing'));
        self::assertSame($tenantA, $context->getTenant());
    }

    public function testItRestoresThePreviousContextWhenTheCallbackThrows(): void
    {
        $context = new TenantContext();
        $scope = new TenantContextScope($context);
        $tenantA = new ScopeTenant('a');
        $tenantB = new ScopeTenant('b');
        $context->setTenant($tenantA);

        try {
            $scope->run($tenantB, static function (): never {
                throw new \RuntimeException('Expected callback failure.');
            });
            self::fail('The callback exception must be propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Expected callback failure.', $exception->getMessage());
        }

        self::assertSame($tenantA, $context->getTenant());
    }

    public function testNestedScopesRestoreInLastInFirstOutOrder(): void
    {
        $context = new TenantContext();
        $scope = new TenantContextScope($context);
        $tenantA = new ScopeTenant('a');
        $tenantB = new ScopeTenant('b');

        $result = $scope->run($tenantA, static function () use ($context, $scope, $tenantB): array {
            $before = $context->getTenant();
            $inside = $scope->run($tenantB, static fn (): ?TenantInterface => $context->getTenant());
            $after = $context->getTenant();

            return [$before, $inside, $after];
        });

        self::assertSame([$tenantA, $tenantB, $tenantA], $result);
        self::assertNull($context->getTenant());
    }
}

final readonly class ScopeTenant implements TenantInterface
{
    public function __construct(private string $id)
    {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlug(): string
    {
        return $this->id;
    }

    public function getMailerDsn(): ?string
    {
        return null;
    }

    public function getMessengerDsn(): ?string
    {
        return null;
    }
}
