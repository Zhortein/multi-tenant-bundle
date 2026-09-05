<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\ObjectStorage;

use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrl;
use Zhortein\MultiTenantBundle\ObjectStorage\TemporaryObjectUrlBackendInterface;

final class SigningBackend extends InstrumentedBackend implements TemporaryObjectUrlBackendInterface
{
    public ?\DateTimeImmutable $forcedExpiry = null;

    public function temporaryUrl(string $qualifiedKey, \DateTimeImmutable $expiresAt): TemporaryObjectUrl
    {
        $this->record('temporaryUrl', $qualifiedKey);

        return new TemporaryObjectUrl('https://synthetic.invalid/object?signature=synthetic', $this->forcedExpiry ?? $expiresAt);
    }
}
