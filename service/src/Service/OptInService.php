<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\BadRequestException;
use App\Platform\Msisdn;
use App\Platform\PlatformClient;
use App\Repository\SubscriberRepository;
use PDO;

final class OptInService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SubscriberRepository $subscribers,
        private readonly PlatformClient $platform,
    ) {
    }

    /** @return array<string, string> */
    public function optIn(string $subscriberId): array
    {
        if (!Msisdn::validate($subscriberId)) {
            throw new BadRequestException('Invalid subscriberId');
        }

        // Lock the row only long enough to read the current opt-in state. The
        // Platform call below is deliberately outside this transaction.
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, status, platform_registered_at FROM subscribers WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$subscriberId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($row !== false) {
            $status = $row['status'] ?? null;
            $registeredAt = $row['platform_registered_at'] ?? null;
            if ($status === 'ACTIVE' && $registeredAt !== null) {
                // Fully registered: never call the Platform again.
                return ['status' => 'already_active', 'subscriberId' => $subscriberId];
            }
            // ACTIVE + NULL timestamp is a half-done opt-in; INACTIVE/SUSPENDED
            // are re-opt-ins. Both paths call the Platform again.
        }

        $this->platform->sendSubscription($subscriberId, '1');

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO subscribers (id, status, platform_registered_at)
                 VALUES (?, 'ACTIVE', now())
                 ON CONFLICT (id) DO UPDATE
                   SET status = 'ACTIVE', platform_registered_at = now(), updated_at = now()"
            );
            $stmt->execute([$subscriberId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $sms = $this->platform->sendSms($subscriberId, 'Welcome to Daily Pass');
        $this->subscribers->insertSmsLog(
            $subscriberId,
            'MT',
            'Welcome to Daily Pass',
            isset($sms['statusCode']) && is_string($sms['statusCode']) ? $sms['statusCode'] : null,
        );

        return ['status' => 'active', 'subscriberId' => $subscriberId];
    }
}
