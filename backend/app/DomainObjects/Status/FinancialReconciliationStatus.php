<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

enum FinancialReconciliationStatus: string
{
    case PASS = 'pass';
    case FAIL = 'fail';
    case REVIEW_REQUIRED = 'review_required';
}
