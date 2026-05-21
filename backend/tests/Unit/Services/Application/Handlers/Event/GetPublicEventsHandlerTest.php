<?php

namespace Tests\Unit\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\GetPublicOrganizerEventsDTO;
use HiEvents\Services\Application\Handlers\Event\GetPublicEventsHandler;
use Illuminate\Pagination\LengthAwarePaginator;
use Mockery as m;
use Tests\TestCase;

class GetPublicEventsHandlerTest extends TestCase
{
    public function testAuthenticatedOrganizerPreviewUsesPublicListingFilter(): void
    {
        $eventRepository = m::mock(EventRepositoryInterface::class);
        $queryParams = new QueryParamsDTO(page: 1, per_page: 30);
        $paginator = new LengthAwarePaginator([], 0, 30);

        $eventRepository->shouldReceive('loadRelation')->andReturnSelf()->times(3);
        $eventRepository->shouldReceive('findEventsForOrganizer')->never();
        $eventRepository->shouldReceive('findEvents')
            ->once()
            ->with(
                m::on(static function (array $where): bool {
                    return ($where['organizer_id'] ?? null) === 123
                        && ($where['status'] ?? null) === EventStatus::LIVE->name
                        && count(array_filter($where, 'is_callable')) === 1;
                }),
                $queryParams,
            )
            ->andReturn($paginator);

        $handler = new GetPublicEventsHandler($eventRepository);

        $result = $handler->handle(new GetPublicOrganizerEventsDTO(
            organizerId: 123,
            queryParams: $queryParams,
            authenticatedAccountId: 456,
        ));

        $this->assertSame($paginator, $result);
    }
}
