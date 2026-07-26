<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Receipt;
use App\Services\Warehouse\BarcodeResolver;
use App\Services\Warehouse\ReceivingService;
use App\Services\Warehouse\ScanRecorder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inbound receiving (spec 08 §4.3, §5.5). Scanning is open to any org member (warehouse staff),
 * but completing a receipt with discrepancies — the moment stock actually moves — is owner/admin.
 */
class ReceiptController extends Controller
{
    public function __construct(
        private readonly ReceivingService $receiving,
        private readonly BarcodeResolver $resolver,
        private readonly ScanRecorder $scans,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json(
            Receipt::where('organization_id', $request->header('X-Organization-Id'))
                ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
                ->withCount('items')
                ->latest('id')->paginate(20)
        );
    }

    public function show(Request $request, int $id)
    {
        $receipt = $this->find($request, $id);

        return response()->json($receipt->load(['items', 'warehouse:id,name,code']));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'warehouse_id' => ['nullable', 'integer'],
            'type' => ['nullable', Rule::in(['inbound', 'return', 'transfer'])],
            'supplier_name' => ['nullable', 'string', 'max:180'],
            'reference' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string'],
            'expected_lines' => ['nullable', 'array'],
            'expected_lines.*.sku' => ['required_with:expected_lines', 'string', 'max:120'],
            'expected_lines.*.qty' => ['required_with:expected_lines', 'integer', 'min:1'],
        ]);

        $receipt = $this->receiving->create(
            (int) $request->header('X-Organization-Id'),
            $data,
            $request->user()?->id,
        );

        return response()->json($receipt, 201);
    }

    /** Scan a unit into the receipt. Idempotent on `uuid` so an offline replay never double-counts. */
    public function scan(Request $request, int $id)
    {
        $orgId = (int) $request->header('X-Organization-Id');
        $receipt = $this->find($request, $id);

        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'barcode' => ['required', 'string', 'max:160'],
            'qty' => ['nullable', 'integer', 'min:1'],
            'qty_damaged' => ['nullable', 'integer', 'min:0'],
            'stock_location_id' => ['nullable', 'integer'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'device_id' => ['nullable', 'string', 'max:64'],
            'was_offline' => ['nullable', 'boolean'],
            'client_seq' => ['nullable', 'integer'],
        ]);

        $resolved = $this->resolver->resolve($orgId, $data['barcode']);
        // A case barcode carries its pack size; an explicit qty overrides it.
        $qty = $data['qty'] ?? $resolved->packSize;

        $recorded = $this->scans->record($orgId, $data['uuid'], [
            'user_id' => $request->user()?->id,
            'device_id' => $data['device_id'] ?? null,
            'session_type' => 'receive',
            'session_id' => $receipt->id,
            'target_type' => 'receipt_item',
            'action' => 'scan',
            'barcode' => $resolved->barcode,
            'barcode_raw' => $data['barcode'],
            'resolved_product_id' => $resolved->product?->id,
            'resolved_product_variant_id' => $resolved->variant?->id,
            'stock_location_id' => $data['stock_location_id'] ?? null,
            'qty' => $qty,
            'result' => $resolved->isUnknown() ? 'unknown_barcode' : 'accepted',
            'was_offline' => $data['was_offline'] ?? false,
            'client_seq' => $data['client_seq'] ?? null,
            'payload' => $data,
        ], function () use ($receipt, $data, $qty, $resolved) {
            $line = $this->receiving->scanIn($receipt, $data['barcode'], $qty, [
                'qty_damaged' => $data['qty_damaged'] ?? 0,
                'stock_location_id' => $data['stock_location_id'] ?? null,
                'unit_cost' => $data['unit_cost'] ?? null,
            ]);

            return [
                'resolved' => $resolved->toArray(),
                'line' => $line->toArray(),
                'receipt_status' => $receipt->fresh()->status,
            ];
        });

        return response()->json(array_merge($recorded['response'], ['duplicate' => $recorded['duplicate']]));
    }

    /**
     * Complete the receipt — the point at which stock moves. Accepting discrepancies is owner/admin,
     * because it silently changes catalogue quantities.
     */
    public function complete(Request $request, int $id)
    {
        $receipt = $this->find($request, $id);
        $accept = (bool) $request->boolean('accept_discrepancies');

        if ($accept) {
            $this->authorizeManage($request);
        }

        try {
            $receipt = $this->receiving->complete($receipt, $request->user()?->id, $accept);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }

        return response()->json($receipt->load('items'));
    }

    public function cancel(Request $request, int $id)
    {
        $this->authorizeManage($request);

        try {
            return response()->json($this->receiving->cancel($this->find($request, $id)));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }
    }

    private function find(Request $request, int $id): Receipt
    {
        return Receipt::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    private function authorizeManage(Request $request): void
    {
        $org = Organization::findOrFail($request->header('X-Organization-Id'));
        $role = $org->users()->where('users.id', $request->user()->id)->first()?->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only owners and admins can accept receipt discrepancies.');
    }
}
