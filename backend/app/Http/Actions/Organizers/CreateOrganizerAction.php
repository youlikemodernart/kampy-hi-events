<?php

namespace HiEvents\Http\Actions\Organizers;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Organizer\UpsertOrganizerRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Resources\Organizer\OrganizerResource;
use HiEvents\Services\Application\Handlers\Organizer\CreateOrganizerHandler;
use HiEvents\Services\Application\Handlers\Organizer\DTO\CreateOrganizerDTO;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Http\JsonResponse;

class CreateOrganizerAction extends BaseAction
{
    public function __construct(
        private readonly CreateOrganizerHandler $createOrganizerHandler,
        private readonly IsAuthorizedService    $authorizationService,
    )
    {
    }

    public function __invoke(UpsertOrganizerRequest $request): JsonResponse
    {
        $this->authorizationService->validateAccountWidePermission(
            Permission::ORGANIZER_MANAGE,
            $this->getAuthenticatedUser(),
        );

        $organizerData = array_merge(
            $request->validated(),
            [
                'account_id' => $this->getAuthenticatedAccountId(),
            ]
        );

        $organizer = $this->createOrganizerHandler->handle(
            organizerData: CreateOrganizerDTO::fromArray($organizerData),
        );

        return $this->resourceResponse(
            resource: OrganizerResource::class,
            data: $organizer,
            statusCode: ResponseCodes::HTTP_CREATED,
        );
    }
}
