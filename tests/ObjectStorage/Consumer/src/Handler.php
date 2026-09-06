<?php

declare(strict_types=1);

namespace ObjectStorageConsumer;

use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\ObjectStorage\TenantObjectStorageInterface;

final class Handler
{
    public array $observed = [];

    public function __construct(private TenantObjectStorageInterface $storage, private TenantContextInterface $context)
    {
    }

    public function __invoke(StorageMessage $message): void
    {
        $id = $this->context->getTenant()?->getId();
        $this->observed[] = $id;
        $this->storage->write($message->reference, 'worker-'.$id);
        if ($message->fail) {
            throw new \RuntimeException('Controlled consumer failure.');
        }
    }
}
