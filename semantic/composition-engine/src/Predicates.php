<?php

declare(strict_types=1);

namespace XiangGraph\S1;

/**
 * Generic predicate evaluator.
 *
 * The engine knows only these operators. Business names live in scenario
 * data, never in this file. Adding a new domain must not require editing a
 * predicate named after the domain.
 */
final class Predicates
{
    /**
     * @return array{status: 'pass'|'fail'|'unknown', detail: string}
     */
    public static function evaluate(string $kind, array $inputs, array $facts, array $params = []): array
    {
        foreach ($inputs as $id) {
            if (!\array_key_exists($id, $facts)) {
                return ['status' => 'unknown', 'detail' => \sprintf('missing fact "%s"', $id)];
            }
        }

        return match ($kind) {
            'sum_lte' => self::sumLte($inputs, $facts, $params),
            'at_most_one_true' => self::countTrueLte($inputs, $facts, 1, $params),
            'count_true_lte' => self::countTrueLte($inputs, $facts, (int) ($params['limit'] ?? 1), $params),
            'implies_gte' => self::impliesGte($inputs, $facts, $params),
            'one_of' => self::oneOf($inputs, $facts, $params),
            'equals' => self::equals($inputs, $facts),
            'all_true' => self::allTrue($inputs, $facts, $params),
            'none_true' => self::noneTrue($inputs, $facts, $params),
            default => ['status' => 'unknown', 'detail' => \sprintf('unsupported predicate "%s"', $kind)],
        };
    }

    private static function sumLte(array $inputs, array $facts, array $params): array
    {
        $limit = $params['limit'] ?? null;
        if (!\is_int($limit) && !\is_float($limit)) {
            return ['status' => 'unknown', 'detail' => 'sum_lte requires numeric limit'];
        }

        $sum = 0;
        $parts = [];
        foreach ($inputs as $id) {
            $value = $facts[$id];
            if (!\is_int($value) && !\is_float($value)) {
                return ['status' => 'unknown', 'detail' => \sprintf('fact "%s" is not numeric', $id)];
            }
            $sum += $value;
            $parts[] = \sprintf('%s=%s', $id, (string) $value);
        }

        $ok = $sum <= $limit;
        return [
            'status' => $ok ? 'pass' : 'fail',
            'detail' => \sprintf('sum(%s)=%s %s %s', implode(', ', $parts), (string) $sum, $ok ? '<=' : '>', (string) $limit),
        ];
    }

    private static function countTrueLte(array $inputs, array $facts, int $limit, array $params): array
    {
        $truthy = [];
        foreach ($inputs as $id) {
            if ($facts[$id]) {
                $truthy[] = $id;
            }
        }

        $ok = \count($truthy) <= $limit;
        return [
            'status' => $ok ? 'pass' : 'fail',
            'detail' => \sprintf('true(%s)=%d %s %d', implode(', ', $truthy) ?: 'none', \count($truthy), $ok ? '<=' : '>', $limit),
        ];
    }

    private static function impliesGte(array $inputs, array $facts, array $params): array
    {
        $when = $params['when'] ?? null;
        $then = $params['then'] ?? null;
        $min = $params['min'] ?? null;
        if (!\is_string($when) || !\is_string($then) || (!\is_int($min) && !\is_float($min))) {
            return ['status' => 'unknown', 'detail' => 'implies_gte requires when/then/min'];
        }

        $whenValue = $facts[$when];
        $thenValue = $facts[$then];
        if (!\is_int($thenValue) && !\is_float($thenValue)) {
            return ['status' => 'unknown', 'detail' => \sprintf('fact "%s" is not numeric', $then)];
        }

        if (!$whenValue) {
            return ['status' => 'pass', 'detail' => \sprintf('%s is false; implication not required', $when)];
        }

        $ok = $thenValue >= $min;
        return [
            'status' => $ok ? 'pass' : 'fail',
            'detail' => \sprintf('%s is true; %s=%s %s %s', $when, $then, (string) $thenValue, $ok ? '>=' : '<', (string) $min),
        ];
    }

    private static function oneOf(array $inputs, array $facts, array $params): array
    {
        $allowed = $params['allowed'] ?? null;
        if (!\is_array($allowed)) {
            return ['status' => 'unknown', 'detail' => 'one_of requires allowed[]'];
        }

        $parts = [];
        foreach ($inputs as $id) {
            $value = $facts[$id];
            if (!\in_array($value, $allowed, true)) {
                return [
                    'status' => 'fail',
                    'detail' => \sprintf('%s=%s is not in [%s]', $id, Canonical::encode($value), implode(', ', array_map(Canonical::encode(...), $allowed))),
                ];
            }
            $parts[] = \sprintf('%s=%s', $id, Canonical::encode($value));
        }

        return ['status' => 'pass', 'detail' => implode(', ', $parts) . ' allowed'];
    }

    private static function equals(array $inputs, array $facts): array
    {
        $values = array_map(static fn (string $id): mixed => $facts[$id], $inputs);
        $first = $values[0] ?? null;
        foreach ($values as $value) {
            if (Canonical::encode($value) !== Canonical::encode($first)) {
                return ['status' => 'fail', 'detail' => 'input values differ'];
            }
        }

        return ['status' => 'pass', 'detail' => 'input values equal'];
    }

    private static function allTrue(array $inputs, array $facts, array $params): array
    {
        $false = [];
        foreach ($inputs as $id) {
            if (!$facts[$id]) {
                $false[] = $id;
            }
        }

        return [
            'status' => $false === [] ? 'pass' : 'fail',
            'detail' => $false === [] ? 'all true' : 'false: ' . implode(', ', $false),
        ];
    }

    private static function noneTrue(array $inputs, array $facts, array $params): array
    {
        $true = [];
        foreach ($inputs as $id) {
            if ($facts[$id]) {
                $true[] = $id;
            }
        }

        return [
            'status' => $true === [] ? 'pass' : 'fail',
            'detail' => $true === [] ? 'all false' : 'true: ' . implode(', ', $true),
        ];
    }
}
