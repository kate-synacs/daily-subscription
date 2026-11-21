<?php

declare(strict_types=1);

namespace App\Tests\Webhook;

use App\Tests\Charge\ChargeTestCase;

final class MoSmsOptOutTest extends ChargeTestCase
{
    private const SUBSCRIBER = 'tel:1000000020';

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec(
            "DELETE FROM sms_log WHERE subscriber_id = '" . self::SUBSCRIBER . "';
             DELETE FROM charge_attempts WHERE subscriber_id = '" . self::SUBSCRIBER . "';
             DELETE FROM subscribers WHERE id = '" . self::SUBSCRIBER . "';"
        );
    }

    public function test_stop_opts_out_known_subscriber(): void
    {
        $this->setDebitMode('S1000');
        $this->optIn(self::SUBSCRIBER);
        self::assertSame('ACTIVE', $this->subscriberStatus(self::SUBSCRIBER));

        [$status, $body] = $this->stop(self::SUBSCRIBER);
        self::assertSame(200, $status);
        self::assertSame('S1000', $body['statusCode'] ?? null);
        self::assertSame('INACTIVE', $this->subscriberStatus(self::SUBSCRIBER));

        $goodbyes = array_values(array_filter(
            array_map(static fn (array $sms): string => (string) ($sms['message'] ?? ''), $this->smsLog()),
            static fn (string $message): bool => str_contains($message, 'unsubscribed'),
        ));
        self::assertCount(1, $goodbyes);

        $subscriptions = $this->mockLog('/_log/subscriptions');
        self::assertCount(2, $subscriptions);
        self::assertSame('0', $subscriptions[1]['action'] ?? null);
    }

    public function test_stop_for_unknown_subscriber_is_noop(): void
    {
        [$status, $body] = $this->stop('tel:9999999999');

        self::assertSame(200, $status);
        self::assertSame('S1000', $body['statusCode'] ?? null);
        self::assertSame(0, (int) $this->pdo->query(
            "SELECT count(*) FROM subscribers WHERE id = 'tel:9999999999'"
        )->fetchColumn());
    }

    public function test_stop_is_idempotent(): void
    {
        $this->optIn(self::SUBSCRIBER);

        self::assertSame(200, $this->stop(self::SUBSCRIBER)[0]);
        [, $second] = $this->stop(self::SUBSCRIBER);
        self::assertSame('S1000', $second['statusCode'] ?? null);
        self::assertSame('INACTIVE', $this->subscriberStatus(self::SUBSCRIBER));

        $subscriptions = $this->mockLog('/_log/subscriptions');
        self::assertCount(2, $subscriptions, 'action "0" must be sent once');
        $goodbyes = array_filter(
            array_map(static fn (array $sms): string => (string) ($sms['message'] ?? ''), $this->smsLog()),
            static fn (string $message): bool => str_contains($message, 'unsubscribed'),
        );
        self::assertCount(1, $goodbyes);
    }

    public function test_opt_out_cancels_pending_retries(): void
    {
        $this->setDebitMode('E1326');
        $this->optIn(self::SUBSCRIBER);
        $this->runChargeJob();

        $attempt = $this->attemptFor(self::SUBSCRIBER);
        self::assertIsArray($attempt);
        self::assertSame('failed_transient', $attempt['status']);
        self::assertNotNull($attempt['next_retry_at']);
        $debitsBefore = count($this->debitLog());

        $this->stop(self::SUBSCRIBER);

        $stopped = $this->attemptFor(self::SUBSCRIBER);
        self::assertIsArray($stopped);
        self::assertNull($stopped['next_retry_at']);
        self::assertSame('INACTIVE', $this->subscriberStatus(self::SUBSCRIBER));

        $this->setDebitMode('S1000');
        $this->pdo->exec(
            "UPDATE charge_attempts SET next_retry_at = now() - interval '1 second'
              WHERE subscriber_id = '" . self::SUBSCRIBER . "'"
        );
        [, $summary] = $this->runChargeJob();

        self::assertSame(0, $summary['charged'] ?? null);
        self::assertSame($debitsBefore, count($this->debitLog()), 'No Platform call after STOP');
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function stop(string $subscriberId): array
    {
        return $this->post('/webhooks/mo-sms', [
            'applicationId' => 'APP_000123',
            'sourceAddress' => $subscriberId,
            'message' => 'STOP',
            'encoding' => '0',
            'version' => '1.0',
        ]);
    }

    private function subscriberStatus(string $subscriberId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM subscribers WHERE id = ?');
        $stmt->execute([$subscriberId]);

        return (string) $stmt->fetchColumn();
    }
}
