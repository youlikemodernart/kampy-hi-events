<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum FinancialSnapshotKind: string
{
    case PLANNED_POSITION = 'planned_position';
    case SPARK_TICKET = 'spark_ticket';
    case STRIPE_SETTLEMENT = 'stripe_settlement';
    case DONORBOX = 'donorbox';
}
