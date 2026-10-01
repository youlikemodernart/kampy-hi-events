<?php

namespace HiEvents\DomainObjects\Status;

enum EventReminderOccurrenceStatus: string
{
    case PLANNED = 'PLANNED';
    case CLAIMING = 'CLAIMING';
    case DISPATCHING = 'DISPATCHING';
    case COMPLETED = 'COMPLETED';
    case CANCELLED = 'CANCELLED';
    case SKIPPED_LATE = 'SKIPPED_LATE';
    case FAILED = 'FAILED';
    case UNKNOWN = 'UNKNOWN';
}
