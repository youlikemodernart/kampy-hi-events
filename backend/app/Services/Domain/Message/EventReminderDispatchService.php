<?php

namespace HiEvents\Services\Domain\Message;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\EventReminderOccurrenceStatus;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\MessageStatus;
use HiEvents\DomainObjects\Status\OutgoingMessageStatus;
use HiEvents\Jobs\Message\SendEventReminderRecipientJob;
use HiEvents\Mail\Event\EventReminder;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\EventReminderOccurrence;
use HiEvents\Models\Message;
use HiEvents\Models\OutgoingMessage;
use HiEvents\Repository\Interfaces\EventReminderOccurrenceRepositoryInterface;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;

class EventReminderDispatchService
{
    public function __construct(
        private readonly EventReminderOccurrenceRepositoryInterface $occurrences,
        private readonly EventReminderContextBuilder $contexts,
        private readonly EventReminderRecipientClaimService $recipients,
        private readonly MessagingEligibilityService $messagingEligibility,
        private readonly Mailer $mailer,
    ) {
    }

    public function dispatchDue(): void
    {
        if ((config('event-reminders.enabled') ?? false) !== true) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        EventReminderOccurrence::query()->where('status', EventReminderOccurrenceStatus::PLANNED->value)
            ->where('due_at_utc', '<=', $now)->each(function (EventReminderOccurrence $occurrence) use ($now): void {
                if ($this->occurrences->claimDue($occurrence->id, $now)) {
                    $this->dispatchClaimed($occurrence->id);
                }
            });
    }

    public function dispatchClaimed(int $occurrenceId): void
    {
        $occurrence = EventReminderOccurrence::query()->find($occurrenceId);
        if ($occurrence === null || $occurrence->status !== EventReminderOccurrenceStatus::CLAIMING->value) {
            return;
        }
        $event = Event::query()->with('event_settings')->find($occurrence->event_id);
        if (!$this->isStillDispatchable($occurrence, $event) || $this->messagingEligibility->checkEligibility($event->account_id, $event->id) !== null) {
            $this->returnToPlanned($occurrence, 'event_policy_or_messaging_changed');
            return;
        }
        $context = $this->contexts->build($event);
        if ($context === null) {
            $this->failOccurrence($occurrence, 'missing_required_binding');
            return;
        }

        $mail = new EventReminder($context);
        $html = $mail->render();
        $text = view('emails.event.reminder-text', ['context' => $context->payload(), 'theme' => $context->theme])->render();
        $digest = hash('sha256', $html . "\n" . $text);
        $message = DB::transaction(function () use ($occurrence, $event, $context, $html, $text, $digest): Message {
            $fresh = EventReminderOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);
            if ($fresh->status !== EventReminderOccurrenceStatus::CLAIMING->value) {
                throw new \LogicException('Reminder occurrence claim was lost');
            }
            $message = Message::query()->firstOrCreate(['source_key' => 'event-reminder:' . $fresh->id], [
                'event_id' => $event->id, 'subject' => __('Reminder: :event is coming up', ['event' => $context->eventTitle]),
                'message' => $html, 'type' => MessageTypeEnum::ALL_ATTENDEES->name,
                'status' => MessageStatus::PROCESSING->name, 'source' => 'EVENT_REMINDER',
                'sent_by_user_id' => null, 'send_data' => ['payload_digest' => $digest, 'text' => $text],
            ]);
            $fresh->update(['message_id' => $message->id, 'payload_digest' => $digest, 'status' => EventReminderOccurrenceStatus::DISPATCHING->value]);
            return $message;
        });

