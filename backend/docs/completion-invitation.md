# Completion invitation — local, default-off candidate

This replaces the public copy/paste-code journey with one completion invitation. It does not import evidence, send automatically on deployment, create another waiver engine, or prove identity/guardianship. The committed historical cohort is already 148 rows: **do not reimport**.

## Journey and boundaries

1. The existing synchronous request service issues a challenge only to the resolved immutable checkout anchor or historical delivered-receipt mailbox. For historical work this is the sealed evidence mailbox, never the editable receipt address. The invitation freezes the exact active sibling identity/name/public-ticket snapshot and provenance commitment at issuance. Only its SHA-256 digest is stored in the challenge table.
2. The email carries a fragment capability. GET serves only a shell; opening an email, GET, or automatic same-origin POST exchange does not verify a signer, consume the challenge, bind assignments, provision packets, or send another email. The page scrubs the fragment and exchanges it into a Secure/HttpOnly/SameSite=Strict, order-path-scoped Laravel-encrypted cookie. No OTP input or extra inbox-verification screen exists. `verified_at` is not populated at issuance.
3. The existing explicit all-attendee form POST commits adult-self/guardian choices plus one bridge outbox record atomically. A unique consumed challenge permits only exact replay, not changed choices. The browser-delivery marker is persisted on the assignments atomically, so later challenge retention can never turn an outbox retry into a second email. Expiry, revocation, paid/current order, exact siblings, Portal create-only history, and cancellation guards remain. Refresh/lost-response recovery uses the committed assignments; corrected assignments cannot be borrowed by an old invitation.
4. After confirmation, the existing Native bridge provisions the existing canonical Portal packets. `completion_invitation` creates terminal **cancelled** initial-email intents with a distinct browser-handoff marker, never fake `sent` records. The authenticated exact-order/all-assignment handoff revalidates Native and derives the original stored opaque Portal tokens. It never replaces packets or completed records.
5. Portal links also use fragments. A short, domain-separated AES-GCM cookie envelope uses the existing versioned derivation key map; it is not a new login/role/identity system. A non-bearer token-hash handle scopes each cookie path so sibling tabs cannot overwrite one another. Every read/save/complete still resolves the original Portal token and Native authority. Cookie lifetime is at most 24h; original token/source/deadline/revocation checks always apply. Canonical forms, signature evidence and completions remain Portal-owned.
6. Browser handoff timestamps substitute only for initial-delivery time in existing 7d/48h/24h/8h followup eligibility and its minimum gap. Cadence, late-stage suppression, completion cancellation and default-off scheduling remain. Followups use the fragment route when the new Portal flag is enabled. No followup or initial Portal email is sent by choosing signers.

A forwarded invitation is a bearer capability for this order, in the same security class as a forwarded waiver link. Neither mailbox delivery nor signer selection proves an adult's identity or a guardian's legal authority. Each proper signer still must complete and affirm the waiver/signature.

## Changes and activation prerequisites (NOT performed)

- Native additive migration `2026_10_06_000003_add_completion_invitation_marker.php`.
- Portal separately gated SQL `0029_completion_invitation.sql`, after the existing foundation/runtime/followup prerequisites. The unrelated MCP worktree retains its `drizzle-candidates/0028_season_guide_stable_scope.sql`; it is neither a dependency nor part of this release. The automatic 25-file manifest is unchanged. Apply only explicitly approved 0029 before matching Portal runtime, never a migration-directory glob or replay of 0024/0027; keep flags off until separately approved.
- New flags default false: Native `KAMP_COMPLETION_INVITATION_ENABLED`, Portal `KAMPY_COMPLETION_INVITATION_ENABLED`. Existing intake/historical/bridge/cohort controls still apply independently; new flags alone cannot enable delivery or admission.
- New invitations expire at the earliest of **2026-10-17T20:00:00Z** (the existing event-7 Portal completion deadline), current Native event end, and the historical cohort's `valid_until` when applicable. This permits an email opened hours later and reopening the same unfinished work through the authorized window. Issuance after that bound is refused. No old row is extended/backfilled. Consumption means one assignment commitment, with exact replay only; reopening does not rotate/revoke the existing Portal token or create duplicate work.
- The separate Native encrypted session cookie has a **15-minute** browser and server-enforced expiry; reopen the original email after session expiry. Portal session envelopes remain at most **24 hours**, with original source/token authority rechecked on every operation. Re-exchange is non-consuming and does not invalidate another legitimate tab's cookie. Legacy verification codes retain their **15-minute** TTL and attempt limits. Existing challenge purge remains 24 hours after challenge expiry, with no historical ciphertext/metadata or legal retention change.
- Existing 2026-10-17T20:00:00Z historical ciphertext/authority cutoff and 2027-01-15T20:00:00Z metadata cutoff are unchanged. Keep existing keys and manual retention obligations. No key enrollment, rotation or custody change is implemented here.
- Before any real send, independently verify HTTPS ingress, request-body/cookie redaction, no capability-bearing analytics, and mail-provider link tracking/rewriting disabled. The local tests cannot attest provider or edge logging. Log mail transports are refused for new invitations; test array/fake transports remain supported.
- Preserve the sealed manifest/cohort and keep import, prospective capture and unrelated mail controls OFF. Deployment, migration, rollout configuration, exact canary and real SEND each remain outside this local change and require the parent's effect approval.

