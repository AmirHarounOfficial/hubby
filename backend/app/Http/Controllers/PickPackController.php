<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Organization;
use App\Models\PackSession;
use App\Models\PickList;
use App\Models\PickListItem;
use App\Services\Warehouse\BarcodeResolver;
use App\Services\Warehouse\PackService;
use App\Services\Warehouse\PickService;
use App\Services\Warehouse\ScanRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Pick + pack (spec 08 §4.1, §4.2). Warehouse staff may pick and pack; accepting shorts — which
 * means telling a customer their order cannot be filled — is owner/admin.
 */
class PickPackController extends Controller
{
    private const SHORT_REASONS = ['not_found', 'damaged', 'insufficient', 'wrong_location', 'other'];

    public function __construct(
        private readonly PickService $picking,
        private readonly PackService $packing,
        private readonly BarcodeResolver $resolver,
        private readonly ScanRecorder $scans,
    ) {
    }

    // --- Pick ---

    public function index(Request $request)
    {
        return response()->json(
            PickList::where('organization_id', $request->header('X-Organization-Id'))
                ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
                ->withCount('items')
                ->orderBy('priority')->latest('id')->paginate(20)
        );
    }

    public function show(Request $request, int $id)
    {
        $list = $this->findList($request, $id);

        return response()->json($list->load(['items' => fn ($q) => $q->orderBy('sequence')]));
    }

    public function store(Request $request)
    {
        $orgId = (int) $request->header('X-Organization-Id');
        $data = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:50'],
            'order_ids.*' => ['integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'assigned_user_id' => ['nullable', 'integer'],
            'priority' => ['nullable', 'integer', 'min:1', 'max:9'],
        ]);

        $orders = Order::whereHas('store', fn ($q) => $q->where('organization_id', $orgId))
            ->whereIn('id', $data['order_ids'])
            ->with('items')
            ->get();

        try {
            $list = $this->picking->createFromOrders($orgId, $orders, $data, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }

        return response()->json($list, 201);
    }

    public function start(Request $request, int $id)
    {
        try {
            return response()->json($this->picking->start($this->findList($request, $id), $request->user()?->id));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }
    }

