---
name: webhook-idempotency
description: Rules for handling Carrier Platform subscription notification webhooks idempotently. Use when implementing or reviewing /webhook/subscription.
---

# Webhook idempotency

- Table `processed_webhook_events`:
  - `event_key TEXT PRIMARY KEY`
  - `payload JSONB NOT NULL`
  - `processed_at TIMESTAMPTZ NOT NULL DEFAULT now()`
- `event_key = hash('sha256', implode('|', [$applicationId, $subscriberId, $status, $timeStamp, $version]))`.
- Handler:
  1. `INSERT INTO processed_webhook_events (event_key, payload) VALUES (?, ?::jsonb) ON CONFLICT (event_key) DO NOTHING`.
  2. If 0 rows affected → duplicate: return `{"statusCode":"S1000","statusDetail":"Success"}` immediately.
  3. Else process: `REGISTERED` → ensure subscriber ACTIVE; `UNREGISTERED` → ensure INACTIVE; `PENDING`/`CHARGE` → log only (no money side-effect).
- Webhook never calls `/caas/direct/debit`. Charging is the daily job's job.
- Always reply `S1000` to the Platform (even on internal error — log it, don't 5xx; Platform may retry forever).