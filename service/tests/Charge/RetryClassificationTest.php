<?php

declare(strict_types=1);

namespace App\Tests\Charge;

final class RetryClassificationTest extends ChargeTestCase
{
    public function test_s1000_marks_charged_and_sends_receipt(): void
    {
        $this->setDebitMode('S1000');
        $this->optIn('tel:1000000011');
        [, $summary] = $this->runChargeJob();

        self::assertSame(1, $summary['charged'] ?? null);
        $attempt = $this->attemptFor('tel:1000000011');
        self::assertIsArray($attempt);
        self::assertSame('charged', $attempt['status']);
        self::assertSame('S1000', $attempt['platform_status_code']);
        self::assertNotNull($attempt['reference_id']);
        self::assertCount(1, $this->receipts());
    }

    public function test_e1337_marks_already_charged_and_sends_no_receipt(): void
    {
        $this->setDebitMode('E1337');
        $this->optIn('tel:1000000011');
        [, $summary] = $this->runChargeJob();

        self::assertSame(1, $summary['alreadyCharged'] ?? null);
        $attempt = $this->attemptFor('tel:1000000011');
        self::assertIsArray($attempt);
        self::assertSame('already_charged', $attempt['status']);
        self::assertSame('E1337', $attempt['platform_status_code']);
        self::assertCount(0, $this->receipts());
    }

    public function test_e1308_marks_failed_permanent_and_suspends_subscriber(): void
    {
        $this->setDebitMode('E1308');
        $this->optIn('tel:1000000012');
        [, $summary] = $this->runChargeJob();

        self::assertSame(1, $summary['suspended'] ?? null);
        $attempt = $this->attemptFor('tel:1000000012');
        self::assertIsArray($attempt);
        self::assertSame('failed_permanent', $attempt['status']);
        self::assertSame('E1308', $attempt['platform_status_code']);

        $stmt = $this->pdo->prepare('SELECT status, last_charge_error FROM subscribers WHERE id = ?');
        $stmt->execute(['tel:1000000012']);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame('SUSPENDED', $row['status']);
        self::assertSame('E1308', $row['last_charge_error']);
        self::assertCount(0, $this->receipts());
    }

    public function test_e1326_marks_failed_transient_and_schedules_retry(): void
    {
        $this->setDebitMode('E1326');
        $this->optIn('tel:1000000013');
        [, $summary] = $this->runChargeJob();

        self::assertSame(1, $summary['transient'] ?? null);
        $attempt = $this->attemptFor('tel:1000000013');
        self::assertIsArray($attempt);
        self::assertSame('failed_transient', $attempt['status']);
        self::assertSame('E1326', $attempt['platform_status_code']);
        self::assertSame(1, (int) $attempt['attempt_count']);
        self::assertNotNull($attempt['next_retry_at']);

        $stmt = $this->pdo->prepare('SELECT status FROM subscribers WHERE id = ?');
        $stmt->execute(['tel:1000000013']);
        self::assertSame('ACTIVE', $stmt->fetchColumn());
    }

    public function test_e1326_third_attempt_does_not_schedule(): void
    {
        $this->setDebitMode('E1326');
        $this->optIn('tel:1000000013');
        $this->runChargeJob();

        // Simulate two earlier failures so this is the third.
        $this->pdo->exec(
            "UPDATE charge_attempts SET status = 'pending', attempt_count = 2,
                 next_retry_at = NULL WHERE subscriber_id = 'tel:1000000013'"
        );
        $this->runChargeJob();

        $attempt = $this->attemptFor('tel:1000000013');
        self::assertIsArray($attempt);
        self::assertSame('failed_transient', $attempt['status']);
        self::assertSame(3, (int) $attempt['attempt_count']);
        self::assertNull($attempt['next_retry_at']);
    }
}
