<?php

declare(strict_types=1);

namespace Tests\Unit\DomainObjects\Enums;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\Enums\Role;
use PHPUnit\Framework\TestCase;

class RolePermissionTest extends TestCase
{
    public function test_assignable_roles_include_ptw_staff_presets_but_exclude_superadmin(): void
    {
        $this->assertSame([
            Role::ADMIN->value,
            Role::ORGANIZER->value,
            Role::FINANCE->value,
            Role::REPORTING->value,
            Role::CHECK_IN->value,
        ], Role::getAssignableRoles());
    }

    public function test_finance_can_handle_orders_and_reports_without_account_or_event_configuration_power(): void
    {
        $role = Role::FINANCE;

        $this->assertTrue($role->hasPermission(Permission::ORDERS_VIEW));
        $this->assertTrue($role->hasPermission(Permission::ORDERS_MANAGE));
        $this->assertTrue($role->hasPermission(Permission::ORDERS_REFUND));
        $this->assertTrue($role->hasPermission(Permission::REPORTS_EXPORT));

        $this->assertTrue($role->hasPermission(Permission::EVENT_CONTENT_VIEW));

        $this->assertFalse($role->hasPermission(Permission::TEAM_MANAGE));
        $this->assertFalse($role->hasPermission(Permission::BILLING_MANAGE));
        $this->assertFalse($role->hasPermission(Permission::EVENT_CONTENT_MANAGE));
        $this->assertFalse($role->hasPermission(Permission::INTEGRATIONS_MANAGE));
    }

    public function test_reporting_is_read_only_but_can_export_reports(): void
    {
        $role = Role::REPORTING;

        $this->assertTrue($role->hasPermission(Permission::EVENT_VIEW));
        $this->assertTrue($role->hasPermission(Permission::EVENT_CONTENT_VIEW));
        $this->assertTrue($role->hasPermission(Permission::ATTENDEES_VIEW));
        $this->assertTrue($role->hasPermission(Permission::ORDERS_VIEW));
        $this->assertTrue($role->hasPermission(Permission::REPORTS_VIEW));
        $this->assertTrue($role->hasPermission(Permission::REPORTS_EXPORT));

        $this->assertFalse($role->hasPermission(Permission::ORDERS_MANAGE));
        $this->assertFalse($role->hasPermission(Permission::ORDERS_REFUND));
        $this->assertFalse($role->hasPermission(Permission::EVENT_CONTENT_MANAGE));
        $this->assertFalse($role->hasPermission(Permission::MESSAGES_MANAGE));
    }

    public function test_check_in_staff_can_only_reach_event_overview_and_check_in_operations(): void
    {
        $role = Role::CHECK_IN;

        $this->assertTrue($role->hasPermission(Permission::EVENT_VIEW));
        $this->assertTrue($role->hasPermission(Permission::CHECK_IN_MANAGE));

        $this->assertFalse($role->hasPermission(Permission::EVENT_CONTENT_VIEW));
        $this->assertFalse($role->hasPermission(Permission::ATTENDEES_VIEW));
        $this->assertFalse($role->hasPermission(Permission::ORDERS_VIEW));
        $this->assertFalse($role->hasPermission(Permission::REPORTS_VIEW));
        $this->assertFalse($role->hasPermission(Permission::EVENT_CONTENT_MANAGE));
    }

    public function test_admin_has_all_non_system_permissions_and_superadmin_has_every_permission(): void
    {
        $this->assertFalse(Role::ADMIN->hasPermission(Permission::SYSTEM_ADMIN));
        $this->assertTrue(Role::SUPERADMIN->hasPermission(Permission::SYSTEM_ADMIN));

        foreach (Permission::cases() as $permission) {
            $this->assertTrue(Role::SUPERADMIN->hasPermission($permission));

            if ($permission !== Permission::SYSTEM_ADMIN) {
                $this->assertTrue(Role::ADMIN->hasPermission($permission));
            }
        }
    }
}
