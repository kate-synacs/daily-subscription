<?php

declare(strict_types=1);

namespace Tests\Money;

use GuzzleHttp\Client;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;
use PDO;
use PHPUnit\Framework\TestCase;

final class MoneyIdempotencyTest extends TestCase
{
    private const DAY = '2026-01-15';

    private Client $api;
    private Client $mock;
    private PDO $db;

    protected function setUp(): void
    {
        $this->api = new Client([
            'base_uri'    => getenv('SERVICE_URL') ?: 'http://127.0.0.1:8080',
            'http_errors' => false,
            'timeout'     => 20,
        ]);

        $this->mock = new Client([
            'base_uri'    => getenv('MOCK_URL') ?: 'http://platform-mock:8080',
            'http_errors' => false,
            'timeout'     => 20,
        ]);

        $this->db = new PDO(
            sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                getenv('DB_HOST') ?: 'db',
                getenv('DB_PORT') ?: '5432',
                getenv('DB_NAME') ?: 'dailypass'
            ),
            getenv('DB_USER') ?: 'dailypass',
            getenv('DB_PASSWORD') ?: 'dailypass',
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $this->db->exec('TRUNCATE charge_attempts, sms_log, processed_webhook_events, subscribers RESTART IDENTITY CASCADE');
        $this->mock->post('/_control/reset');
        $this->debitStatus('S1000');
    }

    public function test_two_parallel_jobs_charge_once(): void
    {
        $msisdn = 'tel:1000000301';
        $this->optIn($msisdn);

        $this->parallel([
            fn (): PromiseInterface => $this->api->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
            fn (): PromiseInterface => $this->api->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
        ]);

        self::assertCount(1, $this->debitsFor($msisdn), 'two parallel jobs must produce exactly one debit');

        $attempt = $this->attempt($msisdn);
        self::assertNotNull($attempt);
        self::assertSame('charged', $attempt['status']);
        self::assertSame(1, (int) $attempt['attempt_count']);
    }

    public function test_parallel_jobs_do_not_double_process_retry(): void
    {
        $msisdn = 'tel:1000000302';
        $this->optIn($msisdn);
        $this->debitStatus('E1326');

        $this->parallel([
            fn (): PromiseInterface => $this->api->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
            fn (): PromiseInterface => $this->api->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
        ]);

        $attempt = $this->attempt($msisdn);
        self::assertSame('failed_transient', $attempt['status']);
        self::assertSame(1, (int) $attempt['attempt_count'], 'parallel runs must not double-count the attempt');
        self::assertCount(1, $this->debitsFor($msisdn));

        // делаем ретрай просроченным и запускаем два прогона параллельно
        $this->db
            ->prepare("UPDATE charge_attempts SET next_retry_at = now() - interval '1 minute' WHERE subscriber_id = ?")
            ->execute([$msisdn]);
        $this->debitStatus('S1000');

        $this->parallel([
            fn (): PromiseInterface => $this->api->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
            fn (): PromiseInterface => $this->api->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
        ]);

        $attempt = $this->attempt($msisdn);
        self::assertSame('charged', $attempt['status']);
        self::assertSame(2, (int) $attempt['attempt_count'], 'one retry claimed by exactly one run');
        self::assertCount(2, $this->debitsFor($msisdn), 'first failing call + one retry call');
    }

    public function test_duplicate_stop_sends_one_goodbye_sms(): void
    {
        $msisdn = 'tel:1000000303';
        $this->optIn($msisdn);
        $smsBefore = $this->outgoingSmsCount($msisdn);

        $this->stop($msisdn);
        $this->stop($msisdn);

        self::assertSame('INACTIVE', $this->subscriber($msisdn)['status']);
        self::assertSame($smsBefore + 1, $this->outgoingSmsCount($msisdn), 'exactly one goodbye SMS');
    }

