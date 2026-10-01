<?php

return [
    'enabled' => false,
    'policy_version' => 'kamp-attendee-reminders-v1',
    'content_version' => 'kamp-attendee-reminder-content-v1',
    'theme_projection_version' => 'universityThemes.json',
    'offsets' => [
        '7-days' => -10080,
        '24-hours' => -1440,
    ],
    'late_grace_minutes' => 360,
    'event_allowlist' => [7],
    'sender' => 'tickets@kamplove.org',
    // These activation bindings deliberately remain unresolved. Dispatch fails closed.
    'reply_to' => null,
    'physical_address' => null,
    'preference_url' => null,
];
