# Historical receipt recovery (local candidate, off by default)

`historical_receipt_v1` means control of a purchase-window **receipt mailbox**. It is not checkout ownership, globally first dispatch, guardianship, consent, or waiver completion. No checkout anchors are backfilled. The existing legal form, event cutoff and reminder cadence are unchanged.

## One bounded import

`registration:import-historical-receipts <private-bundle.json>` is offline dry-run by default. It prints only count/digest. `--commit` additionally requires `historical-receipt-recovery.import_enabled`, the exact configured manifest digest, configured versioned encryption/integrity keys, exact Portal absence, and fresh locked native eligibility. It stores evidence only; no assignments, outbox, mail, providers or enrollment. Never run it against real evidence under the current NO SEND/local-only hold.

The bundle is `{manifest, rows}`. `tests/Support/HistoricalReceiptFixture.php` is the executable **synthetic-only** example. The manifest freezes exact admitted IDs, reconstructed IDs, the separately quarantined ambiguous association and its commitment, the original ordered aggregate, per-order keyed complete-evidence commitments, and bounded source/time/retention scope. Every reconstructed row is validated before the subset is used; no replacement members or earliest-receipt selection. The original aggregate is continuity evidence only, not recipient authority. The validator deliberately holds any multi-candidate receipt search for review rather than guessing.

Future evidence acquisition is not implemented here: no Stripe/Postmark adapter or scanner was added. An authorized acquisition must supply complete bounded exact-object envelopes, not old helper booleans or substring matches. The trusted frozen manifest's source bindings and continuity commitments must correspond to the approved source observation. Dry-run does not assert current native or Portal eligibility; commit performs those reads.

Only minimal derived evidence is encrypted with versioned AES-256-GCM and committed with a distinct versioned HMAC key. No raw body, capability URL, billing contact or provider JSON is persisted. Sealed membership/evidence cannot be changed, appended or deleted by ordinary SQL. `purge()` expires challenges then removes ciphertext after the deadline; unique order/receipt tombstones remain to prevent reimport. Revocation is one-way. No purge scheduler or runtime role/configuration activation was added.

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
