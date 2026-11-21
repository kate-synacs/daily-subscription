<?php

declare(strict_types=1);

namespace Mock;

/**
 * File-backed state for the mock. Single writer in the demo; flock() guards
 * writes so concurrent curl scenarios cannot corrupt the JSON document.
 */
final class MockState
{
    private const PATH = '/tmp/mock-state.json';

    /** @return array<string, mixed> */
    public function load(): array
    {
        $raw = @file_get_contents(self::PATH);
        if ($raw === false) {
            return $this->defaults();
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->defaults();
        }

        if (!is_array($decoded)) {
            return $this->defaults();
        }

        /** @var array<string, mixed> $decoded */
        return array_merge($this->defaults(), $decoded);
    }

    /** @param array<string, mixed> $state */
    public function save(array $state): void
    {
        $handle = @fopen(self::PATH, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (flock($handle, LOCK_EX)) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, json_encode($state, JSON_UNESCAPED_SLASHES) ?: '{}');
                fflush($handle);
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, mixed> */
    public function reset(): array
    {
        $defaults = $this->defaults();
        $this->save($defaults);

        return $defaults;
    }

    /** @return array<string, mixed> */
    private function defaults(): array
    {
        return [
            'mode' => 'S1000',
            'seenExternalTrxIds' => [],
            'sms' => [],
            'debits' => [],
        ];
    }
}
