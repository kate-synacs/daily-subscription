<?php

declare(strict_types=1);

namespace Mock;

use GuzzleHttp\Client;

/** Admin endpoints (underscore-prefixed): not part of the Platform contract. */
final class Control
{
    private const MODES = ['S1000', 'E1326', 'E1308', 'E1318', 'E1337', 'auto-dup'];
    private const STATUSES = ['REGISTERED', 'UNREGISTERED', 'PENDING', 'CHARGE'];

    public function __construct(private readonly MockState $state)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function debitStatus(array $body): array
    {
        $mode = $body['mode'] ?? null;
        if (!is_string($mode) || !in_array($mode, self::MODES, true)) {
            return [400, ['error' => 'invalid mode']];
        }

        $state = $this->state->load();
        $state['mode'] = $mode;
        $this->state->save($state);

        return [200, ['ok' => true, 'mode' => $mode]];
    }

    /**
     * Push a §B subscription notification to the service. Upstream failure is
     * reported in the response, never thrown: the caller wants to see what happened.
     *
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function pushCallback(array $body): array
    {
        $subscriberId = $body['subscriberId'] ?? null;
        $status = $body['status'] ?? null;
        if (
            !is_string($subscriberId) || preg_match('/^tel:\d+$/', $subscriberId) !== 1
            || !is_string($status) || !in_array($status, self::STATUSES, true)
        ) {
            return [400, ['error' => 'invalid request']];
        }

        $payload = [
            'applicationId' => (string) (getenv('PLATFORM_APP_ID') ?: ''),
            'subscriberId' => $subscriberId,
            'frequency' => 'daily',
            'status' => $status,
            'version' => '1.0',
            'timeStamp' => gmdate('c'),
        ];

        $url = getenv('SERVICE_CALLBACK_URL') ?: 'http://service:8080/webhooks/subscription';
        $client = new Client(['timeout' => 5, 'http_errors' => false]);

        try {
            $response = $client->post($url, ['json' => $payload]);

            return [200, [
                'delivered' => true,
                'upstreamStatus' => $response->getStatusCode(),
                'upstreamBody' => (string) $response->getBody(),
            ]];
        } catch (\Throwable) {
            return [200, [
                'delivered' => false,
                'upstreamStatus' => null,
                'upstreamBody' => null,
            ]];
        }
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    public function reset(): array
    {
        $this->state->reset();

        return [200, ['ok' => true]];
    }
}
