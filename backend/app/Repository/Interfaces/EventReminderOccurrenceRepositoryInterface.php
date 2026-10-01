<?php

namespace HiEvents\Repository\Interfaces;

use Carbon\CarbonInterface;
use HiEvents\Models\EventReminderOccurrence;

interface EventReminderOccurrenceRepositoryInterface
{
    public function upsertPlanned(array $identity, array $values): EventReminderOccurrence;

    public function claimDue(int $occurrenceId, CarbonInterface $now): bool;

    public function cancelUnclaimedForEvent(int $eventId, string $reason): int;
}
