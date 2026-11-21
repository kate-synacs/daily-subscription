# Daily Pass

A paid daily subscription on top of a mock Carrier Platform. One subscriber is
charged at most once per UTC billing day; `STOP` opts out immediately.

## Prerequisites

Docker + Docker Compose. **Nothing else** — no local PHP, Composer or Postgres.

## Layout

- `service/` — the subscription service: `src/` (HTTP, services, repositories, platform client, clock), `migrations/` (plain SQL), `tests/` (PHPUnit).
- `platform-mock/` — the fake operator: `public/index.php` (single front controller), `src/` (contract handlers, state and admin controls).
- `docs/ASSIGNMENT.md` — the full task and Platform API contract.
- `AGENTS.md` — working agreement and hard money-path rules.
- `DECISIONS.md` — the reasoning behind the design; trade-offs and known gaps live there.

## Setup (clean machine)

```bash
cp .env.example .env
docker compose up -d
```

To watch the service logs: `docker compose logs -f service` (optional).

`service/entrypoint.sh` waits for Postgres with a retry loop, applies
`service/migrations/*.sql` in lexical order, then starts `php -S`. No manual
migration step. Trade-offs and known gaps live in `DECISIONS.md`.

Service: `http://localhost:8080` · mock: `http://localhost:8081`

## Driving the mock

The mock is a controllable fake operator. Its state lives in
`/tmp/mock-state.json` inside the container; `POST /_control/reset` clears
logs, seen trx ids and both modes.

| Endpoint | What it does |
|---|---|
| `POST /_control/debit-status` | result of the next `/caas/direct/debit`: `S1000`, `E1326`, `E1308`, `E1318`, `E1337`, `auto-dup` |
| `POST /_control/subscription-status` | result of the next `/subscription/send`: `S1000`, any `E*` code, or `transport` (HTTP 500) |
| `POST /_control/push-callback` | push a §B notification (`REGISTERED`, `UNREGISTERED`, `PENDING`, `CHARGE`) to the service |
| `POST /_control/reset` | clear logs, `seenExternalTrxIds`, both modes back to `S1000` |
| `GET /_log/sms`, `GET /_log/debits`, `GET /_log/subscriptions` | what the service actually sent |

```bash
curl -sS -X POST localhost:8081/_control/debit-status -H 'Content-Type: application/json' -d '{"mode":"E1326"}'
curl -sS -X POST localhost:8081/_control/subscription-status -H 'Content-Type: application/json' -d '{"mode":"transport"}'
curl -sS -X POST localhost:8081/_control/reset -H 'Content-Type: application/json' -d '{}'
```

## Scenarios

Run these in order on a fresh stack. `source .env` first so `$DB_USER`/`$DB_NAME`
work in the `psql` lines. Re-running on a live stack needs
`docker compose down -v && docker compose up -d --build` first — that wipes the
database, the mock state file and all `_log` endpoints.

### a) Health

```bash
curl -sS localhost:8080/health; echo
curl -sS localhost:8081/health; echo
```
`{"ok":true,"service":"service"}` · `{"ok":true,"service":"platform-mock"}`

### b) Opt-in `tel:1000000001`

```bash
curl -sS -X POST localhost:8080/subscribers/opt-in -H 'Content-Type: application/json' \
  -d '{"subscriberId":"tel:1000000001"}'; echo
curl -sS localhost:8081/_log/sms | python3 -m json.tool
docker compose exec db psql -U "$DB_USER" -d "$DB_NAME" -c \
  "SELECT id,status FROM subscribers WHERE id='tel:1000000001';"
```
`{"ok":true,"status":"active",...}` · one MT welcome SMS · `tel:1000000001 | ACTIVE`

### c) Opt-in the same number again

```bash
curl -sS -X POST localhost:8080/subscribers/opt-in -H 'Content-Type: application/json' \
  -d '{"subscriberId":"tel:1000000001"}'; echo
curl -sS localhost:8081/_log/sms | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'
curl -sS localhost:8081/_log/subscriptions | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'
```
`{"ok":true,"status":"already_active",...}` · one welcome SMS, one `action:"1"` — no re-registration

### d) Daily charge, mock `S1000`

```bash
curl -fsS -X POST localhost:8081/_control/debit-status -H 'Content-Type: application/json' -d '{"mode":"S1000"}'; echo
curl -sS -X POST localhost:8080/jobs/daily-charge -H 'Content-Type: application/json' -d '{}'; echo
curl -fsS localhost:8081/_log/debits | python3 -m json.tool
curl -sS localhost:8081/_log/sms | python3 -m json.tool
```
`"charged":1` · exactly one debit with `statusCode:"S1000"` · one receipt `Daily Pass charged: 5.00 XTS. Ref: …`

### e) Run the job again the same day

