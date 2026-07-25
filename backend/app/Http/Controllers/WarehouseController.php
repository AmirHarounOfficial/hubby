<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\ProductBarcode;
use App\Models\ProductVariant;
use App\Models\ScanEvent;
use App\Models\StockLocation;
use App\Models\Warehouse;
use App\Services\Warehouse\BarcodeResolver;
use App\Services\Warehouse\ScanRecorder;
use App\Services\Warehouse\WarehouseService;
use App\Support\BarcodeNormalizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Warehouse scanning (spec 08 §5.5) — slice 1: warehouses, locations, barcode mappings, and the
 * lookup scan that every other workflow builds on.
 */
class WarehouseController extends Controller
{
    public function __construct(
        private readonly BarcodeResolver $resolver,
        private readonly ScanRecorder $scans,
        private readonly WarehouseService $warehouses,
    ) {
    }

    // --- Warehouses + locations ---

    public function index(Request $request)
    {
        $orgId = (int) $request->header('X-Organization-Id');
        $this->warehouses->ensureDefault($orgId); // single-warehouse orgs never see the concept

        return response()->json(
            Warehouse::where('organization_id', $orgId)->withCount('locations')->orderBy('id')->get()
        );
    }

    public function storeWarehouse(Request $request)
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:24'],
            'address' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string', 'max:64'],
        ]);

        $warehouse = Warehouse::create($data + [
            'organization_id' => (int) $request->header('X-Organization-Id'),
            'is_active' => true,
        ]);

        return response()->json($warehouse, 201);
    }

    public function locations(Request $request, int $warehouseId)
    {
        $warehouse = $this->findWarehouse($request, $warehouseId);

        return response()->json(
            $warehouse->locations()->orderBy('sequence')->orderBy('code')->get()
        );
    }

    public function storeLocation(Request $request, int $warehouseId)
    {
        $this->authorizeManage($request);
        $orgId = (int) $request->header('X-Organization-Id');
        $warehouse = $this->findWarehouse($request, $warehouseId);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'name' => ['nullable', 'string', 'max:120'],
            'type' => ['nullable', Rule::in(StockLocation::TYPES)],
            'barcode' => ['nullable', 'string', 'max:64'],
            'sequence' => ['nullable', 'integer', 'min:0'],
        ]);

        $code = strtoupper(trim($data['code']));

        // A location code that collides with an existing item barcode/SKU would resolve to the ITEM
        // (§4.0 ordering), so the operator would scan a shelf and see a product. Warn at creation.
        $collision = $this->resolver->resolve($orgId, $code);
        if (! $collision->isUnknown() && $collision->kind !== 'location') {
            return response()->json([
                'message' => "The code {$code} already resolves to an item and would be ambiguous when scanned.",
                'code' => 'LOCATION_CODE_COLLIDES',
            ], 422);
        }

        $location = StockLocation::create($data + [
            'organization_id' => $orgId,
            'warehouse_id' => $warehouse->id,
            'code' => $code,
            'type' => $data['type'] ?? 'bin',
            'is_active' => true,
        ]);

        return response()->json($location, 201);
    }

    // --- Barcode mappings ---

    public function barcodes(Request $request)
    {
        $orgId = (int) $request->header('X-Organization-Id');

        return response()->json(
            ProductBarcode::where('organization_id', $orgId)
                ->when($request->get('product_id'), fn ($q, $p) => $q->where('product_id', $p))
                ->when($request->get('search'), fn ($q, $s) => $q->where('barcode', 'like', "%".strtoupper($s)."%"))
                ->with(['product:id,name,sku', 'variant:id,sku'])
                ->latest('id')->paginate(50)
        );
    }

    public function storeBarcode(Request $request)
    {
        $this->authorizeManage($request);
        $orgId = (int) $request->header('X-Organization-Id');

        $data = $request->validate([
            'barcode' => ['required', 'string', 'max:160'],
            'product_id' => ['nullable', 'integer'],
            'product_variant_id' => ['nullable', 'integer'],
            'symbology' => ['nullable', 'string', 'max:24'],
            'pack_size' => ['nullable', 'integer', 'min:1'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        if (empty($data['product_id']) && empty($data['product_variant_id'])) {
            return response()->json(['message' => 'A barcode must point at a product or a variant.', 'code' => 'BARCODE_NEEDS_TARGET'], 422);
        }

        // Backfill product_id from the variant so joins stay cheap (§3.3).
        $productId = $data['product_id'] ?? null;
        if (! empty($data['product_variant_id'])) {
            $variant = ProductVariant::whereHas('product', fn ($q) => $q->where('organization_id', $orgId))
                ->findOrFail($data['product_variant_id']);
            $productId = $variant->product_id;
        }

        $normalized = BarcodeNormalizer::normalize($data['barcode']);

        if (ProductBarcode::where('organization_id', $orgId)->where('barcode', $normalized)->exists()) {
            return response()->json([
                'message' => 'That barcode is already mapped to an item.', 'code' => 'BARCODE_ALREADY_MAPPED',
            ], 422);
        }

        $barcode = ProductBarcode::create([
            'organization_id' => $orgId,
            'product_id' => $productId,
            'product_variant_id' => $data['product_variant_id'] ?? null,
            'barcode' => $normalized,
            'barcode_raw' => $data['barcode'],
            'symbology' => $data['symbology'] ?? 'unknown',
            'pack_size' => $data['pack_size'] ?? 1,
            'is_primary' => $data['is_primary'] ?? false,
            'source' => 'manual',
            'created_by_user_id' => $request->user()?->id,
        ]);

        if ($barcode->is_primary) {
            $this->enforceSinglePrimary($barcode);
        }

        return response()->json($barcode, 201);
    }

    public function destroyBarcode(Request $request, int $id)
    {
        $this->authorizeManage($request);
        ProductBarcode::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    // --- The scan ---

    /**
     * Resolve a scanned barcode and record it. Idempotent on `uuid` so an offline replay returns the
     * original answer instead of double-counting.
     */
    public function scan(Request $request)
    {
        $orgId = (int) $request->header('X-Organization-Id');

        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'barcode' => ['required', 'string', 'max:160'],
            'session_type' => ['nullable', Rule::in(['pick', 'pack', 'receive', 'count', 'lookup'])],
            'input_method' => ['nullable', Rule::in(['camera', 'hid', 'manual'])],
            'device_id' => ['nullable', 'string', 'max:64'],
            'client_seq' => ['nullable', 'integer'],
            'client_scanned_at' => ['nullable', 'date'],
            'was_offline' => ['nullable', 'boolean'],
        ]);

        $result = $this->resolver->resolve($orgId, $data['barcode']);

        $recorded = $this->scans->record($orgId, $data['uuid'], [
            'user_id' => $request->user()?->id,
            'device_id' => $data['device_id'] ?? null,
            'session_type' => $data['session_type'] ?? 'lookup',
            'action' => 'scan',
            'barcode' => $result->barcode,
            'barcode_raw' => $data['barcode'],
            'input_method' => $data['input_method'] ?? 'camera',
            'resolved_product_id' => $result->product?->id,
            'resolved_product_variant_id' => $result->variant?->id,
            'stock_location_id' => $result->location?->id,
            'qty' => $result->packSize,
            'result' => $result->isUnknown() ? 'unknown_barcode' : 'accepted',
            // A bad check digit never blocks resolution, but it is worth recording — a warehouse
            // seeing many of these has a label-printing problem.
            'reject_reason' => $result->checkDigitValid === false ? 'check_digit_mismatch' : null,
            'was_offline' => $data['was_offline'] ?? false,
            'client_seq' => $data['client_seq'] ?? null,
            'client_scanned_at' => $data['client_scanned_at'] ?? null,
            'payload' => $data,
        ], fn () => $result->toArray());

        return response()->json(array_merge($recorded['response'], [
            'duplicate' => $recorded['duplicate'],
        ]), $result->isUnknown() ? 404 : 200);
    }

    /** Recent scans — the supervisor activity feed. */
    public function scanEvents(Request $request)
    {
        return response()->json(
            ScanEvent::where('organization_id', $request->header('X-Organization-Id'))
                ->when($request->get('session_type'), fn ($q, $t) => $q->where('session_type', $t))
                ->when($request->get('result'), fn ($q, $r) => $q->where('result', $r))
                ->with('user:id,name')
                ->latest('id')->paginate(50)
        );
    }

    private function enforceSinglePrimary(ProductBarcode $barcode): void
    {
        ProductBarcode::where('organization_id', $barcode->organization_id)
            ->where('id', '!=', $barcode->id)
            ->when($barcode->product_variant_id,
                fn ($q) => $q->where('product_variant_id', $barcode->product_variant_id),
                fn ($q) => $q->where('product_id', $barcode->product_id)->whereNull('product_variant_id'))
            ->update(['is_primary' => false]);
    }

    private function findWarehouse(Request $request, int $id): Warehouse
    {
        return Warehouse::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    private function authorizeManage(Request $request): void
    {
        $org = Organization::findOrFail($request->header('X-Organization-Id'));
        $role = $org->users()->where('users.id', $request->user()->id)->first()?->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only owners and admins can manage warehouse setup.');
    }
}
