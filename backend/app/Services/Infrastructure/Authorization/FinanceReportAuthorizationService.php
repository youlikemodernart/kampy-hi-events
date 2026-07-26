<?php

namespace HiEvents\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\UnauthorizedException;

readonly class FinanceReportAuthorizationService
{
    public function __construct(
        private IsAuthorizedService $authorizationService,
    ) {
    }

    public function authorize(
        EventDomainObject $event,
        UserDomainObject $authenticatedUser,
        int $currentAccountId,
        string $requestedSurface,
        bool $includeReconciliation,
    ): void {
        $permission = match ($requestedSurface) {
            'view' => Permission::REPORTS_VIEW,
            'export' => Permission::REPORTS_EXPORT,
            default => throw new UnauthorizedException(__('You are not authorized to perform this action.')),
        };

        $this->authorizationService->validateUserPermission($permission, $authenticatedUser);
        $this->authorizationService->validateEventAccess($event, $authenticatedUser, $currentAccountId);

        if ($includeReconciliation) {
            $this->authorizationService->validateAccountWidePermission(
                Permission::FINANCIAL_RECONCILIATION_VIEW,
                $authenticatedUser,
            );
        }
    }
}
