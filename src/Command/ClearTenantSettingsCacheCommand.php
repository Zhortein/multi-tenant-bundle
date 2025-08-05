<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Command;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zhortein\MultiTenantBundle\Registry\TenantRegistryInterface;

/**
 * Command to clear tenant settings cache.
 */
#[AsCommand(
    name: 'tenant:settings:clear-cache',
    description: 'Clears the tenant settings cache for all or specific tenants'
)]
final class ClearTenantSettingsCacheCommand extends Command
{
    public function __construct(
        private readonly CacheItemPoolInterface $cache,
        private readonly TenantRegistryInterface $tenantRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tenant-slug', InputArgument::OPTIONAL, 'Specific tenant slug to clear cache for')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Clear cache for all tenants');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string|null $tenantSlug */
        $tenantSlug = $input->getArgument('tenant-slug');

        /** @var bool $clearAll */
        $clearAll = $input->getOption('all');

        if (null !== $tenantSlug) {
            return $this->clearCacheForTenant($io, $tenantSlug);
        }

        if ($clearAll) {
            return $this->clearCacheForAllTenants($io);
        }

        $io->error('Please specify either a tenant slug or use --all option.');

        return Command::FAILURE;
    }

    private function clearCacheForTenant(SymfonyStyle $io, string $tenantSlug): int
    {
        try {
            $tenant = $this->tenantRegistry->getBySlug($tenantSlug);
            $cacheKey = 'zhortein_tenant_settings_'.$tenant->getId();

            try {
                if ($this->cache->deleteItem($cacheKey)) {
                    $io->success(sprintf('Cache cleared for tenant `%s`.', $tenantSlug));
                } else {
                    $io->warning(sprintf('No cache found for tenant `%s`.', $tenantSlug));
                }
            } catch (InvalidArgumentException|\Exception $e) {
                $io->error(sprintf('Error for tenant `%s` : %s', $tenantSlug, $e->getMessage()));

                return Command::FAILURE;
            }

            return Command::SUCCESS;
        } catch (\RuntimeException $e) {
            $io->error(sprintf('Tenant `%s` not found.', $tenantSlug));
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }

    private function clearCacheForAllTenants(SymfonyStyle $io): int
    {
        $tenants = $this->tenantRegistry->getAll();
        $clearedCount = 0;

        foreach ($tenants as $tenant) {
            $cacheKey = 'zhortein_tenant_settings_'.$tenant->getId();

            try {
                if ($this->cache->deleteItem($cacheKey)) {
                    ++$clearedCount;
                }
            } catch (InvalidArgumentException|\Exception $e) {
                $io->error(sprintf('Error for tenant `%s` : %s', $tenant->getId(), $e->getMessage()));
                continue; // Ignore exceptions and move on to next tenant
            }
        }

        $io->success(sprintf('Cache cleared for %d tenant%s.', $clearedCount, $clearedCount > 1 ? 's' : ''));

        return Command::SUCCESS;
    }
}
