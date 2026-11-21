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

        $cleanup = $this->pdo->prepare(
            "DELETE FROM subscribers WHERE id IN ('tel:1000000001', 'tel:9999999999')"
        );
        $cleanup->execute();

        // Keep the mock's SMS log deterministic for each test.
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

    /** @return array<int|string, mixed> */
    protected function mockLog(string $path): array
    {
        $response = $this->client->get('http://platform-mock:8080' . $path);
        $decoded = json_decode((string) $response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
