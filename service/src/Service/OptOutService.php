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

    /**
     * STOP is idempotent even under parallel delivery. The subscriber row is
     * locked until status='INACTIVE' is committed, so only the first STOP
     * ($wasActive === true) calls the Platform and sends the goodbye SMS.
     *
     * @return array<string, string>
     */
    public function optOut(string $subscriberId): array
    {
        if (!Msisdn::validate($subscriberId)) {
            throw new BadRequestException('Invalid subscriberId');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT status FROM subscribers WHERE id = ? FOR UPDATE');
            $stmt->execute([$subscriberId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false || ($row['status'] ?? null) === 'INACTIVE') {
                $this->pdo->commit();

                return ['status' => 'already_inactive'];
            }

            $wasActive = ($row['status'] ?? null) === 'ACTIVE';

            // Platform failure must roll the claim back: never leave INACTIVE
            // if opt-out was not accepted by the Platform.
            if ($wasActive) {
                $this->platform->sendSubscription($subscriberId, '0');
            }

            $this->subscribers->setStatus($subscriberId, 'INACTIVE');
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

        if ($wasActive) {
            $message = 'You have been unsubscribed from Daily Pass. Reply START to resubscribe.';
            $sms = $this->platform->sendSms($subscriberId, $message);
            $this->subscribers->insertSmsLog(
                $subscriberId,
                'MT',
                $message,
                isset($sms['statusCode']) && is_string($sms['statusCode']) ? $sms['statusCode'] : null,
            );
        }

        return ['status' => $wasActive ? 'inactive' : 'already_inactive'];
    }
}
