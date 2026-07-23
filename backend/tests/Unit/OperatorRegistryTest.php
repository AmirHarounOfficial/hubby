<?php

namespace Tests\Unit;

use App\Services\Automation\OperatorRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The operator layer (spec 02 §3.6). The null and coercion rules are the correctness core — a
 * missing value must never accidentally satisfy a rule — so they're pinned here.
 */
class OperatorRegistryTest extends TestCase
{
    public function test_numeric_comparisons(): void
    {
        $this->assertTrue(OperatorRegistry::evaluate('gte', 1500, 1500));
        $this->assertTrue(OperatorRegistry::evaluate('gt', '2000', 1500));
        $this->assertFalse(OperatorRegistry::evaluate('lt', 2000, 1500));
        $this->assertTrue(OperatorRegistry::evaluate('between', 30, [0, 100]));
        $this->assertFalse(OperatorRegistry::evaluate('between', 200, [0, 100]));
    }

    public function test_a_null_actual_never_matches_except_the_null_safe_operators(): void
    {
        // The single most important semantic: unknown values never match a threshold.
        $this->assertFalse(OperatorRegistry::evaluate('lt', null, 5000));
        $this->assertFalse(OperatorRegistry::evaluate('eq', null, 'x'));
        $this->assertFalse(OperatorRegistry::evaluate('gte', null, 0));
        // …but null legitimately satisfies these:
        $this->assertTrue(OperatorRegistry::evaluate('is_empty', null, null));
        $this->assertTrue(OperatorRegistry::evaluate('not_in', null, ['a', 'b']));
        $this->assertTrue(OperatorRegistry::evaluate('none_of', null, ['a']));
    }

    public function test_a_non_numeric_actual_on_a_numeric_operator_is_false_not_an_error(): void
    {
        $this->assertFalse(OperatorRegistry::evaluate('gt', 'not-a-number', 10));
    }

    public function test_strings_are_case_insensitive_and_trimmed_by_default(): void
    {
        $this->assertTrue(OperatorRegistry::evaluate('eq', '  Riyadh ', 'riyadh'));
        $this->assertFalse(OperatorRegistry::evaluate('eq', 'Riyadh', 'riyadh', caseSensitive: true));
        $this->assertTrue(OperatorRegistry::evaluate('contains', 'FRAGILE-ITEM', 'fragile'));
        $this->assertTrue(OperatorRegistry::evaluate('in', 'SALLA', ['salla', 'zid']));
    }

    public function test_array_operators_with_glob(): void
    {
        $skus = ['FRAGILE-CUP', 'MUG-01'];
        $this->assertTrue(OperatorRegistry::evaluate('any_of', $skus, ['FRAGILE-*']));
        $this->assertFalse(OperatorRegistry::evaluate('any_of', $skus, ['GLASS-*']));
        $this->assertTrue(OperatorRegistry::evaluate('none_of', $skus, ['GLASS-*']));
        $this->assertTrue(OperatorRegistry::evaluate('all_of', $skus, ['FRAGILE-*', 'MUG-01']));
        $this->assertFalse(OperatorRegistry::evaluate('all_of', $skus, ['FRAGILE-*', 'PLATE-*']));
    }

    public function test_boolean_and_membership(): void
    {
        $this->assertTrue(OperatorRegistry::evaluate('is_true', true, null));
        $this->assertTrue(OperatorRegistry::evaluate('eq', true, true));
        $this->assertFalse(OperatorRegistry::evaluate('is_true', false, null));
        $this->assertTrue(OperatorRegistry::evaluate('not_in', 'noon', ['salla', 'zid']));
    }

    public function test_regex_redos_guard_rejects_nested_quantifiers(): void
    {
        // A catastrophic-backtracking pattern must be refused, not run.
        $this->assertFalse(OperatorRegistry::evaluate('matches', 'aaaaaaaaaa!', '(a+)+$'));
        // A safe pattern still works.
        $this->assertTrue(OperatorRegistry::evaluate('matches', 'SA-123', '^SA-\d+$'));
    }
}
