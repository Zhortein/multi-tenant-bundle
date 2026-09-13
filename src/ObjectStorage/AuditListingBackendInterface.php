<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

/** Optional technical port. No I/O outside the exact tenant prefix, even for anomalies. */
interface AuditListingBackendInterface
{
    /** One bounded, strictly ordered page. Keys may be malformed but must remain inside the prefix.
     * A missing key, foreign physical key, unordered page or uncertain scope fails the whole page.
     */
    public function auditList(string $tenantPrefix, int $limit, ?string $afterKey = null): BackendObjectPage;
}
