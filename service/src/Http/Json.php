<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\BadRequestException;

final class Json
{
    /** @return array<string, mixed> */
    public static function decode(string $body): array
    {
        if (trim($body) === '') {
            throw new BadRequestException('Invalid JSON body');
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestException('Invalid JSON body');
        }

        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new BadRequestException('Invalid JSON body');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
