<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Application;
use Mockery as m;
use Tests\TestCase;

class IsAuthorizedServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_finance_role_can_refund_orders(): void
    {
        $service = $this->serviceWithAuthLogoutExpectation(0);

        $service->validateUserPermission(
            Permission::ORDERS_REFUND,
            $this->user(Role::FINANCE, UserStatus::ACTIVE),
        );

        $this->addToAssertionCount(1);
    }

    public function test_reporting_role_cannot_refund_orders(): void
    {
        $service = $this->serviceWithAuthLogoutExpectation(0);

        $this->expectException(UnauthorizedException::class);

        $service->validateUserPermission(
            Permission::ORDERS_REFUND,
            $this->user(Role::REPORTING, UserStatus::ACTIVE),
        );
    }

    public function test_check_in_role_cannot_view_orders(): void
    {
        $service = $this->serviceWithAuthLogoutExpectation(0);

        $this->expectException(UnauthorizedException::class);

        $service->validateUserPermission(
            Permission::ORDERS_VIEW,
            $this->user(Role::CHECK_IN, UserStatus::ACTIVE),
        );
    }

    public function test_inactive_user_is_denied_and_logged_out_before_permission_check(): void
    {
        $service = $this->serviceWithAuthLogoutExpectation(1);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('Your account is not active.');

        $service->validateUserPermission(
            Permission::AUTHENTICATED,
            $this->user(Role::ADMIN, UserStatus::INACTIVE),
        );
    }

    public function test_missing_current_account_user_is_denied(): void
    {
        $service = $this->serviceWithAuthLogoutExpectation(1);
        $user = (new UserDomainObject())
            ->setId(10)
            ->setEmail('missing@example.test')
            ->setFirstName('Missing')
            ->setLastName('Role')
            ->setPassword('hashed')
            ->setTimezone('America/Phoenix')
            ->setLocale('en');

        $this->expectException(UnauthorizedException::class);

        $service->validateUserPermission(Permission::ACCOUNT_VIEW, $user);
    }

    private function serviceWithAuthLogoutExpectation(int $logoutCount): IsAuthorizedService
    {
        $auth = m::mock(AuthManager::class);
        $auth->shouldReceive('logout')->times($logoutCount);

        return new IsAuthorizedService(
            m::mock(Application::class),
            m::mock(AccountUserRepositoryInterface::class),
            $auth,
        );
    }

    private function user(Role $role, UserStatus $status): UserDomainObject
    {
        $accountUser = (new AccountUserDomainObject())
            ->setId(1)
            ->setUserId(10)
            ->setAccountId(123)
            ->setRole($role->name)
            ->setStatus($status->name);

        return (new UserDomainObject())
            ->setId(10)
            ->setEmail('user@example.test')
            ->setFirstName('Test')
            ->setLastName('User')
            ->setPassword('hashed')
            ->setTimezone('America/Phoenix')
            ->setLocale('en')
            ->setCurrentAccountUser($accountUser);
    }
}
