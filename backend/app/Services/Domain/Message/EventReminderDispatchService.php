<?php

namespace HiEvents\Services\Domain\Message;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\EventReminderOccurrenceStatus;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\Status\MessageStatus;
use HiEvents\DomainObjects\Status\OutgoingMessageStatus;
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
        if (!$this->isStillDispatchable($occurrence, $event)) {
            $this->returnOrCancel($occurrence, 'event_or_policy_changed');
            return;
        }
        if ($this->messagingEligibility->checkEligibility($event->account_id, $event->id) !== null) {
            $this->returnOrCancel($occurrence, 'messaging_ineligible');
            return;
        }
        $context = $this->contexts->build($event);
        if ($context === null) {
            $this->returnOrCancel($occurrence, 'missing_required_binding');
            return;
        }

        $mail = new EventReminder($context);
        $html = $mail->render();
        $text = view('emails.event.reminder-text', ['context' => $context->payload(), 'theme' => $context->theme])->render();
        $digest = hash('sha256', $html . "\n" . $text);
        $message = DB::transaction(function () use ($occurrence, $event, $context, $html, $digest): Message {
            $fresh = EventReminderOccurrence::query()->lockForUpdate()->findOrFail($occurrence->id);
            if ($fresh->status !== EventReminderOccurrenceStatus::CLAIMING->value) {
                throw new \LogicException('Reminder occurrence claim was lost');
            }
            $message = Message::query()->firstOrCreate(['source_key' => 'event-reminder:' . $fresh->id], [
                'event_id' => $event->id,
                'subject' => __('Reminder: :event is coming up', ['event' => $context->eventTitle]),
                'message' => $html,
                'type' => MessageTypeEnum::ALL_ATTENDEES->name,
                'status' => MessageStatus::PROCESSING->name,
                'source' => 'EVENT_REMINDER',
                'sent_by_user_id' => null,
                'send_data' => ['payload_digest' => $digest, 'text' => $text],
            ]);
            $fresh->update(['message_id' => $message->id, 'payload_digest' => $digest]);
            return $message;
        });

        Attendee::query()->where('event_id', $event->id)->where('status', AttendeeStatus::ACTIVE->name)->each(
            function (Attendee $attendee) use ($message, $event, $context, $digest, $occurrence): void {
                $claim = $this->recipients->claim($message->id, $event->id, $attendee->id, $attendee->email, $message->subject, $digest);
                if ($claim !== null) {
                    $this->handoff($claim, $occurrence->id, $context);
                }
            }
        );
        $this->aggregate($occurrence->id, $message->id);
    }

    private function handoff(OutgoingMessage $claim, int $occurrenceId, $context): void
    {
        $active = Attendee::query()->whereKey($claim->attendee_id)->where('status', AttendeeStatus::ACTIVE->name)->exists();
        $occurrence = EventReminderOccurrence::query()->find($occurrenceId);
        if (!$active || !$this->isStillDispatchable($occurrence, Event::query()->find($claim->event_id))) {
            $claim->update(['status' => OutgoingMessageStatus::SUPPRESSED->name]);
            return;
        }
        if (!$this->recipients->markSubmitting($claim)) {
            return;
        }
        try {
            $this->mailer->to($claim->recipient)->send(new EventReminder($context));
            $claim->update(['status' => OutgoingMessageStatus::SENT->name, 'provider_accepted_at' => now()]);
        } catch (\Throwable $exception) {
            $this->recipients->markUnknownAfterUncertainHandoff($claim, $exception::class);
        }
    }

    private function isStillDispatchable(?EventReminderOccurrence $occurrence, ?Event $event): bool
    {
        $policy = config('event-reminders');
        if ($occurrence === null || $event === null || ($policy['enabled'] ?? false) !== true
            || !in_array($event->id, $policy['event_allowlist'] ?? [], true)
            || $event->status !== EventStatus::LIVE->name || $event->trashed() || $event->start_date === null || $event->timezone === null
            || CarbonImmutable::instance($event->start_date)->utc()->lte(now())) {
            return false;
        }
        $offset = $policy['offsets'][$occurrence->offset_key] ?? null;
        if (!is_int($offset)) {
            return false;
        }
        $due = CarbonImmutable::instance($event->start_date)->utc()->addMinutes($offset);
        return $due->equalTo($occurrence->due_at_utc) && $this->contexts->build($event) !== null;
    }

    private function returnOrCancel(EventReminderOccurrence $occurrence, string $reason): void
    {
        EventReminderOccurrence::query()->whereKey($occurrence->id)->where('status', EventReminderOccurrenceStatus::CLAIMING->value)
            ->update(['status' => EventReminderOccurrenceStatus::CANCELLED->value, 'reason_code' => $reason]);
    }

    private function aggregate(int $occurrenceId, int $messageId): void
    {
        $statuses = OutgoingMessage::query()->where('message_id', $messageId)->pluck('status')->all();
        if (in_array(OutgoingMessageStatus::UNKNOWN->name, $statuses, true)) {
            EventReminderOccurrence::query()->whereKey($occurrenceId)->update(['status' => EventReminderOccurrenceStatus::UNKNOWN->value]);
            Message::query()->whereKey($messageId)->update(['status' => MessageStatus::UNKNOWN->name]);
            return;
        }
        $terminal = [OutgoingMessageStatus::SENT->name, OutgoingMessageStatus::FAILED_CONFIRMED->name, OutgoingMessageStatus::SUPPRESSED->name, OutgoingMessageStatus::CANCELLED->name];
        if (array_diff($statuses, $terminal) === []) {
            EventReminderOccurrence::query()->whereKey($occurrenceId)->update(['status' => EventReminderOccurrenceStatus::COMPLETED->value, 'completed_at' => now()]);
            Message::query()->whereKey($messageId)->update(['status' => MessageStatus::SENT->name, 'sent_at' => now()]);
        }
    }
}
