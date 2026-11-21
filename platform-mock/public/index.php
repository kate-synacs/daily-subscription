<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Mock\Contract;
use Mock\Control;
use Mock\MockState;

$state = new MockState();
$contract = new Contract($state);
$control = new Control($state);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$rawBody = file_get_contents('php://input');
$decoded = json_decode($rawBody === false ? '' : $rawBody, true);
$body = is_array($decoded) ? $decoded : [];

/** @param array<string, mixed> $payload */
$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
};

$route = $method . ' ' . $path;

switch ($route) {
    case 'GET /health':
        $respond(200, ['ok' => true, 'service' => 'platform-mock']);

    case 'POST /subscription/send':
        $respond(200, $contract->subscriptionSend($body));

    case 'POST /sms/send':
        $respond(200, $contract->smsSend($body));

    case 'POST /caas/direct/debit':
        $respond(200, $contract->debit($body));

    case 'POST /_control/debit-status':
        [$status, $payload] = $control->debitStatus($body);
        $respond($status, $payload);

    case 'POST /_control/push-callback':
        [$status, $payload] = $control->pushCallback($body);
        $respond($status, $payload);

    case 'POST /_control/reset':
        [$status, $payload] = $control->reset();
        $respond($status, $payload);

    case 'GET /_log/sms':
        $current = $state->load();
        $respond(200, is_array($current['sms'] ?? null) ? $current['sms'] : []);

    case 'GET /_log/debits':
        $current = $state->load();
        $respond(200, is_array($current['debits'] ?? null) ? $current['debits'] : []);

    default:
        $respond(404, ['error' => 'not_found']);
}
