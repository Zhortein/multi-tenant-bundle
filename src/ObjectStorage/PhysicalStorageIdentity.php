<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;

/** Canonical non-secret addressing identity, derived by a trusted adapter from effective configuration. */
final readonly class PhysicalStorageIdentity
{
    /** @param array<string, string> $addressingOptions Canonical options influencing addressing; no credentials. */
    public function __construct(
        public string $backendType,
        public string $endpointIdentity,
        public string $containerOrRoot,
        public array $addressingOptions = [],
        public int $schemaVersion = 1,
    ) {
        if (1 !== $schemaVersion || '' === trim($backendType) || '' === trim($endpointIdentity) || '' === trim($containerOrRoot)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        foreach ($addressingOptions as $key => $value) {
            Internal\Validation::nonEmptyString($key);
            self::stringOption($value);
        }
    }

    private static function stringOption(mixed $value): void
    {
        if (!is_string($value)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
    }

    public function fingerprint(): string
    {
        $options = $this->addressingOptions;
        ksort($options, SORT_STRING);

        return hash('sha256', json_encode([
            'schema' => $this->schemaVersion, 'backend' => $this->backendType,
            'endpoint' => $this->endpointIdentity, 'root' => $this->containerOrRoot, 'options' => $options,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
