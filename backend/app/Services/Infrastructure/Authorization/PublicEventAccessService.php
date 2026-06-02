<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Services\Domain\Auth\AuthUserService;
use Illuminate\Auth\AuthManager;

class PublicEventAccessService
{
    public function __construct(
        private AuthManager $auth,
        private AuthUserService $authUserService,
        private IsAuthorizedService $authorizationService,
    )
    {
    }

    public function canAccessEvent(EventDomainObject $event, Permission $permission): bool
    {
        return $this->canAccessEntity(
            entityId: $event->getId(),
            entityType: EventDomainObject::class,
            accountId: $event->getAccountId(),
            permission: $permission,
        );
    }

    public function canAccessOrganizer(OrganizerDomainObject $organizer, Permission $permission): bool
    {
        return $this->canAccessEntity(
            entityId: $organizer->getId(),
            entityType: OrganizerDomainObject::class,
            accountId: $organizer->getAccountId(),
            permission: $permission,
        );
    }

    public function canAccessAccount(int $accountId, Permission $permission): bool
    {
        if (!$this->auth->check()) {
            return false;
        }

        try {
            $authUser = $this->authUserService->getUser();

            if ($authUser === null) {
                return false;
            }

            $this->authorizationService->validateUserPermission($permission, $authUser);

            $role = Role::tryFrom($authUser->getCurrentAccountUser()?->getRole() ?? '');

            if ($role === Role::SUPERADMIN) {
                return true;
            }

            return $this->authUserService->getAuthenticatedAccountId() === $accountId;
        } catch (UnauthorizedException) {
            return false;
        }
    }

    private function canAccessEntity(int $entityId, string $entityType, int $accountId, Permission $permission): bool
    {
        if (!$this->auth->check()) {
            return false;
        }

        try {
            $authUser = $this->authUserService->getUser();

            if ($authUser === null) {
                return false;
            }

            $this->authorizationService->validateUserPermission($permission, $authUser);

            $role = Role::tryFrom($authUser->getCurrentAccountUser()?->getRole() ?? '');

            if ($role === Role::SUPERADMIN) {
                return true;
            }

            $authenticatedAccountId = $this->authUserService->getAuthenticatedAccountId();
            if ($authenticatedAccountId !== $accountId) {
                return false;
            }

            $this->authorizationService->isActionAuthorized(
                entityId: $entityId,
                entityType: $entityType,
                authUser: $authUser,
                authAccountId: $authenticatedAccountId,
                minimumRole: Role::ORGANIZER,
            );

            return true;
        } catch (UnauthorizedException) {
            return false;
        }
    }
}
