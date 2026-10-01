<?php

namespace HiEvents\Services\Domain\Message;

use HiEvents\Models\EventReminderOccurrence;
use HiEvents\Models\OutgoingMessage;

class EventReminderReadbackService
{
    public function aggregateForOccurrence(int $occurrenceId): ?array
    {
        $occurrence = EventReminderOccurrence::query()->find($occurrenceId);
        if ($occurrence === null) {
            return null;
        }
        $counts = $occurrence->message_id === null ? [] : OutgoingMessage::query()
            ->where('message_id', $occurrence->message_id)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')->pluck('count', 'status')->all();

        return [
            'occurrence_id' => $occurrence->id,
            'event_id' => $occurrence->event_id,
            'policy_version' => $occurrence->policy_version,
            'offset_key' => $occurrence->offset_key,
            'due_at_utc' => $occurrence->due_at_utc,
            'status' => $occurrence->status,
            'reason_code' => $occurrence->reason_code,
            'payload_digest' => $occurrence->payload_digest,
            'recipient_outcomes' => $counts,
        ];
    }
}
