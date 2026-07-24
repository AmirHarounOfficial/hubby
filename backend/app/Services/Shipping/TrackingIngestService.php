<?php

namespace App\Services\Shipping;

use App\Models\Shipment;
use App\Models\TrackingEvent;
use App\Services\Shipping\Data\CarrierTrackingEvent;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point for tracking, for both webhooks and polling (spec 04 §4.2). Never two code
 * paths for the same job.
 *
 * Events arrive out of order — webhooks retry, polls overlap, carriers backfill — so:
 *   • tracking_events is append-only, deduped by (shipment_id, fingerprint);
 *   • shipments.status is RECOMPUTED as the status of the event with the greatest event_at (ties
 *     broken by greatest id), never "the last event we received";
 *   • a final status is sticky: on a tie at the greatest event_at a final status wins, so a stale
 *     non-final scan can't demote a delivered parcel — but a strictly-later event (a genuine
 *     delivered → returned_to_origin) still moves it.
 *   • shipped_at / delivered_at / cancelled_at are stamped from the event that first produced the
 *     status, not from now().
 */
class TrackingIngestService
{
    /**
     * @param array<int, CarrierTrackingEvent> $events
     * @return int number of new (non-duplicate) events stored
     */
    public function ingest(Shipment $shipment, array $events): int
    {
        $priorStatus = $shipment->status;

        $inserted = DB::transaction(function () use ($shipment, $events) {
            $count = 0;

            foreach ($events as $event) {
                $fingerprint = $event->fingerprint($shipment->id);

                $exists = TrackingEvent::where('shipment_id', $shipment->id)
                    ->where('fingerprint', $fingerprint)
                    ->exists();

                if ($exists) {
                    continue; // dedupe — never double-count a redelivered webhook / overlapping poll
                }

                TrackingEvent::create([
                    'shipment_id' => $shipment->id,
                    'shipment_package_id' => null,
                    'status' => $event->status,
                    'raw_status' => $event->rawStatus,
                    'raw_code' => $event->rawCode,
                    'description_en' => $event->descriptionEn,
                    'description_ar' => $event->descriptionAr,
                    'location' => $event->location,
                    'city' => $event->city,
                    'country_code' => $event->countryCode,
                    'signed_by' => $event->signedBy,
                    'event_at' => $event->eventAt,
                    'received_at' => now(),
                    'source' => $event->payload['source'] ?? 'poll',
                    'is_exception' => $event->isException,
                    'fingerprint' => $fingerprint,
                    'payload' => $event->payload,
                ]);
                $count++;
            }

            $this->recomputeStatus($shipment->fresh(['trackingEvents']));

            return $count;
        });

        // A parcel that just started returning to origin becomes an RTO return (spec 03) — dispatched
        // after the commit so the worker never races the shipment/event rows. Idempotent downstream.
        if ($shipment->fresh()->status === 'returned_to_origin' && $priorStatus !== 'returned_to_origin') {
            \App\Jobs\DetectRtoJob::dispatch($shipment->id);
        }

        return $inserted;
    }

    /** Recompute shipments.status from the full event history (the ordering rule above). */
    public function recomputeStatus(Shipment $shipment): void
    {
        $events = $shipment->trackingEvents;
        if ($events->isEmpty()) {
            return;
        }

        $maxEventAt = $events->max('event_at');
        $atMax = $events->filter(fn ($e) => $e->event_at->equalTo($maxEventAt));

        // Final wins on a tie at the greatest event_at; otherwise the greatest id wins.
        $chosen = $atMax->first(fn ($e) => in_array($e->status, Shipment::FINAL_STATUSES, true))
            ?? $atMax->sortByDesc('id')->first();

        $updates = [
            'status' => $chosen->status,
            'carrier_status_raw' => $chosen->raw_status,
            'carrier_status_code' => $chosen->raw_code,
            'last_tracked_at' => now(),
        ];

        // Stamp lifecycle timestamps from the earliest event that first reached each status.
        $firstOf = fn (string $status) => $events
            ->filter(fn ($e) => $e->status === $status)
            ->sortBy('event_at')
            ->first();

        if (! $shipment->shipped_at && ($e = $firstOf('picked_up') ?? $firstOf('in_transit'))) {
            $updates['shipped_at'] = $e->event_at;
        }
        if ($chosen->status === 'delivered' && ! $shipment->delivered_at && ($e = $firstOf('delivered'))) {
            $updates['delivered_at'] = $e->event_at;
        }
        if ($chosen->status === 'cancelled' && ! $shipment->cancelled_at && ($e = $firstOf('cancelled'))) {
            $updates['cancelled_at'] = $e->event_at;
        }

        $shipment->forceFill($updates)->save();
    }
}
