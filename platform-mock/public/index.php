<?php

declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH) ?: '/';

header('Content-Type: application/json');

if ($method === 'GET' && $path === '/health') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'service' => 'platform-mock'], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(404);
echo json_encode([
    'ok' => false,
    'error' => ['code' => 'not_found', 'message' => 'Not found'],
], JSON_UNESCAPED_SLASHES);
