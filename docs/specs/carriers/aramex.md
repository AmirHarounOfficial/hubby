# Carrier: Aramex (`aramex`)

**Driver:** `app/Services/Shipping/Carriers/AramexCarrier.php` (uses `SoapCarrierClient`)
**Status:** implemented against documented shapes — **not production-enabled until a live WSDL capture** (spec 04 §6.8 rule 4). The flagship of "the wedge": the default cross-Gulf carrier.

## API surface

- **SOAP over HTTPS.** Every call carries a `ClientInfo` block. We do NOT use ext-soap — `SoapCarrierClient` POSTs a hand-built SOAP envelope through Laravel's Http client so the wire format is faked, redactable, and inspectable (one investment shared with SMSA/Naqel).
- Endpoints used:
  - Rate: `https://ws.aramex.net/ShippingAPI.V2/RateCalculator/Service_1_0.svc` → `CalculateRate`
  - Shipping: `https://ws.aramex.net/ShippingAPI.V2/Shipping/Service_1_0.svc` → `CreateShipments` (returns AWB + `ShipmentLabel/LabelURL`)
  - Tracking: `https://ws.aramex.net/ShippingAPI.V2/Tracking/Service_1_0.svc` → `TrackShipments`
- Capabilities: `rates`, `cod`, `pickup`, `cancel`, `multi_package`, `address_validation`.
- **COD** is a shipment additional service (`CashOnDelivery`) with a value + currency. Gated end-to-end by the COD rules in `ShippingService::assertCodRules()` (§4.7).
- Tracking is **pull-based** — poll, no webhooks.

## Credentials (`carrier_accounts.credentials`, encrypted:array)

```
{ "username": "...", "password": "...", "account_number": "...",
  "account_pin": "...", "account_entity": "RUH", "account_country_code": "SA" }
```

`ClientInfo` never appears in `raw_request`/`raw_response` (spec §6.8 rule 5).

## Verification status — READ BEFORE ENABLING

- **UNVERIFIED against the live WSDL.** Operation names, endpoint hosts, label report ids, and exact field names (`TotalAmount/Value`, `ProcessedShipment/ID`, `ShipmentLabel/LabelURL`, `TrackingResult/*`) were implemented from public developer material, not a captured response. Aramex has been publishing JSON variants alongside SOAP — **check whether the JSON surface covers everything before committing to SOAP**.
- Exercised by `tests/Feature/Carriers/AramexCarrierTest.php` with representative SOAP fixtures (rate, create+label, track, fault→CarrierAuthException).
- **Before enabling in production:** capture request/response XML for `CalculateRate`, `CreateShipments`, `TrackShipments`, and `CreatePickup` from an Aramex test account; diff against the fixtures; correct field drift; store the captured XML next to this doc.

## Status map

Seeded by `CarrierStatusMapSeeder`, matched on lowercased tracking `UpdateDescription` text (robust to unverified `UpdateCode`s). Unmapped → `exception` + warning (§6.8 rule 2).
