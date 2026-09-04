<?php

declare(strict_types=1);

namespace App\Message;

use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;

final readonly class ScheduledGlobalMessage implements GlobalMessageInterface
{
    public function __construct(public string $label)
    {
    }
}