    /** Scan a picked unit. Idempotent on uuid; a wrong item is a hard block. */
    public function pick(Request $request, int $id)
    {
        $orgId = (int) $request->header('X-Organization-Id');
        $list = $this->findList($request, $id);

        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'barcode' => ['required', 'string', 'max:160'],
            'qty' => ['nullable', 'integer', 'min:1'],
            'device_id' => ['nullable', 'string', 'max:64'],
            'was_offline' => ['nullable', 'boolean'],
            'client_seq' => ['nullable', 'integer'],
        ]);

        $resolved = $this->resolver->resolve($orgId, $data['barcode']);
        $qty = $data['qty'] ?? $resolved->packSize;
        $outcome = null;

        $recorded = $this->scans->record($orgId, $data['uuid'], [
            'user_id' => $request->user()?->id,
            'device_id' => $data['device_id'] ?? null,
            'session_type' => 'pick',
            'session_id' => $list->id,
            'target_type' => 'pick_list_item',
            'action' => 'scan',
            'barcode' => $resolved->barcode,
            'barcode_raw' => $data['barcode'],
            'resolved_product_id' => $resolved->product?->id,
            'resolved_product_variant_id' => $resolved->variant?->id,
            'qty' => $qty,
            'result' => 'pending',
            'was_offline' => $data['was_offline'] ?? false,
            'client_seq' => $data['client_seq'] ?? null,
            'payload' => $data,
        ], function () use ($list, $data, $qty, $request, &$outcome) {
            $outcome = $this->picking->pick($list, $data['barcode'], $qty, $request->user()?->id);

            return [
                'result' => $outcome['result'],
                'line' => $outcome['line']?->toArray(),
                'pick_list_status' => $list->fresh()->status,
            ];
        });

        // Record the real outcome on the event (the recorder wrote a placeholder before running).
        if (! $recorded['duplicate'] && $outcome) {
            $recorded['event']->forceFill([
                'result' => $outcome['result'],
                'target_id' => $outcome['line']?->id,
            ])->save();
        }

        $response = array_merge($recorded['response'], ['duplicate' => $recorded['duplicate']]);
        $status = in_array($response['result'] ?? '', ['accepted'], true) ? 200 : 422;

        return response()->json($response, $recorded['duplicate'] ? 200 : $status);
    }

    public function short(Request $request, int $id, int $itemId)
    {
        $list = $this->findList($request, $id);
        $line = PickListItem::where('pick_list_id', $list->id)->findOrFail($itemId);

        $data = $request->validate([
            'reason' => ['required', Rule::in(self::SHORT_REASONS)],
            'qty_short' => ['nullable', 'integer', 'min:1'],
        ]);

        $line = $this->picking->short($line, $data['reason'], $data['qty_short'] ?? null, $request->user()?->id);

        return response()->json($line);
    }

    public function complete(Request $request, int $id)
    {
        $list = $this->findList($request, $id);
        $accept = $request->boolean('accept_shorts');

        if ($accept) {
            $this->authorizeManage($request);
        }

        try {
            return response()->json($this->picking->complete($list, $accept)->load('items'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }
    }

    public function cancel(Request $request, int $id)
    {
        $this->authorizeManage($request);

        try {
            return response()->json($this->picking->cancel($this->findList($request, $id)));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }
    }

    // --- Pack ---

    public function openPack(Request $request)
    {
        $orgId = (int) $request->header('X-Organization-Id');
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'pick_list_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'packaging_type' => ['nullable', 'string', 'max:32'],
        ]);

        $order = Order::whereHas('store', fn ($q) => $q->where('organization_id', $orgId))
            ->with('items', 'store')
            ->findOrFail($data['order_id']);

        try {
            $session = $this->packing->open($order, $data, $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }

        return response()->json($session, 201);
    }

    public function showPack(Request $request, int $id)
    {
        return response()->json($this->findSession($request, $id)->load('items', 'order:id,external_id,status'));
    }

    public function packScan(Request $request, int $id)
    {
        $orgId = (int) $request->header('X-Organization-Id');
        $session = $this->findSession($request, $id);

        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'barcode' => ['required', 'string', 'max:160'],
            'qty' => ['nullable', 'integer', 'min:1'],
            'device_id' => ['nullable', 'string', 'max:64'],
            'was_offline' => ['nullable', 'boolean'],
        ]);

        $resolved = $this->resolver->resolve($orgId, $data['barcode']);
        $qty = $data['qty'] ?? $resolved->packSize;
        $outcome = null;

        $recorded = $this->scans->record($orgId, $data['uuid'], [
            'user_id' => $request->user()?->id,
            'device_id' => $data['device_id'] ?? null,
            'session_type' => 'pack',
            'session_id' => $session->id,
            'target_type' => 'pack_session_item',
            'action' => 'scan',
            'barcode' => $resolved->barcode,
            'barcode_raw' => $data['barcode'],
            'resolved_product_id' => $resolved->product?->id,
            'resolved_product_variant_id' => $resolved->variant?->id,
            'qty' => $qty,
            'result' => 'pending',
            'was_offline' => $data['was_offline'] ?? false,
            'payload' => $data,
        ], function () use ($session, $data, $qty, &$outcome) {
            $outcome = $this->packing->pack($session, $data['barcode'], $qty);

            return [
                'result' => $outcome['result'],
                'line' => $outcome['line']?->toArray(),
                'pack_status' => $session->fresh()->status,
            ];
        });

        if (! $recorded['duplicate'] && $outcome) {
            $recorded['event']->forceFill([
                'result' => $outcome['result'],
                'target_id' => $outcome['line']?->id,
            ])->save();
        }

        $response = array_merge($recorded['response'], ['duplicate' => $recorded['duplicate']]);
        $status = ($response['result'] ?? '') === 'accepted' ? 200 : 422;

        return response()->json($response, $recorded['duplicate'] ? 200 : $status);
    }

    public function completePack(Request $request, int $id)
    {
        $session = $this->findSession($request, $id);
        $data = $request->validate([
            'weight_grams' => ['nullable', 'integer', 'min:0'],
            'length_mm' => ['nullable', 'integer', 'min:0'],
            'width_mm' => ['nullable', 'integer', 'min:0'],
            'height_mm' => ['nullable', 'integer', 'min:0'],
            'packaging_type' => ['nullable', 'string', 'max:32'],
        ]);

        try {
            return response()->json($this->packing->complete($session, $data)->load('items'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }
    }

    public function voidPack(Request $request, int $id)
    {
        try {
            return response()->json($this->packing->void($this->findSession($request, $id)));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }
    }

    private function findList(Request $request, int $id): PickList
    {
        return PickList::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    private function findSession(Request $request, int $id): PackSession
    {
        return PackSession::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    private function authorizeManage(Request $request): void
    {
        $org = Organization::findOrFail($request->header('X-Organization-Id'));
        $role = $org->users()->where('users.id', $request->user()->id)->first()?->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only owners and admins can do that.');
    }
}
