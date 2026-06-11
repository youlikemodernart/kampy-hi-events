<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Services\Domain\Account\AccountUserEventAssignmentService;
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

    public function test_admin_can_access_any_account_event_without_assignment(): void
    {
        $assignmentService = m::mock(AccountUserEventAssignmentService::class);
        $assignmentService->shouldNotReceive('isAssignedToEvent');

        $service = $this->serviceForEntity(
            EventRepositoryInterface::class,
            $this->event(44, 123),
            $assignmentService,
        );

        $service->isActionAuthorized(
            entityId: 44,
            entityType: EventDomainObject::class,
            authUser: $this->user(Role::ADMIN, UserStatus::ACTIVE),
            authAccountId: 123,
            minimumRole: Role::ORGANIZER,
        );

        $this->addToAssertionCount(1);
    }

    public function test_event_scoped_role_can_access_assigned_event(): void
    {
        $assignmentService = m::mock(AccountUserEventAssignmentService::class);
        $assignmentService
            ->shouldReceive('isAssignedToEvent')
            ->once()
            ->with(m::on(fn(AccountUserDomainObject $accountUser) => $accountUser->getId() === 1), 44)
            ->andReturnTrue();

        $service = $this->serviceForEntity(
            EventRepositoryInterface::class,
            $this->event(44, 123),
            $assignmentService,
        );

        $service->isActionAuthorized(
            entityId: 44,
            entityType: EventDomainObject::class,
            authUser: $this->user(Role::ORGANIZER, UserStatus::ACTIVE),
            authAccountId: 123,
            minimumRole: Role::ORGANIZER,
        );

        $this->addToAssertionCount(1);
    }

    public function test_event_scoped_role_cannot_access_unassigned_event(): void
    {
        $assignmentService = m::mock(AccountUserEventAssignmentService::class);
        $assignmentService
            ->shouldReceive('isAssignedToEvent')
            ->once()
            ->with(m::on(fn(AccountUserDomainObject $accountUser) => $accountUser->getId() === 1), 45)
            ->andReturnFalse();

        $service = $this->serviceForEntity(
            EventRepositoryInterface::class,
            $this->event(45, 123),
            $assignmentService,
        );

        $this->expectException(UnauthorizedException::class);

        $service->isActionAuthorized(
            entityId: 45,
            entityType: EventDomainObject::class,
            authUser: $this->user(Role::CHECK_IN, UserStatus::ACTIVE),
            authAccountId: 123,
            minimumRole: Role::ORGANIZER,
        );
    }

    public function test_finance_keeps_account_wide_event_access_for_finance_setup(): void
    {
        $assignmentService = m::mock(AccountUserEventAssignmentService::class);
        $assignmentService->shouldNotReceive('isAssignedToEvent');

        $service = $this->serviceForEntity(
            EventRepositoryInterface::class,
            $this->event(44, 123),
            $assignmentService,
        );

        $service->isActionAuthorized(
            entityId: 44,
            entityType: EventDomainObject::class,
            authUser: $this->user(Role::FINANCE, UserStatus::ACTIVE),
            authAccountId: 123,
            minimumRole: Role::ORGANIZER,
        );

        $this->addToAssertionCount(1);
    }

    public function test_event_scoped_role_is_denied_organizer_aggregate_surfaces(): void
    {
        $service = $this->serviceForEntity(
            OrganizerRepositoryInterface::class,
            $this->organizer(9, 123),
            m::mock(AccountUserEventAssignmentService::class),
        );

        $this->expectException(UnauthorizedException::class);

        $service->isActionAuthorized(
            entityId: 9,
            entityType: OrganizerDomainObject::class,
            authUser: $this->user(Role::ORGANIZER, UserStatus::ACTIVE),
            authAccountId: 123,
            minimumRole: Role::ORGANIZER,
        );
    }

    public function test_only_account_wide_event_roles_can_create_or_duplicate_events(): void
    {
        $service = $this->serviceWithAuthLogoutExpectation(0);

        $service->validateAccountWideEventManagement($this->user(Role::ADMIN, UserStatus::ACTIVE));
        $this->addToAssertionCount(1);

        $this->expectException(UnauthorizedException::class);
        $service->validateAccountWideEventManagement($this->user(Role::ORGANIZER, UserStatus::ACTIVE));
    }

    public function test_account_wide_permission_denies_event_scoped_roles_on_aggregate_surfaces(): void
    {
        $service = $this->serviceWithAuthLogoutExpectation(0);

        $service->validateAccountWidePermission(
            Permission::ORGANIZER_VIEW,
            $this->user(Role::FINANCE, UserStatus::ACTIVE),
        );
        $this->addToAssertionCount(1);

        $this->expectException(UnauthorizedException::class);
        $service->validateAccountWidePermission(
            Permission::ORGANIZER_VIEW,
            $this->user(Role::REPORTING, UserStatus::ACTIVE),
        );
    }

    private function serviceWithAuthLogoutExpectation(int $logoutCount): IsAuthorizedService
    {
        $auth = m::mock(AuthManager::class);
        $auth->shouldReceive('logout')->times($logoutCount);

        return new IsAuthorizedService(
            m::mock(Application::class),
            m::mock(AccountUserRepositoryInterface::class),
            $auth,
            m::mock(AccountUserEventAssignmentService::class),
        );
    }

    private function serviceForEntity(
        string $repositoryInterface,
        mixed $entity,
        AccountUserEventAssignmentService $assignmentService,
    ): IsAuthorizedService
    {
        $auth = m::mock(AuthManager::class);
        $auth->shouldReceive('logout')->never();

        $repository = m::mock($repositoryInterface);
        $repository
            ->shouldReceive('findById')
            ->once()
            ->andReturn($entity);

        $app = m::mock(Application::class);
        $app
            ->shouldReceive('make')
            ->once()
            ->with($repositoryInterface)
            ->andReturn($repository);

        return new IsAuthorizedService(
            $app,
            m::mock(AccountUserRepositoryInterface::class),
            $auth,
            $assignmentService,
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

    private function event(int $id, int $accountId): EventDomainObject
    {
        return (new EventDomainObject())
            ->setId($id)
            ->setAccountId($accountId)
            ->setUserId(10)
            ->setTitle('Event ' . $id)
            ->setShortId('event-' . $id)
            ->setCurrency('USD');
    }

    private function organizer(int $id, int $accountId): OrganizerDomainObject
    {
        return (new OrganizerDomainObject())
            ->setId($id)
            ->setAccountId($accountId)
            ->setName('Organizer ' . $id);
    }
}
