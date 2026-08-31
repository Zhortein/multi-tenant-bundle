<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/** @internal Routes every new physical DBAL connection from explicit lifecycle state. */
final readonly class TenantRoutingDriverMiddleware implements Middleware
{
    public function __construct(private DoctrineTenantConnectionRouter $router)
    {
    }

    public function wrap(Driver $driver): Driver
    {
        $router = $this->router;

        return new class($driver, $router) extends AbstractDriverMiddleware {
            public function __construct(Driver $driver, private readonly DoctrineTenantConnectionRouter $router)
            {
                parent::__construct($driver);
            }

            public function connect(#[\SensitiveParameter] array $params): Driver\Connection
            {
                return parent::connect($this->router->parameters());
            }
        };
    }
}
