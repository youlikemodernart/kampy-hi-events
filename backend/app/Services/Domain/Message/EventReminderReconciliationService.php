<?php

namespace HiEvents\Services\Domain\Message;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Status\EventReminderOccurrenceStatus;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\OutgoingMessageStatus;
use HiEvents\Jobs\Message\SendEventReminderRecipientJob;
use HiEvents\Models\Event;
use HiEvents\Models\EventReminderOccurrence;
use HiEvents\Models\OutgoingMessage;
use HiEvents\Repository\Interfaces\EventReminderOccurrenceRepositoryInterface;

class EventReminderReconciliationService
{
    public function __construct(
        private readonly EventReminderOccurrenceRepositoryInterface $occurrences,
        private readonly EventReminderDispatchService $dispatch,
        private readonly EventReminderRecipientClaimService $recipients,
    ) {
    }

    public function reconcile(): void
    {
        $policy = config('event-reminders');
        $this->reconcileStaleState();
        if (($policy['enabled'] ?? false) !== true) {
            return;
        }
        $this->cancelNowIneligible($policy);
        EventReminderOccurrence::query()->where('status', EventReminderOccurrenceStatus::CLAIMING->value)->whereNotNull('message_id')
            ->each(fn (EventReminderOccurrence $occurrence) => $this->dispatch->dispatchClaimed($occurrence->id));
        $now = CarbonImmutable::now('UTC');
        Event::query()->whereIn('id', $policy['event_allowlist'] ?? [])->where('status', EventStatus::LIVE->name)
            ->whereNotNull('start_date')->whereNotNull('timezone')->each(function (Event $event) use ($policy, $now): void {
                foreach ($policy['offsets'] as $offsetKey => $offsetMinutes) {
                    $dueAt = CarbonImmutable::instance($event->start_date)->utc()->addMinutes($offsetMinutes);
                    $occurrence = $this->occurrences->upsertPlanned([
                        'event_id' => $event->id, 'policy_version' => $policy['policy_version'], 'offset_key' => $offsetKey,
                    ], [
                        'due_at_utc' => $dueAt, 'source_event_start_at_utc' => CarbonImmutable::instance($event->start_date)->utc(),
                        'source_event_timezone' => $event->timezone, 'content_version' => $policy['content_version'],
                        'theme_projection_version' => $policy['theme_projection_version'],
                    ]);
                    if ($occurrence->status === EventReminderOccurrenceStatus::PLANNED->value && $dueAt->lt($now)
                        && ($now->diffInMinutes($dueAt) > $policy['late_grace_minutes'] || CarbonImmutable::instance($event->start_date)->utc()->lte($now))) {
                        $occurrence->update(['status' => EventReminderOccurrenceStatus::SKIPPED_LATE->value, 'reason_code' => 'outside_late_grace_or_event_started']);
                    }
                }
            });
        $this->resumeClaimedRecipients();
    }

    public function cancelForIneligibleEvent(int $eventId, string $reason = 'operator_cancelled'): int
    {
        return $this->occurrences->cancelUnclaimedForEvent($eventId, $reason);
    }

    private function reconcileStaleState(): void
    {
        $cutoff = now()->subMinutes((int) config('event-reminders.recovery_stale_minutes', 15));
        EventReminderOccurrence::query()->where('status', EventReminderOccurrenceStatus::CLAIMING->value)
            ->where('claimed_at', '<=', $cutoff)->whereNull('message_id')->update([
                'status' => EventReminderOccurrenceStatus::PLANNED->value, 'reason_code' => 'claiming_recovered_without_provider_intent', 'claimed_at' => null,
            ]);
        OutgoingMessage::query()->where('status', OutgoingMessageStatus::SUBMITTING->name)->where('submitted_at', '<=', $cutoff)->update([
            'status' => OutgoingMessageStatus::UNKNOWN->name, 'last_error_class' => 'stale_submitting_requires_provider_reconciliation', 'updated_at' => now(),
        ]);
        EventReminderOccurrence::query()->whereNotNull('message_id')->whereIn('status', [EventReminderOccurrenceStatus::DISPATCHING->value, EventReminderOccurrenceStatus::UNKNOWN->value])
            ->each(fn (EventReminderOccurrence $occurrence) => $this->dispatch->aggregate($occurrence->id, $occurrence->message_id));
    }

    private function resumeClaimedRecipients(): void
    {
        OutgoingMessage::query()->where('status', OutgoingMessageStatus::CLAIMED->name)->each(function (OutgoingMessage $claim): void {
            try {
                SendEventReminderRecipientJob::dispatch($claim->id);
            } catch (\Throwable $exception) {
                $this->recipients->markFailedBeforeStart($claim, $exception::class);
            }
        });
    }

    private function cancelNowIneligible(array $policy): void
    {
        EventReminderOccurrence::query()->where('status', EventReminderOccurrenceStatus::PLANNED->value)->each(function (EventReminderOccurrence $occurrence) use ($policy): void {
            $event = Event::withTrashed()->find($occurrence->event_id);
            $reason = match (true) {
                $event === null || $event->trashed() => 'event_deleted',
                !in_array($event->id, $policy['event_allowlist'] ?? [], true) => 'event_not_allowlisted',
                $event->status !== EventStatus::LIVE->name => 'event_ineligible',
                default => null,
            };
            if ($reason !== null) $this->occurrences->cancelUnclaimedForEvent($occurrence->event_id, $reason);
        });
    }
}
