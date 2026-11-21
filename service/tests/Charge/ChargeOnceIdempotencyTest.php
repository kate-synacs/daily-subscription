<?php

declare(strict_types=1);

namespace App\Tests\Charge;

use App\Service\ChargeCalculator;

final class ChargeOnceIdempotencyTest extends ChargeTestCase
{
    public function test_run_twice_charges_once(): void
    {
        $this->setDebitMode('S1000');
        $this->optIn('tel:1000000011');

        [$status] = $this->runChargeJob();
        self::assertSame(200, $status);
        [, $first] = $this->runChargeJob();
        self::assertSame(0, $first['charged'] ?? null);
        self::assertSame(1, $first['skipped'] ?? null);

        $attempt = $this->attemptFor('tel:1000000011');
        self::assertIsArray($attempt);
        self::assertSame('charged', $attempt['status']);
        self::assertNotNull($attempt['reference_id']);
        self::assertSame(1, (int) $this->pdo->query(
            "SELECT count(*) FROM charge_attempts WHERE subscriber_id = 'tel:1000000011'"
        )->fetchColumn());

        self::assertCount(1, $this->debitLog());
        self::assertCount(1, $this->receipts());
    }

    public function test_external_trx_id_is_deterministic(): void
    {
        $first = ChargeCalculator::externalTrxId('tel:1000000011', '2026-11-21');
        self::assertSame($first, ChargeCalculator::externalTrxId('tel:1000000011', '2026-11-21'));
        self::assertSame(64, strlen($first));
        self::assertNotSame($first, ChargeCalculator::externalTrxId('tel:1000000011', '2026-11-22'));
        self::assertNotSame($first, ChargeCalculator::externalTrxId('tel:1000000012', '2026-11-21'));
    }

    public function test_run_survives_restart(): void
    {
        $this->setDebitMode('S1000');
        $this->optIn('tel:1000000011');
        $this->runChargeJob();

        $original = $this->attemptFor('tel:1000000011');
        self::assertIsArray($original);
        $externalTrxId = (string) $original['external_trx_id'];

        // Simulate a crash after the Platform call but before the DB update.
        $this->pdo->exec(
            "UPDATE charge_attempts SET status = 'pending', reference_id = NULL,
                 internal_trx_id = NULL WHERE subscriber_id = 'tel:1000000011'"
        );
        $this->setDebitMode('auto-dup');

        [, $summary] = $this->runChargeJob();
        self::assertSame(1, $summary['alreadyCharged'] ?? null);

        $recovered = $this->attemptFor('tel:1000000011');
        self::assertIsArray($recovered);
        self::assertSame('already_charged', $recovered['status']);
        self::assertSame('E1337', $recovered['platform_status_code']);
        self::assertSame($externalTrxId, $recovered['external_trx_id']);

        $debits = $this->debitLog();
        self::assertCount(2, $debits);
        self::assertSame($externalTrxId, $debits[0]['externalTrxId'] ?? null);
        self::assertSame($externalTrxId, $debits[1]['externalTrxId'] ?? null);
        self::assertSame('E1337', $debits[1]['statusCode'] ?? null);
        self::assertCount(1, $this->receipts());
    }
}
