<?php

namespace Tests\Unit\Services\Application\Handlers\CheckInList\Public;

use HiEvents\DomainObjects\AttendeeCheckInDomainObject;
use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\CheckInListDomainObject;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Exceptions\CannotCheckInException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\CheckInListRepositoryInterface;
use HiEvents\Services\Application\Handlers\CheckInList\Public\GetCheckInListAttendeePublicHandler;
use Mockery as m;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class GetCheckInListAttendeePublicHandlerTest extends TestCase
{
    private CheckInListRepositoryInterface $checkInListRepository;
    private AttendeeRepositoryInterface $attendeeRepository;
    private GetCheckInListAttendeePublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkInListRepository = m::mock(CheckInListRepositoryInterface::class);
        $this->attendeeRepository = m::mock(AttendeeRepositoryInterface::class);

        $this->handler = new GetCheckInListAttendeePublicHandler(
            $this->attendeeRepository,
            $this->checkInListRepository
        );
    }

    public function testHandleThrowsNotFoundIfCheckInListMissing(): void
    {
        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturnNull();

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle('short-id', 'attendee-public-id');
    }

    public function testHandleThrowsCannotCheckInIfListExpired(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->twice()->andReturn(now()->subMinute());

        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->expectException(CannotCheckInException::class);

        $this->handler->handle('short-id', 'attendee-public-id');
    }

    public function testHandleThrowsCannotCheckInIfListNotActiveYet(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->twice()->andReturn(now()->addMinute());

        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->expectException(CannotCheckInException::class);

        $this->handler->handle('short-id', 'attendee-public-id');
    }

    public function testHandleReturnsAttendeeSuccessfully(): void
    {
        $checkInList = m::mock(CheckInListDomainObject::class);
        $checkInList->shouldReceive('getExpiresAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getActivatesAt')->once()->andReturn(null);
        $checkInList->shouldReceive('getEventId')->once()->andReturn(123);
        $checkInList->shouldReceive('getId')->once()->andReturn(456);

        $otherCheckIn = m::mock(AttendeeCheckInDomainObject::class);
        $otherCheckIn->shouldReceive('getCheckInListId')->once()->andReturn(789);

        $matchingCheckIn = m::mock(AttendeeCheckInDomainObject::class);
        $matchingCheckIn->shouldReceive('getCheckInListId')->once()->andReturn(456);

        $attendee = m::mock(AttendeeDomainObject::class);
        $attendee->shouldReceive('getCheckIns')->once()->andReturn(collect([$otherCheckIn, $matchingCheckIn]));
        $attendee->shouldReceive('setCheckIn')->once()->with($matchingCheckIn)->andReturnSelf();

        $this->checkInListRepository
            ->shouldReceive('loadRelation')
            ->andReturnSelf()
            ->times(2);

        $this->checkInListRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn($checkInList);

        $this->attendeeRepository
            ->shouldReceive('loadRelation')
            ->once()
            ->with(m::type(Relationship::class))
            ->andReturnSelf();

        $this->attendeeRepository
            ->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'public_id' => 'attendee-public-id',
                'event_id' => 123,
            ])
            ->andReturn($attendee);

        $result = $this->handler->handle('short-id', 'attendee-public-id');

        $this->assertSame($attendee, $result);
    }
}
