<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialScope extends BaseModel
{
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(Organizer::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(FinancialSourceMappingRevision::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(FinancialSnapshot::class);
    }

    protected function getCastMap(): array
    {
        return [
            'account_id' => 'integer',
            'organizer_id' => 'integer',
            'event_id' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'scope_key',
            'account_id',
            'organizer_id',
            'event_id',
            'university_id',
            'cycle_id',
            'timezone',
            'currency',
            'recorded_at',
        ];
    }

    protected function getTimestampsEnabled(): bool
    {
        return false;
    }
}
