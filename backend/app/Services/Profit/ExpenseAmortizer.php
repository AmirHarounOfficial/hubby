<?php

namespace App\Services\Profit;

use App\Models\Expense;
use App\Models\ExpenseAllocation;
use App\Models\Organization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Expands `expenses` into materialized daily `expense_allocations` for a date range (spec 01).
 *
 * Reporting sums the allocations, never the recurrence rules, so a P&L never runs a date-math loop.
 * Idempotent: it rebuilds the window from scratch each run (delete-then-insert within [from,to]),
 * which keeps it correct when an expense is edited, shortened, or deleted.
 */
class ExpenseAmortizer
{
    /** Approximate days per recurrence period — used to turn a period charge into a daily rate. */
    private const PERIOD_DAYS = [
        'daily' => 1, 'weekly' => 7, 'monthly' => 30, 'quarterly' => 91, 'yearly' => 365,
    ];

    public function amortize(Organization $organization, string $from, string $to): void
    {
        $from = Carbon::parse($from)->startOfDay();
        $to = Carbon::parse($to)->startOfDay();

        if ($from->gt($to)) {
            return;
        }

        DB::transaction(function () use ($organization, $from, $to) {
            // Clear the window first, so edits/removals don't leave stale slices behind.
            ExpenseAllocation::where('organization_id', $organization->id)
                ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->delete();

            $expenses = Expense::where('organization_id', $organization->id)->get();

            $rows = [];
            foreach ($expenses as $expense) {
                foreach ($this->slices($expense, $from, $to) as $date => $amount) {
                    if ($amount === 0.0) {
                        continue;
                    }
                    $rows[] = [
                        'organization_id' => $organization->id,
                        'expense_id' => $expense->id,
                        'store_id' => $expense->store_id,
                        'date' => $date,
                        'amount_base' => number_format($amount, 4, '.', ''),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                ExpenseAllocation::insert($chunk);
            }
        });
    }

    /**
     * Daily {date => amount_base} slices for one expense, clipped to [from,to].
     *
     * @return array<string, float>
     */
    private function slices(Expense $expense, Carbon $from, Carbon $to): array
    {
        $amount = (float) $expense->amount_base;
        $starts = Carbon::parse($expense->starts_on)->startOfDay();
        $ends = $expense->ends_on ? Carbon::parse($expense->ends_on)->startOfDay() : null;

        // Nothing before the expense starts or after it ends.
        $windowStart = $starts->greaterThan($from) ? $starts->copy() : $from->copy();
        $windowEnd = $ends && $ends->lessThan($to) ? $ends->copy() : $to->copy();
        if ($windowStart->gt($windowEnd)) {
            return [];
        }

        // One-off, charged in full on its start date.
        if ($expense->type !== 'recurring' && ! $expense->amortize) {
            return $this->inRange($starts, $from, $to) ? [$starts->toDateString() => $amount] : [];
        }

        // One-off, spread evenly across [starts, ends] (single day if no end).
        if ($expense->type !== 'recurring') {
            $span = $ends ? $starts->diffInDays($ends) + 1 : 1;
            $perDay = $amount / max(1, $span);

            return $this->daily($windowStart, $windowEnd, $perDay);
        }

        // Recurring, charged in full on each occurrence date.
        if (! $expense->amortize) {
            return $this->occurrences($expense, $starts, $ends, $from, $to, $amount);
        }

        // Recurring, spread evenly: convert the period charge to a daily rate.
        $periodDays = self::PERIOD_DAYS[$expense->recurrence] ?? 30;
        $perDay = $amount / $periodDays;

        return $this->daily($windowStart, $windowEnd, $perDay);
    }

    /** @return array<string, float> */
    private function daily(Carbon $start, Carbon $end, float $perDay): array
    {
        $out = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $out[$d->toDateString()] = $perDay;
        }

        return $out;
    }

    /** Full-amount charges on each recurrence occurrence within [from,to]. @return array<string, float> */
    private function occurrences(Expense $expense, Carbon $starts, ?Carbon $ends, Carbon $from, Carbon $to, float $amount): array
    {
        $out = [];
        $step = match ($expense->recurrence) {
            'daily' => fn (Carbon $d) => $d->addDay(),
            'weekly' => fn (Carbon $d) => $d->addWeek(),
            'quarterly' => fn (Carbon $d) => $d->addMonths(3),
            'yearly' => fn (Carbon $d) => $d->addYear(),
            default => fn (Carbon $d) => $d->addMonth(), // monthly
        };

        $cursor = $starts->copy();
        $hardStop = $ends ?? $to;
        while ($cursor->lte($hardStop) && $cursor->lte($to)) {
            if ($this->inRange($cursor, $from, $to)) {
                $out[$cursor->toDateString()] = $amount;
            }
            $step($cursor);
        }

        return $out;
    }

    private function inRange(Carbon $date, Carbon $from, Carbon $to): bool
    {
        return $date->betweenIncluded($from, $to);
    }
}
