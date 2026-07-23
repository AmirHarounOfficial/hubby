<?php

namespace App\Http\Controllers;

use App\Models\CarrierAccount;
use App\Models\Organization;
use App\Services\Shipping\CarrierFactory;
use App\Services\Shipping\Exceptions\CarrierAuthException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-organization carrier accounts (spec 04 §5.7). Credentials are write-only: they go in encrypted
 * and never come back out — the API exposes only `has_credentials` plus the non-secret fields.
 * Mutations are owner/admin; viewers can list.
 */
class CarrierAccountController extends Controller
{
    public function index(Request $request)
    {
        $accounts = CarrierAccount::where('organization_id', $request->header('X-Organization-Id'))
            ->latest('id')
            ->get();

        return response()->json($accounts);
    }

    public function store(Request $request)
    {
        $this->authorizeManage($request);
        $orgId = (int) $request->header('X-Organization-Id');

        $data = $request->validate([
            'carrier_code' => ['required', 'string', Rule::in(array_keys(config('shipping.carriers', [])))],
            'label' => ['required', 'string', 'max:120'],
            'environment' => ['nullable', Rule::in(['sandbox', 'production'])],
            'credentials' => ['nullable', 'array'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'settings' => ['nullable', 'array'],
            'cod_enabled' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        if (! in_array($data['carrier_code'], CarrierFactory::available(), true)) {
            return response()->json([
                'message' => 'That carrier is not available yet.', 'code' => 'CARRIER_NOT_AVAILABLE',
            ], 422);
        }

        $account = CarrierAccount::create([
            'organization_id' => $orgId,
            'carrier_code' => $data['carrier_code'],
            'label' => $data['label'],
            'environment' => $data['environment'] ?? 'sandbox',
            'credentials' => $data['credentials'] ?? [],
            'account_number' => $data['account_number'] ?? null,
            'settings' => $data['settings'] ?? null,
            'cod_enabled' => $data['cod_enabled'] ?? false,
            'is_active' => $data['is_active'] ?? true,
            'is_default' => $data['is_default'] ?? false,
        ]);

        $this->enforceSingleDefault($account);

        return response()->json($account, 201);
    }

    public function update(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $account = $this->find($request, $id);

        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'environment' => ['sometimes', Rule::in(['sandbox', 'production'])],
            'credentials' => ['sometimes', 'array'],
            'account_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'cod_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        // An empty credentials payload must not wipe stored secrets — only overwrite when provided.
        if (array_key_exists('credentials', $data) && empty($data['credentials'])) {
            unset($data['credentials']);
        }

        $account->update($data);
        $this->enforceSingleDefault($account);

        return response()->json($account->fresh());
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $account = $this->find($request, $id);

        if ($account->shipments()->exists()) {
            // Keep the audit trail: deactivate rather than orphan historical shipments.
            $account->update(['is_active' => false]);

            return response()->json(['message' => 'Account has shipments; deactivated instead of deleted.'], 200);
        }

        $account->delete();

        return response()->json(null, 204);
    }

    /** Probe the stored credentials against the carrier (spec §5.1 validateCredentials). */
    public function validateCredentials(Request $request, int $id)
    {
        $account = $this->find($request, $id);
        $carrier = CarrierFactory::make($account->carrier_code);

        try {
            $ok = $carrier->validateCredentials($account);
            $account->update(['last_validated_at' => now(), 'last_error' => $ok ? null : 'validation_failed']);

            return response()->json(['valid' => $ok, 'last_validated_at' => $account->last_validated_at]);
        } catch (CarrierAuthException $e) {
            $account->update(['last_error' => $e->getMessage()]);

            return response()->json(['valid' => false, 'message' => $e->getMessage(), 'code' => 'CARRIER_CREDENTIALS_INVALID'], 422);
        }
    }

    /** One default carrier account per org (app-enforced, spec §3.1). */
    private function enforceSingleDefault(CarrierAccount $account): void
    {
        if ($account->is_default) {
            CarrierAccount::where('organization_id', $account->organization_id)
                ->where('id', '!=', $account->id)
                ->update(['is_default' => false]);
        }
    }

    private function find(Request $request, int $id): CarrierAccount
    {
        return CarrierAccount::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    private function authorizeManage(Request $request): void
    {
        $org = Organization::findOrFail($request->header('X-Organization-Id'));
        $role = $org->users()->where('users.id', $request->user()->id)->first()?->pivot->role;

        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only owners and admins can manage carrier accounts.');
    }
}
