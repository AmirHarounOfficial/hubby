<?php

namespace App\Services\Cod;

use App\Exceptions\InvalidCodTransition;
use App\Models\CodTransaction;
use App\Models\Shipment;
use Illuminate\Support\Carbon;

/**
 * Maintains the COD ledger (spec 06 §4.1). Transitions are validated by a state machine — an illegal
 * move throws rather than corrupting the real state. The ledger is fed automatically from shipping:
 * a COD shipment creates a pending row, dispatch → in_transit, delivery → collected (cash in the
 * carrier's hands), RTO → rto. The merchant then confirms remittance to close the loop.
 */
class CodTransactionService
{
    /** @var array<string, array<int,string>> */
    private const TRANSITIONS = [
        'pending' => ['in_transit', 'cancelled'],
        'in_transit' => ['collected', 'rto', 'cancelled'],
        'collected' => ['remitted', 'short_paid', 'over_paid', 'disputed', 'written_off'],
        'remitted' => ['reconciled', 'short_paid', 'over_paid', 'disputed'],
        'rto' => ['rto_closed', 'written_off'],
        'short_paid' => ['disputed', 'written_off', 'reconciled'],
        'over_paid' => ['disputed', 'reconciled'],
        'disputed' => ['reconciled', 'written_off', 'collected'],
    ];

    private function cycleDays(?string $carrierCode): int
    {
        return (int) config('cod.remittance_cycle_days', 14);
    }

    /** Create/refresh the ledger row for a COD shipment and advance it to match the shipment state. */
    public function syncFromShipment(Shipment $shipment): ?CodTransaction
    {
        if (! $shipment->is_cod || ! $shipment->order_id) {
            return null;
        }

        $order = $shipment->order()->first();
        $txn = CodTransaction::firstOrNew(['order_id' => $shipment->order_id]);
        $txn->fill([
            'organization_id' => $shipment->organization_id,
            'store_id' => $shipment->store_id,
            'shipment_id' => $shipment->id,
            'carrier_code' => $shipment->carrier_code,
            'awb_number' => $this->normalizeAwb($shipment->tracking_number),
            'currency' => $shipment->cod_currency ?? $shipment->currency ?? 'SAR',
            'expected_amount' => (float) $shipment->cod_amount,
            'delivery_city' => optional($shipment->shipToAddress)->city,
            'customer_key' => $this->customerKey($order),
        ]);
        if (! $txn->exists) {
            $txn->status = 'pending';
        }
        $txn->save();

        $target = $this->targetStatus($shipment->status);
        if ($target && $target !== $txn->status && $this->canTransition($txn->status, $target)) {
            $this->applyTransition($txn, $target, $shipment);
        }

        return $txn->fresh();
    }

    /** Map a shipment status to the COD status it implies. */
    private function targetStatus(string $shipmentStatus): ?string
    {
        return match ($shipmentStatus) {
            'draft', 'rated' => null,
            'label_purchased', 'awaiting_pickup', 'picked_up', 'in_transit', 'at_origin_hub',
            'at_destination_hub', 'customs_clearance', 'out_for_delivery', 'delivery_attempted', 'held' => 'in_transit',
            'delivered' => 'collected',
            'returned_to_origin', 'rto_in_transit', 'rto_delivered' => 'rto',
            'cancelled' => 'cancelled',
            default => null,
        };
    }

    private function applyTransition(CodTransaction $txn, string $target, ?Shipment $shipment): void
    {
        if ($target === 'in_transit') {
            $txn->dispatched_at = $shipment?->shipped_at ?? now();
        }
        if ($target === 'collected') {
            $at = $shipment?->delivered_at ?? now();
            $txn->collected_at = $at;
            $txn->collected_amount ??= $txn->expected_amount; // assume full COD collected on delivery
            $txn->variance_amount = (float) $txn->collected_amount - (float) $txn->expected_amount;
            $txn->due_at = $at->copy()->addDays($this->cycleDays($txn->carrier_code));
        }
        $this->transition($txn, $target);
    }

    /** Merchant confirms the carrier remitted the cash. */
    public function markRemitted(CodTransaction $txn, ?float $amount = null, ?Carbon $at = null): CodTransaction
    {
        if ($txn->status !== 'collected') {
            $this->transition($txn, 'collected'); // best-effort recover if it wasn't marked collected
        }
        $txn->remitted_amount = $amount ?? $txn->collected_amount ?? $txn->expected_amount;
        $txn->remitted_at = $at ?? now();
        $this->transition($txn, 'remitted');

        return $txn->fresh();
    }

    /** Merchant records the carrier's collected amount off a statement (manual, pre-import). */
    public function markCollected(CodTransaction $txn, float $amount, ?Carbon $at = null): CodTransaction
    {
        $at ??= now();
        $txn->collected_amount = $amount;
        $txn->collected_at ??= $at;
        $txn->variance_amount = $amount - (float) $txn->expected_amount;
        $txn->due_at ??= $at->copy()->addDays($this->cycleDays($txn->carrier_code));
        if ($txn->status === 'in_transit') {
            $this->transition($txn, 'collected');
        } else {
            $txn->save();
        }

        return $txn->fresh();
    }

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    private function transition(CodTransaction $txn, string $to): void
    {
        if ($txn->status === $to) {
            $txn->save();

            return;
        }
        if (! $this->canTransition($txn->status, $to)) {
            throw new InvalidCodTransition($txn->status, $to);
        }
        if ($to === 'remitted') {
            $txn->remitted_at ??= now();
        }
        if ($to === 'reconciled') {
            $txn->reconciled_at = now();
        }
        $txn->status = $to;
        $txn->save();
    }

    private function normalizeAwb(?string $awb): ?string
    {
        if (! $awb) {
            return null;
        }

        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $awb)) ?: null;
    }

    private function customerKey($order): ?string
    {
        if (! $order) {
            return null;
        }
        $phone = $order->customer_phone ?? null;
        if ($phone) {
            return preg_replace('/[^\d+]/', '', $phone);
        }

        return $order->customer_email ? mb_strtolower($order->customer_email) : null;
    }
}
