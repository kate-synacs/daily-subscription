# Daily Pass

A paid daily subscription on top of a mock Carrier Platform. One subscriber is
charged at most once per UTC billing day; `STOP` opts out immediately.

## Prerequisites

Docker + Docker Compose. **Nothing else** — no local PHP, Composer or Postgres.

## Setup (clean machine)

```bash
cp .env.example .env
docker compose up
```

`service/entrypoint.sh` waits for Postgres with a retry loop, applies
`service/migrations/*.sql` in lexical order, then starts `php -S`. No manual
migration step. The platform mock is stateful in `/tmp/mock-state.json` inside
its container; `POST /_control/reset` gives a clean demo state.

Service: `http://localhost:8080` · mock: `http://localhost:8081`

## Scenarios

Run these in order on a fresh `docker compose down -v && docker compose up -d`.
`source .env` first so `$DB_USER`/`$DB_NAME` work in the `psql` lines.

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

### j) `STOP` and no charge afterwards

```bash
curl -sS -X POST localhost:8080/webhooks/mo-sms -H 'Content-Type: application/json' \
  -d '{"sourceAddress":"tel:1000000001","message":"STOP","encoding":"0","version":"1.0"}'; echo
curl -sS localhost:8081/_log/sms | python3 -c 'import json,sys; print(len([x for x in json.load(sys.stdin) if "unsubscribed" in x["message"]]))'
curl -fsS -X POST localhost:8081/_control/debit-status -H 'Content-Type: application/json' -d '{"mode":"S1000"}'; echo
curl -sS -X POST localhost:8080/jobs/daily-charge -H 'Content-Type: application/json' -d '{}'; echo
curl -fsS localhost:8081/_log/debits | python3 -c 'import json,sys; print(len([x for x in json.load(sys.stdin) if x["subscriberId"]=="tel:1000000001"]))'
```
`{"statusCode":"S1000","statusDetail":"Success"}` · one goodbye SMS · no new debit for that number

### k) Duplicate subscription callback

```bash
for i in 1 2; do
  curl -sS -X POST localhost:8081/_control/push-callback -H 'Content-Type: application/json' \
    -d '{"subscriberId":"tel:1000000009","status":"REGISTERED"}'; echo
done
docker compose exec db psql -U "$DB_USER" -d "$DB_NAME" -c 'SELECT count(*) FROM processed_webhook_events;'
```
`{"delivered":true,"upstreamStatus":200,...}` twice · `processed_webhook_events` count = 1

### l) Status of all three subscribers

```bash
for id in tel:1000000001 tel:1000000002 tel:1000000003; do
  curl -sS "localhost:8080/subscribers/$id"; echo
done
```
INACTIVE with last charge · ACTIVE with last charge · SUSPENDED with `E1308` attempt

## Tests

```bash
docker compose exec service ./vendor/bin/phpunit
```

Tests run inside the `service` container and hit the already-running server at
`http://service:8080` (Compose DNS) rather than starting a second PHP process;
mock control/log calls use `http://platform-mock:8080`. Coverage includes
charge-once, retry classification, opt-out race, and parallel-delivery idempotency.

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

## Out of scope

Real auth/SSO, multi-currency, USSD, DLR handling, Query Balance, production/HA
infra, a real scheduler or queue, and pixel-perfect UI.
