<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/** An illegal shipment status change (spec 04 §4.1) — surfaces as 422 INVALID_SHIPMENT_TRANSITION. */
class InvalidShipmentTransition extends \RuntimeException
{
    public function __construct(public readonly string $from, public readonly string $to)
    {
        parent::__construct("Illegal shipment transition [{$from}] → [{$to}].");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'INVALID_SHIPMENT_TRANSITION',
            'from' => $this->from,
            'to' => $this->to,
        ], 422);
    }
}
