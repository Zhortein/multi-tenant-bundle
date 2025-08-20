<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Message;

/**
 * Test message for tenant-aware messaging tests.
 */
final readonly class TestTenantAwareMessage
{
    public function __construct(
        private string $data,
    ) {
    }

    public function getData(): string
    {
        return $this->data;
    }
}