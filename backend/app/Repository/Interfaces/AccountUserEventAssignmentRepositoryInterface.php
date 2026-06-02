<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\AccountUserEventAssignmentDomainObject;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<AccountUserEventAssignmentDomainObject>
 */
interface AccountUserEventAssignmentRepositoryInterface extends RepositoryInterface
{
    /**
     * @return array<int>
     */
    public function getAssignedEventIdsForAccountUser(int $accountUserId): array;

    public function isAssignedToEvent(int $accountUserId, int $eventId): bool;

    /**
     * @return Collection<int, AccountUserEventAssignmentDomainObject>
     */
    public function findForAccountUser(int $accountUserId): Collection;
}
