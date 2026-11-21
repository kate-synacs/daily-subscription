-- Tracks whether the Platform has actually confirmed registration. An ACTIVE
-- row with platform_registered_at NULL is a half-done opt-in and must be
-- recovered (re-register + welcome) instead of being treated as already active.
ALTER TABLE subscribers
    ADD COLUMN IF NOT EXISTS platform_registered_at TIMESTAMPTZ NULL;
