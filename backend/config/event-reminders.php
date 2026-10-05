<?php

return [
    'enabled' => env('KAMP_EVENT_REMINDERS_ENABLED', false) === true,
    'policy_version' => 'kamp-attendee-reminders-v1',
    'content_version' => 'kamp-attendee-reminder-content-v1',
    'theme_projection_version' => 'universityThemes.json',
    'offsets' => [
        '7-days' => -10080,
        '24-hours' => -1440,
    ],
    'late_grace_minutes' => 360,
    'recovery_stale_minutes' => 15,
    // Require an explicit JSON list of positive integer event IDs. Invalid input selects no events.
    'event_allowlist' => (static function (): array {
        $ids = json_decode((string) env('KAMP_EVENT_REMINDERS_EVENT_ALLOWLIST', '[]'), true);
        if (! is_array($ids) || ! array_is_list($ids)) {
            return [];
        }
        foreach ($ids as $id) {
            if (! is_int($id) || $id <= 0) {
                return [];
            }
        }

        return array_values(array_unique($ids));
    })(),
    'sender' => 'tickets@kamplove.org',
    'reply_to' => env('KAMP_EVENT_REMINDERS_REPLY_TO'),
    // Registered-attendee logistics only. Configure optional footer facts only when genuine.
    // This is not a marketing subscription or an unsubscribe/suppression mechanism.
    'physical_address' => env('KAMP_EVENT_REMINDERS_PHYSICAL_ADDRESS'),
    'preference_url' => env('KAMP_EVENT_REMINDERS_PREFERENCE_URL'),
];
