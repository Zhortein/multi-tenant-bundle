<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

enum ObjectObservationState: string
{
    case VERIFIED = 'verified';
    case IDENTITY_ABSENT = 'identity_absent';
    case IDENTITY_INVALID = 'identity_invalid';
    case FOREIGN = 'foreign';
    case INVALID_REFERENCE = 'invalid_reference';
    case UNREACHABLE = 'unreachable';
    case INDETERMINATE = 'indeterminate';
    case UNAVAILABLE = 'unavailable';
}
