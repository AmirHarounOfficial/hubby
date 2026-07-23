<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Organization;
use App\Models\Shipment;
use App\Services\Shipping\Data\CarrierTrackingEvent;
use App\Services\Shipping\ShippingService;
use App\Services\Shipping\TrackingIngestService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Shipments queue + lifecycle actions (spec 04 §5.7) over the slice-1 engine. Org-scoped via
 * org.member; label purchase and manual tracking entry are owner/admin. Rate shopping, real label
 * artefacts, manifests and COD land in later slices.
 */
class ShipmentController extends Controller
{
    /** The normalized tracking vocabulary a merchant may enter by hand (spec §4.2, carrier-driven set). */
    private const MANUAL_STATUSES = [
        'picked_up', 'in_transit', 'at_origin_hub', 'at_destination_hub', 'customs_clearance',
        'out_for_delivery', 'delivery_attempted', 'held', 'delivered', 'returned_to_origin',
        'rto_in_transit', 'rto_delivered', 'lost', 'damaged', 'exception',
    ];

    public function __construct(
        private readonly ShippingService $service,
        private readonly TrackingIngestService $tracking,
    ) {
    }

    public function index(Request $request)
    {
        $shipments = Shipment::where('organization_id', $request->header('X-Organization-Id'))
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->get('carrier_code'), fn ($q, $c) => $q->where('carrier_code', $c))
            ->when($request->get('search'), fn ($q, $s) => $q->where(fn ($w) => $w
                ->where('reference', 'like', "%{$s}%")
                ->orWhere('tracking_number', 'like', "%{$s}%")))
            ->withCount('items')
            ->latest('id')
            ->paginate(20);

        return response()->json($shipments);
    }

    public function show(Request $request, int $id)
    {
        $shipment = $this->find($request, $id);

        return response()->json($shipment->load([
            'packages', 'items', 'carrierAccount:id,carrier_code,label',
            'trackingEvents' => fn ($q) => $q->orderByDesc('event_at')->orderByDesc('id'),
            'order:id,external_id,total,currency',
        ]));
    }

    public function store(Request $request)
    {
        $this->authorizeManage($request);
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'weight_kg' => ['required', 'numeric', 'min:0.01', 'max:1000'],
            'tracking_number' => ['nullable', 'string', 'max:64'],
            'contents_description' => ['nullable', 'string', 'max:255'],
            'special_instructions' => ['nullable', 'string', 'max:500'],
        ]);

        $order = $this->findOrder($request, $data['order_id']);

        $shipment = $this->service->createDraft($order, array_filter([
            'weight_kg' => $data['weight_kg'],
            'tracking_number' => $data['tracking_number'] ?? null,
            'contents_description' => $data['contents_description'] ?? null,
            'special_instructions' => $data['special_instructions'] ?? null,
            'created_by_user_id' => $request->user()?->id,
        ], fn ($v) => $v !== null));

        return response()->json($shipment, 201);
    }

    /** POST /orders/{id}/shipments — the order-first entry point. */
    public function storeForOrder(Request $request, int $id)
    {
        $request->merge(['order_id' => $id]);

        return $this->store($request);
    }

    public function purchaseLabel(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $shipment = $this->find($request, $id);

        $data = $request->validate([
            'carrier_account_id' => ['required', 'integer'],
            'label_format' => ['nullable', Rule::in(['pdf', 'zpl', 'png'])],
        ]);

        $account = \App\Models\CarrierAccount::where('organization_id', $request->header('X-Organization-Id'))
            ->where('is_active', true)
            ->findOrFail($data['carrier_account_id']);

        try {
            $shipment = $this->service->purchaseLabel(
                $shipment->load('packages', 'items'),
                $account,
                ['label_format' => $data['label_format'] ?? 'pdf'],
            );
        } catch (\App\Exceptions\InvalidShipmentTransition $e) {
            throw $e;
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => strtoupper($e->getMessage())], 422);
        }

        return response()->json($shipment);
    }

    public function cancel(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $shipment = $this->find($request, $id);

        try {
            return response()->json($this->service->cancel($shipment));
        } catch (\App\Exceptions\InvalidShipmentTransition $e) {
            throw $e;
        }
    }

    public function tracking(Request $request, int $id)
    {
        $shipment = $this->find($request, $id);

        return response()->json(
            $shipment->trackingEvents()->orderByDesc('event_at')->orderByDesc('id')->get()
        );
    }

    /** Manual tracking entry for carriers with no API at all (spec §4.1). owner/admin. */
    public function addManualEvent(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $shipment = $this->find($request, $id);

        $data = $request->validate([
            'status' => ['required', Rule::in(self::MANUAL_STATUSES)],
            'event_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:160'],
            'city' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $exception = in_array($data['status'], ['delivery_attempted', 'held', 'returned_to_origin', 'rto_in_transit', 'rto_delivered', 'lost', 'damaged', 'exception'], true);

        $this->tracking->ingest($shipment, [new CarrierTrackingEvent(
            status: $data['status'],
            eventAt: Carbon::parse($data['event_at']),
            descriptionEn: $data['description'] ?? null,
            location: $data['location'] ?? null,
            city: $data['city'] ?? null,
            isException: $exception,
            payload: ['source' => 'manual'],
        )]);

        return response()->json($shipment->fresh(['trackingEvents' => fn ($q) => $q->orderByDesc('event_at')->orderByDesc('id')]));
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizeManage($request);
        $shipment = $this->find($request, $id);

        if ($shipment->status !== Shipment::STATUS_DRAFT) {
            return response()->json(['message' => 'Only draft shipments can be deleted.', 'code' => 'SHIPMENT_NOT_DRAFT'], 422);
        }

        $shipment->delete();

        return response()->json(null, 204);
    }

    private function find(Request $request, int $id): Shipment
    {
        return Shipment::where('organization_id', $request->header('X-Organization-Id'))->findOrFail($id);
    }

    private function findOrder(Request $request, int $orderId): Order
    {
        return Order::whereHas('store', fn ($q) => $q->where('organization_id', $request->header('X-Organization-Id')))
            ->with('items', 'store')
            ->findOrFail($orderId);
    }

    private function authorizeManage(Request $request): void
    {
        $org = Organization::findOrFail($request->header('X-Organization-Id'));
        $role = $org->users()->where('users.id', $request->user()->id)->first()?->pivot->role;

        abort_unless(in_array($role, ['owner', 'admin'], true), 403, 'Only owners and admins can manage shipments.');
    }
}
