<?php

namespace HiEvents\Services\Domain\Message;

use HiEvents\DomainObjects\Status\EventReminderOccurrenceStatus;
use HiEvents\DomainObjects\Status\MessageStatus;
use HiEvents\DomainObjects\Status\OutgoingMessageStatus;
use HiEvents\Models\EventReminderOccurrence;
use HiEvents\Models\Message;
use HiEvents\Models\OutgoingMessage;
use Illuminate\Support\Facades\DB;

class EventReminderCancellationService
{
    public function cancelUnsent(int $occurrenceId, string $reason = 'operator_cancelled'): array
    {
        return DB::transaction(function () use ($occurrenceId, $reason): array {
            $occurrence = EventReminderOccurrence::query()->lockForUpdate()->find($occurrenceId);
            if ($occurrence === null) {
                return ['cancelled' => false, 'reason' => 'occurrence_not_found'];
            }
            if (! in_array($occurrence->status, [EventReminderOccurrenceStatus::PLANNED->value, EventReminderOccurrenceStatus::CLAIMING->value, EventReminderOccurrenceStatus::DISPATCHING->value], true)) {
                return ['cancelled' => false, 'reason' => 'occurrence_not_unsent'];
            }
            if ($occurrence->message_id !== null) {
                $claims = OutgoingMessage::query()->where('message_id', $occurrence->message_id)->lockForUpdate()->get();
                $unsafe = [OutgoingMessageStatus::SUBMITTING->name, OutgoingMessageStatus::SENT->name, OutgoingMessageStatus::UNKNOWN->name];
                if ($claims->contains(fn (OutgoingMessage $claim): bool => in_array($claim->status, $unsafe, true))) {
                    return ['cancelled' => false, 'reason' => 'provider_handoff_or_unknown'];
                }
                OutgoingMessage::query()->where('message_id', $occurrence->message_id)->where('status', OutgoingMessageStatus::CLAIMED->name)->update([
                    'status' => OutgoingMessageStatus::CANCELLED->name,
                    'last_error_class' => $reason,
                    'updated_at' => now(),
                ]);
            }
            $occurrence->update(['status' => EventReminderOccurrenceStatus::CANCELLED->value, 'reason_code' => $reason]);
            if ($occurrence->message_id !== null) {
                Message::query()->whereKey($occurrence->message_id)->update(['status' => MessageStatus::CANCELLED->name]);
            }

            return ['cancelled' => true, 'reason' => $reason];
        });
    }
}
