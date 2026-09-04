<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Message;

use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;

final readonly class SynchronousTenantMessage implements TenantAwareMessageInterface
{
}
