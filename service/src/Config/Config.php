<?php

declare(strict_types=1);

namespace App\Config;

use App\Exception\ConfigurationException;

final class Config
{
    /** Platform credentials have no defaults: they must come from the environment. */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }

        return $value;
    }

    /** Fail fast: the key must be present in the environment (empty value is allowed). */
    public static function require(string $key): string
    {
        $value = getenv($key);
        if ($value === false) {
            throw new ConfigurationException('Missing required configuration: ' . $key);
        }

        return $value;
    }

    /** @param string[] $keys */
    public static function validateRequired(string ...$keys): void
    {
        $missing = [];
        foreach ($keys as $key) {
            if (getenv($key) === false) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new ConfigurationException(
                'Missing required configuration: ' . implode(', ', $missing)
            );
        }
    }
}