    public function test_parallel_stop_sends_one_goodbye_sms(): void
    {
        $msisdn = 'tel:1000000304';
        $this->optIn($msisdn);
        $smsBefore = $this->outgoingSmsCount($msisdn);

        $this->parallel([
            fn (): PromiseInterface => $this->api->postAsync('/webhooks/mo-sms', [
                'json' => ['sourceAddress' => $msisdn, 'message' => 'STOP', 'encoding' => '0', 'version' => '1.0'],
            ]),
            fn (): PromiseInterface => $this->api->postAsync('/webhooks/mo-sms', [
                'json' => ['sourceAddress' => $msisdn, 'message' => 'STOP', 'encoding' => '0', 'version' => '1.0'],
            ]),
        ]);

        self::assertSame('INACTIVE', $this->subscriber($msisdn)['status']);
        self::assertSame($smsBefore + 1, $this->outgoingSmsCount($msisdn), 'parallel STOP must send one SMS');
    }

    public function test_charge_after_stop_never_debits(): void
    {
        $msisdn = 'tel:1000000305';
        $this->optIn($msisdn);
        $this->stop($msisdn);

        $this->runJob(self::DAY);

        self::assertCount(0, $this->debitsFor($msisdn));
        self::assertNull($this->attempt($msisdn));
    }

    public function test_charge_once_survives_job_rerun_in_new_process(): void
    {
        $msisdn = 'tel:1000000306';
        $this->optIn($msisdn);

        $this->runJob(self::DAY);
        // второй прогон в новом HTTP-запросе = новый процесс, никакого in-memory state
        $this->runJob(self::DAY);
        $this->runJob(self::DAY);

        self::assertCount(1, $this->debitsFor($msisdn));
        self::assertSame('charged', $this->attempt($msisdn)['status']);
    }

    private function optIn(string $msisdn): void
    {
        $response = $this->json($this->api, 'POST', '/subscribers/opt-in', ['subscriberId' => $msisdn]);
        self::assertNotSame('error', $response['status'] ?? 'error', json_encode($response));
    }

    private function stop(string $msisdn): void
    {
        $response = $this->json($this->api, 'POST', '/webhooks/mo-sms', [
            'sourceAddress' => $msisdn,
            'message'       => 'STOP',
            'encoding'      => '0',
            'version'       => '1.0',
        ]);

        self::assertSame('S1000', $response['statusCode'] ?? null, json_encode($response));
    }

    private function runJob(string $day): array
    {
        return $this->json($this->api, 'POST', '/jobs/daily-charge?date=' . $day);
    }

    private function debitStatus(string $mode): void
    {
        $this->mock->post('/_control/debit-status', ['json' => ['mode' => $mode]]);
    }

    private function json(Client $client, string $method, string $path, array $body = []): array
    {
        $response = $client->request($method, $path, ['json' => $body]);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<callable(): PromiseInterface> $calls
     */
    private function parallel(array $calls): void
    {
        $promises = [];
        foreach ($calls as $call) {
            $promises[] = $call();
        }
        Utils::settle($promises)->wait();
    }

    /** @return list<array<string, mixed>> */
    private function debitsFor(string $msisdn): array
    {
        $debits = $this->json($this->mock, 'GET', '/_log/debits');

        return array_values(array_filter(
            $debits,
            static fn (array $row): bool => $row['subscriberId'] === $msisdn
        ));
    }

    private function outgoingSmsCount(string $msisdn): int
    {
        $sms = $this->json($this->mock, 'GET', '/_log/sms');

        return count(array_filter(
            $sms,
            static fn (array $row): bool => ($row['to'] ?? null) === $msisdn
                && ($row['direction'] ?? 'MT') === 'MT'
        ));
    }

    private function attempt(string $msisdn): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM charge_attempts WHERE subscriber_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$msisdn]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function subscriber(string $msisdn): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM subscribers WHERE id = ?');
        $stmt->execute([$msisdn]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}