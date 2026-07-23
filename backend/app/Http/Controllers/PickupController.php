<?php

namespace App\Http\Controllers;

use App\Models\CarrierAccount;
use App\Models\PickupRequest;
use App\Services\Shipping\PickupService;
use Illuminate\Http\Request;

/** Carrier pickup requests (spec 04 §5.7). owner/admin. */
class PickupController extends Controller
{
    public function __construct(private readonly PickupService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            PickupRequest::where('organization_id', $request->header('X-Organization-Id'))
                ->latest('id')->paginate(20)
        );
    }

    public function store(Request $request)
    {
        $this->authorizeManage($request);
        $orgId = (int) $request->header('X-Organization-Id');

        $data = $request->validate([
            'carrier_account_id' => ['required', 'integer'],
            'pickup_date' => ['required', 'date'],
            'ready_at' => ['nullable', 'date_format:H:i'],
            'close_at' => ['nullable', 'date_format:H:i'],
            'pickup_address_id' => ['nullable', 'integer'],
            'contact_name' => ['nullable', 'string', 'max:160'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'pieces' => ['nullable', 'integer', 'min:1'],
            'total_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'instructions' => ['nullable', 'string', 'max:500'],
        ]);

        $account = CarrierAccount::where('organization_id', $orgId)->findOrFail($data['carrier_account_id']);
        $pickup = $this->service->create($orgId, $account, $data, $request->user()?->id);

        return response()->json($pickup, 201);
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $pickup = PickupRequest::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);

        return response()->json($this->service->cancel($pickup));
    }

    private function authorizeManage(Request $request): void
    {
        $org = \App\Models\Organization::findOrFail($request->header('X-Organization-Id'));
        $role = $org->users()->where('users.id', $request->user()->id)->first()?->pivot->role;
        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only owners and admins can book pickups.');
    }
}
