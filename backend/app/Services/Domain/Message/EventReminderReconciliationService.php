<?php

namespace HiEvents\Services\Domain\Message;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Status\EventReminderOccurrenceStatus;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Models\Event;
use HiEvents\Models\EventReminderOccurrence;
use HiEvents\Repository\Interfaces\EventReminderOccurrenceRepositoryInterface;

class EventReminderReconciliationService
{
    public function __construct(private readonly EventReminderOccurrenceRepositoryInterface $occurrences)
    {
    }

    public function reconcile(): void
    {
        $policy = config('event-reminders');
        if (($policy['enabled'] ?? false) !== true) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        $allowlist = $policy['event_allowlist'] ?? [];
        Event::query()
            ->whereIn('id', $allowlist)
            ->where('status', EventStatus::LIVE->name)
            ->whereNull('deleted_at')
            ->whereNotNull('start_date')
            ->whereNotNull('timezone')
            ->each(function (Event $event) use ($policy, $now): void {
                foreach ($policy['offsets'] as $offsetKey => $offsetMinutes) {
                    $dueAt = CarbonImmutable::instance($event->start_date)->utc()->addMinutes($offsetMinutes);
                    $values = [
                        'due_at_utc' => $dueAt,
                        'source_event_start_at_utc' => CarbonImmutable::instance($event->start_date)->utc(),
                        'source_event_timezone' => $event->timezone,
                        'content_version' => $policy['content_version'],
                        'theme_projection_version' => $policy['theme_projection_version'],
                    ];
                    $occurrence = $this->occurrences->upsertPlanned([
                        'event_id' => $event->id,
                        'policy_version' => $policy['policy_version'],
                        'offset_key' => $offsetKey,
                    ], $values);

                    if ($occurrence->status !== EventReminderOccurrenceStatus::PLANNED->value) {
                        continue;
                    }

                    if ($dueAt->lt($now)) {
                        $insideGrace = $now->diffInMinutes($dueAt) <= $policy['late_grace_minutes']
                            && CarbonImmutable::instance($event->start_date)->utc()->gt($now);
                        if (!$insideGrace) {
                            $occurrence->update([
                                'status' => EventReminderOccurrenceStatus::SKIPPED_LATE->value,
                                'reason_code' => 'outside_late_grace_or_event_started',
                            ]);
                        }
                    }
                }
            });
    }

    public function cancelForIneligibleEvent(int $eventId, string $reason): int
    {
        return $this->occurrences->cancelUnclaimedForEvent($eventId, $reason);
    }
}
