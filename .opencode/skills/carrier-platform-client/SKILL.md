---
name: carrier-platform-client
description: How to call the Carrier Platform (opt-in/out, SMS, direct debit) from this service. Use when writing or reviewing any HTTP call to the Platform.
---

# Platform client

- Base URL from `PLATFORM_BASE_URL` env.
- `applicationId` / `password` from env, passed in JSON body per §A–§D. NEVER log them.
- Guzzle client with `timeout: 5`, `connect_timeout: 2`, `http_errors: false`.
- Every call goes through `App\Platform\PlatformClient`. No raw Guzzle in services.
- Response contract: always check `statusCode` string, not HTTP status.
- Log only: `requestId`, `statusCode`, `externalTrxId`, `referenceId`. Never `password`/`applicationId`.
- SMS: `sourceAddress` from env (`SHORTCODE`), `encoding: "0"`.
- Mask MSISDN in logs: last 4 digits only.