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
