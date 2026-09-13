<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

/** Optional atomic content+opaque-envelope writes; copy/move must preserve the envelope. */
interface ObjectIdentityBackendInterface extends ObjectIdentityObserverInterface
{
    public function writeWithIdentity(string $qualifiedKey, string $content, string $envelope): void;

    public function writeFromStreamWithIdentity(string $qualifiedKey, ObjectStreamSourceInterface $source, string $envelope): void;
}
