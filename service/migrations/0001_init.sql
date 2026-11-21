-- Daily Pass initial schema.
-- Applied lexically by the entrypoint. Every statement is IF NOT EXISTS so a
-- re-run against an already-migrated database is a no-op.

-- subscriberId is an opaque alias: never parsed as a phone number.
CREATE TABLE IF NOT EXISTS subscribers (
    id TEXT PRIMARY KEY,
    status TEXT NOT NULL,                       -- ACTIVE | INACTIVE | SUSPENDED
    last_charge_error TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- One row per (subscriber, billing day). The composite UNIQUE constraint is the
-- database-level charge-once guarantee; the UNIQUE on external_trx_id is a second
-- line of defence (a logical retry must reuse the same id, never mint a new one).
CREATE TABLE IF NOT EXISTS charge_attempts (
    id BIGSERIAL PRIMARY KEY,
    subscriber_id TEXT NOT NULL REFERENCES subscribers(id),
    billing_day DATE NOT NULL,
    external_trx_id TEXT NOT NULL,
    amount TEXT NOT NULL,                       -- 2-dp string, e.g. '5.00'
    currency TEXT NOT NULL,                     -- e.g. 'XTS'
    status TEXT NOT NULL,                       -- pending | charged | failed_transient | failed_permanent | already_charged
    reference_id TEXT NULL,
    internal_trx_id TEXT NULL,
    platform_status_code TEXT NULL,
    platform_status_detail TEXT NULL,
    attempt_count INT NOT NULL DEFAULT 0,
    next_retry_at TIMESTAMPTZ NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    CONSTRAINT charge_attempts_subscriber_billing_day_key UNIQUE (subscriber_id, billing_day),
    CONSTRAINT charge_attempts_external_trx_id_key UNIQUE (external_trx_id)
);

-- Webhook idempotency: event_key = sha256(applicationId|subscriberId|status|timeStamp|version)
CREATE TABLE IF NOT EXISTS processed_webhook_events (
    event_key TEXT PRIMARY KEY,
    payload JSONB NOT NULL,
    processed_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS sms_log (
    id BIGSERIAL PRIMARY KEY,
    direction TEXT NOT NULL,                    -- MT | MO
    subscriber_id TEXT NOT NULL,
    message TEXT NOT NULL,
    platform_status_code TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
