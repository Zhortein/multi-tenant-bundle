<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Zhortein\MultiTenantBundle\Exception\DoctrineProtectionException;

/** @internal Composes DoctrineBundle's public EntityManager configurator. */
final readonly class TenantEntityManagerConfigurator
{
    /** @var \Closure(EntityManagerInterface): mixed */
    private \Closure $configureInner;

    public function __construct(
        object $inner,
        private TenantContextSynchronizerInterface $synchronizer,
        private string $filterName = 'tenant_filter',
    ) {
        $configure = [$inner, 'configure'];
        if (!is_callable($configure)) {
            throw new \InvalidArgumentException('The decorated Doctrine EntityManager configurator must expose configure().');
        }
        $this->configureInner = \Closure::fromCallable($configure);
    }

    public function configure(EntityManagerInterface $entityManager): void
    {
        ($this->configureInner)($entityManager);

        $filters = $entityManager->getFilters();
        if (!$filters->isEnabled($this->filterName)) {
            throw new DoctrineProtectionException('A newly created EntityManager was delivered without active tenant protection.');
        }

        $state = $this->synchronizer->currentState();
        $filter = $filters->getFilter($this->filterName);
        $filter->setParameter('tenant_context_mode', $state->mode->value);
        $filter->setParameter('tenant_id', TenantConnectionMode::TENANT === $state->mode ? (string) $state->tenant?->getId() : '__NO_TENANT__');
        if (TenantConnectionMode::GLOBAL === $state->mode) {
            $filters->suspend($this->filterName);
        }
    }
}
