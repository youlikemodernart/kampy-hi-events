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
        if ((config('event-reminders.enabled') ?? false) !== true) return;

        $now = CarbonImmutable::now('UTC');
        EventReminderOccurrence::query()->where('status', EventReminderOccurrenceStatus::PLANNED->value)
            ->where('due_at_utc', '<=', $now)->each(function (EventReminderOccurrence $occurrence) use ($now): void {
                if ($this->occurrences->claimDue($occurrence->id, $now)) $this->dispatchClaimed($occurrence->id);
            });
    }

    public function dispatchClaimed(int $occurrenceId): void
    {
        $occurrence = EventReminderOccurrence::query()->find($occurrenceId);
        if ($occurrence === null || $occurrence->status !== EventReminderOccurrenceStatus::CLAIMING->value) return;
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
        $message = DB::transaction(function () use ($occurrence, $event, $context, $html, $text, $digest): ?Message {
            $fresh = EventReminderOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);
            if ($fresh->status !== EventReminderOccurrenceStatus::CLAIMING->value) throw new \LogicException('Reminder occurrence claim was lost');
            $message = Message::query()->firstOrCreate(['source_key' => 'event-reminder:' . $fresh->id], [
                'event_id' => $event->id, 'subject' => __('Reminder: :event is coming up', ['event' => $context->eventTitle]),
                'message' => $html, 'type' => MessageTypeEnum::ALL_ATTENDEES->name,
                'status' => MessageStatus::PROCESSING->name, 'source' => 'EVENT_REMINDER',
                'sent_by_user_id' => null, 'send_data' => ['payload_digest' => $digest, 'text' => $text],
            ]);
            $storedDigest = $message->send_data['payload_digest'] ?? null;
            if (!is_string($storedDigest) || !hash_equals($storedDigest, $digest)) return null;
            $fresh->update(['message_id' => $message->id, 'payload_digest' => $digest]);
            return $message;
        });
        if ($message === null) {
            $this->failOccurrence(EventReminderOccurrence::query()->findOrFail($occurrenceId), 'payload_changed_during_audience_claim');
            return;
        }

        $occurrence = EventReminderOccurrence::query()->findOrFail($occurrenceId);
        if ($occurrence->audience_claimed_at === null) {
            $invalidRecipients = 0;
            Attendee::query()->where('event_id', $event->id)->where('status', AttendeeStatus::ACTIVE->name)->each(
                function (Attendee $attendee) use ($message, $event, $digest, &$invalidRecipients): void {
                    if ($this->recipients->claim($message->id, $event->id, $attendee->id, $attendee->email, $message->subject, $digest) === null) $invalidRecipients++;
                }
            );
            DB::transaction(function () use ($occurrenceId, $message, $invalidRecipients): void {
                $fresh = EventReminderOccurrence::query()->lockForUpdate()->findOrFail($occurrenceId);
                if ($fresh->status !== EventReminderOccurrenceStatus::CLAIMING->value || $fresh->audience_claimed_at !== null) return;
                $fresh->update([
                    'expected_recipient_count' => OutgoingMessage::query()->where('message_id', $message->id)->count(),
                    'invalid_recipient_count' => $invalidRecipients,
                    'audience_claimed_at' => now(),
                    'status' => EventReminderOccurrenceStatus::DISPATCHING->value,
                ]);
            });
        }

        $occurrence = EventReminderOccurrence::query()->findOrFail($occurrenceId);
        if ($occurrence->status !== EventReminderOccurrenceStatus::DISPATCHING->value) return;
        $recipientCount = OutgoingMessage::query()->where('message_id', $message->id)->count();
        if ($this->messagingEligibility->checkTierLimits($event->account_id, $recipientCount, $html) !== null) {
            OutgoingMessage::query()->where('message_id', $message->id)->where('status', OutgoingMessageStatus::CLAIMED->name)
                ->update(['status' => OutgoingMessageStatus::CANCELLED->name, 'last_error_class' => 'messaging_tier_limit']);
            $this->failOccurrence($occurrence, 'messaging_tier_limit');
            return;
        }
        OutgoingMessage::query()->where('message_id', $message->id)->where('status', OutgoingMessageStatus::CLAIMED->name)->each(function (OutgoingMessage $claim): void {
            try {
                SendEventReminderRecipientJob::dispatch($claim->id);
            } catch (\Throwable $exception) {
                $this->recipients->markFailedBeforeStart($claim, $exception::class);
            }
        });
        $this->aggregate($occurrenceId, $message->id);
    }

    public function sendRecipient(int $outgoingMessageId): void
    {
        $claim = OutgoingMessage::query()->find($outgoingMessageId);
        if ($claim === null || $claim->status !== OutgoingMessageStatus::CLAIMED->name) return;
        $occurrence = EventReminderOccurrence::query()->where('message_id', $claim->message_id)->first();
        $event = $occurrence === null ? null : Event::query()->with('event_settings')->find($claim->event_id);
        $context = $event === null ? null : $this->contexts->build($event);
        if (!$this->recipientMayReceive($claim, $occurrence, $event, $context)) {
            $claim->update(['status' => OutgoingMessageStatus::SUPPRESSED->name]);
            if ($occurrence !== null) $this->aggregate($occurrence->id, $claim->message_id);
            return;
        }
        if (!$this->recipients->markSubmitting($claim)) return;

        $claim = OutgoingMessage::query()->findOrFail($claim->id);
        $occurrence = EventReminderOccurrence::query()->where('message_id', $claim->message_id)->first();
        $event = $occurrence === null ? null : Event::query()->with('event_settings')->find($claim->event_id);
        $context = $event === null ? null : $this->contexts->build($event);
        if (!$this->recipientMayReceive($claim, $occurrence, $event, $context)) {
            $this->recipients->markSuppressedBeforeHandoff($claim, 'binding_changed_before_handoff');
            if ($occurrence !== null) $this->aggregate($occurrence->id, $claim->message_id);
            return;
        }
        try {
            $this->mailer->to($claim->recipient)->send(new EventReminder($context));
            $claim->update(['status' => OutgoingMessageStatus::SENT->name, 'provider_accepted_at' => now()]);
        } catch (\Throwable $exception) {
            $this->recipients->markUnknownAfterUncertainHandoff($claim, $exception::class);
        }
        $this->aggregate($occurrence->id, $claim->message_id);
    }

    public function aggregate(int $occurrenceId, int $messageId): void
    {
        $occurrence = EventReminderOccurrence::query()->find($occurrenceId);
        if ($occurrence === null || !in_array($occurrence->status, [EventReminderOccurrenceStatus::DISPATCHING->value, EventReminderOccurrenceStatus::UNKNOWN->value], true)
            || $occurrence->audience_claimed_at === null || $occurrence->expected_recipient_count === null) return;
        $statuses = OutgoingMessage::query()->where('message_id', $messageId)->pluck('status')->all();
        if (count($statuses) !== $occurrence->expected_recipient_count) return;
        if (in_array(OutgoingMessageStatus::UNKNOWN->name, $statuses, true)) {
            EventReminderOccurrence::query()->whereKey($occurrenceId)->update(['status' => EventReminderOccurrenceStatus::UNKNOWN->value]);
            Message::query()->whereKey($messageId)->update(['status' => MessageStatus::UNKNOWN->name]);
            return;
        }
        $terminal = [OutgoingMessageStatus::SENT->name, OutgoingMessageStatus::FAILED_CONFIRMED->name, OutgoingMessageStatus::SUPPRESSED->name, OutgoingMessageStatus::CANCELLED->name];
        if (array_diff($statuses, $terminal) === []) {
            $reason = $statuses === [] ? 'no_valid_recipients' : null;
            EventReminderOccurrence::query()->whereKey($occurrenceId)->update(['status' => EventReminderOccurrenceStatus::COMPLETED->value, 'completed_at' => now(), 'reason_code' => $reason]);
            Message::query()->whereKey($messageId)->update(['status' => MessageStatus::SENT->name, 'sent_at' => now()]);
        }
    }

    private function recipientMayReceive(OutgoingMessage $claim, ?EventReminderOccurrence $occurrence, ?Event $event, mixed $context): bool
    {
        if ($context === null || !$this->isStillDispatchable($occurrence, $event)
            || $occurrence->status !== EventReminderOccurrenceStatus::DISPATCHING->value
            || $occurrence->audience_claimed_at === null || $occurrence->expected_recipient_count === null) return false;
        if ($this->messagingEligibility->checkEligibility($event->account_id, $event->id) !== null) return false;
        $html = (new EventReminder($context))->render();
        if ($this->messagingEligibility->checkTierLimits($event->account_id, OutgoingMessage::query()->where('message_id', $claim->message_id)->count(), $html) !== null) return false;
        return Attendee::query()->where('event_id', $claim->event_id)->where('status', AttendeeStatus::ACTIVE->name)
            ->get()->contains(fn (Attendee $attendee): bool => $this->recipients->normalize($attendee->email) === $claim->recipient_normalized)
            && $this->digestMatches($claim, $context);
    }

    private function digestMatches(OutgoingMessage $claim, mixed $context): bool
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
        if ($occurrence->message_id !== null) Message::query()->whereKey($occurrence->message_id)->update(['status' => MessageStatus::FAILED->name]);
    }
}
