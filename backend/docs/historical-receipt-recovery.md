# Historical receipt recovery (off by default)

`historical_receipt_v1` means control of a purchase-window **receipt mailbox**. It is not checkout ownership, globally first dispatch, guardianship, consent, or waiver completion. No checkout anchors are backfilled. The existing legal form, event cutoff and reminder cadence are unchanged.

## One bounded import

`registration:import-historical-receipts <private-bundle.json>` is offline dry-run by default. It prints only count/digest. `--commit` additionally requires `historical-receipt-recovery.import_enabled`, the exact configured manifest digest, configured versioned encryption/integrity keys, exact authenticated Portal absence, and fresh locked native eligibility. It stores evidence only; no assignments, outbox, mail, Stripe/Postmark operations or enrollment. Real import requires exact source-read and encrypted-retention authorization independently of customer-send approval.

The bundle is `{manifest, rows}`. `tests/Support/HistoricalReceiptFixture.php` is the executable **synthetic-only** example. The manifest freezes exact admitted IDs, reconstructed IDs, the separately quarantined ambiguous association and its commitment, the original ordered aggregate, per-order keyed complete-evidence commitments, and bounded source/time/retention scope. `reconstructed_associations` contains ordered `{order_id, pi, charge, message_id}` identities for the complete original unique cohort, reproducing its original aggregate before selection. These identities are continuity only, never admission evidence. `excluded_orders` contains exactly its non-admitted IDs, in the same order, with one reason each: `current_ineligible`, `native_history`, `portal_history`, `portal_unknown`, `receipt_ambiguous`, `evidence_invalid`, `evidence_unavailable`, or `source_changed`. The separate original ambiguous association cannot enter that reconstructed cohort or admitted IDs.

`rows` contains **only and all admitted IDs**, sorted exactly as `order_ids`. Every admitted row passes the unchanged strict validator and must match both its original association and its keyed complete-evidence commitment. Missing exclusions, changed associations, extra rows, replacement members or an aggregate mismatch fail closed. An excluded row need not pass admission; no excluded contact/body is needed in the import bundle. The validator still rejects any multi-candidate receipt search, including same-mailbox duplicates. Do not remove nonmatching candidates from a complete search to manufacture a single candidate. The historical304-candidate total does not determine today's admitted or excluded count.

Future evidence acquisition is not implemented here: no Stripe/Postmark adapter or scanner was added. An authorized acquisition must supply complete bounded exact-object envelopes, not old helper booleans or substring matches. The trusted frozen manifest's source bindings and continuity commitments must correspond to the approved source observation. Dry-run does not assert current native or Portal eligibility; commit performs those reads.

Only minimal derived evidence is encrypted with versioned AES-256-GCM and committed with a distinct versioned HMAC key. No raw body, capability URL, billing contact or provider JSON is persisted by the repository. Sealed membership/evidence cannot be changed, appended or deleted by ordinary SQL. `purge()` expires challenges then removes ciphertext after the deadline; unique order/receipt tombstones remain to prevent reimport. Revocation is one-way. No purge scheduler or runtime role/configuration activation was added.

### Disabled-mode import and key custody

The Native historical-absence client and Portal authenticated `historical-preflight` route perform exact event7/order/sibling metadata reads independently of bridge mode and historical activation. The existing inbound bearer is still required; absent/wrong credentials deny before pool construction. Canary mode retains its exact order restriction. No read activates provisioning or delivery. Import can keep Native historical engine, intake, capture, bridge and reminders false, Portal historical false/mode disabled/followups false. Only the Native import flag and exact manifest/key configuration are needed for the evidence commit. Turn import off after the one approved transaction. Enabling intake is a customer-send effect, not an import prerequisite.

Supported runtime variables:

- `KAMP_HISTORICAL_RECEIPT_MANIFEST_DIGEST`: SHA256 of the canonical complete approved manifest.
- `KAMP_HISTORICAL_RECEIPT_ENCRYPTION_KEY_VERSION` / `KAMP_HISTORICAL_RECEIPT_INTEGRITY_KEY_VERSION`: exact selected map versions.
- `KAMP_HISTORICAL_RECEIPT_ENCRYPTION_KEYS` / `KAMP_HISTORICAL_RECEIPT_INTEGRITY_KEYS`: separate secret JSON objects mapping version labels to canonical standard-base64 encoded 32-byte keys. Labels start with a letter and contain only letters, digits, colon, dot, underscore or hyphen, at most 64 characters. Malformed/missing maps fail closed; there is no APP_KEY fallback. Keep referenced old versions until authorized ciphertext/backup expiry; do not rotate or remove them as import cleanup.