```bash
curl -sS -X POST localhost:8080/jobs/daily-charge -H 'Content-Type: application/json' -d '{}'; echo
curl -fsS localhost:8081/_log/debits | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'
```
`"charged":0`, `"processed":0` · debit log still length 1

### f) Restart, run again

```bash
docker compose restart service
sleep 3
curl -sS -X POST localhost:8080/jobs/daily-charge -H 'Content-Type: application/json' -d '{}'; echo
curl -fsS localhost:8081/_log/debits | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))'
```
`"charged":0` · debit log still length 1 — charge-once survives a restart

### g) Insufficient balance (`E1326`)

```bash
curl -fsS -X POST localhost:8081/_control/debit-status -H 'Content-Type: application/json' -d '{"mode":"E1326"}'; echo
curl -sS -o /dev/null -X POST localhost:8080/subscribers/opt-in -H 'Content-Type: application/json' \
  -d '{"subscriberId":"tel:1000000002"}'
curl -sS -X POST localhost:8080/jobs/daily-charge -H 'Content-Type: application/json' -d '{}'; echo
docker compose exec db psql -U "$DB_USER" -d "$DB_NAME" -c \
  "SELECT c.status,c.attempt_count,c.next_retry_at,s.status AS subscriber_status
     FROM charge_attempts c JOIN subscribers s ON s.id=c.subscriber_id
    WHERE c.subscriber_id='tel:1000000002';"
```
`"transient":1` · `failed_transient | 1 | <future timestamp> | ACTIVE`

### h) Retry succeeds with the SAME `externalTrxId`

```bash
docker compose exec db psql -U "$DB_USER" -d "$DB_NAME" -c \
  "UPDATE charge_attempts SET next_retry_at=now()-interval '1 second' WHERE subscriber_id='tel:1000000002';"
curl -fsS -X POST localhost:8081/_control/debit-status -H 'Content-Type: application/json' -d '{"mode":"S1000"}'; echo
curl -sS -X POST localhost:8080/jobs/daily-charge -H 'Content-Type: application/json' -d '{}'; echo
curl -fsS localhost:8081/_log/debits | python3 -c 'import json,sys; d=[x for x in json.load(sys.stdin) if x["subscriberId"]=="tel:1000000002"]; print([(x["statusCode"],x["externalTrxId"]) for x in d])'
```
`"charged":1` · `[('E1326', 'HASH'), ('S1000', 'HASH')]` — same hash both times

### i) Permanent error (`E1308`)

```bash
curl -fsS -X POST localhost:8081/_control/debit-status -H 'Content-Type: application/json' -d '{"mode":"E1308"}'; echo
curl -sS -o /dev/null -X POST localhost:8080/subscribers/opt-in -H 'Content-Type: application/json' \
  -d '{"subscriberId":"tel:1000000003"}'
curl -sS -X POST localhost:8080/jobs/daily-charge -H 'Content-Type: application/json' -d '{}'; echo
docker compose exec db psql -U "$DB_USER" -d "$DB_NAME" -c \
  "SELECT s.id,s.status,s.last_charge_error,c.status FROM subscribers s
     JOIN charge_attempts c ON c.subscriber_id=s.id WHERE s.id='tel:1000000003';"
```
`"suspended":1` · `tel:1000000003 | SUSPENDED | E1308 | failed_permanent` — never retried

### j) `STOP` blocks a charge

```bash
curl -sS -o /dev/null -X POST localhost:8080/subscribers/opt-in -H 'Content-Type: application/json' \
  -d '{"subscriberId":"tel:1000000004"}'
curl -sS -X POST localhost:8080/webhooks/mo-sms -H 'Content-Type: application/json' \
  -d '{"sourceAddress":"tel:1000000004","message":"STOP","encoding":"0","version":"1.0"}'; echo
curl -sS -X POST localhost:8080/webhooks/mo-sms -H 'Content-Type: application/json' \
  -d '{"sourceAddress":"tel:1000000001","message":"STOP","encoding":"0","version":"1.0"}'; echo
curl -fsS -X POST localhost:8081/_control/debit-status -H 'Content-Type: application/json' -d '{"mode":"S1000"}'; echo
curl -sS -X POST localhost:8080/jobs/daily-charge -H 'Content-Type: application/json' -d '{}'; echo
curl -sS localhost:8081/_log/sms | python3 -c 'import json,sys; print("goodbyes:",len([x for x in json.load(sys.stdin) if "unsubscribed" in x["message"]]))'
curl -fsS localhost:8081/_log/debits | python3 -c 'import json,sys; print("debits for 04:",len([x for x in json.load(sys.stdin) if x["subscriberId"]=="tel:1000000004"]))'
docker compose exec db psql -U "$DB_USER" -d "$DB_NAME" -c \
  "SELECT count(*) FROM charge_attempts WHERE subscriber_id='tel:1000000004';"
```
Two `{"statusCode":"S1000","statusDetail":"Success"}` · two goodbye SMS · zero debits and zero `charge_attempts` rows for `tel:1000000004`. `tel:1000000001` is stopped here too — that is why scenario l shows it INACTIVE.

