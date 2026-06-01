<?php

namespace HiEvents\Http\Middleware;

use Closure;
use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Services\Domain\Auth\AuthUserService;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use HiEvents\Services\Infrastructure\Authorization\RoutePermissionRegistry;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

readonly class EnsureRoutePermission
{
    public function __construct(
        private AuthUserService $authUserService,
        private IsAuthorizedService $authorizationService,
    )
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $actionClass = $this->getRouteActionClass($request);
        $permission = RoutePermissionRegistry::permissionForAction($actionClass);

        if ($permission === null) {
            throw new UnauthorizedException(__('No permission rule is configured for this route.'));
        }

        $authUser = $this->authUserService->getUser();

        if ($authUser === null) {
            throw new UnauthorizedException(__('You are not authorized to perform this action.'));
        }

        if ($permission === Permission::AUTHENTICATED) {
            $this->authorizationService->validateUserStatus($authUser);
        } else {
            $this->authorizationService->validateUserPermission($permission, $authUser);
        }

        return $next($request);
    }

    private function getRouteActionClass(Request $request): ?string
    {
        $controller = $request->route()?->getAction('controller');

        if (is_array($controller) && isset($controller[0])) {
            return is_object($controller[0]) ? $controller[0]::class : $controller[0];
        }

        if (!is_string($controller)) {
            return null;
        }

        return str_contains($controller, '@')
            ? strstr($controller, '@', true)
            : $controller;
    }
}
