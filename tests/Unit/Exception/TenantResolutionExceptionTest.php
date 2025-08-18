<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Exception\TenantResolutionException;

/**
 * @covers \Zhortein\MultiTenantBundle\Exception\TenantResolutionException
 */
final class TenantResolutionExceptionTest extends TestCase
{
    public function testConstructorWithDiagnostics(): void
    {
        $diagnostics = [
            'resolvers_tried' => ['path', 'subdomain'],
            'strict_mode' => true,
        ];

        $exception = new TenantResolutionException('Test message', $diagnostics, 400);

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame($diagnostics, $exception->getDiagnostics());
        $this->assertSame(400, $exception->getCode());
    }

    public function testConstructorWithoutDiagnostics(): void
    {
        $exception = new TenantResolutionException('Test message');

        $this->assertSame('Test message', $exception->getMessage());
        $this->assertSame([], $exception->getDiagnostics());
        $this->assertSame(0, $exception->getCode());
    }

    public function testConstructorWithPreviousException(): void
    {
        $previous = new \RuntimeException('Previous error');
        $exception = new TenantResolutionException('Test message', [], 0, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }
}
