---
name: pdo-transactions
description: How to use PDO with PostgreSQL safely in this repo — transactions, SELECT FOR UPDATE, unique-index enforcement, no ORM. Use whenever touching the DB or writing migrations.
---

# PDO + Postgres conventions

- One `PDO` instance via DI; `ERRMODE_EXCEPTION`, `ATTR_EMULATE_PREPARES=false`, `ATTR_DEFAULT_FETCH_MODE=FETCH_ASSOC`.
- Always prepared statements with bound params. No string interpolation into SQL.
- Money-path operations MUST be wrapped in an explicit transaction:

      $pdo->beginTransaction();
      try {
          $stmt = $pdo->prepare('SELECT ... FROM subscribers WHERE id = ? FOR UPDATE');
          $stmt->execute([$id]);
          // ...
          $pdo->commit();
      } catch (\Throwable $e) {
          $pdo->rollBack();
          throw $e;
      }

- Race protection: `SELECT ... FOR UPDATE` on the subscriber row **before** any charge decision.
- Idempotency: enforced by UNIQUE indexes, not by PHP checks alone. Catch SQLSTATE `23505` (unique_violation) and treat as "already done".
- Migrations: plain `.sql` files in `service/migrations/NNNN_name.sql`, applied in lexical order by the entrypoint. Idempotent (`CREATE TABLE IF NOT EXISTS`, `CREATE UNIQUE INDEX IF NOT EXISTS`).
- No ORM, no query builder. Raw SQL is fine; keep it readable and commented where non-obvious.
- `TIMESTAMPTZ` for timestamps, `DATE` for billing day, `TEXT` for ids. No `SERIAL` in money tables — use `TEXT` ids (e.g. `subscriber_id`) or `BIGSERIAL` only for internal PKs.