## Local checks

From this worktree's `backend`:

```sh
vendor/bin/phpunit tests/Unit/Services/Domain/Registration/RespondentConfirmationTest.php
bash scripts/test-respondent-confirmation.sh
bash scripts/test-respondent-confirmation.sh historical
# Set RESPONDENT_PLAYWRIGHT_MODULE to the owned Portal dependency's playwright/index.mjs.
bash scripts/test-respondent-confirmation.sh browser
bash scripts/test-respondent-confirmation.sh checkout-browser
```

From the paired Portal worktree's `app-shell`, set `KAMP_HISTORICAL_NATIVE_BACKEND` to this `backend` and optionally `INVITATION_PREVIEW_OUTPUT` to an owned local artifact directory, then:

```sh
bash scripts/run-historical-recovery-postgres.sh native-http
npx tsx --test tests/registration-invitation-session.test.mjs
bash scripts/run-historical-recovery-postgres.sh
bash scripts/run-waiver-postgres-integration.sh
npx tsc --noEmit --pretty false
```

The paired check uses disposable PostgreSQL, real HTTP Native/Portal handlers, the existing restricted Portal runtime repository, actual compiled participant components and approved static policy text. Only `.test` identities and loopback transports are used. The older bridge branch accepts two synthetic mails in its loopback sink; the new invitation branch uses one fake-rendered Native mail and **zero Portal delivery attempts**. It includes scanner opening, lost confirmation response, cookie reload, both adult/guardian completions, canonical replay protection and expiry.

## Visual source

The chooser reuses the existing Portal registration CSS/font primitives, not a new color-only theme: `app-shell/src/app/r/[token]/registration.module.css`. The Native order panel remains in the existing Mantine/Card checkout shell. Current Native source peers are `frontend/src/styles/global.scss`, `frontend/src/styles/universityThemes.{ts,json}`, `frontend/src/components/layouts/Checkout/{Checkout.module.scss,CheckoutContent/CheckoutContent.module.scss}`, and `frontend/src/components/common/Card/Card.module.scss`. The older Portal stylesheet's historical `frontend/src/checkout/surface/` lineage is not a current file at Native HEAD; do not claim it is.

Email uses the actual existing registration email layout, with the existing Portal waiver-email CTA geometry. Primary controls compose the existing `btn` geometry with `btnPrimary`; no new geometry or color tokens are introduced. Desktop/mobile previews render real components/templates and approved policy source, with synthetic identities/records. The local inert preview index and exact SHA-256 source/surface manifest accompany the handback; no public preview or email-client/provider rendering is claimed. New order-panel strings have all 16 existing locale catalog entries; standard release build recompiles catalogs.

The Native DM Sans 400/700 assets and `OFL-DMSans.txt` are byte-identical copies of the already tracked Portal `app-shell/public/fonts/kampy-registration/` files. The bundled SIL Open Font License permits embedding/redistribution with its notice; no new font download is needed. The accepted 2026-09-09 university theme contract supersedes the older cream/PT Serif transaction direction for these surfaces.

The recovery browser check captures all four existing reminder stages separately (their current email template/copy is intentionally identical), waits for the actual agreement step before waiver capture, and asserts the local DM Sans face loads and existing primary-button radius applies. The paired HTTP fixture transports fonts as binary, not UTF-8 text. The readiness regression now opens the original email eight hours later, clears both browser cookies after saving, reopens two/three days later to resume each same draft, and denies at the exact event deadline without changing canonical completions. Native PostgreSQL checks also cover earlier historical cutoff, revocation, event cutoff, cancellation after confirmation, exact replay, races and preservation of assignments/outbox. Native unit tests separate cookie expiry from invitation authority and keep legacy OTP expiry unchanged. Recovery artifacts in `simple-invitation-preview/recovery-check/` remain appearance evidence, not current lifetime evidence or fresh source hashes; no appearance changed and no captures/index were overwritten.
