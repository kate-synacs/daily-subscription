<?php

declare(strict_types=1);

namespace App\Repository;

use DateTimeImmutable;
use PDO;

final class ChargeAttemptRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * DB-enforced charge-once. A NULL return means the (subscriber_id,
     * billing_day) UNIQUE constraint already has a row — never mint a new id.
     */
    public function insertPending(
        string $subscriberId,
        string $billingDay,
        string $externalTrxId,
        string $amount,
        string $currency,
    ): ?int {
        $stmt = $this->pdo->prepare(
            "INSERT INTO charge_attempts
                (subscriber_id, billing_day, external_trx_id, amount, currency, status, attempt_count)
             VALUES (?, ?, ?, ?, ?, 'pending', 0)
             ON CONFLICT (subscriber_id, billing_day) DO NOTHING
             RETURNING id"
        );
        $stmt->execute([$subscriberId, $billingDay, $externalTrxId, $amount, $currency]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array<string, mixed>|null */
    public function findPendingAttempt(string $subscriberId, string $billingDay): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, subscriber_id, billing_day, external_trx_id, amount, currency,
                    status, attempt_count, next_retry_at, reference_id, platform_status_code
               FROM charge_attempts WHERE subscriber_id = ? AND billing_day = ? LIMIT 1'
        );
        $stmt->execute([$subscriberId, $billingDay]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function markCharged(
        int $id,
        string $referenceId,
        string $internalTrxId,
        string $platformStatusCode,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE charge_attempts
                SET status = 'charged', reference_id = ?, internal_trx_id = ?,
                    platform_status_code = ?, updated_at = now()
              WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$referenceId, $internalTrxId, $platformStatusCode, $id]);
    }

    public function markAlreadyCharged(int $id, string $platformStatusCode): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE charge_attempts
                SET status = 'already_charged', platform_status_code = ?, updated_at = now()
              WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$platformStatusCode, $id]);
    }

    public function markFailedPermanent(
        int $id,
        string $platformStatusCode,
        string $platformStatusDetail,
    ): void {
        $stmt = $this->pdo->prepare(
            "UPDATE charge_attempts
                SET status = 'failed_permanent', platform_status_code = ?,
                    platform_status_detail = ?, updated_at = now()
              WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([$platformStatusCode, $platformStatusDetail, $id]);
    }

    public function markFailedTransient(
        int $id,
        string $platformStatusCode,
        string $platformStatusDetail,
        ?DateTimeImmutable $nextRetryAt,
    ): void {
        // Nullable: after the 3rd failure no further retry is scheduled.
        $stmt = $this->pdo->prepare(
            "UPDATE charge_attempts
                SET status = 'failed_transient', platform_status_code = ?,
                    platform_status_detail = ?, attempt_count = attempt_count + 1,
                    next_retry_at = ?, updated_at = now()
              WHERE id = ? AND status = 'pending'"
        );
        $stmt->execute([
            $platformStatusCode,
            $platformStatusDetail,
            $nextRetryAt?->format('Y-m-d\TH:i:sP'),
            $id,
        ]);
    }
}
