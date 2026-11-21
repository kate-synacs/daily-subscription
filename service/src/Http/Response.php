<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly int $status,
        public readonly array $data,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function json(int $status, array $data): self
    {
        return new self($status, $data);
    }

    public function emit(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json');
        echo json_encode($this->data, JSON_UNESCAPED_SLASHES);
    }
}
