<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Service\ResetInterface;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\TestTenant;

#[Group('rls')]
final class PersistentRlsLifecycleTest extends KernelTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['TEST_KERNEL_RLS_ENABLED']);
        parent::tearDown();
        restore_exception_handler();
    }

    public function testRealServicesResetterClearsRlsAcrossTenantRotationsAndExceptions(): void
    {
        if ('1' !== ($_SERVER['TEST_DATABASE_REQUIRED'] ?? null)) {
            self::markTestSkipped('The mandatory PostgreSQL recipe executes this test.');
        }

        $_SERVER['TEST_KERNEL_RLS_ENABLED'] = '1';
        self::bootKernel(['environment' => 'persistent_rls_boundary']);
        $container = static::getContainer();
        $context = $container->get(TenantContextInterface::class);
        $connection = $container->get(Connection::class);
        $resetter = $container->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);

        foreach ([1, 2, 1] as $tenantId) {
            $context->setTenant((new TestTenant())->setId($tenantId)->setSlug('tenant-'.$tenantId));
            self::assertSame((string) $tenantId, $this->rlsValue($connection));
            $resetter->reset();
            self::assertNull($context->getTenant());
            self::assertSame('', $this->rlsValue($connection));
        }

        $context->setTenant((new TestTenant())->setId(1)->setSlug('tenant-a'));
        try {
            throw new \RuntimeException('Expected operation failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Expected operation failure.', $exception->getMessage());
            $resetter->reset();
        }

        self::assertNull($context->getTenant());
        self::assertSame('', $this->rlsValue($connection));
        $resetter->reset();
        self::assertSame('', $this->rlsValue($connection));
    }

    private function rlsValue(Connection $connection): string
    {
        return (string) $connection->fetchOne("SELECT current_setting('app.tenant_id', true)");
    }
}
