<?php

namespace HiEvents\Http\Actions\EventSettings;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Resources\Event\EventSettingsResource;
use HiEvents\Resources\Event\UniversityDirectorEventSettingsResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class GetEventSettingsAction extends BaseAction
{
    public function __construct(private readonly EventSettingsRepositoryInterface $eventSettingsRepository) {}

    public function __invoke(int $eventId): Response|JsonResponse
    {
        $this->isActionAuthorized($eventId, EventDomainObject::class);

        $settings = $this->eventSettingsRepository->findFirstWhere([
            'event_id' => $eventId,
        ]);

        if ($settings === null) {
            return $this->notFoundResponse();
        }

        $resource = $this->getAuthenticatedUserRole() === Role::UNIVERSITY_DIRECTOR
            ? UniversityDirectorEventSettingsResource::class
            : EventSettingsResource::class;

        return $this->resourceResponse($resource, $settings);
    }
}
