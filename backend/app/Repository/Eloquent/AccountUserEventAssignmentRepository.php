<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\AccountUserEventAssignmentDomainObject;
use HiEvents\Models\AccountUserEventAssignment;
use HiEvents\Repository\Interfaces\AccountUserEventAssignmentRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends BaseRepository<AccountUserEventAssignmentDomainObject>
 */
class AccountUserEventAssignmentRepository extends BaseRepository implements AccountUserEventAssignmentRepositoryInterface
{
    protected function getModel(): string
    {
        return AccountUserEventAssignment::class;
    }

    public function getDomainObject(): string
    {
        return AccountUserEventAssignmentDomainObject::class;
    }

    public function getAssignedEventIdsForAccountUser(int $accountUserId): array
    {
        return $this->model
            ->newQuery()
            ->where('account_user_id', $accountUserId)
            ->pluck('event_id')
            ->map(static fn($eventId) => (int)$eventId)
            ->all();
    }

    public function isAssignedToEvent(int $accountUserId, int $eventId): bool
    {
        return $this->model
            ->newQuery()
            ->where('account_user_id', $accountUserId)
            ->where('event_id', $eventId)
            ->exists();
    }

    public function findForAccountUser(int $accountUserId): Collection
    {
        return $this->findWhere([
            'account_user_id' => $accountUserId,
        ]);
    }
}
