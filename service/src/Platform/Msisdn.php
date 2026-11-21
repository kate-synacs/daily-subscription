<?php

declare(strict_types=1);

namespace App\Platform;

/** The subscriberId is an opaque alias; we only validate its shape and mask it for logs. */
final class Msisdn
{
    public static function validate(string $id): bool
    {
        return preg_match('/^tel:\d+$/', $id) === 1;
    }

    /** e.g. tel:1000000001 -> tel:***0001 */
    public static function mask(string $id): string
    {
        if (!str_starts_with($id, 'tel:')) {
            return 'tel:***';
        }

        $digits = substr($id, 4);
        $length = strlen($digits);
        if ($length >= 4) {
            return 'tel:***' . substr($digits, -4);
        }
        if ($length >= 2) {
            return 'tel:***' . substr($digits, -2);
        }

        return 'tel:***';
    }
}
