---
name: php-strict
description: Baseline PHP 8.3 coding rules for this repo — strict types, PSR-12, no magic, money as strings. Use on every file you create or edit.
---

# PHP strict baseline

- Every file starts with `<?php` + `declare(strict_types=1);` — no exceptions.
- PSR-12 formatting, 4 spaces, LF line endings.
- No `mixed` return types on public APIs; use explicit types or DTOs.
- No global mutable state, no static mutable properties.
- No `eval`, no `extract`, no dynamic property access on DTOs.
- Exceptions: `App\Exception\DomainException` subclasses. Never throw `\Exception`.
- Logging: PSR-3 `LoggerInterface` only. Never `error_log` in `src/`.
- Secrets: via `App\Config\Config::get()`, which reads `getenv()`. Never inline, never log.
- Money: always `string` with 2 decimals (`"5.00"`). Never `float`.
- Dates: `DateTimeImmutable`, UTC internally.