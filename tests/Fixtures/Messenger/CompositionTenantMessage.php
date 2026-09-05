<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger;

use Symfony\Component\Validator\Constraints as Assert;
use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;

#[Assert\Callback([CompositionProbe::class, 'validateMessage'])]
final readonly class CompositionTenantMessage implements TenantAwareMessageInterface
{
    public function __construct(#[Assert\NotBlank] public string $action = 'normal')
    {
    }
}
