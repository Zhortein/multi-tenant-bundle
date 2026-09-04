<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

enum MessengerRoutingStrategy: string
{
    case TENANT_TRANSPORT = 'tenant_transport';
    case SYMFONY_ROUTING = 'symfony_routing';
}
