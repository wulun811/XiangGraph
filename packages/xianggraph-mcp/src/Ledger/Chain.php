<?php

declare(strict_types=1);

namespace XiangGraph\Mcp\Ledger;

/**
 * Hash-chain verification for the demo01 event ledger.
 *
 * The algorithm is the one the runs were written with (see
 * experiments/c0-real-01/harness/EventLog.php):
 *
 *   event_hash = sha256(previous_hash . '|' . canonical_hash(event))
 *
 * where canonical_hash sorts object keys recursively and encodes with
 * JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE, and event_hash /
 * previous_hash are removed before encoding.
 *
 * This class deliberately verifies linkage and integrity only. It does not
 * re-validate the event schema the host enforced at write time.
 */
final class Chain
{
    /**
     * @param array<int, mixed> $events
     * @return array{valid: bool, errors: list<string>, head_hash: string, event_count: int}
     */
    public static function verify(array $events): array
    {
        $errors = [];
        $previousHash = '';
        $previousSeq = 0;

        foreach ($events as $index => $event) {
            $label = 'event[' . $index . ']';
            if (!\is_array($event)) {
                $errors[] = $label . ' must be an object';
                continue;
            }

            if (($event['previous_hash'] ?? null) !== $previousHash) {
                $errors[] = $label . ' previous_hash does not match the chain';
            }

            $seq = (int) ($event['seq'] ?? 0);
            if ($seq <= $previousSeq) {
                $errors[] = $label . ' seq must increase';
            }

            $stored = $event['event_hash'] ?? null;
            $computed = self::hash($event, $previousHash);
            if (!\is_string($stored) || !hash_equals($computed, $stored)) {
                $errors[] = $label . ' event_hash was tampered with';
            }

            $previousHash = \is_string($stored) ? $stored : '';
            $previousSeq = $seq;
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'head_hash' => $previousHash,
            'event_count' => \count($events),
        ];
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function hash(array $event, string $previousHash): string
    {
        unset($event['event_hash'], $event['previous_hash']);

        return hash('sha256', $previousHash . '|' . self::canonicalHash($event));
    }

    private static function canonicalHash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function encode(mixed $value): string
    {
        return json_encode(
            self::normalize($value),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR
        );
    }

    private static function normalize(mixed $value): mixed
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
}
