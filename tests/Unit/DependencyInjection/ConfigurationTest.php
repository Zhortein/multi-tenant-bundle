<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\DependencyInjection;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Dumper\YamlReferenceDumper;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mailer\Transport\TransportFactoryInterface;
use Zhortein\MultiTenantBundle\DependencyInjection\Configuration;
use Zhortein\MultiTenantBundle\DependencyInjection\ZhorteinMultiTenantExtension;
use Zhortein\MultiTenantBundle\Mailer\TenantAwareMailer;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerConfigurator;
use Zhortein\MultiTenantBundle\Mailer\TenantMailerFallbackTransportFactory;
use Zhortein\MultiTenantBundle\Messenger\MessengerRoutingStrategy;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerConfigurator;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportFactory;
use Zhortein\MultiTenantBundle\Messenger\TenantMessengerTransportResolver;
use Zhortein\MultiTenantBundle\Messenger\TenantSendingMiddleware;
use Zhortein\MultiTenantBundle\Messenger\TenantWorkerMiddleware;
use Zhortein\MultiTenantBundle\Repository\TenantSettingRepository;

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

    public function testTenantMetadataEmailHeadersAreIndependentAndDisabledByDefault(): void
    {
        $processed = (new Processor())->processConfiguration(new Configuration(), [[]]);
        self::assertFalse($processed['mailer']['add_tenant_id_header']);
        self::assertFalse($processed['mailer']['add_tenant_name_header']);

        $processed = (new Processor())->processConfiguration(new Configuration(), [[
            'mailer' => ['add_tenant_name_header' => true],
        ]]);
        self::assertFalse($processed['mailer']['add_tenant_id_header']);
        self::assertTrue($processed['mailer']['add_tenant_name_header']);
    }

    public function testMessengerRoutingStrategyDefaultsToTenantTransport(): void
    {
        $processed = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertSame(MessengerRoutingStrategy::TENANT_TRANSPORT->value, $processed['messenger']['routing_strategy']);
    }

    public function testSymfonyMessengerRoutingStrategyIsAccepted(): void
    {
        $processed = (new Processor())->processConfiguration(new Configuration(), [[
            'messenger' => ['routing_strategy' => MessengerRoutingStrategy::SYMFONY_ROUTING->value],
        ]]);

        self::assertSame(MessengerRoutingStrategy::SYMFONY_ROUTING->value, $processed['messenger']['routing_strategy']);
    }

    public function testUnknownMessengerRoutingStrategyIsRejectedDuringConfigurationProcessing(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The value "automatic" is not allowed');

        (new Processor())->processConfiguration(new Configuration(), [[
            'messenger' => ['routing_strategy' => 'automatic'],
        ]]);
    }

    public function testUnknownMessengerRoutingStrategyStopsContainerCompilation(): void
    {
        $container = new ContainerBuilder();
        $extension = new ZhorteinMultiTenantExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), [
            'messenger' => ['routing_strategy' => 'automatic'],
        ]);

        $this->expectException(InvalidConfigurationException::class);
        $container->compile();
    }

    public function testNativeMessengerRoutingStrategyIsWiredAsAnEnum(): void
    {
        $container = new ContainerBuilder();
        (new ZhorteinMultiTenantExtension())->load([[
            'messenger' => ['routing_strategy' => MessengerRoutingStrategy::SYMFONY_ROUTING->value],
        ]], $container);

        self::assertSame(
            MessengerRoutingStrategy::SYMFONY_ROUTING,
            $container->getDefinition('zhortein_multi_tenant.messenger.transport_resolver')->getArgument('$routingStrategy'),
        );
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

        $messengerFactory = $container->getDefinition('zhortein_multi_tenant.messenger.transport_factory');
        $messengerFactories = $messengerFactory->getArgument('$factories');
        self::assertInstanceOf(TaggedIteratorArgument::class, $messengerFactories);
        self::assertSame(
            ['zhortein_multi_tenant.messenger.transport_factory'],
            $messengerFactories->getExclude(),
        );
        self::assertSame('zhortein_multi_tenant.messenger.transport_resolver', (string) $container->getAlias(TenantMessengerTransportResolver::class));
        self::assertSame(
            MessengerRoutingStrategy::TENANT_TRANSPORT,
            $container->getDefinition('zhortein_multi_tenant.messenger.transport_resolver')->getArgument('$routingStrategy'),
        );

        foreach ([TenantWorkerMiddleware::class => 200, TenantSendingMiddleware::class => 150] as $service => $priority) {
            self::assertTrue($container->hasDefinition($service));
            self::assertSame(
                [['priority' => $priority]],
                $container->getDefinition($service)->getTag('messenger.middleware'),
            );
        }
    }

    public function testMailerFactoryUsesANonRecursiveFallbackAggregate(): void
    {
        $container = new ContainerBuilder();
        (new ZhorteinMultiTenantExtension())->load([[
            'mailer' => ['enabled' => true],
        ]], $container);

        self::assertTrue($container->hasDefinition(TenantSettingRepository::class));

        $fallback = $container->getDefinition('zhortein_multi_tenant.mailer.fallback_transport_factory');
        self::assertSame(TenantMailerFallbackTransportFactory::class, $fallback->getClass());
        self::assertTrue(is_a($fallback->getClass(), TransportFactoryInterface::class, true));

        $factories = $fallback->getArgument(0);
        self::assertInstanceOf(TaggedIteratorArgument::class, $factories);
        self::assertSame(
            ['zhortein_multi_tenant.mailer.transport_factory'],
            $factories->getExclude(),
        );

        $factory = $container->getDefinition('zhortein_multi_tenant.mailer.transport_factory');
        $fallbackReference = $factory->getArgument(1);
        self::assertInstanceOf(Reference::class, $fallbackReference);
        self::assertSame('zhortein_multi_tenant.mailer.fallback_transport_factory', (string) $fallbackReference);
    }

    public function testDoctrineResolvesTenantInterfaceToTheConfiguredEntity(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new DoctrineExtension());
        $extension = new ZhorteinMultiTenantExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), [
            'tenant_entity' => "App\Entity\Tenant",
        ]);

        $extension->prepend($container);

        $doctrineConfig = $container->getExtensionConfig('doctrine');
        self::assertSame(
            "App\Entity\Tenant",
            $doctrineConfig[0]['orm']['resolve_target_entities'][\Zhortein\MultiTenantBundle\Entity\TenantInterface::class],
        );
    }

    public function testMessengerMiddlewareIsPrependedToTheConfiguredBus(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new \Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension());
        $extension = new ZhorteinMultiTenantExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), [
            'messenger' => [
                'enabled' => true,
                'fallback_bus' => 'application.bus',
            ],
        ]);

        $extension->prepend($container);

        $frameworkConfig = $container->getExtensionConfig('framework');
        self::assertSame(
            [
                TenantWorkerMiddleware::class,
                TenantSendingMiddleware::class,
                TenantMessengerTransportResolver::class,
            ],
            $frameworkConfig[0]['messenger']['buses']['application.bus']['middleware'],
        );
    }

    public function testMessengerMiddlewareIsPrependedToEveryDeclaredBus(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new \Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension());
        $extension = new ZhorteinMultiTenantExtension();
        $container->registerExtension($extension);
        $container->loadFromExtension('framework', [
            'messenger' => [
                'buses' => [
                    'command.bus' => [],
                    'event.bus' => [],
                ],
            ],
        ]);
        $container->loadFromExtension($extension->getAlias(), [
            'messenger' => ['enabled' => true, 'fallback_bus' => 'query.bus'],
        ]);

        $extension->prepend($container);

        $frameworkConfig = $container->getExtensionConfig('framework')[0];
        self::assertSame(
            ['query.bus', 'command.bus', 'event.bus'],
            array_keys($frameworkConfig['messenger']['buses']),
        );
        foreach ($frameworkConfig['messenger']['buses'] as $bus) {
            self::assertSame([
                TenantWorkerMiddleware::class,
                TenantSendingMiddleware::class,
                TenantMessengerTransportResolver::class,
            ], $bus['middleware']);
        }
    }

    public function testReferenceUsesCanonicalResolverSyntax(): void
    {
        $reference = (new YamlReferenceDumper())->dump(new Configuration());

        self::assertStringContainsString('resolver:             path', $reference);
        self::assertStringContainsString('routing_strategy:     tenant_transport', $reference);
        self::assertStringNotContainsString('resolution:', $reference);
        self::assertStringNotContainsString("resolver:\n        type:", $reference);
    }
}
