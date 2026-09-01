<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Account;

use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\AccountUserEventAssignmentDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\CannotUpdateResourceException;
use HiEvents\Repository\Interfaces\AccountUserEventAssignmentRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Account\AccountUserEventAssignmentService;
use Illuminate\Support\Collection;
use Mockery as m;
use Tests\TestCase;

class AccountUserEventAssignmentServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    public function test_event_scoped_role_requires_at_least_one_event(): void
    {
        $service = $this->service(
            m::mock(AccountUserEventAssignmentRepositoryInterface::class),
            m::mock(EventRepositoryInterface::class),
        );

        $this->expectException(CannotUpdateResourceException::class);

        $service->replaceAssignmentsForRole(
            accountUser: $this->accountUser(),
            role: Role::CHECK_IN,
            eventIds: [],
            accountId: 123,
            createdByUserId: 10,
        );
    }

    public function test_account_wide_role_rejects_event_assignments(): void
    {
        $service = $this->service(
            m::mock(AccountUserEventAssignmentRepositoryInterface::class),
            m::mock(EventRepositoryInterface::class),
        );

        $this->expectException(CannotUpdateResourceException::class);

        $service->replaceAssignmentsForRole(
            accountUser: $this->accountUser(),
            role: Role::ADMIN,
            eventIds: [44],
            accountId: 123,
            createdByUserId: 10,
        );
    }

    public function test_replaces_assignments_after_validating_events_belong_to_account(): void
    {
        $assignmentRepository = m::mock(AccountUserEventAssignmentRepositoryInterface::class);
        $eventRepository = m::mock(EventRepositoryInterface::class);
        $service = $this->service($assignmentRepository, $eventRepository);

        $eventRepository
            ->shouldReceive('findWhereIn')
            ->once()
            ->with('id', [44, 45], ['account_id' => 123], ['id'])
            ->andReturn(new Collection([
                $this->event(44, 123),
                $this->event(45, 123),
            ]));

        $assignmentRepository
            ->shouldReceive('deleteWhere')
            ->once()
            ->with(['account_user_id' => 77]);

        $assignmentRepository
            ->shouldReceive('create')
            ->once()
            ->with([
                'account_user_id' => 77,
                'event_id' => 44,
                'created_by_user_id' => 10,
            ]);

        $assignmentRepository
            ->shouldReceive('create')
            ->once()
            ->with([
                'account_user_id' => 77,
                'event_id' => 45,
                'created_by_user_id' => 10,
            ]);

        $assignments = new Collection([
            $this->assignment(77, 44),
            $this->assignment(77, 45),
        ]);

        $assignmentRepository
            ->shouldReceive('findForAccountUser')
            ->once()
            ->with(77)
            ->andReturn($assignments);

        $this->assertSame($assignments, $service->replaceAssignmentsForRole(
            accountUser: $this->accountUser(),
            role: Role::UNIVERSITY_DIRECTOR,
            eventIds: ['44', 45, 44],
            accountId: 123,
            createdByUserId: 10,
        ));
    }

    public function test_rejects_events_outside_account(): void
    {
        $assignmentRepository = m::mock(AccountUserEventAssignmentRepositoryInterface::class);
        $eventRepository = m::mock(EventRepositoryInterface::class);
        $service = $this->service($assignmentRepository, $eventRepository);

        $assignmentRepository->shouldNotReceive('deleteWhere');

        $eventRepository
            ->shouldReceive('findWhereIn')
            ->once()
            ->with('id', [44, 45], ['account_id' => 123], ['id'])
            ->andReturn(new Collection([
                $this->event(44, 123),
            ]));

        $assignmentRepository->shouldNotReceive('create');

        $this->expectException(CannotUpdateResourceException::class);

        $service->replaceAssignmentsForRole(
            accountUser: $this->accountUser(),
            role: Role::REPORTING,
            eventIds: [44, 45],
            accountId: 123,
            createdByUserId: 10,
        );
    }

    public function test_get_assigned_event_ids_uses_loaded_assignments_when_available(): void
    {
        $assignmentRepository = m::mock(AccountUserEventAssignmentRepositoryInterface::class);
        $assignmentRepository->shouldNotReceive('getAssignedEventIdsForAccountUser');

        $accountUser = $this->accountUser()->setEventAssignments(new Collection([
            $this->assignment(77, 44),
            $this->assignment(77, 45),
            $this->assignment(77, 44),
        ]));

        $service = $this->service($assignmentRepository, m::mock(EventRepositoryInterface::class));

        $this->assertSame([44, 45], $service->getAssignedEventIds($accountUser));
    }

    private function service(
        AccountUserEventAssignmentRepositoryInterface $assignmentRepository,
        EventRepositoryInterface $eventRepository,
    ): AccountUserEventAssignmentService
    {
        return new AccountUserEventAssignmentService($assignmentRepository, $eventRepository);
    }

    private function accountUser(): AccountUserDomainObject
    {
        return (new AccountUserDomainObject())
            ->setId(77)
            ->setAccountId(123)
            ->setUserId(55)
            ->setRole(Role::ORGANIZER->name);
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

    private function assignment(int $accountUserId, int $eventId): AccountUserEventAssignmentDomainObject
    {
        return (new AccountUserEventAssignmentDomainObject())
            ->setId($eventId)
            ->setAccountUserId($accountUserId)
            ->setEventId($eventId);
    }
}
