<?php

namespace HiEvents\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\TaxAndFeeRepositoryInterface;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Domain\Account\AccountUserEventAssignmentService;
use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Application;

readonly class IsAuthorizedService
{
    public function __construct(
        private Application                         $app,
        private AccountUserRepositoryInterface      $accountUserRepository,
        private AuthManager                         $auth,
        private AccountUserEventAssignmentService   $accountUserEventAssignmentService,
    )
    {
    }

    public function validateUserRole(Role $minimumRole, UserDomainObject $authUser): void
    {
        $this->validateUserStatus($authUser);

        $role = $this->getCurrentRole($authUser);

        if ($minimumRole === Role::SUPERADMIN && $role !== Role::SUPERADMIN) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }

        if ($minimumRole === Role::ADMIN
            && in_array($role, [Role::SUPERADMIN, Role::ADMIN], true) === false
        ) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }

        if ($minimumRole === Role::ORGANIZER && $role === null) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }
    }

    public function validateUserPermission(Permission $permission, UserDomainObject $authUser): void
    {
        $this->validateUserStatus($authUser);

        $role = $this->getCurrentRole($authUser);

        if ($role === null || !$role->hasPermission($permission)) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }
    }

    public function validateAccountWideEventManagement(UserDomainObject $authUser): void
    {
        $this->validateAccountWidePermission(Permission::EVENT_MANAGE, $authUser);
    }

    public function validateAccountWidePermission(Permission $permission, UserDomainObject $authUser): void
    {
        $this->validateUserPermission($permission, $authUser);

        $role = $this->getCurrentRole($authUser);

        if ($role === null || $role->requiresEventAssignments()) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }
    }

    public function isActionAuthorized(
        int              $entityId,
        string           $entityType,
        UserDomainObject $authUser,
        int              $authAccountId,
        Role             $minimumRole
    ): void
    {
        $this->validateUserRole($minimumRole, $authUser);

        $repository = match ($entityType) {
            EventDomainObject::class => $this->app->make(EventRepositoryInterface::class),
            AccountDomainObject::class => $this->app->make(AccountRepositoryInterface::class),
            UserDomainObject::class => $this->app->make(UserRepositoryInterface::class),
            TaxAndFeesDomainObject::class => $this->app->make(TaxAndFeeRepositoryInterface::class),
            OrganizerDomainObject::class => $this->app->make(OrganizerRepositoryInterface::class),
            ImageDomainObject::class => $this->app->make(ImageRepositoryInterface::class),
        };

        $entity = $repository->findById($entityId);

        $result = match ($entityType) {
            EventDomainObject::class => $this->canAccessEvent($entity, $authUser, $authAccountId),
            ImageDomainObject::class,
            OrganizerDomainObject::class => $this->validateAccountWideEntity($entity, $authUser, $authAccountId),
            AccountDomainObject::class => $entity?->getId() === $authAccountId,
            UserDomainObject::class => $this->validateUserUpdate($entity, $authAccountId),
            TaxAndFeesDomainObject::class => $this->validateTax($entity, $authAccountId),
        };

        if (!$result) {
            throw new UnauthorizedException();
        }
    }

    public function validateEventAccess(
        EventDomainObject $event,
        UserDomainObject $authUser,
        int $authAccountId,
    ): void {
        $this->validateUserRole(Role::ORGANIZER, $authUser);

        if (!$this->canAccessEvent($event, $authUser, $authAccountId)) {
            throw new UnauthorizedException();
        }
    }

    private function canAccessEvent(?EventDomainObject $event, UserDomainObject $authUser, int $authAccountId): bool
    {
        if ($event === null || $event->getAccountId() !== $authAccountId) {
            return false;
        }

        $role = $this->getCurrentRole($authUser);
        if ($role === null) {
            return false;
        }

        if ($role->hasAccountWideEventAccess()) {
            return true;
        }

        if (!$role->requiresEventAssignments()) {
            return false;
        }

        $accountUser = $authUser->getCurrentAccountUser();
        if ($accountUser === null) {
            return false;
        }

        return $this->accountUserEventAssignmentService->isAssignedToEvent($accountUser, $event->getId());
    }

    private function validateAccountWideEntity(mixed $entity, UserDomainObject $authUser, int $authAccountId): bool
    {
        if ($entity === null || $entity->getAccountId() !== $authAccountId) {
            return false;
        }

        $role = $this->getCurrentRole($authUser);

        return $role !== null && !$role->requiresEventAssignments();
    }

    private function validateUserUpdate(?UserDomainObject $user, int $authAccountId): bool
    {
        if ($user === null) {
            return false;
        }

        $accountUser = $this->accountUserRepository->findFirstWhere([
            'account_id' => $authAccountId,
            'user_id' => $user->getId(),
        ]);

        return $accountUser !== null;
    }

    private function validateTax(?TaxAndFeesDomainObject $taxOrFee, int $authAccountId): bool
    {
        if ($taxOrFee === null) {
            return false;
        }

        if ($taxOrFee->getAccountId() === $authAccountId) {
            return true;
        }

        return false;
    }

    private function getCurrentRole(UserDomainObject $authUser): ?Role
    {
        $role = $authUser->getCurrentAccountUser()?->getRole();

        if ($role === null) {
            return null;
        }

        return Role::tryFrom($role);
    }

    public function validateUserStatus(UserDomainObject $authUser): void
    {
        if ($authUser->getCurrentAccountUser()?->getStatus() !== UserStatus::ACTIVE->name) {
            // Log the user out if their account is not active. This can happen if a user is
            // deactivated while they are logged in.
            $this->auth->logout();
            throw new UnauthorizedException(__('Your account is not active.'));
        }
    }
}
