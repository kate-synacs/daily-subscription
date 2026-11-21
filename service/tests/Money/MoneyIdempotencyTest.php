<?php

declare(strict_types=1);

namespace App\Tests\Money;

use App\Repository\PdoFactory;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;
use PDO;
use PHPUnit\Framework\TestCase;

/** Parallel-delivery idempotency: correctness must come from Postgres only. */
final class MoneyIdempotencyTest extends TestCase
{
    private Client $service;
    private Client $mock;
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->service = new Client([
            'base_uri' => 'http://service:8080',
            'timeout' => 15,
            'http_errors' => false,
        ]);
        $this->mock = new Client([
            'base_uri' => 'http://platform-mock:8080',
            'timeout' => 15,
            'http_errors' => false,
        ]);
        $this->pdo = PdoFactory::fromEnv();
        $this->pdo->exec(
            'TRUNCATE charge_attempts, sms_log, processed_webhook_events, subscribers RESTART IDENTITY CASCADE'
        );
        $this->mock->post('/_control/reset');
    }

    public function test_parallel_daily_charge_charges_once(): void
    {
        $this->mode('S1000');
        $this->optIn('tel:1000000031');

        $responses = Utils::all([
            $this->service->postAsync('/jobs/daily-charge', ['json' => []]),
            $this->service->postAsync('/jobs/daily-charge', ['json' => []]),
        ])->wait();
        foreach ($responses as $response) {
            self::assertSame(200, $response->getStatusCode());
        }

        self::assertSame(1, (int) $this->pdo->query(
            "SELECT count(*) FROM charge_attempts WHERE subscriber_id = 'tel:1000000031'"
        )->fetchColumn());
        self::assertSame('charged', $this->scalar(
            "SELECT status FROM charge_attempts WHERE subscriber_id = 'tel:1000000031'"
        ));
        self::assertCount(1, $this->mockLog('/_log/debits'));
        self::assertCount(1, $this->receipts());
    }

    public function test_parallel_stop_is_idempotent(): void
    {
        $this->mode('S1000');
        $this->optIn('tel:1000000032');

        $payload = [
            'json' => [
                'applicationId' => 'APP_000123',
                'sourceAddress' => 'tel:1000000032',
                'message' => 'STOP',
                'encoding' => '0',
                'version' => '1.0',
            ],
        ];
        $responses = Utils::all([
            $this->service->postAsync('/webhooks/mo-sms', $payload),
            $this->service->postAsync('/webhooks/mo-sms', $payload),
        ])->wait();
        foreach ($responses as $response) {
            self::assertSame(200, $response->getStatusCode());
            self::assertSame('S1000', $this->json((string) $response->getBody())['statusCode'] ?? null);
        }

        self::assertSame('INACTIVE', $this->scalar(
            "SELECT status FROM subscribers WHERE id = 'tel:1000000032'"
        ));
        $subscriptions = $this->mockLog('/_log/subscriptions');
        self::assertSame(1, count(array_filter(
            $subscriptions,
            static fn (array $s): bool => ($s['action'] ?? null) === '0'
                && ($s['subscriberId'] ?? null) === 'tel:1000000032',
        )));
        self::assertSame(1, count(array_filter(
            $this->sms(),
            static fn (array $s): bool => str_contains((string) ($s['message'] ?? ''), 'unsubscribed'),
        )));
    }

    public function test_parallel_retry_claims_once(): void
    {
        $this->mode('E1326');
        $this->optIn('tel:1000000033');
        $this->runJob();

        self::assertSame('failed_transient', $this->scalar(
            "SELECT status FROM charge_attempts WHERE subscriber_id = 'tel:1000000033'"
        ));

        $this->mode('S1000');
        $this->pdo->exec(
            "UPDATE charge_attempts
                SET next_retry_at = now() - interval '1 second'
              WHERE subscriber_id = 'tel:1000000033'"
        );

        Utils::all([
            $this->service->postAsync('/jobs/daily-charge', ['json' => []]),
            $this->service->postAsync('/jobs/daily-charge', ['json' => []]),
        ])->wait();

        $debits = array_values(array_filter(
            $this->mockLog('/_log/debits'),
            static fn (array $d): bool => ($d['subscriberId'] ?? null) === 'tel:1000000033',
        ));
        self::assertCount(2, $debits, 'Initial E1326 plus exactly one leased retry');
        self::assertSame($debits[0]['externalTrxId'] ?? null, $debits[1]['externalTrxId'] ?? null);
        self::assertSame('E1326', $debits[0]['statusCode'] ?? null);
        self::assertSame('S1000', $debits[1]['statusCode'] ?? null);
        self::assertSame('charged', $this->scalar(
            "SELECT status FROM charge_attempts WHERE subscriber_id = 'tel:1000000033'"
        ));
        self::assertCount(1, $this->receipts());
    }

    public function test_e1337_recovery_does_not_resend_receipt(): void
    {
        $this->mode('S1000');
        $this->optIn('tel:1000000034');
        $this->runJob();
        self::assertCount(1, $this->receipts());

        $this->pdo->exec(
            "UPDATE charge_attempts
                SET status = 'pending', reference_id = NULL, internal_trx_id = NULL
              WHERE subscriber_id = 'tel:1000000034'"
        );
        $this->mode('auto-dup');
        $this->runJob();

        self::assertSame('already_charged', $this->scalar(
            "SELECT status FROM charge_attempts WHERE subscriber_id = 'tel:1000000034'"
        ));
        self::assertCount(2, $this->mockLog('/_log/debits'));
        self::assertCount(1, $this->receipts(), 'E1337 must never resend a receipt');
    }

    private function mode(string $mode): void
    {
        $this->mock->post('/_control/debit-status', ['json' => ['mode' => $mode]]);
    }

    private function optIn(string $subscriberId): void
    {
        $response = $this->service->post('/subscribers/opt-in', ['json' => ['subscriberId' => $subscriberId]]);
        self::assertSame(200, $response->getStatusCode());
    }

    private function runJob(): void
    {
        $response = $this->service->post('/jobs/daily-charge', ['json' => []]);
        self::assertSame(200, $response->getStatusCode());
    }

    /** @return array<int|string, mixed> */
    private function mockLog(string $path): array
    {
        $decoded = $this->json((string) $this->mock->get($path)->getBody());

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<int|string, mixed> */
    private function sms(): array
    {
        return $this->mockLog('/_log/sms');
    }

    /** @return array<int, string> */
    private function receipts(): array
    {
        return array_values(array_filter(
            array_map(static fn (array $s): string => (string) ($s['message'] ?? ''), $this->sms()),
            static fn (string $m): bool => str_contains($m, 'Daily Pass charged'),
        ));
    }

    /** @return array<string, mixed> */
    private function json(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function scalar(string $sql): string
    {
        return (string) $this->pdo->query($sql)->fetchColumn();
    }
}
