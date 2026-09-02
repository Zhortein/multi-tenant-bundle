<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;

final class SecurityLateResolutionTest extends WebTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['SECURITY_ENABLED'], $_SERVER['AUTO_RESOLUTION']);
        parent::tearDown();
    }

    public function testApplicationResolverCanLoadAfterALazyFirewallWithoutCoreSecurityDependency(): void
    {
        $_SERVER['SECURITY_ENABLED'] = '1';
        $_SERVER['AUTO_RESOLUTION'] = '0';
        $client = static::createClient(['environment' => 'security_late_resolution']);
        $client->disableReboot();

        $client->request('GET', '/_test/tenant-context', server: [
            'PHP_AUTH_USER' => 'tenant-a',
            'PHP_AUTH_PW' => 'fixture-password',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('tenant-a', $client->getResponse()->getContent());
        $context = static::getContainer()->get(TenantContextInterface::class);
        self::assertNull($context->getTenant());
    }
}
