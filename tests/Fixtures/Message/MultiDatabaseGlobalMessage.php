<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Message;

use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;

final readonly class MultiDatabaseGlobalMessage implements GlobalMessageInterface
{
    public function __construct(public string $step)
    {
    }
}
