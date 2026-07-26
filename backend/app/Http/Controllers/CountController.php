<?php

namespace App\Http\Controllers;

use App\Models\CountSession;
use App\Models\Organization;
use App\Services\Warehouse\BarcodeResolver;
use App\Services\Warehouse\CountService;
use App\Services\Warehouse\ScanRecorder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cycle counting (spec 08 §4.4). Counting is open to warehouse staff; approving — the only path that
 * mutates stock — is owner/admin, always, with no auto-approve.
 */
class CountController extends Controller
{
    public function __construct(
        private readonly CountService $counting,
        private readonly BarcodeResolver $resolver,
        private readonly ScanRecorder $scans,
    ) {
    }

    public function index(Request $request)
    {
        return response()->json(
            CountSession::where('organization_id', $request->header('X-Organization-Id'))
                ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
                ->withCount('entries')
                ->latest('id')->paginate(20)
        );
    }

    /** Blind sessions omit expected_qty entirely for non-supervisors — it never leaves the server. */
    public function show(Request $request, int $id)
    {
        $session = $this->find($request, $id);

        return response()->json($this->counting->forDevice($session, $this->isSupervisor($request)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'mode' => ['nullable', Rule::in(['blind', 'informed'])],
            'scope_type' => ['nullable', Rule::in(['full', 'location', 'category', 'sku_list'])],
            'scope_ref' => ['nullable', 'array'],
            'warehouse_id' => ['nullable', 'integer'],
            'assigned_user_id' => ['nullable', 'integer'],
        ]);

        // May throw LocationScopedCountUnsupported, which renders its own 422.
        $session = $this->counting->create(
            (int) $request->header('X-Organization-Id'),
            $data,
            $request->user()?->id,
        );

        return response()->json($this->counting->forDevice($session, $this->isSupervisor($request)), 201);
    }

    /** Record an ABSOLUTE counted quantity. Highest client_seq wins; the server never sums. */
    public function count(Request $request, int $id)
    {
        $orgId = (int) $request->header('X-Organization-Id');
        $session = $this->find($request, $id);

        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'barcode' => ['required', 'string', 'max:160'],
            'counted_qty' => ['required', 'integer', 'min:0'],
            'client_seq' => ['nullable', 'integer', 'min:0'],
            'stock_location_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:64'],
            'was_offline' => ['nullable', 'boolean'],
        ]);

        $resolved = $this->resolver->resolve($orgId, $data['barcode']);
        $outcome = null;

        $recorded = $this->scans->record($orgId, $data['uuid'], [
            'user_id' => $request->user()?->id,
            'device_id' => $data['device_id'] ?? null,
            'session_type' => 'count',
            'session_id' => $session->id,
            'target_type' => 'count_entry',
            'action' => 'qty_set',
            'barcode' => $resolved->barcode,
            'barcode_raw' => $data['barcode'],
            'resolved_product_id' => $resolved->product?->id,
            'resolved_product_variant_id' => $resolved->variant?->id,
            'stock_location_id' => $data['stock_location_id'] ?? null,
            'qty' => $data['counted_qty'],
            'result' => 'pending',
            'was_offline' => $data['was_offline'] ?? false,
            'client_seq' => $data['client_seq'] ?? null,
            'payload' => $data,
        ], function () use ($session, $data, $request, &$outcome) {
            $outcome = $this->counting->count(
                $session,
                $data['barcode'],
                (int) $data['counted_qty'],
                $data['client_seq'] ?? null,
                $request->user()?->id,
                ['stock_location_id' => $data['stock_location_id'] ?? null, 'note' => $data['note'] ?? null],
            );

            $entry = $outcome['entry']?->toArray();
            // Never echo the expected quantity back to a blind-mode device.
            if ($entry && $session->isBlind() && ! $this->isSupervisor(request())) {
                unset($entry['expected_qty'], $entry['variance'], $entry['live_qty_at_approval']);
            }

            return ['result' => $outcome['result'], 'entry' => $entry];
        });

        if (! $recorded['duplicate'] && $outcome) {
            $recorded['event']->forceFill([
                'result' => $outcome['result'],
                'target_id' => $outcome['entry']?->id,
            ])->save();
        }

        $response = array_merge($recorded['response'], ['duplicate' => $recorded['duplicate']]);
        $ok = in_array($response['result'] ?? '', ['accepted', 'duplicate'], true);

        return response()->json($response, $ok || $recorded['duplicate'] ? 200 : 422);
    }

    public function submit(Request $request, int $id)
    {
        try {
            $session = $this->counting->submit($this->find($request, $id));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }

        return response()->json($this->counting->forDevice($session, $this->isSupervisor($request)));
    }

    /** The only path that mutates stock — always owner/admin, never auto-approved. */
    public function approve(Request $request, int $id)
    {
        $this->authorizeManage($request);

        try {
            return response()->json($this->counting->approve($this->find($request, $id), $request->user()?->id)->load('entries'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }
    }

    public function reject(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            return response()->json($this->counting->reject($this->find($request, $id), $data['reason']));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 422);
        }
    }

    private function find(Request $request, int $id): CountSession
    {
        return CountSession::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    private function isSupervisor(Request $request): bool
    {
        $org = Organization::find($request->header('X-Organization-Id'));
        $role = $org?->users()->where('users.id', $request->user()?->id)->first()?->pivot->role;

        return in_array($role, ['owner', 'admin'], true);
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($this->isSupervisor($request), 403, 'Only owners and admins can approve a count.');
    }
}
