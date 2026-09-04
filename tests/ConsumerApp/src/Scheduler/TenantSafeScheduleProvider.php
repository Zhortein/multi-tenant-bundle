<?php

declare(strict_types=1);

namespace App\Scheduler;

use App\Message\ScheduledGlobalMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;

#[AsSchedule('tenant_safe')]
final class TenantSafeScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())->add(
            RecurringMessage::every(
                1,
                new RedispatchMessage(new ScheduledGlobalMessage('consumer-scheduler-proof'), 'scheduler_persistent'),
            ),
        );
    }
}
