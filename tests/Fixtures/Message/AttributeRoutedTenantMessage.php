<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;

#[AsMessage('attribute_transport')]
final readonly class AttributeRoutedTenantMessage implements TenantAwareMessageInterface
{
}
