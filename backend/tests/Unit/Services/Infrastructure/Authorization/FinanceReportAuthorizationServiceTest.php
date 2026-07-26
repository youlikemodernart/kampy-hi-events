<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Services\Domain\Account\AccountUserEventAssignmentService;
use HiEvents\Services\Infrastructure\Authorization\FinanceReportAuthorizationService;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Auth\AuthManager;
use Illuminate\Foundation\Application;
use Mockery as m;
use Tests\TestCase;

class FinanceReportAuthorizationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_admin_can_view_reconciliation_for_event_in_current_account(): void
    {
        $this->service()->authorize($this->event(), $this->user(Role::ADMIN), 123, 'view', true);

        $this->addToAssertionCount(1);
    }

    public function test_finance_can_view_reconciliation_for_event_in_current_account(): void
    {
        $this->service()->authorize($this->event(), $this->user(Role::FINANCE), 123, 'view', true);

        $this->addToAssertionCount(1);
    }

    public function test_assigned_reporting_user_can_view_ordinary_event_finance_surface(): void
    {
        $assignments = m::mock(AccountUserEventAssignmentService::class);
        $assignments->shouldReceive('isAssignedToEvent')->once()->with(m::type(AccountUserDomainObject::class), 44)->andReturnTrue();

        $this->service($assignments)->authorize($this->event(), $this->user(Role::REPORTING), 123, 'view', false);

        $this->addToAssertionCount(1);
    }

    public function test_unassigned_reporting_user_is_denied_by_delegated_event_authorization(): void
    {
        $assignments = m::mock(AccountUserEventAssignmentService::class);
        $assignments->shouldReceive('isAssignedToEvent')->once()->with(m::type(AccountUserDomainObject::class), 44)->andReturnFalse();

        $this->expectException(UnauthorizedException::class);

        $this->service($assignments)->authorize($this->event(), $this->user(Role::REPORTING), 123, 'view', false);
    }

    public function test_event_from_wrong_account_is_denied_before_assignment_lookup(): void
    {
        $assignments = m::mock(AccountUserEventAssignmentService::class);
        $assignments->shouldNotReceive('isAssignedToEvent');

        $this->expectException(UnauthorizedException::class);

        $this->service($assignments)->authorize($this->event(accountId: 999), $this->user(Role::REPORTING), 123, 'view', false);
    }

    public function test_reporting_user_cannot_include_reconciliation_even_when_assigned(): void
    {
        $assignments = m::mock(AccountUserEventAssignmentService::class);
        $assignments->shouldReceive('isAssignedToEvent')->once()->andReturnTrue();

        $this->expectException(UnauthorizedException::class);

        $this->service($assignments)->authorize($this->event(), $this->user(Role::REPORTING), 123, 'view', true);
    }

    public function test_check_in_user_cannot_view_finance_surface(): void
    {
        $assignments = m::mock(AccountUserEventAssignmentService::class);
        $assignments->shouldNotReceive('isAssignedToEvent');

        $this->expectException(UnauthorizedException::class);

        $this->service($assignments)->authorize($this->event(), $this->user(Role::CHECK_IN), 123, 'view', false);
    }

    public function test_assigned_reporting_user_can_export_ordinary_event_finance_surface(): void
    {
        $assignments = m::mock(AccountUserEventAssignmentService::class);
        $assignments->shouldReceive('isAssignedToEvent')->once()->with(m::type(AccountUserDomainObject::class), 44)->andReturnTrue();

        $this->service($assignments)->authorize($this->event(), $this->user(Role::REPORTING), 123, 'export', false);

        $this->addToAssertionCount(1);
    }

    public function test_unknown_surface_is_denied_before_event_authorization(): void
    {
        $assignments = m::mock(AccountUserEventAssignmentService::class);
        $assignments->shouldNotReceive('isAssignedToEvent');

        $this->expectException(UnauthorizedException::class);

        $this->service($assignments)->authorize($this->event(), $this->user(Role::ADMIN), 123, 'query', false);
    }

    private function service(?AccountUserEventAssignmentService $assignments = null): FinanceReportAuthorizationService
    {
        $auth = m::mock(AuthManager::class);
        $auth->shouldReceive('logout')->never();

        return new FinanceReportAuthorizationService(new IsAuthorizedService(
            m::mock(Application::class),
            m::mock(AccountUserRepositoryInterface::class),
            $auth,
            $assignments ?? m::mock(AccountUserEventAssignmentService::class),
        ));
    }

    private function user(Role $role): UserDomainObject
    {
        $accountUser = (new AccountUserDomainObject())
            ->setId(1)
            ->setUserId(10)
            ->setAccountId(123)
            ->setRole($role->name)
            ->setStatus(UserStatus::ACTIVE->name);

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

    private function event(int $accountId = 123): EventDomainObject
    {
        return (new EventDomainObject())
            ->setId(44)
            ->setAccountId($accountId)
            ->setUserId(10)
            ->setTitle('Event 44')
            ->setShortId('event-44')
            ->setCurrency('USD');
    }
}
