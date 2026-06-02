<?php

namespace HiEvents\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\EventStatisticDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\GetEventsDTO;
use HiEvents\Services\Domain\Account\AccountUserEventAssignmentService;
use Illuminate\Pagination\LengthAwarePaginator;

class GetEventsHandler
{
    public function __construct(
        private readonly EventRepositoryInterface            $eventRepository,
        private readonly AccountUserEventAssignmentService   $accountUserEventAssignmentService,
    )
    {
    }

    public function handle(GetEventsDTO $dto, UserDomainObject $authUser): LengthAwarePaginator
    {
        $where = [
            'account_id' => $dto->accountId,
        ];

        $role = Role::tryFrom($authUser->getCurrentAccountUser()?->getRole() ?? '');
        if ($role?->requiresEventAssignments()) {
            $assignedEventIds = $this->accountUserEventAssignmentService->getAssignedEventIds(
                $authUser->getCurrentAccountUser(),
            );

            $where[] = static function ($builder) use ($assignedEventIds) {
                $builder->whereIn(EventDomainObjectAbstract::ID, $assignedEventIds);
            };
        }

        return $this->eventRepository
            ->loadRelation(new Relationship(ImageDomainObject::class))
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->loadRelation(new Relationship(EventStatisticDomainObject::class))
            ->loadRelation(new Relationship(
                domainObject: ProductDomainObject::class,
                nested: [
                    new Relationship(ProductPriceDomainObject::class),
                ],
            ))
            ->loadRelation(new Relationship(
                domainObject: OrganizerDomainObject::class,
                name: 'organizer',
            ))
            ->findEvents(
                where: $where,
                params: $dto->queryParams
            );
    }
}
