<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\BadRequestException;
use App\Platform\Msisdn;
use App\Platform\PlatformClient;
use App\Repository\SubscriberRepository;
use PDO;

final class OptOutService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SubscriberRepository $subscribers,
        private readonly PlatformClient $platform,
    ) {
    }

    /** @return array<string, string> */
    public function optOut(string $subscriberId): array
    {
        if (!Msisdn::validate($subscriberId)) {
            throw new BadRequestException('Invalid subscriberId');
        }

        // Read under the row lock; the Platform call is outside the transaction.
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT id, status FROM subscribers WHERE id = ? FOR UPDATE');
            $stmt->execute([$subscriberId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->pdo->commit();

            if ($row !== false && ($row['status'] ?? null) === 'INACTIVE') {
                return ['status' => 'already_inactive'];
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        // Platform failure must not leave the subscriber INACTIVE.
        $this->platform->sendSubscription($subscriberId, '0');

        $this->pdo->beginTransaction();
        try {
            $this->subscribers->setStatus($subscriberId, 'INACTIVE');
            // STOP also cancels any scheduled retries for this subscriber.
            $stmt = $this->pdo->prepare(
                "UPDATE charge_attempts
                    SET next_retry_at = NULL, updated_at = now()
                  WHERE subscriber_id = ? AND status = 'failed_transient'"
            );
            $stmt->execute([$subscriberId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $message = 'You have been unsubscribed from Daily Pass. Reply START to resubscribe.';
        $sms = $this->platform->sendSms($subscriberId, $message);
        $this->subscribers->insertSmsLog(
            $subscriberId,
            'MT',
            $message,
            isset($sms['statusCode']) && is_string($sms['statusCode']) ? $sms['statusCode'] : null,
        );

        return ['status' => 'inactive'];
    }
}
