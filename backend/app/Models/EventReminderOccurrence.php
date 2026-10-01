<?php

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventReminderOccurrence extends BaseModel
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    protected function getCastMap(): array
    {
        return [
            'due_at_utc' => 'datetime',
            'source_event_start_at_utc' => 'datetime',
            'claimed_at' => 'datetime',
            'audience_claimed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
