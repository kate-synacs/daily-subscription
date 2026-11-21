<?php

declare(strict_types=1);

namespace App\Http;

final class Request
{
    /** @param array<string, string> $params */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly string $body,
        public readonly array $params = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $body = file_get_contents('php://input');
        if ($body === false) {
            $body = '';
        }

        return new self($method, $path, $body);
    }

    /** @param array<string, string> $params */
    public function withParams(array $params): self
    {
        return new self($this->method, $this->path, $this->body, $params);
    }
}
