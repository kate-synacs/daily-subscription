<?php

declare(strict_types=1);

namespace App\Tests\Money;

use App\Tests\Http\HttpTestCase;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\Utils;

final class MoneyIdempotencyTest extends HttpTestCase
{
    private const DAY = '2026-01-15';

    public function test_two_parallel_jobs_charge_once(): void
    {
        $msisdn = 'tel:1000000301';
        $this->optIn($msisdn);

        $this->parallel([
            fn (): PromiseInterface => $this->client->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
            fn (): PromiseInterface => $this->client->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
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
        $this->setDebitMode('E1326');

        $this->parallel([
            fn (): PromiseInterface => $this->client->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
            fn (): PromiseInterface => $this->client->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
        ]);

        $attempt = $this->attempt($msisdn);
        self::assertSame('failed_transient', $attempt['status']);
        self::assertSame(1, (int) $attempt['attempt_count'], 'parallel runs must not double-count the attempt');
        self::assertCount(1, $this->debitsFor($msisdn));

        // make the retry due, then run two jobs in parallel again
        $this->pdo
            ->prepare("UPDATE charge_attempts SET next_retry_at = now() - interval '1 minute' WHERE subscriber_id = ?")
            ->execute([$msisdn]);
        $this->setDebitMode('S1000');

        $this->parallel([
            fn (): PromiseInterface => $this->client->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
            fn (): PromiseInterface => $this->client->postAsync('/jobs/daily-charge?date=' . self::DAY, ['json' => []]),
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
            fn (): PromiseInterface => $this->client->postAsync('/webhooks/mo-sms', [
                'json' => ['sourceAddress' => $msisdn, 'message' => 'STOP', 'encoding' => '0', 'version' => '1.0'],
            ]),
            fn (): PromiseInterface => $this->client->postAsync('/webhooks/mo-sms', [
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
        // a second run is a new HTTP request = new process, no in-memory state
        $this->runJob(self::DAY);
        $this->runJob(self::DAY);

        self::assertCount(1, $this->debitsFor($msisdn));
        self::assertSame('charged', $this->attempt($msisdn)['status']);
    }

    private function optIn(string $msisdn): void
    {
        $response = $this->apiJson('POST', '/subscribers/opt-in', ['subscriberId' => $msisdn]);
        self::assertNotSame('error', $response['status'] ?? 'error', json_encode($response));
    }

    private function stop(string $msisdn): void
    {
        $response = $this->apiJson('POST', '/webhooks/mo-sms', [
            'sourceAddress' => $msisdn,
            'message' => 'STOP',
            'encoding' => '0',
            'version' => '1.0',
        ]);

        self::assertSame('S1000', $response['statusCode'] ?? null, json_encode($response));
    }

    private function runJob(string $day): array
    {
        return $this->apiJson('POST', '/jobs/daily-charge?date=' . $day);
    }

    private function setDebitMode(string $mode): void
    {
        $this->mockPost('/_control/debit-status', ['mode' => $mode]);
    }

    /** @param array<string, mixed> $body */
    private function apiJson(string $method, string $path, array $body = []): array
    {
        $response = $this->client->request($method, $path, ['json' => $body]);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param list<callable(): PromiseInterface> $calls */
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
        return array_values(array_filter(
            $this->mockLog('/_log/debits'),
            static fn (array $row): bool => $row['subscriberId'] === $msisdn
        ));
    }

    private function outgoingSmsCount(string $msisdn): int
    {
        return count(array_filter(
            $this->mockLog('/_log/sms'),
            static fn (array $row): bool => ($row['to'] ?? null) === $msisdn
                && ($row['direction'] ?? 'MT') === 'MT'
        ));
    }

    private function attempt(string $msisdn): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM charge_attempts WHERE subscriber_id = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$msisdn]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function subscriber(string $msisdn): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM subscribers WHERE id = ?');
        $stmt->execute([$msisdn]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}