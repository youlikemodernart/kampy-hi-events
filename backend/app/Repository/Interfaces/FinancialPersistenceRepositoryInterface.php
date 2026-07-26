<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\Services\Domain\Financial\DTOs\FinancialAppendResultDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPersistedSnapshotDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialScopeDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotBatchDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotQueryDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSourceMappingRevisionDTO;

interface FinancialPersistenceRepositoryInterface
{
    public function appendScope(FinancialScopeDTO $scope): FinancialAppendResultDTO;

    public function appendMappingRevision(
        FinancialSourceMappingRevisionDTO $mapping,
    ): FinancialAppendResultDTO;

    public function appendSnapshotBatch(FinancialSnapshotBatchDTO $batch): FinancialAppendResultDTO;

    public function getLatestPromotable(
        FinancialSnapshotQueryDTO $query,
    ): ?FinancialPersistedSnapshotDTO;

    public function getLatestSourceControlled(
        FinancialSnapshotQueryDTO $query,
    ): ?FinancialPersistedSnapshotDTO;

    public function getSnapshotById(
        string $snapshotId,
        FinancialSnapshotQueryDTO $query,
    ): ?FinancialPersistedSnapshotDTO;
}
