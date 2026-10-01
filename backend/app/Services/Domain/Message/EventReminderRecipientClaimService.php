<?php

namespace HiEvents\Services\Domain\Message;

use HiEvents\DomainObjects\Status\OutgoingMessageStatus;
use HiEvents\Models\OutgoingMessage;

class EventReminderRecipientClaimService
{
    public function normalize(string $email): ?string
    {
        $normalized = strtolower(trim($email));

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) ? $normalized : null;
    }

    /**
     * The unique database constraint is the concurrency boundary. A duplicate claim returns
     * the existing row and never schedules another provider submission.
     */
    public function claim(int $messageId, int $eventId, ?int $attendeeId, string $email, string $subject, string $payloadDigest): ?OutgoingMessage
    {
        $normalized = $this->normalize($email);
        if ($normalized === null) {
            return null;
        }

        OutgoingMessage::query()->insertOrIgnore([
            'message_id' => $messageId,
            'event_id' => $eventId,
            'attendee_id' => $attendeeId,
            'recipient' => $email,
            'recipient_normalized' => $normalized,
            'subject' => $subject,
            'payload_digest' => $payloadDigest,
            'status' => OutgoingMessageStatus::CLAIMED->name,
            'claimed_at' => now(),
            'attempt_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return OutgoingMessage::query()
            ->where('message_id', $messageId)
            ->where('recipient_normalized', $normalized)
            ->first();
    }

    public function markSubmitting(OutgoingMessage $claim): bool
    {
        return OutgoingMessage::query()
            ->whereKey($claim->id)
            ->where('status', OutgoingMessageStatus::CLAIMED->name)
            ->update([
                'status' => OutgoingMessageStatus::SUBMITTING->name,
                'submitted_at' => now(),
                'attempt_count' => $claim->attempt_count + 1,
                'updated_at' => now(),
            ]) === 1;
    }

    public function markUnknownAfterUncertainHandoff(OutgoingMessage $claim, string $errorClass): void
    {
        OutgoingMessage::query()->whereKey($claim->id)->update([
            'status' => OutgoingMessageStatus::UNKNOWN->name,
            'last_error_class' => $errorClass,
            'updated_at' => now(),
        ]);
    }
}
