<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Resolver;

use Symfony\Component\HttpFoundation\Request;
use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Resolves tenants based on DNS TXT records.
 *
 * This resolver queries DNS TXT records for the pattern `_tenant.<domain>` or `_tenant.<subdomain>`
 * to determine the tenant identifier. The value found in the TXT record is then used to resolve
 * the tenant through the tenant registry.
 *
 * Example DNS configuration:
 * - `_tenant.acme.com` TXT "acme"
 * - `_tenant.client.example.com` TXT "client_tenant"
 *
 * This resolver is particularly useful for:
 * - Multi-domain setups where DNS control is available
 * - Dynamic tenant assignment without code changes
 * - Distributed systems with DNS-based configuration
 *
 * @phpstan-type DnsRecord array{host: string, class: string, ttl: int, type: string, txt: string}
 */
class DnsTxtTenantResolver implements TenantResolverInterface
{
    /** @var string The DNS record prefix used for tenant resolution */
    private const string DNS_RECORD_PREFIX = '_tenant.';

    /** @var int Default DNS query timeout in seconds */
    private const int DEFAULT_TIMEOUT = 5;

    /**
     * @param TenantRegistryInterface $tenantRegistry The tenant registry for resolving tenant identifiers
     * @param int                     $dnsTimeout     DNS query timeout in seconds
     * @param bool                    $enableCache    Whether to enable DNS result caching (for performance)
     */
    public function __construct(
        private readonly TenantRegistryInterface $tenantRegistry,
        private readonly int $dnsTimeout = self::DEFAULT_TIMEOUT,
        private readonly bool $enableCache = true,
    ) {
    }

    /**
     * Resolves tenant from DNS TXT record.
     *
     * The resolver performs the following steps:
     * 1. Extracts the host from the HTTP request
     * 2. Constructs the DNS query for `_tenant.<host>`
     * 3. Queries the DNS TXT record
     * 4. Extracts the tenant identifier from the TXT record
     * 5. Resolves the tenant using the tenant registry
     *
     * @param Request $request The HTTP request
     *
     * @return TenantInterface|null The resolved tenant or null if not found
     */
    public function resolveTenant(Request $request): ?TenantInterface
    {
        $host = $request->getHost();

        if (empty($host)) {
            return null;
        }

        // Normalize host (remove port if present)
        $normalizedHost = $this->normalizeHost($host);

        // Query DNS TXT record for tenant information
        $tenantIdentifier = $this->queryDnsTxtRecord($normalizedHost);

        if (null === $tenantIdentifier) {
            return null;
        }

        // Resolve tenant using the registry
        try {
            return $this->tenantRegistry->getBySlug($tenantIdentifier);
        } catch (\Exception) {
            // Tenant not found in registry or other error
            return null;
        }
    }

    /**
     * Queries DNS TXT record for tenant information.
     *
     * @param string $host The normalized host to query
     *
     * @return string|null The tenant identifier or null if not found
     */
    protected function queryDnsTxtRecord(string $host): ?string
    {
        $dnsQuery = self::DNS_RECORD_PREFIX.$host;

        try {
            // Set DNS query timeout if supported
            if (function_exists('dns_get_record')) {
                // Use dns_get_record for better control and error handling
                $records = $this->performDnsQuery($dnsQuery);
            } else {
                // Fallback to basic DNS resolution
                $records = $this->performFallbackDnsQuery($dnsQuery);
            }

            return $this->extractTenantIdentifierFromRecords($records);
        } catch (\Exception) {
            // DNS query failed or other error
            return null;
        }
    }

    /**
     * Performs DNS query using dns_get_record function.
     *
     * @param string $dnsQuery The DNS query string
     *
     * @return array<int, array<string, mixed>> The DNS records
     *
     * @throws \RuntimeException If DNS query fails
     */
    private function performDnsQuery(string $dnsQuery): array
    {
        // Set error handler to catch DNS resolution errors
        $previousErrorHandler = set_error_handler(static function (int $severity, string $message): bool {
            throw new \RuntimeException(sprintf('DNS query failed: %s', $message), $severity);
        });

        try {
            $records = dns_get_record($dnsQuery, DNS_TXT);

            if (false === $records) {
                throw new \RuntimeException('DNS query returned false');
            }

            return $records;
        } finally {
            // Restore previous error handler
            if (null !== $previousErrorHandler) {
                set_error_handler($previousErrorHandler);
            } else {
                restore_error_handler();
            }
        }
    }

