<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\NotFoundException;
use PDO;

final class StatusService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function get(string $subscriberId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, status, last_charge_error FROM subscribers WHERE id = ?'
        );
        $stmt->execute([$subscriberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new NotFoundException('Subscriber not found');
        }

        $stmt = $this->pdo->prepare(
            'SELECT billing_day, status, external_trx_id, reference_id, platform_status_code,
                    attempt_count, next_retry_at, created_at, updated_at
               FROM charge_attempts WHERE subscriber_id = ?
              ORDER BY billing_day DESC, id DESC LIMIT 1'
        );
        $stmt->execute([$subscriberId]);
        $charge = $stmt->fetch(PDO::FETCH_ASSOC);

        $lastCharge = null;
        if ($charge !== false) {
            $lastCharge = [
                'billingDay' => $charge['billing_day'],
                'status' => $charge['status'],
                'externalTrxId' => $charge['external_trx_id'],
                'referenceId' => $charge['reference_id'],
                'platformStatusCode' => $charge['platform_status_code'],
                'attemptCount' => (int) $charge['attempt_count'],
                'nextRetryAt' => $charge['next_retry_at'],
                'createdAt' => $charge['created_at'],
                'updatedAt' => $charge['updated_at'],
            ];
        }

        return [
            'subscriberId' => $row['id'],
            'status' => $row['status'],
            'lastChargeError' => $row['last_charge_error'],
            'lastCharge' => $lastCharge,
        ];
    }
}
