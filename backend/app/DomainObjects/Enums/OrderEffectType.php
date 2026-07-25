<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum OrderEffectType: string
{
    case STATISTICS = 'STATISTICS';
    case EMAIL = 'EMAIL';
    case WEBHOOK = 'WEBHOOK';
}
