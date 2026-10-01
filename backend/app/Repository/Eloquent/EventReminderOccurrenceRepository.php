<?php

namespace HiEvents\Repository\Eloquent;

use Carbon\CarbonInterface;
use HiEvents\DomainObjects\Status\EventReminderOccurrenceStatus;
use HiEvents\Models\EventReminderOccurrence;
use HiEvents\Repository\Interfaces\EventReminderOccurrenceRepositoryInterface;
use Illuminate\Support\Facades\DB;

class EventReminderOccurrenceRepository implements EventReminderOccurrenceRepositoryInterface
{
    public function upsertPlanned(array $identity, array $values): EventReminderOccurrence
    {
        $columns = array_keys($identity + $values + ['status' => EventReminderOccurrenceStatus::PLANNED->value]);
        $bindings = [];
        foreach ($columns as $column) {
            $value = ($identity + $values + ['status' => EventReminderOccurrenceStatus::PLANNED->value])[$column];
            $bindings[] = $value instanceof CarbonInterface ? $value->toDateTimeString() : $value;
        }
        $bindings[] = now()->toDateTimeString();
        $bindings[] = now()->toDateTimeString();
        $quoted = implode(', ', array_map(static fn(string $column) => '"' . $column . '"', $columns));
        $placeholders = implode(', ', array_fill(0, count($columns), '?')) . ', ?, ?';
        $updates = implode(', ', array_map(static fn(string $column) => '"' . $column . '" = EXCLUDED."' . $column . '"', array_keys($values)));
        DB::statement(
            "INSERT INTO event_reminder_occurrences ({$quoted}, created_at, updated_at) VALUES ({$placeholders})
             ON CONFLICT (event_id, policy_version, offset_key) DO UPDATE SET {$updates}, updated_at = EXCLUDED.updated_at
             WHERE event_reminder_occurrences.status = 'PLANNED'",
            $bindings,
        );

        return EventReminderOccurrence::query()->where($identity)->firstOrFail();
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
            ->where('status', EventReminderOccurrenceStatus::PLANNED->value)
            ->update([
                'status' => EventReminderOccurrenceStatus::CANCELLED->value,
                'reason_code' => $reason,
                'updated_at' => now(),
            ]);
    }
}
