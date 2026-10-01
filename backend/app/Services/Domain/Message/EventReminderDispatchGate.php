<?php

namespace HiEvents\Services\Domain\Message;

class EventReminderDispatchGate
{
    public function mayClaimRecipients(): bool
    {
        $policy = config('event-reminders');

        return ($policy['enabled'] ?? false) === true
            && filled($policy['reply_to'] ?? null)
            && filled($policy['physical_address'] ?? null)
            && filled($policy['preference_url'] ?? null);
    }
}
