<?php

return [
    'enabled' => env('KAMP_RESPONDENT_CONFIRMATION_ENABLED', false),
    'origin' => env('KAMP_RESPONDENT_CONFIRMATION_ORIGIN', 'https://tickets.kamplove.org'),
    'retention_hours' => (int) env('KAMP_RESPONDENT_CONFIRMATION_RETENTION_HOURS', 24),
    'purge_schedule_enabled' => env('KAMP_RESPONDENT_CONFIRMATION_PURGE_SCHEDULE_ENABLED', false),
    'ttl_minutes' => 15,
    'cooldown_seconds' => 60,
    'requests_per_hour' => 5,
    'attempts' => 5,
];
