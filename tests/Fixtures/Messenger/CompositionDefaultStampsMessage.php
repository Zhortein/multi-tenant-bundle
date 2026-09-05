<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger;

use Symfony\Component\Messenger\Message\DefaultStampsProviderInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\Messenger\TenantStamp;

final readonly class CompositionDefaultStampsMessage implements TenantAwareMessageInterface, DefaultStampsProviderInterface
{
    public function getDefaultStamps(): array
    {
        return [new TenantStamp('1')];
    }
}
