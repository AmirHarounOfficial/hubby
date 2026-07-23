<?php

namespace App\Services\Shipping;

use App\Models\CarrierAccount;
use App\Models\Manifest;
use App\Models\PickupRequest;
use App\Models\Shipment;
use App\Services\Shipping\Data\CarrierRate;
use App\Services\Shipping\Data\CarrierTrackingEvent;
use App\Services\Shipping\Data\RateRequest;

/**
 * The carrier abstraction (spec 04 §5.1) — deliberately shaped like IntegrationServiceInterface
 * (flat, concrete, no over-abstraction) so it reads as native to this codebase.
 *
 * Slice 1 covers the core lifecycle: validate → rate → create shipment → label → cancel → track,
 * plus reverse shipments for Spec 03. Pickups and manifests join the interface with their tables in
 * a later slice. A carrier safely stubs anything it can't do via BaseShippingCarrier::unsupported().
 */
interface ShippingCarrierInterface
{
    /** Stable code: 'aramex','smsa','naqel','jnt','torod','dhl','fedex','manual'. */
    public function code(): string;

    /** Capability probe: 'rates','multi_package','cod','pickup','manifest','return_label','address_validation','tracking_webhook','zpl','cancel'. */
    public function supports(string $capability): bool;

    /** Cheap credential probe. Throws CarrierAuthException on bad credentials. */
    public function validateCredentials(CarrierAccount $account): bool;

    /** @return array{is_valid:bool,normalized:array<string,mixed>,notes:array<int,array{severity:string,message:string}>} */
    public function validateAddress(CarrierAccount $account, array $address): array;

    /** @return array<int, CarrierRate> */
    public function getRates(CarrierAccount $account, RateRequest $request): array;

    /**
     * @return array{tracking_number:string,carrier_shipment_id:?string,packages:array<int,array{sequence:int,tracking_number:?string}>,label:?array{format:string,content_base64:?string,url:?string},cost:?array{amount:float,currency:string},estimated_delivery_at:?string,raw:array<string,mixed>}
     */
    public function createShipment(CarrierAccount $account, Shipment $shipment): array;

    /** Fetch (or re-fetch) label bytes. Returns raw binary. */
    public function getLabel(CarrierAccount $account, Shipment $shipment, string $format = 'pdf'): string;

    public function cancelShipment(CarrierAccount $account, Shipment $shipment): bool;

    /** @return array<int, CarrierTrackingEvent> */
    public function track(CarrierAccount $account, string $trackingNumber): array;

    /** Reverse logistics: a label the customer uses to send goods back. */
    public function createReturnShipment(CarrierAccount $account, Shipment $shipment): array;

    /**
     * Submit an end-of-day manifest to the carrier (spec §4.10).
     *
     * @return array{carrier_manifest_id:?string,document_base64:?string,document_url:?string,raw:array<string,mixed>}
     */
    public function createManifest(CarrierAccount $account, Manifest $manifest): array;

    /**
     * Book a pickup ("send a driver", spec §4.10).
     *
     * @return array{carrier_pickup_id:?string,confirmed:bool,raw:array<string,mixed>}
     */
    public function createPickup(CarrierAccount $account, PickupRequest $pickup): array;

    public function cancelPickup(CarrierAccount $account, PickupRequest $pickup): bool;

    /** Map a raw carrier status to the normalized vocabulary (§4.2). */
    public function normalizeStatus(?string $rawStatus, ?string $rawCode = null): string;

    /** Verify an inbound tracking webhook. Returns the shipments it concerns. */
    public function parseWebhook(array $payload, array $headers): array;
}
