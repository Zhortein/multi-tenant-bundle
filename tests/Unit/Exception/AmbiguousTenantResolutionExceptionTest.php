<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Exception\AmbiguousTenantResolutionException;

/**
 * @covers \Zhortein\MultiTenantBundle\Exception\AmbiguousTenantResolutionException
 */
final class AmbiguousTenantResolutionExceptionTest extends TestCase
{
    public function testConstructorGeneratesCorrectMessage(): void
    {
        $tenant1 = $this->createMockTenant('tenant-one');
        $tenant2 = $this->createMockTenant('tenant-two');

        $conflictingResults = [
            'subdomain' => $tenant1,
            'path' => $tenant2,
        ];

        $exception = new AmbiguousTenantResolutionException($conflictingResults);

        $expectedMessage = 'Ambiguous tenant resolution: resolvers subdomain, path returned different tenants: tenant-one, tenant-two';
        $this->assertSame($expectedMessage, $exception->getMessage());
    }

    public function testConstructorWithDiagnostics(): void
    {
        $tenant1 = $this->createMockTenant('tenant-one');
        $tenant2 = $this->createMockTenant('tenant-two');

        $conflictingResults = [
            'subdomain' => $tenant1,
            'path' => $tenant2,
        ];

        $diagnostics = [
            'resolvers_tried' => ['subdomain', 'path'],
            'strict_mode' => true,
        ];

        $exception = new AmbiguousTenantResolutionException($conflictingResults, $diagnostics, 400);

        $this->assertSame($diagnostics, $exception->getDiagnostics());
        $this->assertSame(400, $exception->getCode());
    }

    public function testConstructorWithSingleConflict(): void
    {
        $tenant = $this->createMockTenant('tenant-one');

        $conflictingResults = [
            'subdomain' => $tenant,
        ];

        $exception = new AmbiguousTenantResolutionException($conflictingResults);

        $expectedMessage = 'Ambiguous tenant resolution: resolvers subdomain returned different tenants: tenant-one';
        $this->assertSame($expectedMessage, $exception->getMessage());
    }

    private function createMockTenant(string $slug): TenantInterface
    {
        $tenant = $this->createMock(TenantInterface::class);
        $tenant->method('getSlug')->willReturn($slug);

        return $tenant;
    }
}
