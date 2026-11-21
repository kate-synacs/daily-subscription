# Decisions

## externalTrxId
- `hash('sha256', $subscriberId . ':' . $billingDay)` with `$billingDay = YYYY-MM-DD` UTC (`ChargeCalculator::externalTrxId()`). Deterministic by construction: identical input always yields the same logical charge key, across re-runs, retries and process restarts; a different day yields a different key. Nothing random/time-based is used, so a retry can never accidentally create a second charge.

## Charge-once enforcement
- `UNIQUE (subscriber_id, billing_day)` is the real guarantee. `INSERT ... ON CONFLICT (subscriber_id, billing_day) DO NOTHING RETURNING id` gives a durable `pending` row or NULL; NULL means a row already exists today. The Platform is only called after that row is COMMITted, and the `FOR UPDATE` subscriber lock is released before the HTTP call. Every finalizing UPDATE has `AND status='pending'`; 0 rows affected is `race_lost` and no receipt SMS is sent.
- A conflict on a still-`pending` row is crash recovery, not a duplicate charge: the stored `external_trx_id` is reused verbatim (the Platform answers `E1337`). A conflict on any terminal row is skipped without a Platform call.
- Billing day is supplied by the injected `App\Clock\Clock`; the HTTP endpoint takes no date parameter. `SystemClock` is production, `FrozenClock` lives in `tests/Support` for deterministic tests.

## Retry policy
- `S1000` terminal-ok → mark charged, store referenceId, send receipt; `E1337` terminal-ok → mark `already_charged`, no retry, no receipt (we cannot know the original referenceId); `E1308` terminal-fail → `status='SUSPENDED'`, no retry; `E1326` and `E1318` transient → `failed_transient` with attempt_count+1 and a scheduled `next_retry_at`, subscriber stays ACTIVE with `last_charge_error`.
- Backoff is persistent in `charge_attempts`: current count 0 → +15 minutes, 1 → +1 hour, count >= 2 → no further schedule (max 3 attempts total). Retries reuse the SAME `externalTrxId`. Due attempts are now executed by `POST /jobs/daily-charge` (see Iteration 7a below); after the 3rd failure the subscriber stays ACTIVE with `last_charge_error` and no further retry is scheduled.

