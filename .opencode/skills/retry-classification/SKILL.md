---
name: retry-classification
description: Mapping of Carrier Platform status codes to retry/terminal behavior on the charge path. Use when handling debit responses or designing the daily job.
---

# Status code classification

| Code  | Class         | Action |
|-------|---------------|--------|
| S1000 | terminal-ok   | mark charged, store referenceId, send receipt SMS |
| E1337 | terminal-ok   | already charged (same externalTrxId); do not retry, do not re-charge; look up existing attempt |
| E1308 | terminal-fail | suspend subscriber (`status='SUSPENDED'`), no retry, optional SMS |
| E1326 | transient     | schedule retry (15m, 1h, 4h — max 3 attempts total), same externalTrxId |
| E1318 | transient     | same as E1326 |

Rules:
- Never retry E1308 or E1337 into a new externalTrxId.
- Retries reuse the SAME externalTrxId — it is the logical charge key.
- After max retries on transient → leave subscriber ACTIVE but set `last_charge_error='E1326'`; next billing day is a fresh charge (new externalTrxId).
- Retry state stored in DB (`charge_attempts.next_retry_at`, `attempt_count`), not in memory.
- Backoff bounded; no infinite retries.