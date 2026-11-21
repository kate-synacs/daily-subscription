---
name: platform-mock
description: Rules for the platform-mock service — endpoints, configurable debit status, SMS log, callback trigger. Use when building or debugging the mock.
---

# platform-mock

- Single `public/index.php`, run via `php -S 0.0.0.0:8080 -t public public/index.php`.
- Implements §A–§D from `docs/ASSIGNMENT.md`:
  - `POST /subscription/send` → `S1000`, returns `subscriptionStatus` (`REGISTERED`/`UNREGISTERED` based on `action`).
  - `POST /sms/send` → `S1000`, appends to in-memory/file SMS log.
  - `POST /caas/direct/debit` → returns a **configurable** status code.
- Configurable debit status: `POST /_control/debit-status` with `{"statusCode":"E1326"}`. Default `S1000`.
  - On `S1000`: return `referenceId`, `internalTrxId`, `externalTrxId` (echo).
  - On `E1337`: same as duplicate — mock records `externalTrxId` seen; second call with same id returns `E1337` automatically if `_control` set to `auto-dup` mode.
  - On `E1326`/`E1308`/`E1318`: return the code with `statusDetail`.
- Push callback: `POST /_control/push-callback` with `{"subscriberId":"tel:...","status":"REGISTERED"}` → mock POSTs to `SERVICE_CALLBACK_URL` (§B shape).
- Expose logs for the demo: `GET /_log/sms`, `GET /_log/debits` — so the grader can see welcome/receipt SMS and debit calls.
- No real auth. No persistence needed beyond a JSON file or in-memory (single container is fine). Keep it simple.