## Iteration 6 (charge core)
- Currency is read from the existing `CURRENCY` env var (not `DAILY_CURRENCY`), per clarification; the receipt message interpolates `amount()` and `currency()` from config, so it stays consistent with the configured daily fee.
- The `+4 hours` backoff slot in the instruction is unreachable under the explicit `attemptCount >= 2` stop rule; I followed the explicit rule (3 attempts total: 15m, then 1h, then stop) and flagged this in the report.
- The Platform call is a real second debit request on crash recovery (the mock's `auto-dup` answers `E1337`), so the debit log has two entries with the same `externalTrxId`; it is not a second charge and produces no second receipt.

## Iteration 7a (retry execution)
- Retry execution strategy: due retries are processed inside the same `POST /jobs/daily-charge` endpoint. The job selects `failed_transient` rows with `next_retry_at <= now()`, re-arms them to `pending` in a short transaction, and calls `ChargeService::executeRetry()`, which reuses the stored attempt row, `attempt_count` and `external_trx_id` and applies the identical classification helpers as a new charge.
- Recovery of pending attempts: re-sends the SAME `externalTrxId` (relies on platform idempotency / `E1337`) via `executeRecovery()`; it never mints a new id. Terminal conflicts are not replayed, and a recovered `S1000` still goes through the same receipt path.
- After a maxed-out transient retry (attempt_count 3) the attempt stays `failed_transient` with NULL `next_retry_at` and the subscriber stays ACTIVE, per your clarification; this keeps the next billing day a fresh charge.

## Opt-out vs charge race
- Opt-out race: `SELECT ... FOR UPDATE` on the subscriber plus the `status = 'ACTIVE'` re-check inside that lock guarantees STOP wins over a concurrent charge. Whichever transaction acquires the row first decides; a charge that obtains the lock after STOP sees INACTIVE and skips without calling the Platform.
- When opting out, pending retries have `next_retry_at` set to NULL (and `failed_transient` rows stay non-due), so a stopped subscriber can never be revived by the retry phase — which also re-checks ACTIVE under the row lock.
- Opt-out itself is idempotent: an already-INACTIVE subscriber returns without a second Platform `action:"0"` or goodbye SMS; a Platform failure never marks the subscriber INACTIVE.

## Skipped / known gaps
TBD — be honest.

## Iteration 1 (skeleton)
- **Opt-in request field (clarification):** use `subscriberId` (not `msisdn`) in `POST /subscribers/opt-in` request bodies for consistency with platform contract (`docs/ASSIGNMENT.md` §A) and persisted key. Boundary accepts `{"subscriberId":"tel:..."}` validated with `^tel:\d+$` and treated as an opaque alias (never parsed as a phone number); demo value may equal an MSISDN but code must not assume that.
- **Entrypoint wait-for-db/migrations:** both app entrypoints use retry loops (no fixed sleep guess) to wait for readiness; `service` waits for Postgres via PDO connection attempts, applies `service/migrations/*.sql` in lexical order idempotently, then starts `php -S` (single command brings stack up).

## Iteration 2 (schema)
- `UNIQUE (subscriber_id, billing_day)` on `charge_attempts` is the charge-once guarantee: one row per subscriber per UTC billing day, enforced in Postgres rather than by a PHP pre-check. A `23505` on insert means "already attempted today" and is a no-op, including across restarts and job re-runs.
- `external_trx_id` is also `UNIQUE` as a second line of defence: the Platform's own idempotency key must map to exactly one of our rows, so a crash/retry can never create a second charge identity for the same logical debit.
- No separate migrations-tracking table: each `*.sql` file is applied lexically inside its own transaction and uses `IF NOT EXISTS`, so re-running on a migrated DB is a no-op and there is no tracking state to drift out of sync with the schema at this scope.

## Iteration 3 (platform-mock)
- The mock is stateful (`/tmp/mock-state.json`) so charge-once and duplicate-`E1337` behaviour can be driven and asserted from outside: `auto-dup` records seen `externalTrxId`s, and `_log/debits` exposes every call for the finance/test view.
- Admin endpoints use `/_control` and `/_log` prefixes to make it obvious they are demo controls, not part of the Carrier Platform contract; platform-contract routes always return HTTP 200 with `statusCode`/`statusDetail` even on invalid input.
- Invalid `amount` is rejected with `E1308` + `statusDetail: "Invalid amount"` rather than 4xx because the Platform contract replies in-band via `statusCode`; this is a mock-only rejection and is never confused with the service's real `E1308` (permanent charging error) handling.

## Iteration 4 (opt-in/status)
- The `sendSubscription` HTTP call is deliberately outside the `SELECT ... FOR UPDATE` transaction: the lock is held only to decide "already active?" and to ensure the row exists, then committed. Holding a Postgres row lock across a 5s Platform call would serialise all subscribers and risk lock timeouts; a PlatformException therefore leaves no ACTIVE row.
- ~~The provisional row is inserted as `INACTIVE`, not `ACTIVE`~~ — superseded by the follow-up below: the provisional insert was replaced by a post-`S1000` upsert so `status` and `platform_registered_at` are written together. `insertOrIgnoreActive()` remains for later webhook re-registration.
- An already-ACTIVE subscriber returns `already_active` without any Platform call, so no duplicate subscription and no second welcome SMS. `opt_in_platform_error_maps_to_502` is skipped: the mock has no forced-error control for `/subscription/send` (only for `/caas/direct/debit`), so the 502 mapping is wired but not yet exercised end-to-end.
- Follow-up: `already_active` now requires `status='ACTIVE'` **and** `platform_registered_at IS NOT NULL`, tracked by migration `0002_opt_in_tracking.sql`. An ACTIVE row with a NULL timestamp is a half-done opt-in and is re-registered instead of being assumed complete.
- Follow-up: the Platform call stays outside the `FOR UPDATE` transaction; the lock is used only to read state, then committed. The state-changing upsert runs in a second short transaction that sets `status='ACTIVE'` and `platform_registered_at=now()` together, so neither can be persisted without the Platform's `S1000`.
- Follow-up: the mock now records every `/subscription/send` at `GET /_log/subscriptions`, which makes "called exactly once" externally provable for opt-in and for the half-done recovery path; `/_control/reset` clears it.

## Iteration 5 (webhook)
- `event_key = sha256(applicationId|subscriberId|status|timeStamp|version)`; `timeStamp` is included so two genuinely distinct notifications for the same subscriber/status (e.g. re-registration days apart) are not collapsed, while byte-identical redeliveries hit the `processed_webhook_events` PK and are skipped by `ON CONFLICT DO NOTHING`.
- The webhook always replies HTTP 200 `S1000`, including on malformed input or internal errors (logged, swallowed): a 5xx makes the Platform retry indefinitely, whereas `S1000` is the contract's acknowledgement even for a no-op.
- The webhook path never calls `/caas/direct/debit`: it only deduplicates and adjusts subscription state. Charging is exclusively the daily job's responsibility, so a duplicate delivery can never cause a charge.
