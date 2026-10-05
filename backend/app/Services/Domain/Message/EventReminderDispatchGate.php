<?php

namespace HiEvents\Services\Domain\Message;

use Carbon\CarbonImmutable;

class EventReminderDispatchGate
{
    public function mayClaimRecipients(): bool
    {
        $policy = config('event-reminders');

        return ($policy['enabled'] ?? false) === true
            && filled($policy['reply_to'] ?? null);
    }

    public function isWithinLateGrace(\DateTimeInterface $dueAt, \DateTimeInterface $now): bool
    {
        $graceMinutes = max(0, (int) config('event-reminders.late_grace_minutes', 0));
        $lastEligibleAt = CarbonImmutable::instance($dueAt)->addMinutes($graceMinutes);

        return ! CarbonImmutable::instance($now)->greaterThan($lastEligibleAt);
    }
}
