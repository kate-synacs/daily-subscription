<?php

declare(strict_types=1);

namespace App\Tests\Charge;

use App\Clock\SystemClock;
use App\Service\ChargeCalculator;

final class RetryExecutionTest extends ChargeTestCase
{
    private const SUBSCRIBER = 'tel:1000000013';

    public function test_retry_succeeds_after_transient(): void
    {
        $this->setDebitMode('E1326');
        $this->optIn(self::SUBSCRIBER);
        [, $first] = $this->runChargeJob();
        self::assertSame(1, $first['transient'] ?? null);

        $attempt = $this->attemptFor(self::SUBSCRIBER);
        self::assertIsArray($attempt);
        $externalTrxId = (string) $attempt['external_trx_id'];

        $this->setDebitMode('S1000');
        $this->makeRetryDue();
        [, $second] = $this->runChargeJob();
        self::assertSame(1, $second['charged'] ?? null);

        $recovered = $this->attemptFor(self::SUBSCRIBER);
        self::assertIsArray($recovered);
        self::assertSame('charged', $recovered['status']);
        self::assertSame(2, (int) $recovered['attempt_count'], 'The retry slot is consumed at claim time');

        $debits = $this->debitLog();
        self::assertCount(2, $debits);
        self::assertSame($externalTrxId, $debits[0]['externalTrxId'] ?? null);
        self::assertSame($externalTrxId, $debits[1]['externalTrxId'] ?? null);
        self::assertSame('E1326', $debits[0]['statusCode'] ?? null);
        self::assertSame('S1000', $debits[1]['statusCode'] ?? null);
        self::assertCount(1, $this->receipts());
    }

    public function test_retry_suspended_on_e1308(): void
    {
        $this->setDebitMode('E1326');
        $this->optIn(self::SUBSCRIBER);
        $this->runChargeJob();

        $this->setDebitMode('E1308');
        $this->makeRetryDue();
        [, $summary] = $this->runChargeJob();
        self::assertSame(1, $summary['suspended'] ?? null);

        $attempt = $this->attemptFor(self::SUBSCRIBER);
        self::assertIsArray($attempt);
        self::assertSame('failed_permanent', $attempt['status']);
        self::assertSame('E1308', $attempt['platform_status_code']);

        $stmt = $this->pdo->prepare('SELECT status FROM subscribers WHERE id = ?');
        $stmt->execute([self::SUBSCRIBER]);
        self::assertSame('SUSPENDED', $stmt->fetchColumn());
    }

    public function test_retry_stops_after_max_attempts(): void
    {
        $this->setDebitMode('E1326');
        $this->optIn(self::SUBSCRIBER);

        $this->runChargeJob();
        self::assertSame(1, (int) $this->attemptFor(self::SUBSCRIBER)['attempt_count']);

        $this->makeRetryDue();
        $this->runChargeJob();
        self::assertSame(2, (int) $this->attemptFor(self::SUBSCRIBER)['attempt_count']);

        $this->makeRetryDue();
        $this->runChargeJob();
        $third = $this->attemptFor(self::SUBSCRIBER);
        self::assertSame(3, (int) $third['attempt_count']);
        self::assertNull($third['next_retry_at']);
        self::assertSame(3, count($this->debitLog()));

        // Even if someone forces the date back, no 4th Platform call happens.
        $this->makeRetryDue();
        [, $summary] = $this->runChargeJob();
        self::assertSame(0, $summary['charged'] ?? null);
        self::assertSame(0, $summary['transient'] ?? null);
        self::assertSame(3, count($this->debitLog()));

        $stmt = $this->pdo->prepare('SELECT status FROM subscribers WHERE id = ?');
        $stmt->execute([self::SUBSCRIBER]);
        self::assertSame('ACTIVE', $stmt->fetchColumn());
    }

    public function test_crash_recovery_pending_attempt(): void
    {
        $this->optIn(self::SUBSCRIBER);
        // E1337 models an already-charged Platform state; the retry/recovery
        // re-sends the same id and must not create a second charge.
        $this->setDebitMode('E1337');

        $billingDay = (new SystemClock())->today();
        $externalTrxId = ChargeCalculator::externalTrxId(self::SUBSCRIBER, $billingDay);
        $insert = $this->pdo->prepare(
            "INSERT INTO charge_attempts
                (subscriber_id, billing_day, external_trx_id, amount, currency, status, attempt_count)
             VALUES (?, ?, ?, '5.00', 'XTS', 'pending', 1)"
        );
        $insert->execute([self::SUBSCRIBER, $billingDay, $externalTrxId]);

        [, $summary] = $this->runChargeJob();
        self::assertSame(1, $summary['recovered'] ?? null);

        $attempt = $this->attemptFor(self::SUBSCRIBER);
        self::assertIsArray($attempt);
        self::assertSame('already_charged', $attempt['status']);
        self::assertSame('E1337', $attempt['platform_status_code']);
        self::assertNull($attempt['next_retry_at']);

        $debits = $this->debitLog();
        self::assertCount(1, $debits);
        self::assertSame($externalTrxId, $debits[0]['externalTrxId'] ?? null);
        self::assertSame('E1337', $debits[0]['statusCode'] ?? null);
        self::assertCount(0, $this->receipts());
    }

    private function makeRetryDue(): void
    {
        $this->pdo->exec(
            "UPDATE charge_attempts
                SET next_retry_at = now() - interval '1 second'
              WHERE subscriber_id = '" . self::SUBSCRIBER . "'"
        );
    }
}
