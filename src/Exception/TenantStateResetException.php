<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Exception;

final class TenantStateResetException extends \RuntimeException implements MultiTenantException
{
    /**
     * @param list<class-string<\Throwable>> $failureTypes
     */
    public function __construct(private readonly array $failureTypes)
    {
        parent::__construct('Tenant state could not be reset safely; affected Doctrine resources were quarantined.');
    }

    /** @return list<class-string<\Throwable>> */
    public function getFailureTypes(): array
    {
        return $this->failureTypes;
    }
}
