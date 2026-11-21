<?php

declare(strict_types=1);

namespace App\Tests\Charge;

use App\Tests\Http\HttpTestCase;

abstract class ChargeTestCase extends HttpTestCase
{
    protected function setDebitMode(string $mode): void
    {
        $this->mockPost('/_control/debit-status', ['mode' => $mode]);
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
