<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage\Exception;

enum ObjectStorageError: string
{
    case MISSING_CONTEXT = 'missing_context';
    case INVALID_REFERENCE = 'invalid_reference';
    case FOREIGN_REFERENCE = 'foreign_reference';
    case UNKNOWN_PROVIDER = 'unknown_provider';
    case UNKNOWN_LOCATION = 'unknown_location';
    case BINDING_MISMATCH = 'binding_mismatch';
    case TENANT_NOT_ALLOWED = 'tenant_not_allowed';
    case UNSUPPORTED_OPERATION = 'unsupported_operation';
    case OBJECT_NOT_FOUND = 'object_not_found';
    case INVALID_ARGUMENT = 'invalid_argument';
    case CONTEXT_CHANGED = 'context_changed';
    case BACKEND_FAILURE = 'backend_failure';
}
