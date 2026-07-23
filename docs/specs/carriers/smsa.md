# Carrier: SMSA Express (`smsa`)

**Driver:** `app/Services/Shipping/Carriers/SmsaCarrier.php`
**Status:** implemented against documented shapes, fixture-tested (both modes) — **not production-enabled until a live capture per mode** (spec 04 §6.8 rule 4). The domestic Saudi workhorse; with Aramex it covers most Saudi parcels.

## Two drivers behind one class

SMSA has been migrating merchants from a legacy SOAP surface to a newer REST one. Which a merchant gets depends on their contract/onboarding date, so `SmsaCarrier` selects per account on `credentials.mode`:

- **`secom_soap`** (legacy) — ASMX SOAP at `https://track.smsaexpress.com/SECOM/SMSAwebService.asmx`, auth via `passkey`. Operations: `addShipment` (→ AWB string), `getPDF` (→ base64 label), `getStatus` (→ tracking). Uses the shared `SoapCarrierClient`.
- **`rest`** (newer) — JSON API, auth via `apikey` header (base URL overridable via `credentials.base_url`). `POST /api/shipment`, `GET /api/track/{awb}`.

Assuming one surface for everyone means half of SMSA customers can't connect — hence the split.

## Credentials (`carrier_accounts.credentials`, encrypted:array)

```
secom_soap: { "mode": "secom_soap", "passkey": "..." }
rest:       { "mode": "rest", "api_key": "...", "base_url": "https://..." (optional) }
```

`passKey` / `api_key` are never persisted into `raw_request`/`raw_response`.

## Capabilities

`cod`, `cancel`. **No rate API** on the legacy surface, so SMSA advertises no `rates` capability and rate shopping uses the `is_estimate` `rate_table` fallback (§4.3) — configure a flat/zone table in `carrier_accounts.settings.rate_table`.

## Verification status — READ BEFORE ENABLING

- **UNVERIFIED against a live account, both modes.** Operation/field names (`addShipmentResult`, `getPDFResult`, `getStatus` row shape, REST `awbNo`/`events`) are from public material, not a capture. The REST surface field names are the least certain.
- Exercised by `tests/Feature/Carriers/SmsaCarrierTest.php` (SOAP create+label, SOAP tracking, REST create, REST auth failure, mode routing).
- **Before enabling:** capture `addShipment` + `getStatus` + a real AWB PDF from a SECOM test passKey, AND the REST `/api/shipment` + `/api/track` responses from a REST-onboarded account. Correct field drift; store fixtures here.

## Status map

Seeded by `CarrierStatusMapSeeder`, matched on lowercased status text. Unmapped → `exception` + warning (§6.8 rule 2).
