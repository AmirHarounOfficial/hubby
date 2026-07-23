<?php

namespace App\Http\Controllers;

use App\Jobs\AmortizeExpensesJob;
use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD for business expenses (spec 01 §5.5). After any change, allocations for the org are rebuilt
 * so the P&L reflects it immediately (the amortizer is idempotent).
 */
class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $organizationId = $request->header('X-Organization-Id');

        return response()->json(
            Expense::where('organization_id', $organizationId)
                ->orderByDesc('starts_on')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $organizationId = (int) $request->header('X-Organization-Id');
        $data = $this->validated($request);

        $expense = Expense::create($this->prepare($data, $organizationId, $request));

        $this->reamortize($organizationId);

        return response()->json($expense, 201);
    }

    public function update(Request $request, int $id)
    {
        $organizationId = (int) $request->header('X-Organization-Id');
        $expense = Expense::where('organization_id', $organizationId)->findOrFail($id);

        $data = $this->validated($request);
        $expense->update($this->prepare($data, $organizationId, $request));

        $this->reamortize($organizationId);

        return response()->json($expense->fresh());
    }

    public function destroy(Request $request, int $id)
    {
        $organizationId = (int) $request->header('X-Organization-Id');
        $expense = Expense::where('organization_id', $organizationId)->findOrFail($id);

        $expense->delete(); // soft delete; allocations cascade off the DB row on force delete only,
        // so also clear the materialized slices immediately.
        $expense->allocations()->delete();
        $this->reamortize($organizationId);

        return response()->json(['message' => 'Expense deleted']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'category' => ['nullable', Rule::in(Expense::CATEGORIES)],
            'type' => ['required', Rule::in(Expense::TYPES)],
            'amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'recurrence' => ['nullable', Rule::requiredIf(fn () => $request->input('type') === 'recurring'), Rule::in(Expense::RECURRENCES)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'amortize' => ['nullable', 'boolean'],
            'allocation_method' => ['nullable', Rule::in(Expense::ALLOCATION_METHODS)],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'note' => ['nullable', 'string'],
        ]);
    }

    private function prepare(array $data, int $organizationId, Request $request): array
    {
        $amount = (float) $data['amount'];
        // FX conversion for foreign-currency expenses is a follow-up; today base == entered amount.
        $data['amount_base'] = number_format($amount, 2, '.', '');
        $data['fx_rate_to_base'] = 1;
        $data['organization_id'] = $organizationId;
        $data['created_by'] = $request->user()?->id;
        $data['currency'] = strtoupper($data['currency'] ?? 'SAR');

        return $data;
    }

    private function reamortize(int $organizationId): void
    {
        AmortizeExpensesJob::dispatchSync($organizationId);
    }
}
