<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Services\Domain\Auth\AuthUserService;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use HiEvents\Services\Infrastructure\Authorization\PublicEventAccessService;
use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Collection;
use Mockery as m;
use PHPOpenSourceSaver\JWTAuth\Payload;
use Tests\TestCase;

class PublicEventAccessServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_unauthenticated_public_user_has_no_internal_access(): void
    {
        $auth = m::mock(AuthManager::class);
        $auth->shouldReceive('check')->once()->andReturnFalse();

        $service = $this->service(
            $auth,
            new AuthUserService($auth, m::mock(AccountUserRepositoryInterface::class)),
        );

        $this->assertFalse($service->canAccessAccount(123, Permission::EVENT_VIEW));
    }

    public function test_active_matching_account_user_with_permission_has_internal_access(): void
    {
        $service = $this->serviceForUser(
            role: Role::ORGANIZER,
            status: UserStatus::ACTIVE,
            authenticatedAccountId: 123,
        );

        $this->assertTrue($service->canAccessAccount(123, Permission::EVENT_VIEW));
    }

    public function test_active_user_from_different_account_is_not_treated_as_internal_access(): void
    {
        $service = $this->serviceForUser(
            role: Role::ORGANIZER,
            status: UserStatus::ACTIVE,
            authenticatedAccountId: 456,
        );

        $this->assertFalse($service->canAccessAccount(123, Permission::EVENT_VIEW));
    }

    public function test_superadmin_with_active_membership_can_access_other_accounts(): void
    {
        $service = $this->serviceForUser(
            role: Role::SUPERADMIN,
            status: UserStatus::ACTIVE,
            authenticatedAccountId: 456,
        );

        $this->assertTrue($service->canAccessAccount(123, Permission::EVENT_VIEW));
    }

    public function test_inactive_user_is_denied_even_when_account_matches(): void
    {
        $service = $this->serviceForUser(
            role: Role::ADMIN,
            status: UserStatus::INACTIVE,
            authenticatedAccountId: 123,
            logoutCount: 1,
        );

        $this->assertFalse($service->canAccessAccount(123, Permission::EVENT_VIEW));
    }

    public function test_check_in_user_cannot_use_public_draft_checkout_authority(): void
    {
        $service = $this->serviceForUser(
            role: Role::CHECK_IN,
            status: UserStatus::ACTIVE,
            authenticatedAccountId: 123,
        );

        $this->assertFalse($service->canAccessAccount(123, Permission::EVENT_MANAGE));
    }

    private function serviceForUser(
        Role $role,
        UserStatus $status,
        int $authenticatedAccountId,
        int $logoutCount = 0,
    ): PublicEventAccessService
    {
        $auth = m::mock(AuthManager::class);
        $payload = m::mock(Payload::class);
        $accountUserRepository = m::mock(AccountUserRepositoryInterface::class);

        $userModel = new User([
            'id' => 10,
            'email' => 'user@example.test',
            'first_name' => 'Test',
            'last_name' => 'User',
            'password' => 'hashed',
            'timezone' => 'America/Phoenix',
            'locale' => 'en',
        ]);
        $userModel->id = 10;

        $auth->shouldReceive('check')->zeroOrMoreTimes()->andReturnTrue();
        $auth->shouldReceive('user')->once()->andReturn($userModel);
        $auth->shouldReceive('payload')->zeroOrMoreTimes()->andReturn($payload);
        $auth->shouldReceive('logout')->times($logoutCount);
        $payload->shouldReceive('get')->with('account_id')->zeroOrMoreTimes()->andReturn($authenticatedAccountId);

        $accountUserRepository
            ->shouldReceive('findWhere')
            ->once()
            ->with([
                'user_id' => 10,
                'account_id' => $authenticatedAccountId,
            ])
            ->andReturn(new Collection([
                $this->accountUser($role, $status, 10, $authenticatedAccountId),
            ]));

        return $this->service(
            $auth,
            new AuthUserService($auth, $accountUserRepository),
        );
    }

    private function service(AuthManager $auth, AuthUserService $authUserService): PublicEventAccessService
    {
        return new PublicEventAccessService(
            $auth,
            $authUserService,
            new IsAuthorizedService(
                m::mock(Application::class),
                m::mock(AccountUserRepositoryInterface::class),
                $auth,
            ),
        );
    }

    private function accountUser(Role $role, UserStatus $status, int $userId, int $accountId): AccountUserDomainObject
    {
        return (new AccountUserDomainObject())
            ->setId(1)
            ->setUserId($userId)
            ->setAccountId($accountId)
            ->setRole($role->name)
            ->setStatus($status->name);
    }
}
