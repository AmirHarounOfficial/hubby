<?php

namespace App\Services\Shipping;

use App\Exceptions\InvalidShipmentTransition;

/**
 * The merchant-triggered pre-transit transitions (spec 04 §4.1). Pure, no I/O.
 *
 * Only the states Hubby owns are validated here: draft → rated → label_purchased → awaiting_pickup,
 * with cancel available until the parcel is scanned. Everything from picked_up onward is
 * carrier-driven and set by TrackingIngestService's recompute — never asserted here — because
 * tracking events arrive out of order and a strict transition graph would reject legitimate history.
 */
class ShipmentStateMachine
{
    /** @var array<string, array<int, string>> */
    public const TRANSITIONS = [
        'draft' => ['rated', 'label_purchased', 'cancelled'],
        'rated' => ['label_purchased', 'cancelled'],
        'label_purchased' => ['awaiting_pickup', 'cancelled'],
        'awaiting_pickup' => ['cancelled'],
    ];

    /** Statuses a merchant action may legally leave (the pre-transit, Hubby-owned set). */
    public const MERCHANT_OWNED = ['draft', 'rated', 'label_purchased', 'awaiting_pickup'];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function assert(string $from, string $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidShipmentTransition($from, $to);
        }
    }
}
