<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use DateTimeInterface;
use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\FinancialSourceObjectKind;
use HiEvents\DomainObjects\Enums\FinancialSourceSystem;
use HiEvents\DomainObjects\Status\FinancialMappingDisposition;

class FinancialSourceMappingRevisionDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $mappingRevisionId,
        public readonly string $mappingKey,
        public readonly int $financialScopeId,
        public readonly string $scopeKey,
        public readonly string $universityId,
        public readonly string $cycleId,
        public readonly int $revisionNumber,
        public readonly FinancialSourceSystem $sourceSystem,
        public readonly string $sourceNamespace,
        public readonly FinancialSourceObjectKind $sourceObjectKind,
        public readonly string $sourceObjectId,
        public readonly FinancialMappingDisposition $disposition,
        public readonly ?string $supersedesMappingRevisionId,
        public readonly string $contentFingerprint,
        public readonly DateTimeInterface $effectiveAt,
        public readonly DateTimeInterface $recordedAt,
    ) {}
}
