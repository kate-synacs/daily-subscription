---
name: phpunit-money-tests
description: How to write PHPUnit tests for money-critical paths (charge-once, retry classification, opt-out race) against the platform-mock. Use when adding or fixing tests.
---

# Money-path tests

Required test cases (non-negotiable):
1. `test_daily_job_runs_twice_charges_once` — run job, run again same day, assert exactly one `charge_attempts` row with status `charged`, mock received one debit call.
2. `test_duplicate_webhook_does_not_double_charge` — post same REGISTERED webhook twice, assert one `processed_webhook_events` row, no extra debit.
3. `test_e1326_is_retried_e1308_suspends` — mock returns E1326 → attempt scheduled for retry; mock returns E1308 → subscriber SUSPENDED, no retry.
4. `test_stop_then_charge_skips_subscriber` — MO STOP, then run job, assert no debit call, subscriber INACTIVE.
5. `test_external_trx_id_is_deterministic` — same subscriber+day → same hash; different day → different hash.

Rules:
- Tests hit the real platform-mock container via `PLATFORM_BASE_URL`, not a fake.
- DB is a fresh schema per test class (drop/recreate or `TRUNCATE ... RESTART IDENTITY CASCADE`).
- Never `sleep()` in tests; time is injectable via `App\Clock\Clock` interface (real impl + fake).
- Assert on DB state + mock call log, not on log output.
- Reset mock state between tests via `/_control/reset` (add this endpoint to the mock).