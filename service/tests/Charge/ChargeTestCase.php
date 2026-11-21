<?php

declare(strict_types=1);

namespace App\Tests\Charge;

use App\Tests\Http\HttpTestCase;

abstract class ChargeTestCase extends HttpTestCase
{
    /** @var string[] */
    private const CHARGE_SUBSCRIBERS = [
        'tel:1000000011',
        'tel:1000000012',
        'tel:1000000013',
        'tel:1000000014',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $ids = implode(', ', array_map(static fn (string $id): string => "'{$id}'", self::CHARGE_SUBSCRIBERS));
        $this->pdo->exec("DELETE FROM charge_attempts WHERE subscriber_id IN ($ids)");
        $this->pdo->exec("DELETE FROM sms_log WHERE subscriber_id IN ($ids)");
        $this->pdo->exec("DELETE FROM subscribers WHERE id IN ($ids)");
    }

    protected function setDebitMode(string $mode): void
    {
        $this->client->post('http://platform-mock:8080/_control/debit-status', [
            'json' => ['mode' => $mode],
        ]);
    }

    /** @return array<int|string, mixed> */
    protected function debitLog(): array
    {
        return $this->mockLog('/_log/debits');
    }

    /** @return array<int|string, mixed> */
    protected function smsLog(): array
    {
        return $this->mockLog('/_log/sms');
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    protected function runChargeJob(): array
    {
        return $this->post('/jobs/daily-charge', []);
    }

    protected function optIn(string $subscriberId): void
    {
        [$status] = $this->post('/subscribers/opt-in', ['subscriberId' => $subscriberId]);
        self::assertSame(200, $status);
    }

    /** @return array<string, mixed>|false */
    protected function attemptFor(string $subscriberId)
    {
        $stmt = $this->pdo->prepare('SELECT * FROM charge_attempts WHERE subscriber_id = ?');
        $stmt->execute([$subscriberId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /** @return array<int, string> */
    protected function receipts(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $sms): string => (string) ($sms['message'] ?? ''), $this->smsLog()),
            static fn (string $message): bool => str_contains($message, 'Daily Pass charged'),
        ));
    }
}
