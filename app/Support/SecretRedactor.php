<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Strips credential-bearing values before anything is written to a log,
 * audit entry, or connection-log context payload. Keys are matched
 * case-insensitively against config('mikweb.redacted_keys').
 */
final class SecretRedactor
{
    public const MASK = '[redacted]';

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function scrub(array $data): array
    {
        $keys = array_map('strtolower', (array) config('mikweb.redacted_keys', []));

        return self::walk($data, $keys);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<array-key, mixed>
     */
    private static function walk(array $data, array $keys): array
    {
        foreach ($data as $key => $value) {
            $normalized = is_string($key) ? strtolower(str_replace('-', '_', $key)) : $key;

            if (is_string($normalized) && in_array($normalized, $keys, true)) {
                $data[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::walk($value, $keys);
            }
        }

        return $data;
    }
}
