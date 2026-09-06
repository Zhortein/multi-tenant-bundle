<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage;

use Zhortein\MultiTenantBundle\Messenger\TenantAwareMessageInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\StoredObjectReference;

final readonly class StorageMessage implements TenantAwareMessageInterface
{
    public function __construct(public StoredObjectReference $reference, public bool $fail = false)
    {
    }
}
