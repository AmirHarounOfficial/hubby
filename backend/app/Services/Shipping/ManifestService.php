<?php

namespace App\Services\Shipping;

use App\Models\CarrierAccount;
use App\Models\Manifest;
use App\Models\Shipment;
use App\Models\ShippingLabel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;

/**
 * End-of-day manifests (spec 04 §4.10). Selects label_purchased shipments for a carrier, submits the
 * manifest (or generates a local HTML document when the carrier has no manifest API), attaches the
 * shipments, and moves them to awaiting_pickup.
 */
class ManifestService
{
    public function __construct(private readonly LabelStorageService $labels)
    {
    }

    /**
     * @param array<int,int> $shipmentIds
     */
    public function create(int $organizationId, CarrierAccount $account, array $shipmentIds, string $manifestDate, ?int $userId = null): Manifest
    {
        $shipments = Shipment::where('organization_id', $organizationId)
            ->whereIn('id', $shipmentIds)
            ->where('carrier_account_id', $account->id)
            ->where('status', Shipment::STATUS_LABEL_PURCHASED)
            ->get();

        if ($shipments->isEmpty()) {
            throw new \RuntimeException('MANIFEST_NO_ELIGIBLE_SHIPMENTS');
        }

        return DB::transaction(function () use ($organizationId, $account, $shipments, $manifestDate, $userId) {
            $manifest = Manifest::create([
                'organization_id' => $organizationId,
                'carrier_account_id' => $account->id,
                'carrier_code' => $account->carrier_code,
                'reference' => $this->nextReference($organizationId),
                'status' => 'draft',
                'shipment_count' => $shipments->count(),
                'total_weight_kg' => round((float) $shipments->sum('total_weight_kg'), 3),
                'manifest_date' => $manifestDate,
                'created_by_user_id' => $userId,
            ]);

            $carrier = CarrierFactory::make($account->carrier_code);
            $result = $carrier->createManifest($account, $manifest);

            // Keep our own copy of the document, always. A carrier that returns one wins; otherwise we
            // render the local template (carrier_manifest_id null flags it as not carrier-acknowledged).
            if (! empty($result['document_base64']) || ! empty($result['document_url'])) {
                $this->labels->store($manifest->shipments()->first() ?? $shipments->first(), [
                    'format' => 'pdf',
                    'content_base64' => $result['document_base64'] ?? null,
                    'url' => $result['document_url'] ?? null,
                ], 'manifest');
            } else {
                $this->storeLocalDocument($manifest, $shipments, $account);
            }

            foreach ($shipments as $shipment) {
                ShipmentStateMachine::assert($shipment->status, Shipment::STATUS_AWAITING_PICKUP);
                $shipment->forceFill(['manifest_id' => $manifest->id, 'status' => Shipment::STATUS_AWAITING_PICKUP])->save();
            }

            $manifest->forceFill([
                'carrier_manifest_id' => $result['carrier_manifest_id'] ?? null,
                'status' => 'confirmed',
                'submitted_at' => now(),
                'confirmed_at' => now(),
                'raw_response' => $result['raw'] ?? null,
            ])->save();

            return $manifest->fresh();
        });
    }

    /** The stored manifest document (spec §4.10). */
    public function document(Manifest $manifest): ?ShippingLabel
    {
        $shipmentIds = $manifest->shipments()->pluck('id');

        return ShippingLabel::whereIn('shipment_id', $shipmentIds)
            ->where('type', 'manifest')
            ->latest('id')
            ->first();
    }

    private function storeLocalDocument(Manifest $manifest, $shipments, CarrierAccount $account): void
    {
        $html = View::make('shipping.manifest', [
            'manifest' => $manifest,
            'shipments' => $shipments,
            'carrierLabel' => $account->label,
        ])->render();

        $this->labels->store($shipments->first(), [
            'format' => 'html', 'content_base64' => base64_encode($html),
        ], 'manifest');
    }

    /** MAN-YYYY-NNNNNN, sequential per org. */
    private function nextReference(int $organizationId): string
    {
        $seq = Manifest::where('organization_id', $organizationId)->count() + 1;

        return 'MAN-'.now()->year.'-'.str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
    }
}
