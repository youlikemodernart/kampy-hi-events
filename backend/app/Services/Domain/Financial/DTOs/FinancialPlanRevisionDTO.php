<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use DateTimeInterface;
use HiEvents\DataTransferObjects\BaseDataObject;

class FinancialPlanRevisionDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $planRevisionId,
        public readonly string $snapshotId,
        public readonly string $mappingRevisionId,
        public readonly string $sourceIdentityKey,
        public readonly string $contentFingerprint,
        public readonly DateTimeInterface $asOfAt,
        public readonly string $pricingConvention,
        public readonly string $basisPointRounding,
        public readonly int $ticketCustomerPriceCents,
        public readonly int $ticketQuantity,
        public readonly int $perTicketCommissionCents,
        public readonly int $fundraisingGoalCents,
        public readonly int $universityAllocationBasisPoints,
        public readonly int $donorboxFeeBasisPoints,
        public readonly int $plannedTicketCustomerChargeCents,
        public readonly int $plannedCommissionCents,
        public readonly int $plannedTicketProceedsCents,
        public readonly int $plannedUniversityFundraisingAllocationCents,
        public readonly int $plannedDonorboxFeeCents,
        public readonly int $plannedGrossIncomeCents,
        public readonly int $plannedIncomeAfterDonorboxFeeCents,
    ) {}
}
