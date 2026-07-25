<?php

namespace App\Http\Controllers;

use App\Models\CodTransaction;
use App\Models\Organization;
use App\Services\Cod\CodReconciliationService;
use App\Services\Cod\CodTransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * COD reconciliation (spec 06 §5.5): the cash-in-transit view + manual collect/remit marking. Reading
 * is org-scoped; moving money markers is owner/admin.
 */
class CodController extends Controller
{
    public function __construct(private readonly CodTransactionService $service)
    {
    }

    public function summary(Request $request, CodReconciliationService $reports)
    {
        return response()->json($reports->summary((int) $request->header('X-Organization-Id')));
    }

    public function index(Request $request)
    {
        $txns = CodTransaction::where('organization_id', $request->header('X-Organization-Id'))
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s),
                fn ($q) => $q->whereIn('status', ['in_transit', 'collected'])) // default: outstanding
            ->when($request->get('carrier_code'), fn ($q, $c) => $q->where('carrier_code', $c))
            ->when($request->boolean('overdue'), fn ($q) => $q->where('status', 'collected')->where('due_at', '<', now()))
            ->with('order:id,external_id')
            ->latest('id')
            ->paginate(25);

        return response()->json($txns);
    }

    public function markCollected(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $txn = $this->find($request, $id);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0'],
            'collected_at' => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->service->markCollected($txn, (float) $data['amount'], isset($data['collected_at']) ? Carbon::parse($data['collected_at']) : null)
        );
    }

    public function markRemitted(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $txn = $this->find($request, $id);
        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'remitted_at' => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->service->markRemitted($txn, isset($data['amount']) ? (float) $data['amount'] : null, isset($data['remitted_at']) ? Carbon::parse($data['remitted_at']) : null)
        );
    }

    private function find(Request $request, int $id): CodTransaction
    {
        return CodTransaction::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    private function authorizeManage(Request $request): void
    {
        $org = Organization::findOrFail($request->header('X-Organization-Id'));
        $role = $org->users()->where('users.id', $request->user()->id)->first()?->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only owners and admins can reconcile COD.');
    }
}
