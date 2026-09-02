<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Unit\Doctrine;

use PHPUnit\Framework\TestCase;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineTenantRlsStateSynchronizer;
use Zhortein\MultiTenantBundle\Doctrine\TenantConnectionState;

final class DoctrineTenantRlsStateSynchronizerTest extends TestCase
{
    public function testDisabledOrUnavailableDoctrineIntegrationIsANoOp(): void
    {
        $disabled = new DoctrineTenantRlsStateSynchronizer(null, false);
        $enabledWithoutRegistry = new DoctrineTenantRlsStateSynchronizer(null, true);

        $disabled->apply(TenantConnectionState::none());
        $enabledWithoutRegistry->apply(TenantConnectionState::none());

        self::addToAssertionCount(2);
    }
}
