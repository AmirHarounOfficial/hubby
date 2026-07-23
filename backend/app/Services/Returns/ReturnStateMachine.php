<?php

namespace App\Services\Returns;

use App\Exceptions\InvalidReturnTransition;

/**
 * The RMA state machine (spec 03 §4.1). The transition table is the single source of truth for what
 * moves are legal; anything not listed throws InvalidReturnTransition (→ HTTP 422). Guards (actor
 * role, marketplace-managed, quantity/refund preconditions) are checked by ReturnService at the call
 * site — this class answers only "is this edge in the graph?".
 */
class ReturnStateMachine
{
    /** from => [allowed to, ...] */
    public const TRANSITIONS = [
        'requested' => ['approved', 'rejected', 'cancelled', 'in_transit'],
        'approved' => ['awaiting_shipment', 'in_transit', 'cancelled'],
        'awaiting_shipment' => ['in_transit', 'cancelled'],
        'in_transit' => ['received', 'failed'],
        'received' => ['inspecting', 'rejected'],
        'inspecting' => ['inspected'],
        'inspected' => ['refund_pending', 'exchange_pending', 'closed'],
        'refund_pending' => ['refunded', 'failed'],
        'exchange_pending' => ['exchanged'],
        'failed' => ['requested'], // reopen
        // Terminal: rejected, cancelled, refunded, exchanged, closed.
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @throws InvalidReturnTransition */
    public static function assert(string $from, string $to): void
    {
        if (! self::can($from, $to)) {
            throw new InvalidReturnTransition($from, $to);
        }
    }
}
