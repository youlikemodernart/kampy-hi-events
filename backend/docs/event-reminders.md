# Operational attendee reminders

These reminders provide event logistics to existing ACTIVE attendees, not promotion, fundraising, or marketing subscriptions. Marketing opt-in is not repurposed as an operational reminder switch. Cancellation and current attendee eligibility still control delivery. This classification is limited to the current reminder copy; adding promotional content requires a separate review.

## Runtime configuration

Set these through the deployment environment, then deploy/restart using the normal application configuration lifecycle. Existing queue workers must receive the same configuration as the scheduler. No configuration endpoint or direct dispatch is provided.

| Variable | Default | Meaning |
| --- | --- | --- |
| `KAMP_EVENT_REMINDERS_ENABLED` | `false` | Only Laravel's boolean `true` enables reconciliation and handoff. |
| `KAMP_EVENT_REMINDERS_EVENT_ALLOWLIST` | `[]` | JSON list of positive integer event IDs, for example `[900001]`. Empty, malformed, non-list, or mixed-type input selects no events. Never use a real event as a test audience. |
| `KAMP_EVENT_REMINDERS_REPLY_TO` | unset | Required, genuine reply destination. |
| `KAMP_EVENT_REMINDERS_PHYSICAL_ADDRESS` | unset | Optional genuine postal footer. |
| `KAMP_EVENT_REMINDERS_PREFERENCE_URL` | unset | Optional genuine preference destination. This setting does not implement unsubscribe or suppression. Do not label an unrelated URL as email preferences. |

Sender remains `tickets@kamplove.org`; policy/version, 7-day and 24-hour offsets, six-hour late grace, one-attempt recipient claims, and UNKNOWN reconciliation are unchanged. Event support email and required event facts remain mandatory. Missing optional footer facts are omitted in both HTML and plain text; no postal address or unsubscribe claim is invented.

Before activation, read back the exact source/deployment, enabled state, explicit event allowlist, reply destination, current audience, and actual Postmark API transport. An enabled flag alone authorizes no send. Stop on audience/config drift or uncertain provider outcome; never blindly retry UNKNOWN. Disable through the same environment binding and verify effective disabled state before archiving the isolated event. Retain the audit ledger.
