<?php

namespace HiEvents\Repository\Eloquent;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\Status\EventReminderOccurrenceStatus;
use HiEvents\Models\EventReminderOccurrence;
use HiEvents\Repository\Interfaces\EventReminderOccurrenceRepositoryInterface;

class EventReminderOccurrenceRepository implements EventReminderOccurrenceRepositoryInterface
{
    public function upsertPlanned(array $identity, array $values): EventReminderOccurrence
    {
        $occurrence = EventReminderOccurrence::query()->firstOrNew($identity);

        if (!$occurrence->exists || $occurrence->status === EventReminderOccurrenceStatus::PLANNED->value) {
            $occurrence->fill($values + ['status' => EventReminderOccurrenceStatus::PLANNED->value]);
            $occurrence->save();
        }

        return $occurrence;
    }

    public function claimDue(int $occurrenceId, CarbonInterface $now): bool
    {
        return EventReminderOccurrence::query()
            ->whereKey($occurrenceId)
            ->where('status', EventReminderOccurrenceStatus::PLANNED->value)
            ->where('due_at_utc', '<=', $now)
            ->update([
                'status' => EventReminderOccurrenceStatus::CLAIMING->value,
                'claimed_at' => $now,
                'updated_at' => $now,
            ]) === 1;
    }

    public function cancelUnclaimedForEvent(int $eventId, string $reason): int
    {
        return EventReminderOccurrence::query()
            ->where('event_id', $eventId)
            ->whereIn('status', [EventReminderOccurrenceStatus::PLANNED->value, EventReminderOccurrenceStatus::CLAIMING->value])
            ->update([
                'status' => EventReminderOccurrenceStatus::CANCELLED->value,
                'reason_code' => $reason,
                'updated_at' => now(),
            ]);
    }
}
