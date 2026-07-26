<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum FinancialAppendClassification: string
{
    case NEW_SCOPE = 'new_scope';
    case NEW_MAPPING = 'new_mapping';
    case REVISION_GAP = 'revision_gap';
    case NEW_SNAPSHOT = 'new_snapshot';
    case NEW_REVISION = 'new_revision';
    case RECEIPT_ONLY = 'receipt_only';
    case UNCHANGED_REPLAY = 'unchanged_replay';
    case CONTENT_CONFLICT = 'content_conflict';
    case STALE_SNAPSHOT = 'stale_snapshot';
}
