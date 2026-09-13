<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageAuditCodec;

final class AuditCodecFactory
{
    public static function create(): ObjectStorageAuditCodec
    {
        return new ObjectStorageAuditCodec('test', ['test' => str_repeat('a', 64)]);
    }
}