        $claims = [];
        Attendee::query()->where('event_id', $event->id)->where('status', AttendeeStatus::ACTIVE->name)->each(
            function (Attendee $attendee) use ($message, $event, $digest, &$claims): void {
                $claim = $this->recipients->claim($message->id, $event->id, $attendee->id, $attendee->email, $message->subject, $digest);
                if ($claim !== null && $claim->status === OutgoingMessageStatus::CLAIMED->name) {
                    $claims[$claim->id] = $claim;
                }
            }
        );
        if ($this->messagingEligibility->checkTierLimits($event->account_id, count($claims), $html) !== null) {
            OutgoingMessage::query()->whereIn('id', array_keys($claims))->where('status', OutgoingMessageStatus::CLAIMED->name)
                ->update(['status' => OutgoingMessageStatus::CANCELLED->name, 'last_error_class' => 'messaging_tier_limit']);
            $this->failOccurrence(EventReminderOccurrence::query()->findOrFail($occurrenceId), 'messaging_tier_limit');
            return;
        }
        foreach ($claims as $claim) {
            try {
                SendEventReminderRecipientJob::dispatch($claim->id);
            } catch (\Throwable $exception) {
                $this->recipients->markFailedBeforeStart($claim, $exception::class);
            }
        }
        $this->aggregate($occurrenceId, $message->id);
    }

    public function sendRecipient(int $outgoingMessageId): void
    {
        $claim = OutgoingMessage::query()->find($outgoingMessageId);
        if ($claim === null || $claim->status !== OutgoingMessageStatus::CLAIMED->name) {
            return;
        }
        if ((config('event-reminders.enabled') ?? false) !== true) {
            return;
        }
        $occurrence = EventReminderOccurrence::query()->where('message_id', $claim->message_id)->first();
        $event = $occurrence === null ? null : Event::query()->with('event_settings')->find($claim->event_id);
        if (!$this->isStillDispatchable($occurrence, $event)) {
            $claim->update(['status' => OutgoingMessageStatus::SUPPRESSED->name]);
            if ($occurrence !== null) $this->aggregate($occurrence->id, $claim->message_id);
            return;
        }
        $active = Attendee::query()->whereKey($claim->attendee_id)->where('event_id', $claim->event_id)
            ->where('status', AttendeeStatus::ACTIVE->name)->exists();
        $context = $event === null ? null : $this->contexts->build($event);
        if (!$active || $context === null || !$this->digestMatches($claim, $context)) {
            $claim->update(['status' => OutgoingMessageStatus::SUPPRESSED->name]);
            $this->aggregate($occurrence->id, $claim->message_id);
            return;
        }
        if (!$this->recipients->markSubmitting($claim)) {
            return;
        }
        try {
            // The kill switch is intentionally re-read immediately before provider handoff.
            if ((config('event-reminders.enabled') ?? false) !== true) {
                return;
            }
            $this->mailer->to($claim->recipient)->send(new EventReminder($context));
            $claim->update(['status' => OutgoingMessageStatus::SENT->name, 'provider_accepted_at' => now()]);
        } catch (\Throwable $exception) {
            $this->recipients->markUnknownAfterUncertainHandoff($claim, $exception::class);
        }
        $this->aggregate($occurrence->id, $claim->message_id);
    }

    public function aggregate(int $occurrenceId, int $messageId): void
    {
        $statuses = OutgoingMessage::query()->where('message_id', $messageId)->pluck('status')->all();
        if (in_array(OutgoingMessageStatus::UNKNOWN->name, $statuses, true)) {
            EventReminderOccurrence::query()->whereKey($occurrenceId)->update(['status' => EventReminderOccurrenceStatus::UNKNOWN->value]);
            Message::query()->whereKey($messageId)->update(['status' => MessageStatus::UNKNOWN->name]);
            return;
        }
        $terminal = [OutgoingMessageStatus::SENT->name, OutgoingMessageStatus::FAILED_CONFIRMED->name, OutgoingMessageStatus::SUPPRESSED->name, OutgoingMessageStatus::CANCELLED->name];
        if ($statuses !== [] && array_diff($statuses, $terminal) === []) {
            EventReminderOccurrence::query()->whereKey($occurrenceId)->update(['status' => EventReminderOccurrenceStatus::COMPLETED->value, 'completed_at' => now()]);
            Message::query()->whereKey($messageId)->update(['status' => MessageStatus::SENT->name, 'sent_at' => now()]);
        }
    }

    private function digestMatches(OutgoingMessage $claim, $context): bool
    {
        $html = (new EventReminder($context))->render();
        $text = view('emails.event.reminder-text', ['context' => $context->payload(), 'theme' => $context->theme])->render();
        return hash_equals((string) $claim->payload_digest, hash('sha256', $html . "\n" . $text));
    }

    private function isStillDispatchable(?EventReminderOccurrence $occurrence, ?Event $event): bool
    {
        $policy = config('event-reminders');
        if ($occurrence === null || $event === null || ($policy['enabled'] ?? false) !== true || !in_array($event->id, $policy['event_allowlist'] ?? [], true)
            || $event->status !== EventStatus::LIVE->name || $event->trashed() || $event->start_date === null || $event->timezone === null
            || CarbonImmutable::instance($event->start_date)->utc()->lte(now())) return false;
        $offset = $policy['offsets'][$occurrence->offset_key] ?? null;
        return is_int($offset) && CarbonImmutable::instance($event->start_date)->utc()->addMinutes($offset)->equalTo($occurrence->due_at_utc);
    }

    private function returnToPlanned(EventReminderOccurrence $occurrence, string $reason): void
    {
        EventReminderOccurrence::query()->whereKey($occurrence->id)->where('status', EventReminderOccurrenceStatus::CLAIMING->value)
            ->update(['status' => EventReminderOccurrenceStatus::PLANNED->value, 'reason_code' => $reason]);
    }

    private function failOccurrence(EventReminderOccurrence $occurrence, string $reason): void
    {
        EventReminderOccurrence::query()->whereKey($occurrence->id)->whereIn('status', [EventReminderOccurrenceStatus::CLAIMING->value, EventReminderOccurrenceStatus::DISPATCHING->value])
            ->update(['status' => EventReminderOccurrenceStatus::FAILED->value, 'reason_code' => $reason]);
        if ($occurrence->message_id !== null) {
            Message::query()->whereKey($occurrence->message_id)->update(['status' => MessageStatus::FAILED->name]);
        }
    }
}
