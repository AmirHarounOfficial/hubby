<?php

namespace App\Services\Shipping;

use App\Services\Shipping\Carriers\AramexCarrier;
use App\Services\Shipping\Carriers\DhlCarrier;
use App\Services\Shipping\Carriers\FedexCarrier;
use App\Services\Shipping\Carriers\JntCarrier;
use App\Services\Shipping\Carriers\ManualCarrier;
use App\Services\Shipping\Carriers\NaqelCarrier;
use App\Services\Shipping\Carriers\SmsaCarrier;
use App\Services\Shipping\Carriers\TorodCarrier;

/**
 * Builds a carrier driver from its code (spec 04 §5.1), mirroring IntegrationFactory. Real carriers
 * (aramex/smsa/naqel/jnt/torod/dhl/fedex) are added in their own slices; slice 1 ships `manual`.
 */
class CarrierFactory
{
    public static function make(string $carrier): ShippingCarrierInterface
    {
        return match (strtolower($carrier)) {
            'manual' => new ManualCarrier(),
            'dhl' => new DhlCarrier(),
            'aramex' => new AramexCarrier(),
            'smsa' => new SmsaCarrier(),
            'naqel' => new NaqelCarrier(),
            'jnt' => new JntCarrier(),
            'torod' => new TorodCarrier(),
            'fedex' => new FedexCarrier(),
            default => throw new \InvalidArgumentException("Carrier [{$carrier}] not supported"),
        };
    }

    /** Codes the factory can currently build — drives the carrier-account creation UI. */
    public static function available(): array
    {
        return ['manual', 'dhl', 'aramex', 'smsa', 'naqel', 'jnt', 'torod', 'fedex'];
    }
}
