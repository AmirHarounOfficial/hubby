<?php

namespace App\Jobs;

use App\Models\Shipment;
use App\Services\Returns\RtoDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Raise an RTO return from a shipment that the carrier reported as returning to origin (spec 03 §5.4,
 * dispatched from Spec 04 tracking). Idempotent via RtoDetector.
 */
class DetectRtoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly int $shipmentId)
    {
    }

    public function handle(RtoDetector $detector): void
    {
        $shipment = Shipment::find($this->shipmentId);
        if ($shipment) {
            $detector->fromShipment($shipment);
        }
    }
}
