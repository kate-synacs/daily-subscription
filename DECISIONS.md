# Decisions

## externalTrxId
TBD — fill after implementing the charge job.

## Charge-once enforcement
TBD — describe the UNIQUE index and the transaction boundary.

## Retry policy
TBD — bounded retries, backoff, same externalTrxId.

## Opt-out vs charge race
TBD — describe SELECT ... FOR UPDATE and the status re-check.

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
