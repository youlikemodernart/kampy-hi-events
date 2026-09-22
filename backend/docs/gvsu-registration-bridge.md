# GVSU registration bridge

Status: disabled by default. This document names configuration keys only; it contains no values.

The bridge is limited to Hi.Events event `7`. It creates no participant email from Hi.Events. Portal owns Postmark delivery and its outcome audit.

## Required activation configuration

- `KAMP_GVSU_REGISTRATION_BRIDGE_MODE=disabled|dark|canary|live` (defaults to `disabled`)
- `KAMP_GVSU_REGISTRATION_BRIDGE_CANARY_ORDER_IDS`: required exact integer order allowlist in canary mode. This is the same selected paid order identity used by Portal; assignment-level selectors are intentionally unsupported.
- `KAMP_GVSU_REGISTRATION_PORTAL_HOST`: exact DNS host only, no scheme, port, path, query, or fragment. Requests are fixed to HTTPS port 443 and the two internal Portal paths.
- `KAMP_GVSU_REGISTRATION_OUTGOING_BEARER`: 256-bit random URL-safe bearer for Hi.Events to Portal.
- `KAMP_GVSU_REGISTRATION_INCOMING_CURRENT_SHA256`: SHA-256 digest of Portal to Hi.Events bearer.
- `KAMP_GVSU_REGISTRATION_INCOMING_PRIOR_SHA256`: optional prior digest during replacement only.
- `KAMP_GVSU_REGISTRATION_EMAIL_HMAC_CURRENT_KEY`: current Hi.Events-only HMAC key for normalized delivery email fingerprints.
- `KAMP_GVSU_REGISTRATION_EMAIL_HMAC_PRIOR_KEY`: optional prior HMAC key during replacement only.

Use independent random 256-bit values for the two direction-specific bearers. Store only inbound credential digests. Rotate by accepting current and prior inbound digests for a bounded overlap, then remove the prior digest. Rotate email HMAC keys the same way; stored fingerprints must verify with either key before retiring the prior key.

## Effects and recovery

Only enabled event-7 completed and `PAYMENT_RECEIVED` orders can enqueue the existing order-effect worker; disabled mode, every other event, and unselected canary orders create no bridge-only outbox row. Every active attendee in an admitted order must first have an explicit operator-confirmed assignment containing both attendee context and respondent/signer identity. The order effect remains pending and unprovisioned while any assignment is missing; it never derives a respondent route, respondent display name, guardian relationship, or delivery destination from attendee fields. An attendee display name is context for the token holder only and never determines respondent identity, route, or capacity.

Use `php artisan gvsu-registration:bind-respondent <exact-order-id> <exact-attendee-id> --attendee-display-name=<confirmed-attendee-name> --respondent-name=<confirmed-respondent-name> --route=adult|guardian --destination=<confirmed-destination> [--guardian-relationship-reference=<opaque-reference>]` to bind one exact event-seven attendee. The command writes the independently confirmed attendee display name, respondent name, route, optional relationship reference, encrypted destination, keyed identity/context digest, and timestamps. The same confirmed values are idempotent. A changed attendee display name or respondent binding increments the assignment revision, replaces its opaque assignment/respondent IDs, records the old assignment ID and link-replacement timestamp, and triggers the normal exact-order path. Its output intentionally contains no participant data or registration link.

In canary mode the worker requires the exact allowlisted order before reading attendees, then creates one stable batch for every active, non-deleted attendee in that selected order. It never selects a subset of an order. Hi.Events persists a destination ciphertext and HMAC; it sends the confirmed display name only in the authenticated Portal provision request. Portal must return `respondent_identity_digest_sha256` during reverse current-state revalidation and must present the stored `attendee_display_name` for an exact source comparison. The response never contains a display name, guardian reference, or destination; the request never contains respondent display name or guardian reference. Portal success is exactly `{"classification":"accepted"}`. Timeout, connection failure, redirect, or unexpected response is `unknown`; a replay reuses the same batch only when all immutable bindings and either current or prior HMAC key match. Attempted/unknown status is recorded in the owning transaction before the outbound call; delivered batches are not sent again. Do not resend or send registration mail from Hi.Events.

The reverse current-state endpoint is `POST /internal/gvsu-registration/current-state`, rate-limited and bearer-protected. The Portal request supplies the designated delivery email only to prove its stored HMAC binding; the response returns only current/blocked status, server observation time, and a snapshot digest. It never returns designated email or other participant fields.

At check-in, one shared clearance boundary is invoked by both authenticated attendee check-in and public check-in-list creation before any check-in record or mark-paid transition. Event 7 blocks when disabled, Portal is unavailable, clearance is ineligible, the order is no longer paid/current, the attendee is inactive/deleted, or any binding differs. Non-event-7 check-in behavior remains unchanged.

For a paid order created before it is allowlisted, a local authenticated operator may run `php artisan gvsu-registration:reconcile-order <exact-order-id>` only after adding that exact ID to the canary allowlist. The command accepts no broad selector or scan, rechecks canary mode and the allowlist, and applies the normal paid/current and replay-safe bridge path.

## Final local security-repair evidence (2026-09-22)

- The public check-in endpoint has local HTTP coverage for incomplete, revoked, refunded, inactive, and source-unavailable clearance denials (409), plus clearance acceptance (200). Both public and authenticated check-in writers invoke `GvsuRegistrationCheckInClearanceService` before a check-in write; the public mark-paid variant is downstream of that boundary.
- Canary eligibility is now exact-order-only. An unselected order creates no bridge outbox work and the bridge evaluates the exact selected order before loading its attendees. All active attendees in a selected order remain one replay-safe batch; delivered batches are not sent again.
- Local commands passed: focused registration/order/public-route tests (25 tests / 93 assertions); Pint on nine repair paths; public check-in route listing; local command registration; `git diff --check`. PHP 8.5 emitted existing vendor deprecation notices. Dependencies were installed only for this check and must not be retained as an artifact.

Residual live gates: no production configuration, exact order allowlist, provider request, migration, deployment, email, or check-in was performed. Managed database/migration proof, source persistence, immutable release artifacts, Portal capability activation, credential custody, physical accessibility, Postmark, and paid/refunded canaries remain separately blocked.
