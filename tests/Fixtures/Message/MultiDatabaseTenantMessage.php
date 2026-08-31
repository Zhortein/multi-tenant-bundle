<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Message;

use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;

final readonly class MultiDatabaseTenantMessage implements TenantAwareMessageInterface
{
    public function __construct(
        public string $step,
        public bool $fail = false,
    ) {
    }
}
