<?php

declare(strict_types=1);

namespace Zhortein\MultiTenantBundle\Messenger;

/** Marks a message as explicitly global. Application authorization is still required. */
interface GlobalMessageInterface
{
}
