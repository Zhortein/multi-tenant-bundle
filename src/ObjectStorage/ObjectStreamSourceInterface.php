<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\ObjectStorage;

interface ObjectStreamSourceInterface
{
    /** Read at most 64 KiB from the caller's current position; never rewind or close the caller's stream. */
    public function readChunk(): string;

    public function eof(): bool;
}
