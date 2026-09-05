<?php

declare(strict_types=1);

namespace App\Message;

use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;

final readonly class ScheduledTenantMessage implements TenantAwareMessageInterface
{
    public function __construct(public string $label)
    {
    }
}
