<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AutomaticResolutionTest extends WebTestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['AUTO_RESOLUTION']);
        parent::tearDown();
    }

    public function testEarlyAutomaticResolverRemainsAvailableForInfrastructureStrategies(): void
    {
        $_SERVER['AUTO_RESOLUTION'] = '1';
        $client = static::createClient(['environment' => 'automatic_resolution']);
        $client->disableReboot();

        $client->request('GET', '/_test/tenant-context', server: ['HTTP_X_CONSUMER_TENANT' => 'tenant-a']);

        self::assertResponseIsSuccessful();
        self::assertSame('tenant-a', $client->getResponse()->getContent());
    }
}
