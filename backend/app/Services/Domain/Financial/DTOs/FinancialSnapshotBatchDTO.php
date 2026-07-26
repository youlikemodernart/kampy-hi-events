<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use HiEvents\DataTransferObjects\BaseDataObject;

class FinancialSnapshotBatchDTO extends BaseDataObject
{
    /** @param list<FinancialSnapshotRecordDTO> $records */
    public function __construct(
        public readonly FinancialSnapshotDTO $snapshot,
        public readonly array $records,
        public readonly ?FinancialPlanRevisionDTO $planRevision,
        public readonly FinancialReconciliationReceiptDTO $receipt,
    ) {}
}
