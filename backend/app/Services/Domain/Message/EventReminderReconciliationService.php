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
        $this->cancelNowIneligible($policy);
        if (($policy['enabled'] ?? false) !== true) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        Event::query()
            ->whereIn('id', $policy['event_allowlist'] ?? [])
            ->where('status', EventStatus::LIVE->name)
            ->whereNotNull('start_date')
            ->whereNotNull('timezone')
            ->each(function (Event $event) use ($policy, $now): void {
                foreach ($policy['offsets'] as $offsetKey => $offsetMinutes) {
                    $dueAt = CarbonImmutable::instance($event->start_date)->utc()->addMinutes($offsetMinutes);
                    $occurrence = $this->occurrences->upsertPlanned([
                        'event_id' => $event->id,
                        'policy_version' => $policy['policy_version'],
                        'offset_key' => $offsetKey,
                    ], [
                        'due_at_utc' => $dueAt,
                        'source_event_start_at_utc' => CarbonImmutable::instance($event->start_date)->utc(),
                        'source_event_timezone' => $event->timezone,
                        'content_version' => $policy['content_version'],
                        'theme_projection_version' => $policy['theme_projection_version'],
                    ]);
                    if ($occurrence->status === EventReminderOccurrenceStatus::PLANNED->value && $dueAt->lt($now)) {
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

    public function cancelForIneligibleEvent(int $eventId, string $reason = 'operator_cancelled'): int
    {
        return $this->occurrences->cancelUnclaimedForEvent($eventId, $reason);
    }

    private function cancelNowIneligible(array $policy): void
    {
        EventReminderOccurrence::query()
            ->where('status', EventReminderOccurrenceStatus::PLANNED->value)
            ->each(function (EventReminderOccurrence $occurrence) use ($policy): void {
                $event = Event::withTrashed()->find($occurrence->event_id);
                $reason = match (true) {
                    ($policy['enabled'] ?? false) !== true => 'reminders_disabled',
                    $event === null || $event->trashed() => 'event_deleted',
                    !in_array($event->id, $policy['event_allowlist'] ?? [], true) => 'event_not_allowlisted',
                    $event->status !== EventStatus::LIVE->name => 'event_ineligible',
                    default => null,
                };
                if ($reason !== null) {
                    $this->occurrences->cancelUnclaimedForEvent($occurrence->event_id, $reason);
                }
            });
    }
}
