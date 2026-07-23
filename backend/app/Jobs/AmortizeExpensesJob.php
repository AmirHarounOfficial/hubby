<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\Profit\ExpenseAmortizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Keeps `expense_allocations` current (spec 01). Scheduled daily; also dispatched with an org id
 * after an expense is created/edited so the P&L reflects it immediately. Idempotent via the
 * amortizer's delete-then-rebuild over the window.
 */
class AmortizeExpensesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $organizationId = null,
        public ?string $from = null,
        public ?string $to = null,
    ) {
    }

    public function handle(ExpenseAmortizer $amortizer): void
    {
        $from = $this->from ?? now()->subYear()->toDateString();
        $to = $this->to ?? now()->addYear()->toDateString();

        $organizations = $this->organizationId
            ? Organization::whereKey($this->organizationId)->get()
            : Organization::query()->get();

        foreach ($organizations as $organization) {
            $amortizer->amortize($organization, $from, $to);
        }
    }
}
