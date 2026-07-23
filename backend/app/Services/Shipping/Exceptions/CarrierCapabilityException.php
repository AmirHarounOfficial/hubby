<?php

namespace App\Services\Shipping\Exceptions;

/** A carrier was asked to do something it does not support (spec 04 §5.1). */
class CarrierCapabilityException extends \RuntimeException
{
    public static function for(string $carrier, string $capability): self
    {
        return new self("Carrier [{$carrier}] does not support capability [{$capability}].");
    }
}
