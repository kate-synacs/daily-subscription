---
name: money-idempotency
description: Non-negotiable rules for the charge-once guarantee — deterministic externalTrxId, DB-enforced dedupe, transaction boundaries, crash recovery. Use when writing or reviewing any code that calls /caas/direct/debit or computes externalTrxId.
---

# Money idempotency — hard rules

## externalTrxId
- MUST be deterministic: `hash('sha256', $subscriberId . ':' . $billingDay)`.
- `$billingDay` is `YYYY-MM-DD` in UTC.
- NEVER `uniqid()`, `random_bytes()`, `microtime()`, UUID v4.
- Same subscriber + same billing day -> same externalTrxId, forever.
- Different billing day -> different externalTrxId.
- Retries of the same logical charge reuse the same externalTrxId.

## DB enforcement
- Table `charge_attempts`:
  - `id BIGSERIAL PRIMARY KEY`
  - `subscriber_id TEXT NOT NULL`
  - `billing_day DATE NOT NULL`
  - `external_trx_id TEXT NOT NULL`
  - `amount TEXT NOT NULL`  -- 2-dp string, e.g. "5.00"
  - `currency TEXT NOT NULL`
  - `status TEXT NOT NULL`  -- pending | charged | already_charged | failed_transient | failed_permanent
  - `reference_id TEXT NULL`
  - `internal_trx_id TEXT NULL`
  - `platform_status_code TEXT NULL`
  - `platform_status_detail TEXT NULL`
  - `attempt_count INT NOT NULL DEFAULT 0`
  - `next_retry_at TIMESTAMPTZ NULL`
  - `created_at TIMESTAMPTZ NOT NULL DEFAULT now()`
  - `updated_at TIMESTAMPTZ NOT NULL DEFAULT now()`
  - `UNIQUE (subscriber_id, billing_day)`
  - `UNIQUE (external_trx_id)`
- Insert the attempt row **before** calling the Platform, inside the same transaction as the `FOR UPDATE` lock.
- If unique_violation fires -> another attempt already exists for this day: skip, do not call Platform.

## Transaction boundaries
- Lock subscriber: `SELECT ... FROM subscribers WHERE id = ? FOR UPDATE`.
- Re-check `status = 'ACTIVE'` inside the lock (guards opt-out race).
- Insert `charge_attempts` row (status=`pending`).
- COMMIT the transaction. **Then** call Platform /caas/direct/debit with the external_trx_id. Do not hold the DB lock across the HTTP call.
- On response: UPDATE attempt with status/reference, UPDATE subscriber, send SMS — all in one short transaction.

## Crash safety
- If the process dies after the Platform call but before the DB update: on restart the attempt row is `pending` with a known `external_trx_id`. Re-query via that id (or treat `E1337` as already-charged). Never mint a new id.
- Updates to `charge_attempts` must include `AND status='pending'` in the WHERE clause. If the UPDATE affects 0 rows, another process already finalized this attempt — treat as `race_lost` and do not send SMS.

## Logging
- Log only: requestId, statusCode, externalTrxId, referenceId, attemptId.
- NEVER log `password`, `applicationId`, or full `subscriberId`.
