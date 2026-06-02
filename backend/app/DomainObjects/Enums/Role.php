<?php

namespace HiEvents\DomainObjects\Enums;

enum Role: string
{
    use BaseEnum;

    case SUPERADMIN = 'SUPERADMIN';
    case ADMIN = 'ADMIN';
    case ORGANIZER = 'ORGANIZER';
    case FINANCE = 'FINANCE';
    case REPORTING = 'REPORTING';
    case CHECK_IN = 'CHECK_IN';

    public static function getAssignableRoles(): array
    {
        return [
            self::ADMIN->value,
            self::ORGANIZER->value,
            self::FINANCE->value,
            self::REPORTING->value,
            self::CHECK_IN->value,
        ];
    }

    public function getDisplayName(): string
    {
        return match ($this) {
            self::SUPERADMIN => 'Super Admin',
            self::ADMIN => 'Admin',
            self::ORGANIZER => 'Event Manager',
            self::FINANCE => 'Finance',
            self::REPORTING => 'Reporting',
            self::CHECK_IN => 'Check-in Staff',
        };
    }

    /**
     * @return array<Permission>
     */
    public function getPermissions(): array
    {
        return match ($this) {
            self::SUPERADMIN => Permission::cases(),
            self::ADMIN => array_values(array_filter(
                Permission::cases(),
                fn(Permission $permission) => $permission !== Permission::SYSTEM_ADMIN,
            )),
            self::ORGANIZER => [
                Permission::AUTHENTICATED,
                Permission::ACCOUNT_VIEW,
                Permission::ORGANIZER_VIEW,
                Permission::ORGANIZER_MANAGE,
                Permission::EVENT_VIEW,
                Permission::EVENT_CONTENT_VIEW,
                Permission::EVENT_MANAGE,
                Permission::EVENT_PUBLISH,
                Permission::EVENT_CONTENT_MANAGE,
                Permission::ATTENDEES_VIEW,
                Permission::ATTENDEES_MANAGE,
                Permission::ORDERS_VIEW,
                Permission::ORDERS_MANAGE,
                Permission::ORDERS_REFUND,
                Permission::REPORTS_VIEW,
                Permission::REPORTS_EXPORT,
                Permission::MESSAGES_MANAGE,
                Permission::INTEGRATIONS_MANAGE,
                Permission::CHECK_IN_MANAGE,
            ],
            self::FINANCE => [
                Permission::AUTHENTICATED,
                Permission::ACCOUNT_VIEW,
                Permission::ORGANIZER_VIEW,
                Permission::EVENT_VIEW,
                Permission::EVENT_CONTENT_VIEW,
                Permission::ATTENDEES_VIEW,
                Permission::ORDERS_VIEW,
                Permission::ORDERS_MANAGE,
                Permission::ORDERS_REFUND,
                Permission::REPORTS_VIEW,
                Permission::REPORTS_EXPORT,
            ],
            self::REPORTING => [
                Permission::AUTHENTICATED,
                Permission::ACCOUNT_VIEW,
                Permission::ORGANIZER_VIEW,
                Permission::EVENT_VIEW,
                Permission::EVENT_CONTENT_VIEW,
                Permission::ATTENDEES_VIEW,
                Permission::ORDERS_VIEW,
                Permission::REPORTS_VIEW,
                Permission::REPORTS_EXPORT,
            ],
            self::CHECK_IN => [
                Permission::AUTHENTICATED,
                Permission::ACCOUNT_VIEW,
                Permission::ORGANIZER_VIEW,
                Permission::EVENT_VIEW,
                Permission::CHECK_IN_MANAGE,
            ],
        };
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission, $this->getPermissions(), true);
    }

    public function allowsEventAssignments(): bool
    {
        return in_array($this, [
            self::ORGANIZER,
            self::REPORTING,
            self::CHECK_IN,
        ], true);
    }

    public function requiresEventAssignments(): bool
    {
        return $this->allowsEventAssignments();
    }

    public function hasAccountWideEventAccess(): bool
    {
        return in_array($this, [
            self::SUPERADMIN,
            self::ADMIN,
            self::FINANCE,
        ], true);
    }

    /**
     * @return array<string>
     */
    public function getPermissionValues(): array
    {
        return array_map(
            fn(Permission $permission) => $permission->value,
            $this->getPermissions(),
        );
    }
}
