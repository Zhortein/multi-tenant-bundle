<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\Internal\Validation;

/** Server-owned keyring. Inject runtime secrets; never store keys in compiled configuration. */
final readonly class ObjectStorageAuditCodec
{
    /** @param array<string, string> $keys Independent random keys, exactly 64 lowercase hex characters each. */
    public function __construct(private string $activeKey, #[\SensitiveParameter] private array $keys)
    {
        if (!extension_loaded('openssl') || [] === $keys || count($keys) > 16 || !isset($keys[$activeKey])) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
        foreach ($keys as $id => $key) {
            Validation::identifier($id);
            self::validateKey($key);
        }
    }

    private static function validateKey(#[\SensitiveParameter] mixed $key): void
    {
        if (!is_string($key) || 1 !== preg_match('/\A[a-f0-9]{64}\z/D', $key)) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
    }

    /** @param array<array-key, mixed> $data */
    public function seal(#[\SensitiveParameter] array $data, string $purpose): string
    {
        try {
            $json = json_encode($data, JSON_THROW_ON_ERROR);
            if (strlen($json) > 8192) {
                throw new \RuntimeException();
            }
            $iv = random_bytes(12);
            $tag = '';
            $ciphertext = openssl_encrypt($json, 'aes-256-gcm', $this->key($this->activeKey, $purpose), OPENSSL_RAW_DATA, $iv, $tag, 'mtb-audit-v1:'.$purpose.':'.$this->activeKey, 16);
            if (false === $ciphertext) {
                throw new \RuntimeException();
            }

            return 'a1.'.$this->activeKey.'.'.rtrim(strtr(base64_encode($iv.$tag.$ciphertext), '+/', '-_'), '=');
        } catch (\Throwable) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_ARGUMENT);
        }
    }

    /** @return array<array-key, mixed> */
    public function open(#[\SensitiveParameter] string $token, string $purpose): array
    {
        try {
            if (strlen($token) > 12288 || 1 !== preg_match('/\Aa1\.([A-Za-z][A-Za-z0-9_-]{0,63})\.([A-Za-z0-9_-]+)\z/D', $token, $parts)
                || !isset($this->keys[$parts[1]])) {
                throw new \RuntimeException();
            }
            $raw = base64_decode(strtr($parts[2], '-_', '+/'), true);
            if (false === $raw || strlen($raw) < 29 || rtrim(strtr(base64_encode($raw), '+/', '-_'), '=') !== $parts[2]) {
                throw new \RuntimeException();
            }
            $json = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $this->key($parts[1], $purpose), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), 'mtb-audit-v1:'.$purpose.':'.$parts[1]);
            if (false === $json || strlen($json) > 8192) {
                throw new \RuntimeException();
            }
            $data = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \RuntimeException();
            }

            return $data;
        } catch (\Throwable) {
            throw new ObjectStorageException(ObjectStorageError::INVALID_REFERENCE);
        }
    }

    private function key(string $id, string $purpose): string
    {
        return hash_hkdf('sha256', $this->keys[$id], 32, 'mtb-object-storage-audit-v1:'.$purpose);
    }

    /** @return array{} */
    public function __debugInfo(): array
    {
        return [];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new ObjectStorageException(ObjectStorageError::UNSUPPORTED_OPERATION);
    }
}
