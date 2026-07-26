<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/**
 * A location-scoped count was requested while locations are not authoritative for quantity
 * (spec 08 §3.9). Counting one bin against a warehouse-wide scalar produces a FALSE variance, so the
 * scope is refused rather than silently producing a wrong shrinkage number.
 */
class LocationScopedCountUnsupported extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Location-scoped counts need per-location quantities, which arrive in a later phase. '
            .'Count the whole warehouse instead, or keep one active location per warehouse.'
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'LOCATION_SCOPED_COUNT_UNSUPPORTED',
        ], 422);
    }
}
