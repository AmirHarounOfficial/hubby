<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/** Raised when an RMA is asked to move to a status the state machine forbids (spec 03 §4.1). */
class InvalidReturnTransition extends \RuntimeException
{
    public function __construct(public string $from, public string $to)
    {
        parent::__construct("Cannot move a return from '{$from}' to '{$to}'.");
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'INVALID_RETURN_TRANSITION',
            'from' => $this->from,
            'to' => $this->to,
        ], 422);
    }
}