### k) Duplicate subscription callback

```bash
for i in 1 2; do
  curl -sS -X POST localhost:8080/webhooks/subscription -H 'Content-Type: application/json' \
    -d '{"applicationId":"APP_000123","subscriberId":"tel:1000000009","frequency":"daily","status":"REGISTERED","version":"1.0","timeStamp":"2026-11-21T10:00:00Z"}'; echo
done
docker compose exec db psql -U "$DB_USER" -d "$DB_NAME" -c 'SELECT count(*) FROM processed_webhook_events;'
```
`{"statusCode":"S1000","statusDetail":"Success"}` twice · `processed_webhook_events` count = 1. The dedupe key includes `timeStamp`, so the two requests must carry the **same** `timeStamp`. `POST /_control/push-callback` stamps a fresh `timeStamp` on every call and therefore produces two distinct events — it is a delivery demo, not a dedupe test.

### l) Status of the three subscribers

```bash
for id in tel:1000000001 tel:1000000002 tel:1000000003; do
  curl -sS "localhost:8080/subscribers/$id"; echo
done
```
INACTIVE with last charge · ACTIVE with last charge · SUSPENDED with `E1308` attempt

### m) Platform refuses the opt-in

```bash
curl -fsS -X POST localhost:8081/_control/subscription-status -H 'Content-Type: application/json' -d '{"mode":"E1308"}'; echo
curl -sS -o /dev/null -w "opt-in HTTP %{http_code}\n" -X POST localhost:8080/subscribers/opt-in \
  -H 'Content-Type: application/json' -d '{"subscriberId":"tel:1000000010"}'
docker compose exec db psql -U "$DB_USER" -d "$DB_NAME" -c \
  "SELECT count(*) FROM subscribers WHERE id='tel:1000000010';"
curl -sS -X POST localhost:8081/_control/reset -H 'Content-Type: application/json' -d '{}'; echo
```
`opt-in HTTP 502` · `count = 0` — nothing is left half-registered when the Platform refuses.

## Tests

```bash
docker compose exec service ./vendor/bin/phpunit
```

45 tests, 244 assertions, nothing skipped. Tests run inside the `service`
container against the already-running stack (no second PHP process); mock
control/log calls use `http://platform-mock:8080`. Test classes clean their data
in `setUp()` — `MoneyIdempotencyTest` truncates all tables and every class calls
`/_control/reset` — so the suite is order-independent and can be run twice in a
row. Coverage: charge-once (repeat run and restart), retry classification with
the same `externalTrxId`, `E1308` suspend, opt-out vs charge race, parallel job
runs, duplicate callbacks, and platform failure mapping (E-code and transport → 502).

## Design notes

**externalTrxId.** `hash('sha256', $subscriberId . ':' . $billingDay)` with a UTC
`YYYY-MM-DD` billing day. Deterministic by construction: the same subscriber and
day always produce the same logical charge key, so a retry or a restart can
never turn into a second charge.

**Charge-once.** Postgres `UNIQUE (subscriber_id, billing_day)` is the real
guarantee. The service locks the subscriber row, inserts a `pending` attempt in
that same transaction and commits *before* calling the Platform; a second run
hits the unique index and skips without any debit call.

**Retry classification.** `S1000` charges and sends a receipt; `E1337` means
already charged (reuse the id, no receipt); `E1308` suspends; `E1326`/`E1318`
are transient with bounded backoff (15m, 1h, max 3 attempts) and the same
`externalTrxId`. Due retries are leased with `FOR UPDATE SKIP LOCKED`.

**Opt-out race.** `SELECT ... FOR UPDATE` plus an `ACTIVE` re-check inside the
lock means `STOP` wins over a concurrent charge; opt-out also clears
`next_retry_at`, so no scheduled retry can revive it.

**Platform call outside the lock.** The row lock is released before the HTTP
call so no long network wait serialises subscribers. Crash safety comes from
the durable `pending` attempt: the next run replays the stored `externalTrxId`
and treats `E1337` as success.

**Platform failures at opt-in.** A non-`S1000` subscription response or a
transport error both map to `502 Bad Gateway`, and the subscriber row is not
created — nothing is left half-registered.

## Out of scope

Real auth/SSO, multi-currency, USSD, DLR handling, Query Balance, production/HA
infra, a real scheduler or queue, pixel-perfect UI, and a real operator sandbox.

## License

MIT — see [LICENSE](LICENSE). Copyright (c) 2026 Ekaterina Gorshkova.