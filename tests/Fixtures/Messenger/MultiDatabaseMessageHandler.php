<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Tests\Fixtures\Messenger;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Zhortein\MultiTenantBundle\Context\TenantContextInterface;
use Zhortein\MultiTenantBundle\Doctrine\DoctrineTenantConnectionRouter;
use Zhortein\MultiTenantBundle\Messenger\GlobalMessageInterface;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Entity\LifecycleRecord;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Message\MultiDatabaseGlobalMessage;
use Zhortein\MultiTenantBundle\Tests\Fixtures\Message\MultiDatabaseTenantMessage;

final readonly class MultiDatabaseMessageHandler
{
    public function __construct(
        private TenantContextInterface $tenantContext,
        private EntityManagerInterface $entityManager,
        private DoctrineTenantConnectionRouter $router,
        private MultiDatabaseMessengerProbe $probe,
    ) {
    }

    #[AsMessageHandler]
    public function handleTenant(MultiDatabaseTenantMessage $message): void
    {
        $this->handle($message);
    }

    #[AsMessageHandler]
    public function handleGlobal(MultiDatabaseGlobalMessage $message): void
    {
        $this->handle($message);
    }

    private function handle(MultiDatabaseTenantMessage|MultiDatabaseGlobalMessage $message): void
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();
        $before = $this->identityMap($unitOfWork->getIdentityMap());
        $record = $this->entityManager->getRepository(LifecycleRecord::class)->find(1);

        $this->probe->record([
            'step' => $message->step,
            'message_class' => $message::class,
            'classification' => $message instanceof GlobalMessageInterface ? 'global' : 'tenant',
            'tenant' => $this->tenantContext->getTenant()?->getSlug(),
            'connection_database' => $this->entityManager->getConnection()->fetchOne('SELECT current_database()'),
            'connection_backend' => $this->entityManager->getConnection()->fetchOne('SELECT pg_backend_pid()'),
            'data' => $record?->getName(),
            'identity_map_before' => $before,
            'identity_map_after' => $this->identityMap($unitOfWork->getIdentityMap()),
            'order' => count($this->probe->observations) + 1,
        ]);

        if ($message instanceof MultiDatabaseTenantMessage && $message->fail) {
            throw new \RuntimeException('deliberate B handler failure');
        }
    }

    /**
     * @param array<class-string, array<string, object>> $identityMap
     *
     * @return list<string>
     */
    private function identityMap(array $identityMap): array
    {
        $entities = [];
        foreach ($identityMap as $class => $instances) {
            foreach (array_keys($instances) as $identifier) {
                $entities[] = $class.'#'.$identifier;
            }
        }

        sort($entities);

        return $entities;
    }
}
