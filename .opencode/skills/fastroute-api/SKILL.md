---
name: fastroute-api
description: Conventions for HTTP endpoints with FastRoute in this repo — routing, JSON parsing, validation at boundary, error mapping, single front controller. Use when adding or changing any route or controller.
---

# FastRoute API conventions

- Single front controller: `public/index.php`. No `.htaccess` gymnastics; run behind `php -S 0.0.0.0:8080 -t public public/index.php` or php-fpm+nginx. For this demo, `php -S` is fine.
- Routes declared in `src/Http/routes.php` as `$r->addRoute('POST', '/opt-in', [OptInController::class, 'handle'])`. Use `[{name}]` placeholders.
- `FastRoute\Dispatcher` only dispatches. Controllers are plain classes with `handle(ServerRequestInterface $req): ResponseInterface` style — but since we don't use PSR-7, define a tiny `App\Http\Request` / `App\Http\Response` DTO pair. Keep them minimal.
- JSON parsing: one helper `App\Http\Json::decode(string $body): array` that throws `BadRequestException` on invalid JSON or non-object.
- Validation at the boundary (controllers):
  - MSISDN: `^tel:\d+$` → else 400.
  - amount: `^\d+\.\d{2}$` → else 400.
- Response: always `Content-Type: application/json`. Shape `{"ok":true,...}` on success, `{"ok":false,"error":{"code":"...","message":"..."}}` on error.
- Error mapping: `DomainException` subclasses → 400/404/409; `Throwable` → 500 with generic message (never leak stack or SQL).
- Never echo request bodies or headers. Never log full MSISDN.
- 404 for unknown route, 405 for wrong method — FastRoute gives you the dispatcher status; handle both.