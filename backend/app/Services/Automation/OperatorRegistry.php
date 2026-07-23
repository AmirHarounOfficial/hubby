<?php

namespace App\Services\Automation;

/**
 * Evaluates a single condition leaf: operator × actual value × expected value → bool (spec 02 §3.6).
 *
 * The null and coercion rules are the whole ballgame and are exhaustively tested:
 *  - a null actual is `false` for every operator EXCEPT is_empty / none_of / not_in — a missing
 *    weight must never accidentally satisfy `weight < 5000`;
 *  - strings compare case-insensitively and trimmed unless case_sensitive;
 *  - a non-numeric actual on a numeric operator is `false`, never an exception.
 */
class OperatorRegistry
{
    /** Operators that a null actual can still legitimately satisfy. */
    private const NULL_SAFE = ['is_empty', 'none_of', 'not_in'];

    public static function evaluate(string $operator, mixed $actual, mixed $value, bool $caseSensitive = false): bool
    {
        if ($actual === null && ! in_array($operator, self::NULL_SAFE, true)) {
            return false;
        }

        return match ($operator) {
            'eq' => self::scalarEq($actual, $value, $caseSensitive),
            'neq' => ! self::scalarEq($actual, $value, $caseSensitive),
            'gt' => self::isNumeric($actual, $value) && (float) $actual > (float) $value,
            'gte' => self::isNumeric($actual, $value) && (float) $actual >= (float) $value,
            'lt' => self::isNumeric($actual, $value) && (float) $actual < (float) $value,
            'lte' => self::isNumeric($actual, $value) && (float) $actual <= (float) $value,
            'between' => self::between($actual, $value),
            'in' => self::inList($actual, $value, $caseSensitive),
            'not_in' => ! self::inList($actual, $value, $caseSensitive),
            'contains' => self::str($actual, $caseSensitive) !== null
                && str_contains(self::str($actual, $caseSensitive), self::str($value, $caseSensitive) ?? ''),
            'not_contains' => ! (self::str($actual, $caseSensitive) !== null
                && str_contains(self::str($actual, $caseSensitive), self::str($value, $caseSensitive) ?? '')),
            'starts_with' => str_starts_with(self::str($actual, $caseSensitive) ?? '', self::str($value, $caseSensitive) ?? ''),
            'ends_with' => str_ends_with(self::str($actual, $caseSensitive) ?? '', self::str($value, $caseSensitive) ?? ''),
            'matches' => self::matches($actual, $value),
            'is_empty' => self::isEmpty($actual),
            'is_not_empty' => ! self::isEmpty($actual),
            'any_of' => self::globIntersect($actual, $value) === true,
            'all_of' => self::allOf($actual, $value),
            'none_of' => self::globIntersect($actual, $value) === false,
            'is_true' => $actual === true || $actual === 1 || $actual === '1',
            'is_false' => $actual === false || $actual === 0 || $actual === '0',
            default => false,
        };
    }

    private static function scalarEq(mixed $actual, mixed $value, bool $cs): bool
    {
        if (is_bool($value) || is_bool($actual)) {
            return (bool) $actual === (bool) $value;
        }
        if (is_numeric($actual) && is_numeric($value)) {
            return (float) $actual === (float) $value;
        }

        return self::str($actual, $cs) === self::str($value, $cs);
    }

    private static function isNumeric(mixed $a, mixed $b): bool
    {
        return is_numeric($a) && is_numeric($b);
    }

    private static function between(mixed $actual, mixed $value): bool
    {
        if (! is_array($value) || count($value) !== 2 || ! is_numeric($actual)) {
            return false;
        }

        return (float) $actual >= (float) $value[0] && (float) $actual <= (float) $value[1];
    }

    private static function inList(mixed $actual, mixed $value, bool $cs): bool
    {
        if (! is_array($value)) {
            return false;
        }
        foreach ($value as $candidate) {
            if (self::scalarEq($actual, $candidate, $cs)) {
                return true;
            }
        }

        return false;
    }

    /** Case-normalised string, or null if the value isn't stringable. */
    private static function str(mixed $v, bool $cs): ?string
    {
        if (is_array($v) || is_bool($v) || $v === null) {
            return is_bool($v) ? ($v ? '1' : '0') : null;
        }
        $s = trim((string) $v);

        return $cs ? $s : mb_strtolower($s);
    }

    private static function matches(mixed $actual, mixed $value): bool
    {
        $subject = is_scalar($actual) ? (string) $actual : null;
        if ($subject === null || ! is_string($value) || strlen($value) > 200) {
            return false;
        }
        // ReDoS guard: reject nested quantifiers; cap backtracking.
        if (preg_match('/(\([^)]*[+*][^)]*\)[+*])/', $value)) {
            return false;
        }
        $prev = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '50000');
        $result = @preg_match('/'.str_replace('/', '\/', $value).'/u', $subject);
        ini_set('pcre.backtrack_limit', $prev);

        return $result === 1;
    }

    private static function isEmpty(mixed $actual): bool
    {
        return $actual === null || $actual === '' || $actual === [] || (is_string($actual) && trim($actual) === '');
    }

    /**
     * Non-empty intersection between an array actual and a list of (possibly globbed) values.
     * Returns true/false; extracted so any_of and none_of share exactly one implementation.
     */
    private static function globIntersect(mixed $actual, mixed $value): bool
    {
        $haystack = is_array($actual) ? $actual : ($actual === null ? [] : [$actual]);
        $needles = is_array($value) ? $value : [$value];

        foreach ($needles as $needle) {
            $pattern = self::globToRegex((string) $needle);
            foreach ($haystack as $item) {
                if (preg_match($pattern, (string) $item)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function allOf(mixed $actual, mixed $value): bool
    {
        $haystack = is_array($actual) ? $actual : ($actual === null ? [] : [$actual]);
        $needles = is_array($value) ? $value : [$value];

        foreach ($needles as $needle) {
            $pattern = self::globToRegex((string) $needle);
            $found = false;
            foreach ($haystack as $item) {
                if (preg_match($pattern, (string) $item)) {
                    $found = true;
                    break;
                }
            }
            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /** Turn a `FRAGILE-*` glob into a case-insensitive anchored regex. */
    private static function globToRegex(string $glob): string
    {
        $escaped = preg_quote($glob, '/');
        $escaped = str_replace('\*', '.*', $escaped);

        return '/^'.$escaped.'$/i';
    }
}
