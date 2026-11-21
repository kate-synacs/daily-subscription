<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Platform\Msisdn;
use App\Platform\PlatformClient;
use App\Repository\ChargeAttemptRepository;
use App\Repository\SubscriberRepository;
use PDO;
use Psr\Log\LoggerInterface;

final class ChargeService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ChargeAttemptRepository $attempts,
        private readonly SubscriberRepository $subscribers,
        private readonly ChargeCalculator $calculator,
        private readonly PlatformClient $platform,
        private readonly LoggerInterface $log,
    ) {
    }

    /**
     * Charge one subscriber for one UTC billing day.
     *
     * Ordering is the whole point: lock + insert pending + COMMIT first, call
     * the Platform with no DB lock held, then finalize in a short transaction.
     *
     * @return array<string, mixed>
     */
    public function chargeSubscriber(string $subscriberId, string $billingDay, Clock $clock): array
    {
        $externalTrxId = ChargeCalculator::externalTrxId($subscriberId, $billingDay);
        $amount = $this->calculator->amount();
        $currency = $this->calculator->currency();
        $attemptId = null;

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT id, status FROM subscribers WHERE id = ? FOR UPDATE'
            );
            $stmt->execute([$subscriberId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false || ($row['status'] ?? null) !== 'ACTIVE') {
                $this->pdo->rollBack();

                return [
                    'subscriberId' => $subscriberId,
                    'outcome' => 'skipped',
                    'reason' => 'not_active',
                ];
            }

            $attemptId = $this->attempts->insertPending(
                $subscriberId,
                $billingDay,
                $externalTrxId,
                $amount,
                $currency,
            );

            if ($attemptId === null) {
                // Conflict on (subscriber_id, billing_day): never mint a new id.
                $this->pdo->rollBack();
            } else {
                // Attempt is durable BEFORE the Platform call; no lock is held.
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if ($attemptId === null) {
            $existing = $this->attempts->findPendingAttempt($subscriberId, $billingDay);
            if ($existing === null || ($existing['status'] ?? null) !== 'pending') {
                return [
                    'subscriberId' => $subscriberId,
                    'outcome' => 'skipped',
                    'reason' => 'already_attempted_today',
                ];
            }
            // Crash recovery: reuse the exact external_trx_id stored earlier.
            $externalTrxId = (string) $existing['external_trx_id'];
            $attemptId = (int) $existing['id'];
        }

        try {
            $response = $this->platform->directDebit(
                $externalTrxId,
                $subscriberId,
                $amount,
                $currency,
            );
        } catch (\Throwable) {
            // Leave the attempt 'pending': the next run recovers with this id.
            $this->log->warning('direct debit transport error, attempt left pending', [
                'attemptId' => $attemptId,
                'externalTrxId' => $externalTrxId,
            ]);

            return [
                'subscriberId' => $subscriberId,
                'outcome' => 'error',
                'reason' => 'transport',
                'attemptId' => $attemptId,
            ];
        }

        $code = isset($response['statusCode']) && is_string($response['statusCode'])
            ? $response['statusCode']
            : 'UNKNOWN';
        $detail = isset($response['statusDetail']) && is_string($response['statusDetail'])
            ? $response['statusDetail']
            : '';

        switch ($code) {
            case 'S1000':
                return $this->finalizeCharged($subscriberId, $attemptId, $response, $detail, $amount, $currency);

            case 'E1337':
                // Already charged by the original request. Do NOT send a receipt:
                // E1337 carries no referenceId of the original charge.
                $this->pdo->beginTransaction();
                try {
                    $this->attempts->markAlreadyCharged($attemptId, $code);
                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }

                return [
                    'subscriberId' => $subscriberId,
                    'outcome' => 'already_charged',
                    'attemptId' => $attemptId,
                ];

            case 'E1308':
                $this->pdo->beginTransaction();
                try {
                    $this->attempts->markFailedPermanent($attemptId, $code, $detail);
                    $stmt = $this->pdo->prepare(
                        "UPDATE subscribers
                            SET status = 'SUSPENDED', last_charge_error = 'E1308', updated_at = now()
                          WHERE id = ?"
                    );
                    $stmt->execute([$subscriberId]);
                    $this->pdo->commit();
                } catch (\Throwable $e) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                    throw $e;
                }

                return [
                    'subscriberId' => $subscriberId,
                    'outcome' => 'suspended',
                    'attemptId' => $attemptId,
                ];

            case 'E1326':
            case 'E1318':
                return $this->finalizeTransient($subscriberId, $attemptId, $code, $detail, $clock);

            default:
                $this->log->warning('unknown debit status code, treating as transient', [
                    'attemptId' => $attemptId,
                    'statusCode' => $code,
                ]);

                return $this->finalizeTransient(
                    $subscriberId,
                    $attemptId,
                    $code,
                    $detail,
                    $clock,
                    '+1 hour',
                );
        }
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function finalizeCharged(
        string $subscriberId,
        int $attemptId,
        array $response,
        string $detail,
        string $amount,
        string $currency,
    ): array {
        $referenceId = is_string($response['referenceId'] ?? null) ? $response['referenceId'] : '';
        $internalTrxId = is_string($response['internalTrxId'] ?? null) ? $response['internalTrxId'] : '';

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE charge_attempts
                    SET status = 'charged', reference_id = ?, internal_trx_id = ?,
                        platform_status_code = 'S1000', platform_status_detail = ?,
                        updated_at = now()
                  WHERE id = ? AND status = 'pending'"
            );
            $stmt->execute([$referenceId, $internalTrxId, $detail, $attemptId]);
            if ($stmt->rowCount() === 0) {
                $this->pdo->rollBack();

                return [
                    'subscriberId' => $subscriberId,
                    'outcome' => 'race_lost',
                    'attemptId' => $attemptId,
                ];
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $message = sprintf('Daily Pass charged: %s %s. Ref: %s', $amount, $currency, $referenceId);
        // SMS is an external call: it is deliberately after the committed DB update.
        $this->platform->sendSms($subscriberId, $message);
        $this->subscribers->insertSmsLog($subscriberId, 'MT', $message, 'S1000');

        return [
            'subscriberId' => $subscriberId,
            'outcome' => 'charged',
            'attemptId' => $attemptId,
            'referenceId' => $referenceId,
        ];
    }

    /** @return array<string, mixed> */
    private function finalizeTransient(
        string $subscriberId,
        int $attemptId,
        string $code,
        string $detail,
        Clock $clock,
        ?string $forcedBackoff = null,
    ): array {
        $stmt = $this->pdo->prepare('SELECT attempt_count FROM charge_attempts WHERE id = ?');
        $stmt->execute([$attemptId]);
        $attemptCount = (int) $stmt->fetchColumn();

        $backoffs = ['+15 minutes', '+1 hour', '+4 hours'];
        $nextRetryAt = null;
        if ($attemptCount < 2) {
            $nextRetryAt = $clock->now()->modify($forcedBackoff ?? $backoffs[$attemptCount]);
        }

        $this->pdo->beginTransaction();
        try {
            $this->attempts->markFailedTransient($attemptId, $code, $detail, $nextRetryAt);
            $stmt = $this->pdo->prepare(
                'UPDATE subscribers SET last_charge_error = ? WHERE id = ?'
            );
            $stmt->execute([$code, $subscriberId]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return [
            'subscriberId' => $subscriberId,
            'outcome' => $forcedBackoff === null ? 'transient' : 'unknown_code',
            'attemptId' => $attemptId,
            'nextRetryAt' => $nextRetryAt?->format('Y-m-d\TH:i:sP'),
        ];
    }
}
