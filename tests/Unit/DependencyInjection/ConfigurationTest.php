<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\DependencyInjection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Dumper\YamlReferenceDumper;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Zhortein\MultiTenantBundle\DependencyInjection\Configuration;
use Zhortein\MultiTenantBundle\DependencyInjection\ZhorteinMultiTenantExtension;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportFactory;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportResolver;

/**
 * @covers \Zhortein\MultiTenantBundle\DependencyInjection\Configuration
 */
final class ConfigurationTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedResolvers(): iterable
    {
        foreach (['path', 'subdomain', 'header', 'query', 'domain', 'hybrid', 'dns_txt', 'chain', 'custom'] as $resolver) {
            yield $resolver => [$resolver];
        }
    }

    #[DataProvider('supportedResolvers')]
    public function testCanonicalResolverIsAcceptedAndNormalized(string $resolver): void
    {
        $processed = (new Processor())->processConfiguration(new Configuration(), [['resolver' => $resolver]]);

        self::assertSame($resolver, $processed['resolver']);
    }

    public function testChainAcceptsEveryBuiltInResolver(): void
    {
        $order = ['path', 'subdomain', 'header', 'query', 'domain', 'hybrid', 'dns_txt'];
        $processed = (new Processor())->processConfiguration(new Configuration(), [[
            'resolver' => 'chain',
            'resolver_chain' => ['order' => $order],
        ]]);

        self::assertSame($order, $processed['resolver_chain']['order']);
    }

    public function testChainRejectsUnknownResolverInsteadOfSilentlySkippingIt(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The value "jwt" is not allowed');

        (new Processor())->processConfiguration(new Configuration(), [[
            'resolver' => 'chain',
            'resolver_chain' => ['order' => ['jwt', 'path']],
        ]]);
    }

    public function testResolverTypeStructureIsRejectedWithMigrationInstruction(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Use "resolver: path" instead of the unsupported "resolver.type" structure.');

        (new Processor())->processConfiguration(new Configuration(), [['resolver' => ['type' => 'path']]]);
    }

    public function testResolutionStrategyStructureIsRejectedWithMigrationInstruction(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Use the scalar "resolver: path" syntax instead.');

        (new Processor())->processConfiguration(new Configuration(), [['resolution' => ['strategy' => 'path']]]);
    }

    public function testUnknownResolverIsRejectedAndListsSupportedValues(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('Permissible values: "path", "subdomain", "header", "query", "domain", "hybrid", "dns_txt", "chain", "custom".');

        (new Processor())->processConfiguration(new Configuration(), [['resolver' => 'automatic']]);
    }

    public function testOptionalIntegrationClassAliasesAreRegistered(): void
    {
        $container = new ContainerBuilder();
        (new ZhorteinMultiTenantExtension())->load([[
            'mailer' => ['enabled' => true],
            'messenger' => ['enabled' => true],
        ]], $container);

        self::assertSame('zhortein_multi_tenant.mailer.configurator', (string) $container->getAlias(TenantMailerConfigurator::class));
        self::assertSame('zhortein_multi_tenant.mailer.tenant_aware', (string) $container->getAlias(TenantAwareMailer::class));
        self::assertSame('zhortein_multi_tenant.messenger.configurator', (string) $container->getAlias(TenantMessengerConfigurator::class));
        self::assertSame('zhortein_multi_tenant.messenger.transport_factory', (string) $container->getAlias(TenantMessengerTransportFactory::class));
        self::assertSame('zhortein_multi_tenant.messenger.transport_resolver', (string) $container->getAlias(TenantMessengerTransportResolver::class));
    }

    public function testReferenceUsesCanonicalResolverSyntax(): void
    {
        $reference = (new YamlReferenceDumper())->dump(new Configuration());

        self::assertStringContainsString('resolver:             path', $reference);
        self::assertStringNotContainsString('resolution:', $reference);
        self::assertStringNotContainsString("resolver:\n        type:", $reference);
    }
}
