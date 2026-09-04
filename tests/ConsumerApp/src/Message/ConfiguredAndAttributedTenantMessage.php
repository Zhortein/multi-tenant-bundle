<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;

#[AsMessage('attribute_transport')]
final readonly class ConfiguredAndAttributedTenantMessage implements TenantAwareMessageInterface
{
}
