<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Repository\PdoFactory;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class MigrationSchemaTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = PdoFactory::fromEnv();
    }

    public function testRequiredTablesExist(): void
    {
        $tables = $this->pdo
            ->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'")
            ->fetchAll(PDO::FETCH_COLUMN);

        foreach (['subscribers', 'charge_attempts', 'processed_webhook_events', 'sms_log'] as $table) {
            self::assertContains($table, $tables, "Missing table: {$table}");
        }
    }

    public function testChargeAttemptsHasBothUniqueConstraints(): void
    {
        $stmt = $this->pdo->query(
            "SELECT tc.constraint_name, kcu.column_name
               FROM information_schema.table_constraints tc
               JOIN information_schema.key_column_usage kcu
                 ON kcu.constraint_catalog = tc.constraint_catalog
                AND kcu.constraint_schema = tc.constraint_schema
                AND kcu.constraint_name = tc.constraint_name
                AND kcu.table_schema = tc.table_schema
                AND kcu.table_name = tc.table_name
              WHERE tc.table_schema = 'public'
                AND tc.table_name = 'charge_attempts'
                AND tc.constraint_type = 'UNIQUE'
              ORDER BY tc.constraint_name, kcu.ordinal_position"
        );

        $constraints = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $constraints[$row['constraint_name']][] = $row['column_name'];
        }

        self::assertContains(['subscriber_id', 'billing_day'], array_values($constraints));
        self::assertContains(['external_trx_id'], array_values($constraints));
    }

    public function testProcessedWebhookEventsHasPrimaryKeyOnEventKey(): void
    {
        $stmt = $this->pdo->query(
            "SELECT kcu.column_name
               FROM information_schema.table_constraints tc
               JOIN information_schema.key_column_usage kcu
                 ON kcu.constraint_catalog = tc.constraint_catalog
                AND kcu.constraint_schema = tc.constraint_schema
                AND kcu.constraint_name = tc.constraint_name
                AND kcu.table_schema = tc.table_schema
                AND kcu.table_name = tc.table_name
              WHERE tc.table_schema = 'public'
                AND tc.table_name = 'processed_webhook_events'
                AND tc.constraint_type = 'PRIMARY KEY'"
        );

        self::assertSame(['event_key'], $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function testDuplicateBillingDayIsRejectedByDatabase(): void
    {
        // Roll everything back so the suite can run repeatedly on the same DB.
        $this->pdo->beginTransaction();
        try {
            $subscriber = $this->pdo->prepare(
                'INSERT INTO subscribers (id, status) VALUES (?, ?)'
            );
            $subscriber->execute(['tel:9999999999', 'ACTIVE']);

            $attempt = $this->pdo->prepare(
                'INSERT INTO charge_attempts
                    (subscriber_id, billing_day, external_trx_id, amount, currency, status)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $attempt->execute(['tel:9999999999', '2026-11-21', 'test-trx-1', '5.00', 'XTS', 'pending']);

            $caught = null;
            try {
                // Same (subscriber_id, billing_day), different external_trx_id:
                // the composite UNIQUE must still reject it with SQLSTATE 23505.
                $attempt->execute(['tel:9999999999', '2026-11-21', 'test-trx-2', '5.00', 'XTS', 'pending']);
            } catch (PDOException $e) {
                $caught = $e;
            }

            self::assertInstanceOf(PDOException::class, $caught);
            self::assertSame('23505', $caught->getCode());
        } finally {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        }
    }
}
