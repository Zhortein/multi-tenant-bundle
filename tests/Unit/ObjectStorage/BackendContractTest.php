<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\ObjectStorageBackendInterface;
use Zhortein\MultiTenantBundle\Test\ObjectStorageBackendTestCase;
use Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage\InstrumentedBackend;

final class BackendContractTest extends ObjectStorageBackendTestCase
{
    protected function createBackend(): ObjectStorageBackendInterface
    {
        return new InstrumentedBackend();
    }
}
