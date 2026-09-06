<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Exception;

use Zhortein\MultiTenantBundle\Exception\MultiTenantException;

class ObjectStorageException extends \RuntimeException implements MultiTenantException
{
    public function __construct(public readonly ObjectStorageError $reason)
    {
        parent::__construct('Object storage: '.$reason->value.'.');
    }
}
