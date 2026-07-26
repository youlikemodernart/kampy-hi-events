<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\Accounts\UpdateAccountAction;
use HiEvents\Http\Actions\Admin\Stats\GetAdminStatsAction;
use HiEvents\Http\Actions\Attendees\CheckInAttendeeAction;
use HiEvents\Http\Actions\Events\CreateEventAction;
use HiEvents\Http\Actions\Events\GetEventAction;
use HiEvents\Http\Actions\Events\UpdateEventStatusAction;
use HiEvents\Http\Actions\EventSettings\GetEventSettingsAction;
use HiEvents\Http\Actions\EventSettings\GetPlatformFeePreviewAction;
use HiEvents\Http\Actions\Financial\ExportFinancialReportAction;
use HiEvents\Http\Actions\Financial\GetFinancialReportAction;
use HiEvents\Http\Actions\Messages\SendMessageAction;
use HiEvents\Http\Actions\CheckInLists\GetCheckInListAction;
use HiEvents\Http\Actions\CheckInLists\GetCheckInListsAction;
use HiEvents\Http\Actions\Orders\Payment\RefundOrderAction;
use HiEvents\Http\Actions\Organizers\CreateOrganizerAction;
use HiEvents\Http\Actions\Products\GetProductsAction;
use HiEvents\Http\Actions\PromoCodes\GetPromoCodesAction;
use HiEvents\Http\Actions\Affiliates\GetAffiliatesAction;
use HiEvents\Http\Actions\Users\GetMeAction;
use HiEvents\Services\Infrastructure\Authorization\RoutePermissionRegistry;
use PHPUnit\Framework\TestCase;

class RoutePermissionRegistryTest extends TestCase
{
    public function test_every_authenticated_and_admin_route_action_has_a_permission_rule(): void
    {
        $routeActionClasses = $this->getAuthenticatedAndAdminRouteActionClasses();
        $registryActionClasses = $this->getRegistryActionClasses();

        $this->assertCount(177, $routeActionClasses);
        $this->assertSame([], array_values(array_diff($routeActionClasses, $registryActionClasses)));
        $this->assertSame([], array_values(array_diff($registryActionClasses, $routeActionClasses)));
    }

    public function test_high_risk_actions_map_to_specific_permissions(): void
    {
        $this->assertSame(Permission::SYSTEM_ADMIN, RoutePermissionRegistry::permissionForAction(GetAdminStatsAction::class));
        $this->assertSame(Permission::AUTHENTICATED, RoutePermissionRegistry::permissionForAction(GetMeAction::class));
        $this->assertSame(Permission::ACCOUNT_MANAGE, RoutePermissionRegistry::permissionForAction(UpdateAccountAction::class));
        $this->assertSame(Permission::ORGANIZER_MANAGE, RoutePermissionRegistry::permissionForAction(CreateOrganizerAction::class));
        $this->assertSame(Permission::EVENT_MANAGE, RoutePermissionRegistry::permissionForAction(CreateEventAction::class));
        $this->assertSame(Permission::EVENT_PUBLISH, RoutePermissionRegistry::permissionForAction(UpdateEventStatusAction::class));
        $this->assertSame(Permission::ORDERS_REFUND, RoutePermissionRegistry::permissionForAction(RefundOrderAction::class));
        $this->assertSame(Permission::MESSAGES_MANAGE, RoutePermissionRegistry::permissionForAction(SendMessageAction::class));
        $this->assertSame(Permission::CHECK_IN_MANAGE, RoutePermissionRegistry::permissionForAction(CheckInAttendeeAction::class));
        $this->assertSame(Permission::REPORTS_VIEW, RoutePermissionRegistry::permissionForAction(GetFinancialReportAction::class));
        $this->assertSame(Permission::REPORTS_EXPORT, RoutePermissionRegistry::permissionForAction(ExportFinancialReportAction::class));
    }