    /**
     * Performs fallback DNS query using basic functions.
     *
     * @param string $dnsQuery The DNS query string
     *
     * @return array<array{txt: string}> The DNS records in compatible format
     *
     * @throws \RuntimeException If DNS query fails
     */
    private function performFallbackDnsQuery(string $dnsQuery): array
    {
        // This is a simplified fallback - in production, consider using a DNS library
        // like ReactPHP/DNS or similar for better control and async capabilities

        $command = sprintf('dig +short TXT %s 2>/dev/null', escapeshellarg($dnsQuery));
        $output = shell_exec($command);

        if (null === $output || false === $output) {
            throw new \RuntimeException('DNS query command failed');
        }

        $lines = array_filter(explode("\n", trim($output)));
        $records = [];

        foreach ($lines as $line) {
            // Remove quotes from TXT record value
            $txtValue = trim($line, '"');
            if (!empty($txtValue)) {
                $records[] = ['txt' => $txtValue];
            }
        }

        return $records;
    }

    /**
     * Extracts tenant identifier from DNS records.
     *
     * @param array<int, array<string, mixed>> $records The DNS TXT records
     *
     * @return string|null The tenant identifier or null if not found
     */
    private function extractTenantIdentifierFromRecords(array $records): ?string
    {
        if (empty($records)) {
            return null;
        }

        // Use the first TXT record found
        $firstRecord = $records[0];
        $txtValue = $firstRecord['txt'];

        // Validate and sanitize the tenant identifier
        $tenantIdentifier = $this->sanitizeTenantIdentifier($txtValue);

        return !empty($tenantIdentifier) ? $tenantIdentifier : null;
    }

    /**
     * Sanitizes and validates the tenant identifier.
     *
     * @param string $identifier The raw tenant identifier from DNS
     *
     * @return string The sanitized tenant identifier
     */
    private function sanitizeTenantIdentifier(string $identifier): string
    {
        // Remove any whitespace
        $identifier = trim($identifier);

        // Basic validation: only allow alphanumeric characters, hyphens, and underscores
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $identifier)) {
            return '';
        }

        // Convert to lowercase for consistency
        return strtolower($identifier);
    }

    /**
     * Normalizes the host by removing port information.
     *
     * @param string $host The raw host from the request
     *
     * @return string The normalized host
     */
    private function normalizeHost(string $host): string
    {
        // Remove port if present (e.g., "example.com:8080" -> "example.com")
        if (str_contains($host, ':')) {
            $host = explode(':', $host)[0];
        }

        return strtolower(trim($host));
    }

    /**
     * Checks if DNS TXT record exists for a given host.
     *
     * This method can be used for validation or debugging purposes.
     *
     * @param string $host The host to check
     *
     * @return bool True if DNS TXT record exists
     */
    public function hasDnsTxtRecord(string $host): bool
    {
        $normalizedHost = $this->normalizeHost($host);
        $tenantIdentifier = $this->queryDnsTxtRecord($normalizedHost);

        return null !== $tenantIdentifier;
    }

    /**
     * Gets the tenant identifier from DNS without resolving the tenant.
     *
     * This method can be used for debugging or validation purposes.
     *
     * @param string $host The host to query
     *
     * @return string|null The tenant identifier or null if not found
     */
    public function getTenantIdentifierFromDns(string $host): ?string
    {
        $normalizedHost = $this->normalizeHost($host);

        return $this->queryDnsTxtRecord($normalizedHost);
    }

    /**
     * Gets the DNS query string that would be used for a given host.
     *
     * @param string $host The host
     *
     * @return string The DNS query string
     */
    public function getDnsQueryForHost(string $host): string
    {
        $normalizedHost = $this->normalizeHost($host);

        return self::DNS_RECORD_PREFIX.$normalizedHost;
    }

    /**
     * Gets the configured DNS timeout.
     *
     * @return int The DNS timeout in seconds
     */
    public function getDnsTimeout(): int
    {
        return $this->dnsTimeout;
    }

    /**
     * Checks if DNS result caching is enabled.
     *
     * @return bool True if caching is enabled
     */
    public function isCacheEnabled(): bool
    {
        return $this->enableCache;
    }
}
