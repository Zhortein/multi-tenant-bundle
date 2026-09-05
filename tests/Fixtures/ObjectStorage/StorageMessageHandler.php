<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageError;
use Zhortein\MultiTenantBundle\ObjectStorage\Exception\ObjectStorageException;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;

final class StorageMessageHandler
{
    public array $observed = [];

    public function __construct(private TenantObjectStorageInterface $storage, private TenantContextInterface $context)
    {
    }

    public function __invoke(StorageMessage|GlobalStorageMessage $message): void
    {
        $this->observed[] = $this->context->getTenant()?->getId();
        if ($message instanceof GlobalStorageMessage) {
            try {
                $this->storage->allocate();
            } catch (ObjectStorageException $e) {
                if (ObjectStorageError::MISSING_CONTEXT === $e->reason) {
                    return;
                }
                throw $e;
            }
            throw new \LogicException('Global message leaked tenant storage.');
        }
        $this->storage->write($message->reference, 'worker-'.(string) $this->context->getTenant()?->getId());
        if ($message->fail) {
            throw new \RuntimeException('Controlled storage consumer failure.');
        }
    }
}
