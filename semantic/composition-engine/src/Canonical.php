<?php

declare(strict_types=1);

namespace XiangGraph\S1;

/**
 * Canonical JSON encoding used for semantic comparison.
 *
 * This is deliberately not a byte-level diff: two patches are compared by
 * their normalized semantic projection (facts, invariant statuses, events).
 */
final class Canonical
{
    public static function normalize(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        $keys = array_keys($value);
        sort($keys, \SORT_STRING);
        $normalized = [];
        foreach ($keys as $key) {
            $normalized[$key] = self::normalize($value[$key]);
        }

        return $normalized;
    }

    public static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR
        );
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function same(mixed $a, mixed $b): bool
    {
        return self::encode($a) === self::encode($b);
    }
}
