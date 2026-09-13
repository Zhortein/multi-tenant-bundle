<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\Entity\TenantInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\Validation;

final readonly class ObjectStorageRegistry
{
    /** @var array<string, StorageLocation|StorageLocationRegistration> */
    private array $locations;

    public string $revision;

    /** @param iterable<StorageLocation|StorageLocationRegistration> $locations
     * @param array<string, string> $providers logical provider => active generation ID
     */
    public function __construct(iterable $locations, private array $providers, private ?ObjectStorageAuditCodec $auditCodec = null)
    {
        $indexed = [];
        foreach ($locations as $location) {
            $id = $location instanceof StorageLocationRegistration ? $location->descriptor->locationId : $location->id;
            if (isset($indexed[$id])) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
            }
            $indexed[$id] = $location;
        }
        ksort($indexed, SORT_STRING);
        $this->locations = $indexed;
        foreach ($providers as $provider => $locationId) {
            Validation::identifier($provider);
            if (!isset($indexed[$locationId])) {
                throw new ObjectStorageException(ObjectStorageError::UNKNOWN_LOCATION);
            }
        }
        $revision = [];
        foreach ($indexed as $id => $location) {
            if (null !== $auditCodec && !$location instanceof StorageLocationRegistration) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
            }
            if ($location instanceof StorageLocationRegistration) {
                $descriptor = $location->descriptor;
                if (!isset($providers[$descriptor->provider]) || $descriptor->active !== ($providers[$descriptor->provider] === $id)) {
                    throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
                }
                foreach ($providers as $provider => $active) {
                    if ($active === $id && $provider !== $descriptor->provider) {
                        throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
                    }
                }
                $allowed = $location->allowedTenants;
                sort($allowed, SORT_STRING);
                $revision[] = [$descriptor, hash('sha256', serialize($allowed))];
            } else {
                $revision[] = [$id, hash('sha256', serialize($location->allowedTenants))];
            }
        }
        ksort($providers, SORT_STRING);
        $this->revision = hash('sha256', json_encode([$revision, $providers], JSON_THROW_ON_ERROR));
    }

    public function location(string $id): StorageLocation
    {
        $location = $this->locations[$id] ?? throw new ObjectStorageException(ObjectStorageError::UNKNOWN_LOCATION);

        return $location instanceof StorageLocationRegistration ? $location->resolve() : $location;
    }

    public function forTenantLocation(string $id, TenantInterface $tenant): StorageLocation
    {
        $location = $this->locations[$id] ?? throw new ObjectStorageException(ObjectStorageError::UNKNOWN_LOCATION);
        if (!$location->allows($tenant)) {
            throw new ObjectStorageException(ObjectStorageError::TENANT_NOT_ALLOWED);
        }

        return $this->location($id);
    }

    /** Stable bytewise location-ID order, including historical generations. No factory is invoked. */
    public function inventory(TenantInterface $tenant, int $limit = 100, ?string $cursor = null): StorageLocationInventoryPage
    {
        $tenantId = Validation::tenantId($tenant->getId());
        $codec = $this->auditCodec ?? throw new ObjectStorageException(ObjectStorageError::UNSUPPORTED_OPERATION);
        if ($limit < 1 || $limit > 1000) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        $context = [hash('sha256', $tenantId), $this->revision, $limit];
        $after = null;
        if (null !== $cursor) {
            $data = $codec->open($cursor, 'locations');
            if (['context', 'after'] !== array_keys($data) || $data['context'] !== $context || !is_string($data['after'])) {
                throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
            }
            Validation::identifier($data['after']);
            $after = $data['after'];
        }
        $items = [];
        $last = null;
        $more = false;
        foreach ($this->locations as $id => $location) {
            if ((null !== $after && strcmp($id, $after) <= 0) || !$location->allows($tenant)) {
                continue;
            }
            if (!$location instanceof StorageLocationRegistration) {
                throw new ObjectStorageException(ObjectStorageError::UNSUPPORTED_OPERATION);
            }
            if (count($items) === $limit) {
                $more = true;
                break;
            }
            $items[] = $location->descriptor;
            $last = $id;
        }

        return new StorageLocationInventoryPage($items, $more ? $codec->seal(['context' => $context, 'after' => $last], 'locations') : null, $this->revision);
    }

    public function forProvider(string $provider): StorageLocation
    {
        return $this->location($this->providers[$provider] ?? throw new ObjectStorageException(ObjectStorageError::UNKNOWN_PROVIDER));
    }
}