Use existing 1password-ops custody and exact no-output runtime injection. Record only canonical item/field references and version labels in the approval receipt, not keys. No real custody item or key is created by this source change. Use separate encryption and integrity secrets, private provider runtime secret settings, and owner-only runtime config caches; no frontend-prefixed variable or source-file secret. Production role/owner permissions and backup lifecycle still require an explicit accepted boundary.

The importer requires a local regular JSON file; it does not decrypt an encrypted bundle itself. Acquisition must keep raw provider payloads in memory. Transport any needed bundle encrypted; decrypt only inside the approved runtime to a mode-0600 file in a verified memory-backed private mode-0700 directory, run dry-run then the exact commit, and unlink in `finally` on success or failure. Do not put real bundles in task directories, shell arguments, logs, Git, ordinary `/tmp`, persisted volumes or backups. If a verified transient runtime is unavailable, stop rather than write plaintext to durable disk.

### Expiry and purge effect packet

There are deliberately no automatic retention defaults. The frozen manifest must name `selected_at`, `valid_until`, `purge_after` and the exact effect approval reference. Authority must expire no later than **2026-10-17T20:00:00Z** (the existing event boundary); code rejects a later boundary. Proposed minimized policy for explicit approval: authority through that boundary (or an earlier approved recovery cutoff), with `purge_after` equal to `valid_until`, no extra contact-retention grace. This is a proposal, not a selected live retention policy or scheduler activation.

Before real admission, the effect receipt must settle those exact UTC timestamps, the operator responsible for invoking and verifying `purge()` at the deadline, the existing backup expiry/restore behavior, and duration/access for anti-reimport tombstones and minimal assignment provenance. Purging primary ciphertext does not erase backups or tombstones. Do not invent a new durable retention period, enable scheduling, delete tombstones, or destroy keys still needed by retained evidence. Freshness is the observed provider/native/preflight state at acquisition and immediate locked commit, not the old 150/149 report; freeze the actual subset and report its new digest/count.

## Existing challenge engine

One resolver distinguishes genuine checkout anchors from sealed historical evidence; dual authority fails closed. Codes bind exact type/ID/commitment/destination. `verify-mailbox` uses the existing attempts/TTL/rate budget and freezes sibling context. Only then are signer choices shown. Final confirmation requires the same valid code, explicit all-sibling choices, current context, and insert-only assignment binding. Exact final replay is a no-op. No address is returned and no code is placed in storage or a URL.

For historical records, exact Portal absence is read before native locks, then native siblings/eligibility are rechecked under lock. Only provenance IDs/commitment accompany bridge work. Expiry, revocation or changed exact scope blocks handoff and source reverse reads. There is no distributed transaction: a later Portal conflict leaves native work pending/unknown for review; it never clears completion, replaces links, or auto-corrects assignments.

## Portal protection

The separate Portal candidate adds the authenticated `historical-preflight` endpoint and a historical create-only branch inside the existing atomic provisioner. Source reverse reads must agree with historical authority. Replacement is forbidden. The serializable transaction first locks packet/provision tables in `SHARE ROW EXCLUSIVE` mode, then checks any same-event order/attendee/ticket history before inserting any sibling. PostgreSQL's automatic writer locks coordinate even ordinary/non-recovery writers; the focused PostgreSQL test observes the actual wait. Every canonical completion has a packet FK, so any packet history is a stricter exclusion than completion status. Exact committed replay remains a no-op. Existing send-time completion/currentness checks remain in force.

## Local checks

- `vendor/bin/phpunit tests/Unit/Services/Domain/Registration/HistoricalReceiptValidatorTest.php tests/Unit/Services/Domain/Registration/RespondentConfirmationTest.php`
- `bash scripts/test-respondent-confirmation.sh historical`
- `bash scripts/test-respondent-confirmation.sh` (affected challenge concurrency tests)
- `RESPONDENT_PLAYWRIGHT_MODULE=<local playwright/index.mjs> bash scripts/test-respondent-confirmation.sh browser`
- Portal: `bash scripts/run-historical-recovery-postgres.sh`

All use fake identities and disposable loopback PostgreSQL/array or mocked transport. Before any production use, deployment-specific least-privilege role/key/backup-retention wiring and real exact evidence admission remain separate live-effect decisions. These local tests do not authorize any real email, enrollment, import, deployment or provider access.
