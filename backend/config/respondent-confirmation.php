<?php

return [
    'invitation_enabled' => env('KAMP_COMPLETION_INVITATION_ENABLED', false),
    'enabled' => env('KAMP_RESPONDENT_CONFIRMATION_ENABLED', false),
    'capture_enabled' => env('KAMP_RESPONDENT_CONFIRMATION_CAPTURE_ENABLED', false),
    'origin' => env('KAMP_RESPONDENT_CONFIRMATION_ORIGIN', 'https://tickets.kamplove.org'),
    'retention_hours' => (int) env('KAMP_RESPONDENT_CONFIRMATION_RETENTION_HOURS', 24),
    'purge_schedule_enabled' => env('KAMP_RESPONDENT_CONFIRMATION_PURGE_SCHEDULE_ENABLED', false),
    'ttl_minutes' => 15,
    // Event 7's published Portal completion deadline; not a retention or authority extension.
    'invitation_deadline' => '2026-10-17T20:00:00Z',
    'invitation_session_minutes' => 15,
    'cooldown_seconds' => 60,
    'requests_per_hour' => 5,
    'attempts' => 5,
];
