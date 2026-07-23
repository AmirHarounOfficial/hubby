<?php

namespace App\Http\Controllers;

use App\Models\CarrierAccount;
use App\Models\Manifest;
use App\Services\Shipping\LabelStorageService;
use App\Services\Shipping\ManifestService;
use Illuminate\Http\Request;

/** End-of-day carrier manifests (spec 04 §5.7). owner/admin. */
class ManifestController extends Controller
{
    public function __construct(private readonly ManifestService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            Manifest::where('organization_id', $request->header('X-Organization-Id'))
                ->withCount('shipments')->latest('id')->paginate(20)
        );
    }

    public function store(Request $request)
    {
        $this->authorizeManage($request);
        $orgId = (int) $request->header('X-Organization-Id');

        $data = $request->validate([
            'carrier_account_id' => ['required', 'integer'],
            'shipment_ids' => ['required', 'array', 'min:1'],
            'shipment_ids.*' => ['integer'],
            'manifest_date' => ['nullable', 'date'],
        ]);

        $account = CarrierAccount::where('organization_id', $orgId)->findOrFail($data['carrier_account_id']);

        try {
            $manifest = $this->service->create($orgId, $account, $data['shipment_ids'], $data['manifest_date'] ?? now()->toDateString(), $request->user()?->id);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }

        return response()->json($manifest->load('shipments:id,reference,tracking_number,manifest_id'), 201);
    }

    public function show(Request $request, int $id)
    {
        $manifest = $this->find($request, $id);

        return response()->json($manifest->load('shipments:id,reference,tracking_number,manifest_id,total_weight_kg,is_cod,cod_amount,cod_currency,currency'));
    }

    public function document(Request $request, int $id, LabelStorageService $labels)
    {
        $manifest = $this->find($request, $id);
        $doc = $this->service->document($manifest);
        abort_unless($doc, 404, 'No manifest document.');

        return $labels->stream($doc);
    }

    private function find(Request $request, int $id): Manifest
    {
        return Manifest::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    private function authorizeManage(Request $request): void
    {
        $org = \App\Models\Organization::findOrFail($request->header('X-Organization-Id'));
        $role = $org->users()->where('users.id', $request->user()->id)->first()?->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only owners and admins can create manifests.');
    }
}
