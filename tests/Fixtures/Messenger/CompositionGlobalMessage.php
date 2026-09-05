<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger;

use Symfony\Component\Validator\Constraints as Assert;
use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;

#[Assert\Callback([CompositionProbe::class, 'validateMessage'])]
final readonly class CompositionGlobalMessage implements GlobalMessageInterface
{
    public function __construct(public string $action = 'global')
    {
    }
}
