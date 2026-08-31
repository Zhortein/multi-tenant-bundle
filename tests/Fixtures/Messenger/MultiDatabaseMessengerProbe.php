<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger;

final class MultiDatabaseMessengerProbe
{
    /** @var list<array<string, mixed>> */
    public array $observations = [];

    /** @param array<string, mixed> $observation */
    public function record(array $observation): void
    {
        $this->observations[] = $observation;
    }
}
