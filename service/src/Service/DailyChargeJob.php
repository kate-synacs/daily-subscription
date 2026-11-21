<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Platform\Msisdn;
use PDO;
use Psr\Log\LoggerInterface;

final class DailyChargeJob
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ChargeService $charges,
        private readonly LoggerInterface $log,
    ) {
    }

    /** @return array<string, int|string> */
    public function run(Clock $clock): array
    {
        $billingDay = $clock->today();
        $now = $clock->now()->format('Y-m-d\TH:i:sP');
        // Microsecond precision: a pending row created in the same second as
        // this run must still be eligible for recovery.
        $runStartedAt = $clock->now()->format('Y-m-d\TH:i:s.uP');
        $summary = [
            'billingDay' => $billingDay,
            'processed' => 0,
            'charged' => 0,
            'recovered' => 0,
            'alreadyCharged' => 0,
            'transient' => 0,
            'suspended' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        // 1) New charges. Subscribers that already have an attempt today are
        //    handled by the retry/recovery phases below.
        $stmt = $this->pdo->prepare(
            "SELECT s.id FROM subscribers s
              WHERE s.status = 'ACTIVE'
                AND NOT EXISTS (
                    SELECT 1 FROM charge_attempts ca
                     WHERE ca.subscriber_id = s.id AND ca.billing_day = ?
                )
              ORDER BY s.id"
        );
        $stmt->execute([$billingDay]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->runOne(
                fn (): array => $this->charges->chargeSubscriber((string) $id, $billingDay, $clock),
                (string) $id,
                $summary,
            );
        }

        // 2) Due retries: lease at most 20 rows with FOR UPDATE SKIP LOCKED.
        //    Two parallel job runs can never claim the same attempt; a crash
        //    after COMMIT costs one attempt slot, never money.
        $claims = [];
        $backoffs = [1 => '+15 minutes', 2 => '+1 hour', 3 => null];
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, subscriber_id, billing_day, external_trx_id, attempt_count
                   FROM charge_attempts
                  WHERE status = 'failed_transient'
                    AND next_retry_at <= ?
                    AND attempt_count < 3
                  ORDER BY next_retry_at
                  FOR UPDATE SKIP LOCKED
                  LIMIT 20"
            );
            $stmt->execute([$now]);

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $newCount = (int) $row['attempt_count'] + 1;
                $backoff = $backoffs[$newCount] ?? null;
                $nextRetryAt = $backoff === null
                    ? null
                    : $clock->now()->modify($backoff)->format('Y-m-d\TH:i:sP');

                $update = $this->pdo->prepare(
                    "UPDATE charge_attempts
                        SET attempt_count = ?, next_retry_at = ?, updated_at = now()
                      WHERE id = ? AND status = 'failed_transient'"
                );
                $update->execute([$newCount, $nextRetryAt, $row['id']]);

                $claims[] = [
                    'subscriberId' => (string) $row['subscriber_id'],
                    'billingDay' => (string) $row['billing_day'],
                    'attemptId' => (int) $row['id'],
                    'externalTrxId' => (string) $row['external_trx_id'],
                ];
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // The Platform is only called after the lease is COMMITted.
        foreach ($claims as $claim) {
            $this->runOne(
                fn (): array => $this->charges->executeClaimedRetry(
                    $claim['subscriberId'],
                    $claim['billingDay'],
                    $claim['attemptId'],
                    $claim['externalTrxId'],
                    $clock,
                ),
                $claim['subscriberId'],
                $summary,
            );
        }

        // 3) Crash recovery for durable 'pending' rows created before this run.
        $stmt = $this->pdo->prepare(
            "SELECT subscriber_id, billing_day FROM charge_attempts
              WHERE status = 'pending' AND created_at < ?
              ORDER BY id"
        );
        $stmt->execute([$runStartedAt]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $subscriberId = (string) $row['subscriber_id'];
            $attemptDay = (string) $row['billing_day'];
            $this->runOne(
                fn (): array => $this->charges->executeRecovery($subscriberId, $attemptDay, $clock),
                $subscriberId,
                $summary,
            );
        }

        return $summary;
    }

    /**
     * One subscriber's failure must never abort the job.
     *
     * @param callable(): array<string, mixed> $operation
     * @param array<string, int|string> $summary
     */
    private function runOne(callable $operation, string $subscriberId, array &$summary): void
    {
        $summary['processed']++;

        try {
            $result = $operation();
        } catch (\Throwable $e) {
            $this->log->error('charge operation failed for subscriber', [
                'subscriber' => Msisdn::mask($subscriberId),
                'error' => $e->getMessage(),
            ]);
            $summary['errors']++;

            return;
        }

        $outcome = $result['outcome'] ?? null;
        if (($result['recovery'] ?? false) === true) {
            $summary['recovered']++;

            return;
        }

        switch ($outcome) {
            case 'charged':
                $summary['charged']++;
                break;
            case 'already_charged':
                $summary['alreadyCharged']++;
                break;
            case 'transient':
            case 'unknown_code':
                $summary['transient']++;
                break;
            case 'suspended':
                $summary['suspended']++;
                break;
            case 'error':
                $summary['errors']++;
                break;
            default:
                $summary['skipped']++;
                break;
        }
    }
}
