<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Financial;

use HiEvents\DomainObjects\Enums\FinancialSnapshotKind;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\FinancialPersistenceRepositoryInterface;
use HiEvents\Services\Application\Handlers\Financial\DTO\GetFinancialReportDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialReadModelDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotQueryDTO;
use HiEvents\Services\Domain\Financial\FinancialPersistencePlanner;
use HiEvents\Services\Domain\Financial\FinancialReadModelService;
use HiEvents\Services\Infrastructure\Authorization\FinanceReportAuthorizationService;
use HiEvents\Services\Infrastructure\Financial\FinancialReportSourceBindingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class GetFinancialReportHandler
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
        private readonly FinancialPersistenceRepositoryInterface $financialRepository,
        private readonly FinanceReportAuthorizationService $authorizationService,
        private readonly FinancialReadModelService $readModelService,
        private readonly FinancialReportSourceBindingService $sourceBindingService,
        private readonly FinancialPersistencePlanner $persistencePlanner,
    ) {}

    public function handle(
        GetFinancialReportDTO $data,
        UserDomainObject $authenticatedUser,
        int $currentAccountId,
    ): FinancialReadModelDTO {
        return $this->handleForSurface(
            $data,
            $authenticatedUser,
            $currentAccountId,
            'view',
        );
    }

    public function export(
        GetFinancialReportDTO $data,
        UserDomainObject $authenticatedUser,
        int $currentAccountId,
    ): FinancialReadModelDTO {
        if ($data->includeReconciliation) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }

        return $this->handleForSurface(
            $data,
            $authenticatedUser,
            $currentAccountId,
            'export',
        );
    }

    private function handleForSurface(
        GetFinancialReportDTO $data,
        UserDomainObject $authenticatedUser,
        int $currentAccountId,
        string $requestedSurface,
    ): FinancialReadModelDTO {
        try {
            $event = $this->eventRepository->findById($data->eventId);
        } catch (ModelNotFoundException) {
            throw new ResourceNotFoundException(__('Event not found'));
        }
        if (! $event instanceof EventDomainObject) {
            throw new ResourceNotFoundException(__('Event not found'));
        }

        $this->authorizationService->authorize(
            event: $event,
            authenticatedUser: $authenticatedUser,
            currentAccountId: $currentAccountId,
            requestedSurface: $requestedSurface,
            includeReconciliation: $data->includeReconciliation,
        );

        if ($event->getAccountId() !== $currentAccountId) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }

        $organizerId = $event->getOrganizerId();
        $sourceBinding = $this->sourceBindingService->resolve(
            $data->eventId,
            $data->universityId,
            $data->cycleId,
        );
        $scopeKey = $this->persistencePlanner->scopeKey(
            $currentAccountId,
            $organizerId,
            $data->eventId,
            $data->universityId,
            $data->cycleId,
        );

        $planPacket = $this->financialRepository->getLatestPromotable($this->query(
            $data,
            $currentAccountId,
            $organizerId,
            $scopeKey,
            FinancialSnapshotKind::PLANNED_POSITION,
            $sourceBinding->planSourceNamespace,
        ));
        if ($planPacket === null) {
            throw new ResourceNotFoundException(__('Latest promotable financial plan is unavailable'));
        }

        $ticketPacket = $this->financialRepository->getLatestPromotable($this->query(
            $data,
            $currentAccountId,
            $organizerId,
            $scopeKey,
            FinancialSnapshotKind::SPARK_TICKET,
            $sourceBinding->ticketSourceNamespace,
        ));
        $settlementPacket = $this->financialRepository->getLatestSourceControlled($this->query(
            $data,
            $currentAccountId,
            $organizerId,
            $scopeKey,
            FinancialSnapshotKind::STRIPE_SETTLEMENT,
            $sourceBinding->settlementSourceNamespace,
        ));
        $donationPacket = $this->financialRepository->getLatestSourceControlled($this->query(
            $data,
            $currentAccountId,
            $organizerId,
            $scopeKey,
            FinancialSnapshotKind::DONORBOX,
            $sourceBinding->donationSourceNamespace,
        ));

        return $this->readModelService->compose(
            scopeKey: $scopeKey,
            accountId: $currentAccountId,
            organizerId: $organizerId,
            eventId: $data->eventId,
            universityId: $data->universityId,
            cycleId: $data->cycleId,
            planPacket: $planPacket,
            ticketPacket: $ticketPacket,
            settlementPacket: $settlementPacket,
            donationPacket: $donationPacket,
            cutoffAt: $data->cutoffAt,
            generatedAt: $data->generatedAt,
            includeReconciliation: $data->includeReconciliation,
        );
    }

    private function query(
        GetFinancialReportDTO $data,
        int $currentAccountId,
        int $organizerId,
        string $scopeKey,
        FinancialSnapshotKind $snapshotKind,
        string $sourceNamespace,
    ): FinancialSnapshotQueryDTO {
        return new FinancialSnapshotQueryDTO(
            scopeKey: $scopeKey,
            accountId: $currentAccountId,
            organizerId: $organizerId,
            eventId: $data->eventId,
            universityId: $data->universityId,
            cycleId: $data->cycleId,
            snapshotKind: $snapshotKind,
            sourceNamespace: $sourceNamespace,
        );
    }
}
