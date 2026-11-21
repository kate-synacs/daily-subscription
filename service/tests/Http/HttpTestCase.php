<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Repository\PdoFactory;
use GuzzleHttp\Client;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class HttpTestCase extends TestCase
{
    protected Client $client;
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->client = new Client([
            'base_uri' => 'http://service:8080',
            'timeout' => 5,
            'http_errors' => false,
        ]);

        $this->pdo = PdoFactory::fromEnv();

        // charge_attempts references subscribers, so a plain DELETE FROM
        // subscribers fails with SQLSTATE 23503 whenever leftover attempts
        // exist (e.g. after the README scenarios). Truncate the whole set in a
        // single statement: children are removed too, sequences are reset and
        // no test depends on what other tests or manual curls left behind.
        $this->pdo->exec(
            'TRUNCATE charge_attempts, sms_log, processed_webhook_events, subscribers '
            . 'RESTART IDENTITY CASCADE'
        );

        // Keep the mock deterministic: clears logs and seen transaction ids and
        // resets both mode switches (debit + subscription) back to S1000.
        $this->client->post('http://platform-mock:8080/_control/reset');
    }

    /** @param array<string, mixed> $json @return array{0: int, 1: array<string, mixed>} */
    protected function post(string $path, array $json): array
    {
        $response = $this->client->post($path, ['json' => $json]);
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    protected function get(string $path): array
    {
        $response = $this->client->get($path);
        $decoded = json_decode((string) $response->getBody(), true);

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
    }

    /** POST to a platform-mock control endpoint; failures are intentionally ignored. */
    protected function mockPost(string $path, array $json = []): void
    {
        $this->client->post('http://platform-mock:8080' . $path, ['json' => $json]);
    }

    /** @return array<int|string, mixed> */
    protected function mockLog(string $path): array
    {
        $response = $this->client->get('http://platform-mock:8080' . $path);
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
