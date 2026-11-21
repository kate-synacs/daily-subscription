# Daily Pass — working agreement

## Source of truth
Full assignment: `docs/ASSIGNMENT.md`. Read it on the first iteration.
This file only adds project-specific constraints. It does NOT replace the assignment.

## Stack (do not change without asking)
- PHP 8.3, `declare(strict_types=1);` in every file
- No framework. FastRoute for routing, PDO for DB, Guzzle for HTTP, Monolog for logs, phpdotenv for config
- PostgreSQL 16
- PHPUnit 11 (no Pest)
- Everything runs via `docker compose up` on a clean machine. No manual steps.
- Two containers: `service`, `platform-mock`, plus `db` (postgres:16-alpine)

## Layout
- `service/public/index.php` — single front controller
- `service/src/` — code: `Http/`, `Service/`, `Repository/`, `Platform/`, `Config/`, `Exception/`
- `service/migrations/NNNN_*.sql` — plain SQL, applied in lexical order by entrypoint
- `platform-mock/public/index.php` — single front controller for the mock
- `docs/ASSIGNMENT.md` — full task text (do not edit)

## Hard rules for the money path
These are non-negotiable. If you deviate, stop and ask.
- `externalTrxId = hash('sha256', $subscriberId . ':' . $billingDay)` where `$billingDay` is `YYYY-MM-DD` UTC. Never random/uniqid/time.
- Charge-once is enforced by a UNIQUE index on `charge_attempts(subscriber_id, billing_day)` in Postgres, not only in PHP. Catch `23505` and treat as "already charged".
- Charge flow runs inside a DB transaction with `SELECT ... FOR UPDATE` on the subscriber row.
- Re-check `status = 'ACTIVE'` inside the lock (guards opt-out vs charge race).
- Webhook idempotency via `processed_webhook_events(event_key PRIMARY KEY)` + `ON CONFLICT DO NOTHING`. `event_key = sha256(applicationId|subscriberId|status|timeStamp|version)`.
- Webhook never calls `/caas/direct/debit`.
- Retry classification:
  - `S1000` → success, send receipt, mark charged
  - `E1337` → already charged (same externalTrxId), treat as success, do NOT retry
  - `E1308` → permanent: `status='SUSPENDED'`, do NOT retry
  - `E1326` → transient: bounded retry (max 3, backoff 15m/1h/4h), same externalTrxId
  - `E1318` → transient: same as E1326
- Retries reuse the SAME `externalTrxId`. Never mint a new one for a retry.
- If the process crashes after the Platform call but before the DB update: on restart the `charge_attempts` row is `pending` with a known `external_trx_id`. Re-query via that id (or treat `E1337` as success). Never mint a new id.

## Security & hygiene
- `PLATFORM_APP_ID`, `PLATFORM_PASSWORD` from env only. Never in code, never in logs.
- Never log full MSISDN. Mask to last 4 digits.
- Validate MSISDN with `^tel:\d+$` at the HTTP boundary. Amount: `^\d+\.\d{2}$`.
- `subscriberId` is an opaque alias — never parse a phone number out of it.
- Money amounts are strings with 2 decimals (`"5.00"`), never `float`.

## Working style
- Work in small iterations. Do NOT scaffold the whole project in one shot.
- After each iteration: `docker compose up -d`, then `docker compose exec service ./vendor/bin/phpunit`.
- After each iteration: write 2–4 lines in `DECISIONS.md` explaining the non-obvious choice. Do not invent; only what you actually did.
- If something in `docs/ASSIGNMENT.md` is ambiguous, state your assumption explicitly in `DECISIONS.md` before coding.

## First iteration (do this first, then stop)
Read `docs/ASSIGNMENT.md` and this file. Produce a numbered plan of iterations (max 8). Do not write code. Wait for approval.

## File hygiene
- `opencode.json` lives ONLY at the repo root. Never create `opencode.json`
  inside `service/` or `platform-mock/`.
- Never overwrite `composer.json` with non-composer content. Before every build,
  sanity-check: each `composer.json` must start with `"name":` and contain
  `autoload.psr-4` for the app namespace (`App\\` in service, `Mock\\` in mock).
- If a class is referenced with `use App\...`, the file must exist at the matching
  PSR-4 path. Do not reference classes that do not exist yet.