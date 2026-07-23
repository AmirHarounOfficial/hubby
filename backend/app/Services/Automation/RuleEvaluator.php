<?php

namespace App\Services\Automation;

/**
 * Recursively evaluates a condition group against a flat facts map (spec 02 §4.3).
 *
 * A group matches when `match: all` and every child matches, or `match: any` and at least one does.
 * An empty group ("always match") returns true. Alongside the boolean it collects a per-leaf trace
 * so a run is fully explainable in the audit view.
 */
class RuleEvaluator
{
    /** @return array{matched: bool, trace: array<int, array<string, mixed>>, unknownField: ?string} */
    public function evaluate(array $conditions, array $facts): array
    {
        $trace = [];
        $unknownField = null;
        $matched = $this->group($conditions, $facts, $trace, $unknownField);

        return ['matched' => $matched, 'trace' => $trace, 'unknownField' => $unknownField];
    }

    private function group(array $group, array $facts, array &$trace, ?string &$unknownField): bool
    {
        $match = ($group['match'] ?? 'all') === 'any' ? 'any' : 'all';
        $rules = $group['rules'] ?? [];

        if ($rules === []) {
            return true; // empty group = always match
        }

        $results = [];
        foreach ($rules as $rule) {
            $results[] = isset($rule['rules'])
                ? $this->group($rule, $facts, $trace, $unknownField)
                : $this->leaf($rule, $facts, $trace, $unknownField);
        }

        return $match === 'all'
            ? ! in_array(false, $results, true)
            : in_array(true, $results, true);
    }

    private function leaf(array $leaf, array $facts, array &$trace, ?string &$unknownField): bool
    {
        $field = $leaf['field'] ?? null;
        $operator = $leaf['operator'] ?? null;

        // Unknown field → false (never a hard failure), but flag the pass as skipped upstream.
        if ($field === null || ! array_key_exists($field, $facts)) {
            $unknownField ??= (string) $field;
            $trace[] = ['field' => $field, 'operator' => $operator, 'value' => $leaf['value'] ?? null, 'actual' => null, 'result' => false];

            return false;
        }

        $actual = $facts[$field];
        $result = OperatorRegistry::evaluate(
            (string) $operator,
            $actual,
            $leaf['value'] ?? null,
            (bool) ($leaf['case_sensitive'] ?? false),
        );

        if ($leaf['negate'] ?? false) {
            $result = ! $result;
        }

        $trace[] = [
            'field' => $field,
            'operator' => $operator,
            'value' => $leaf['value'] ?? null,
            'actual' => is_array($actual) ? $actual : (is_scalar($actual) || $actual === null ? $actual : (string) $actual),
            'result' => $result,
        ];

        return $result;
    }
}
