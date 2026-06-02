<?php

namespace HiEvents\Services\Domain\Account;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\CannotUpdateResourceException;
use HiEvents\Repository\Interfaces\AccountUserEventAssignmentRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use Illuminate\Support\Collection;

class AccountUserEventAssignmentService
{
    public function __construct(
        private readonly AccountUserEventAssignmentRepositoryInterface $assignmentRepository,
        private readonly EventRepositoryInterface                      $eventRepository,
    )
    {
    }

    /**
     * @param array<int|string> $eventIds
     * @return Collection<int, \HiEvents\DomainObjects\AccountUserEventAssignmentDomainObject>
     * @throws CannotUpdateResourceException
     */
    public function replaceAssignmentsForRole(
        AccountUserDomainObject $accountUser,
        Role                    $role,
        array                   $eventIds,
        int                     $accountId,
        ?int                    $createdByUserId = null,
    ): Collection
    {
        $eventIds = $this->normalizeEventIds($eventIds);

        if (!$role->allowsEventAssignments() && $eventIds !== []) {
            throw new CannotUpdateResourceException(__(':role users cannot be assigned to individual events.', [
                'role' => $role->getDisplayName(),
            ]));
        }

        if ($role->requiresEventAssignments() && $eventIds === []) {
            throw new CannotUpdateResourceException(__(':role users must be assigned to at least one event.', [
                'role' => $role->getDisplayName(),
            ]));
        }

        if ($role->allowsEventAssignments()) {
            $this->assertEventsBelongToAccount($eventIds, $accountId);
        }

        $this->assignmentRepository->deleteWhere([
            'account_user_id' => $accountUser->getId(),
        ]);

        if (!$role->allowsEventAssignments()) {
            return collect();
        }

        foreach ($eventIds as $eventId) {
            $this->assignmentRepository->create([
                'account_user_id' => $accountUser->getId(),
                'event_id' => $eventId,
                'created_by_user_id' => $createdByUserId,
            ]);
        }

        return $this->assignmentRepository->findForAccountUser($accountUser->getId());
    }

    /**
     * @return array<int>
     */
    public function getAssignedEventIds(AccountUserDomainObject $accountUser): array
    {
        if ($accountUser->getEventAssignments() !== null) {
            return $accountUser->getEventAssignments()
                ->map(static fn($assignment) => $assignment->getEventId())
                ->unique()
                ->values()
                ->all();
        }

        return $this->assignmentRepository->getAssignedEventIdsForAccountUser($accountUser->getId());
    }

    public function isAssignedToEvent(AccountUserDomainObject $accountUser, int $eventId): bool
    {
        return $this->assignmentRepository->isAssignedToEvent($accountUser->getId(), $eventId);
    }

    /**
     * @param array<int|string> $eventIds
     * @return array<int>
     */
    private function normalizeEventIds(array $eventIds): array
    {
        $normalized = [];

        foreach ($eventIds as $eventId) {
            if ($eventId === null || $eventId === '') {
                continue;
            }

            $normalized[] = (int)$eventId;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * @param array<int> $eventIds
     * @throws CannotUpdateResourceException
     */
    private function assertEventsBelongToAccount(array $eventIds, int $accountId): void
    {
        if ($eventIds === []) {
            return;
        }

        $events = $this->eventRepository->findWhereIn(
            field: 'id',
            values: $eventIds,
            additionalWhere: ['account_id' => $accountId],
            columns: ['id'],
        );

        $foundEventIds = $events
            ->map(static fn(EventDomainObject $event) => $event->getId())
            ->unique()
            ->values()
            ->all();

        if (count($foundEventIds) !== count($eventIds)) {
            throw new CannotUpdateResourceException(__('One or more assigned events do not belong to this account.'));
        }
    }
}
