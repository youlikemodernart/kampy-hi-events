<?php

namespace HiEvents\DomainObjects\Enums;

enum Permission: string
{
    use BaseEnum;

    case AUTHENTICATED = 'authenticated';
    case SYSTEM_ADMIN = 'system.admin';

    case ACCOUNT_VIEW = 'account.view';
    case ACCOUNT_MANAGE = 'account.manage';
    case TEAM_MANAGE = 'team.manage';
    case BILLING_MANAGE = 'billing.manage';

    case ORGANIZER_VIEW = 'organizer.view';
    case ORGANIZER_MANAGE = 'organizer.manage';

    case EVENT_VIEW = 'event.view';
    case EVENT_CONTENT_VIEW = 'event.content.view';
    case EVENT_MANAGE = 'event.manage';
    case EVENT_PUBLISH = 'event.publish';
    case EVENT_CONTENT_MANAGE = 'event.content.manage';

    case ATTENDEES_VIEW = 'attendees.view';
    case ATTENDEES_MANAGE = 'attendees.manage';

    case ORDERS_VIEW = 'orders.view';
    case ORDERS_MANAGE = 'orders.manage';
    case ORDERS_REFUND = 'orders.refund';

    case REPORTS_VIEW = 'reports.view';
    case REPORTS_EXPORT = 'reports.export';

    case MESSAGES_MANAGE = 'messages.manage';
    case INTEGRATIONS_MANAGE = 'integrations.manage';
    case CHECK_IN_MANAGE = 'check_in.manage';
}
