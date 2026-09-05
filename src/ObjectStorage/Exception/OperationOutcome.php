<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Exception;

enum OperationOutcome: string
{
    case NOT_APPLIED = 'not_applied';
    case UNKNOWN = 'unknown';
    case PARTIAL = 'partial';
}
