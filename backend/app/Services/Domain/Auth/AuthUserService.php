<?php

namespace HiEvents\Services\Domain\Auth;

use Exception;
use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\Interfaces\DomainObjectInterface;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Auth\AuthManager;
use Illuminate\Support\Collection;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use PHPOpenSourceSaver\JWTAuth\Payload;

readonly class AuthUserService
{
    public function __construct(
        /**
         * @var AuthManager
         */
        private AuthManager                    $authManager,
        private AccountUserRepositoryInterface $accountUserRepository,
    )
    {
    }

    public function getAuthenticatedAccountId(): ?int
    {
        if (!$this->authManager->check()) {
            return null;
        }

        try {
            /** @var Payload $payload */
            $payload = $this->authManager->payload();
        } catch (JWTException) {
            return null;
        }

        return $payload->get('account_id');
    }

    public function getAuthenticatedUserRole(): ?Role
    {
        $user = $this->getUser();

        $role = $user?->getCurrentAccountUser()?->getRole();

        if ($role === null) {
            return null;
        }

        try {
            return Role::from($role);
        } catch (Exception) {
            return null;
        }
    }

    public function getUser(): UserDomainObject|DomainObjectInterface|null
    {
        /** @var User $user */
        if ($user = $this->authManager->user()) {
            $user = UserDomainObject::hydrateFromModel($user);

            if ($accountId = $this->getAuthenticatedAccountId()) {
                $user->setCurrentAccountUser($this->getCurrentAccountUser($user, $accountId));
            }

            return $user;
        }

        return null;
    }

    private function getCurrentAccountUser(UserDomainObject $user, int $accountId): ?AccountUserDomainObject
    {
        $accountUsers = $this->accountUserRepository->findWhere([
            'user_id' => $user->getId(),
            'account_id' => $accountId,
        ]);

        return $this->selectCurrentAccountUser($accountUsers);
    }

    /**
     * @param Collection<int, AccountUserDomainObject> $accountUsers
     */
    private function selectCurrentAccountUser(Collection $accountUsers): ?AccountUserDomainObject
    {
        $activeAccountUsers = $accountUsers->filter(
            fn(AccountUserDomainObject $accountUser): bool => $accountUser->getStatus() === UserStatus::ACTIVE->name,
        );

        if ($activeAccountUsers->count() !== 1) {
            return null;
        }

        return $activeAccountUsers->first();
    }
}