    public function test_check_in_staff_is_denied_from_event_setup_read_surfaces(): void
    {
        $blockedActions = [
            GetProductsAction::class,
            GetPromoCodesAction::class,
            GetAffiliatesAction::class,
            GetEventSettingsAction::class,
            GetPlatformFeePreviewAction::class,
        ];

        foreach ($blockedActions as $actionClass) {
            $permission = RoutePermissionRegistry::permissionForAction($actionClass);

            $this->assertSame(Permission::EVENT_CONTENT_VIEW, $permission);
            $this->assertFalse(Role::CHECK_IN->hasPermission($permission));
        }
    }

    public function test_check_in_staff_can_reach_event_overview_and_check_in_routes(): void
    {
        $allowedActions = [
            GetEventAction::class,
            GetCheckInListsAction::class,
            GetCheckInListAction::class,
            CheckInAttendeeAction::class,
        ];

        foreach ($allowedActions as $actionClass) {
            $permission = RoutePermissionRegistry::permissionForAction($actionClass);

            $this->assertNotNull($permission);
            $this->assertTrue(Role::CHECK_IN->hasPermission($permission));
        }
    }

    /**
     * @return array<string>
     */
    private function getAuthenticatedAndAdminRouteActionClasses(): array
    {
        $backendRoot = dirname(__DIR__, 5);
        $routes = file_get_contents($backendRoot . '/routes/api.php');
        $useMap = [];

        preg_match_all('/^use\\s+(HiEvents\\\\Http\\\\Actions\\\\[^;]+);/m', $routes, $uses);

        foreach ($uses[1] as $useStatement) {
            if (str_contains($useStatement, ' as ')) {
                [$fullClass, $alias] = preg_split('/\\s+as\\s+/', $useStatement);
            } else {
                $fullClass = $useStatement;
                $parts = explode('\\', $fullClass);
                $alias = end($parts);
            }

            $useMap[$alias] = $fullClass;
        }

        $bodies = [
            $this->extractRouteGroupBody($routes, "\$router->middleware(['auth:api', 'route.permission'])->group"),
            $this->extractRouteGroupBody($routes, "\$router->prefix('/admin')->middleware(['auth:api', 'route.permission'])->group"),
        ];

        preg_match_all('/\\b([A-Za-z_][A-Za-z0-9_]*)::class/', implode("\n", $bodies), $aliases);

        $classes = [];
        foreach ($aliases[1] as $alias) {
            $classes[] = $useMap[$alias] ?? 'UNRESOLVED:' . $alias;
        }

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }

    private function extractRouteGroupBody(string $routes, string $marker): string
    {
        $markerOffset = strpos($routes, $marker);
        $this->assertNotFalse($markerOffset, 'Route group marker not found: ' . $marker);

        $functionOffset = strpos($routes, 'function (Router $router): void {', $markerOffset);
        $this->assertNotFalse($functionOffset, 'Route group function not found: ' . $marker);

        $bodyStart = strpos($routes, '{', $functionOffset);
        $this->assertNotFalse($bodyStart, 'Route group body not found: ' . $marker);

        $depth = 0;
        $length = strlen($routes);

        for ($i = $bodyStart; $i < $length; $i++) {
            if ($routes[$i] === '{') {
                $depth++;
            }

            if ($routes[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($routes, $bodyStart + 1, $i - $bodyStart - 1);
                }
            }
        }

        $this->fail('Could not extract route group body: ' . $marker);
    }

    /**
     * @return array<string>
     */
    private function getRegistryActionClasses(): array
    {
        $backendRoot = dirname(__DIR__, 5);
        $registry = file_get_contents($backendRoot . '/app/Services/Infrastructure/Authorization/RoutePermissionRegistry.php');

        preg_match_all("/self::ACTION\\s*\\.\\s*'([^']+)'/", $registry, $matches);

        $classes = array_map(
            fn(string $suffix): string => 'HiEvents\\Http\\Actions\\' . stripcslashes($suffix),
            $matches[1],
        );

        $classes = array_values(array_unique($classes));
        sort($classes);

        return $classes;
    }
}
