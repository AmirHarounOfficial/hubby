<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/** An illegal COD ledger transition (spec 06 §4.1) — never silently swallowed. */
class InvalidCodTransition extends \RuntimeException
{
    public function __construct(public readonly string $from, public readonly string $to)
    {
        parent::__construct("Illegal COD transition [{$from}] → [{$to}].");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'INVALID_COD_TRANSITION',
            'from' => $this->from,
            'to' => $this->to,
        ], 422);
    }
}
