<?php

namespace Tests\Unit\Http\Actions\PromoCodes;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Http\Actions\PromoCodes\GetPromoCodePublic;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\PublicEventAccessService;
use Illuminate\Http\Request;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class GetPromoCodePublicTest extends TestCase
{
    private PromoCodeRepositoryInterface|MockInterface $promoCodeRepository;
    private EventRepositoryInterface|MockInterface $eventRepository;
    private PublicEventAccessService|MockInterface $publicEventAccessService;
    private GetPromoCodePublic $action;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret']);

        $this->promoCodeRepository = Mockery::mock(PromoCodeRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->publicEventAccessService = Mockery::mock(PublicEventAccessService::class);

        $this->action = new GetPromoCodePublic(
            $this->promoCodeRepository,
            $this->eventRepository,
            $this->publicEventAccessService,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testMissingEventReturnsInvalidWithoutLookingUpPromoCode(): void
    {
        $this->eventRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'id' => 2,
            ])
            ->andReturnNull();
        $this->promoCodeRepository->shouldNotReceive('findFirstWhere');
        $this->publicEventAccessService->shouldNotReceive('canAccessEvent');

        $response = ($this->action)(2, 'staff', Request::create('/'));

        $this->assertSame(['valid' => false], $response->getData(true));
    }

    public function testNonLiveEventReturnsInvalidWithoutLookingUpPromoCode(): void
    {
        $event = (new EventDomainObject())
            ->setId(2)
            ->setStatus(EventStatus::DRAFT->name)
            ->setAccountId(99);

        $this->eventRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'id' => 2,
            ])
            ->andReturn($event);
        $this->promoCodeRepository->shouldNotReceive('findFirstWhere');
        $this->publicEventAccessService
            ->shouldReceive('canAccessEvent')
            ->once()
            ->with($event, Permission::EVENT_VIEW)
            ->andReturnFalse();

        $response = ($this->action)(2, 'staff', Request::create('/'));

        $this->assertSame(['valid' => false], $response->getData(true));
    }
}
