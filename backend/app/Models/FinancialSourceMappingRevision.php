<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FinancialSourceMappingRevision extends BaseModel
{
    public function financialScope(): BelongsTo
    {
        return $this->belongsTo(FinancialScope::class);
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'supersedes_mapping_revision_id',
            'mapping_revision_id',
        );
    }

    public function snapshotRecords(): HasMany
    {
        return $this->hasMany(
            FinancialSnapshotRecord::class,
            'mapping_revision_id',
            'mapping_revision_id',
        );
    }

    protected function getCastMap(): array
    {
        return [
            'financial_scope_id' => 'integer',
            'revision_number' => 'integer',
            'effective_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'mapping_revision_id',
            'mapping_key',
            'financial_scope_id',
            'revision_number',
            'source_system',
            'source_namespace',
            'source_object_kind',
            'source_object_id',
            'disposition',
            'supersedes_mapping_revision_id',
            'content_fingerprint',
            'effective_at',
            'recorded_at',
        ];
    }

    protected function getTimestampsEnabled(): bool
    {
        return false;
    }
}
