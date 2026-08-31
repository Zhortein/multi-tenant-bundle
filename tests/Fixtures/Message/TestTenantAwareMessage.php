<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Message;

use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;

/**
 * Test message for tenant-aware messaging tests.
 */
final readonly class TestTenantAwareMessage implements TenantAwareMessageInterface
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
