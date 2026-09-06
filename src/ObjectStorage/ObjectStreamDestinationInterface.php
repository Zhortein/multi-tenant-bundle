<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

interface ObjectStreamDestinationInterface
{
    /** Write one chunk of at most 64 KiB; a short/failed write must throw. */
    public function writeChunk(string $chunk): void;
}
