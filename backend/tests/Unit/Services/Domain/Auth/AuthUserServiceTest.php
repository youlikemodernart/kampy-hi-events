<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Auth;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Services\Domain\Auth\AuthUserService;
use Illuminate\Auth\AuthManager;
use Illuminate\Support\Collection;
use Mockery as m;
use PHPOpenSourceSaver\JWTAuth\Payload;
use PHPUnit\Framework\TestCase;

class AuthUserServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_authenticated_user_role_is_loaded_from_current_account_user_record(): void
    {
        $currentAccountUser = $this->accountUser(Role::REPORTING, UserStatus::ACTIVE, 10, 123);
        $service = $this->serviceForCurrentAccountUsers(new Collection([$currentAccountUser]), calls: 2);

        $this->assertSame(Role::REPORTING, $service->getAuthenticatedUserRole());
        $this->assertSame(Role::REPORTING->name, $service->getUser()->getCurrentAccountUser()->getRole());
    }

    public function test_ambiguous_active_account_user_rows_do_not_select_an_arbitrary_role(): void
    {
        $service = $this->serviceForCurrentAccountUsers(new Collection([
            $this->accountUser(Role::ADMIN, UserStatus::ACTIVE, 10, 123, 1),
            $this->accountUser(Role::CHECK_IN, UserStatus::ACTIVE, 10, 123, 2),
        ]), calls: 2);

        $this->assertNull($service->getUser()->getCurrentAccountUser());
        $this->assertNull($service->getAuthenticatedUserRole());
    }

    public function test_single_active_account_user_is_selected_when_inactive_duplicates_exist(): void
    {
        $service = $this->serviceForCurrentAccountUsers(new Collection([
            $this->accountUser(Role::ADMIN, UserStatus::INACTIVE, 10, 123, 1),
            $this->accountUser(Role::CHECK_IN, UserStatus::ACTIVE, 10, 123, 2),
        ]));

        $this->assertSame(Role::CHECK_IN, $service->getAuthenticatedUserRole());
    }

    private function serviceForCurrentAccountUsers(Collection $accountUsers, int $calls = 1): AuthUserService
    {
        $authManager = m::mock(AuthManager::class);
        $accountUserRepository = m::mock(AccountUserRepositoryInterface::class);
        $payload = m::mock(Payload::class);

        $userModel = new User([
            'id' => 10,
            'email' => 'finance@example.test',
            'first_name' => 'Finance',
            'last_name' => 'User',
            'password' => 'hashed',
            'timezone' => 'America/Phoenix',
            'locale' => 'en',
        ]);
        $userModel->id = 10;

        $authManager->shouldReceive('user')->times($calls)->andReturn($userModel);
        $authManager->shouldReceive('check')->times($calls)->andReturnTrue();
        $authManager->shouldReceive('payload')->times($calls)->andReturn($payload);
        $payload->shouldReceive('get')->with('account_id')->times($calls)->andReturn(123);

        $accountUserRepository
            ->shouldReceive('findWhere')
            ->times($calls)
            ->with([
                'user_id' => 10,
                'account_id' => 123,
            ])
            ->andReturn($accountUsers);

        return new AuthUserService($authManager, $accountUserRepository);
    }

    private function accountUser(
        Role $role,
        UserStatus $status,
        int $userId,
        int $accountId,
        int $id = 1,
    ): AccountUserDomainObject
    {
        return (new AccountUserDomainObject())
            ->setId($id)
            ->setUserId($userId)
            ->setAccountId($accountId)
            ->setRole($role->name)
            ->setStatus($status->name);
    }
}
