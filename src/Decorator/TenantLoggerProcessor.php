<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Decorator;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

/**
 * Monolog processor that injects tenant information into log records.
 *
 * This processor adds tenant_id and tenant_slug to the log record's extra data
 * when a tenant context is available. This allows for tenant-aware logging
 * and filtering in log aggregation systems.
 */
final class TenantLoggerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TenantContextInterface $tenantContext,
        private readonly bool $enabled = true,
    ) {
    }

    /**
     * Processes a log record by adding tenant information to the extra data.
     *
     * @param LogRecord $record The log record to process
     *
     * @return LogRecord The processed log record with tenant information
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        if (!$this->enabled) {
            return $record;
        }

        $tenant = $this->tenantContext->getTenant();
        if (!$tenant) {
            return $record;
        }

        $extra = $record->extra;
        $extra['tenant_id'] = $tenant->getId();
        $extra['tenant_slug'] = $tenant->getSlug();

        return $record->with(extra: $extra);
    }
}
