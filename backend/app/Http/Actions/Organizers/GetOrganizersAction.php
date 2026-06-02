<?php

namespace HiEvents\Http\Actions\Organizers;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\ImageDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Resources\Organizer\OrganizerResource;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Http\JsonResponse;

class GetOrganizersAction extends BaseAction
{
    public function __construct(
        private readonly OrganizerRepositoryInterface $organizerRepository,
        private readonly IsAuthorizedService          $authorizationService,
    )
    {
    }

    public function __invoke(): JsonResponse
    {
        $this->authorizationService->validateAccountWidePermission(
            Permission::ORGANIZER_VIEW,
            $this->getAuthenticatedUser(),
        );

        $organizers = $this->organizerRepository
            ->loadRelation(ImageDomainObject::class)
            ->findwhere([
                'account_id' => $this->getAuthenticatedAccountId(),
            ]);

        return $this->resourceResponse(
            resource: OrganizerResource::class,
            data: $organizers,
        );
    }
}
