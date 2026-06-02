<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\GetEventsDTO;
use HiEvents\Services\Application\Handlers\Event\GetEventsHandler;
use HiEvents\Services\Domain\Account\AccountUserEventAssignmentService;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery as m;
use Tests\TestCase;

class GetEventsHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_event_scoped_roles_only_query_assigned_events(): void
    {
        $repository = m::mock(EventRepositoryInterface::class);
        $assignmentService = m::mock(AccountUserEventAssignmentService::class);
        $paginator = new LengthAwarePaginator([], 0, 25);
        $assignedEventIds = [44, 45];
        $capturedFilter = null;

        $assignmentService
            ->shouldReceive('getAssignedEventIds')
            ->once()
            ->with(m::on(fn(AccountUserDomainObject $accountUser) => $accountUser->getId() === 1))
            ->andReturn($assignedEventIds);

        $repository->shouldReceive('loadRelation')->andReturnSelf();
        $repository
            ->shouldReceive('findEvents')
            ->once()
            ->with(
                m::on(function (array $where) use (&$capturedFilter): bool {
                    if (($where['account_id'] ?? null) !== 123 || !is_callable($where[0] ?? null)) {
                        return false;
                    }

                    $capturedFilter = $where[0];
                    return true;
                }),
                m::type(QueryParamsDTO::class),
            )
            ->andReturn($paginator);

        $handler = new GetEventsHandler($repository, $assignmentService);
        $result = $handler->handle($this->dto(), $this->user(Role::REPORTING));

        $builder = m::mock();
        $builder
            ->shouldReceive('whereIn')
            ->once()
            ->with(EventDomainObjectAbstract::ID, $assignedEventIds);
        $capturedFilter($builder);

        $this->assertSame($paginator, $result);
    }

    public function test_account_wide_roles_query_account_events_without_assignment_filter(): void
    {
        $repository = m::mock(EventRepositoryInterface::class);
        $assignmentService = m::mock(AccountUserEventAssignmentService::class);
        $assignmentService->shouldNotReceive('getAssignedEventIds');
        $paginator = new LengthAwarePaginator([], 0, 25);

        $repository->shouldReceive('loadRelation')->andReturnSelf();
        $repository
            ->shouldReceive('findEvents')
            ->once()
            ->with(['account_id' => 123], m::type(QueryParamsDTO::class))
            ->andReturn($paginator);

        $handler = new GetEventsHandler($repository, $assignmentService);
        $result = $handler->handle($this->dto(), $this->user(Role::ADMIN));

        $this->assertSame($paginator, $result);
    }

    private function dto(): GetEventsDTO
    {
        return new GetEventsDTO(
            accountId: 123,
            queryParams: QueryParamsDTO::fromArray([]),
        );
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
}
