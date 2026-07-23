# Carrier: DHL Express (`dhl`)

**Driver:** `app/Services/Shipping/Carriers/DhlCarrier.php`
**Status:** implemented against documented shapes — **not production-enabled until a live sandbox fixture is captured** (spec 04 §6.8 rule 4).

## API surface

- **MyDHL API** (REST), `developer.dhl.com`. Auth: **HTTP Basic**, sent pre-emptively (`api_key` : `api_secret`).
- Base URLs: sandbox `https://express.api.dhl.com/mydhlapi/test`, production `https://express.api.dhl.com/mydhlapi`.
- Endpoints used: `POST /rates`, `POST /shipments` (label returned inline, base64 PDF), `GET /tracking`, `GET /products` (credential probe).
- Capabilities advertised: `rates`, `multi_package`, `pickup`, `cancel`, `address_validation`, `zpl`. COD is **not** advertised (DHL Gulf domestic COD is limited; gated by `carrier_accounts.cod_enabled` regardless).

## Credentials (`carrier_accounts.credentials`, encrypted:array)

```
{ "api_key": "...", "api_secret": "...", "account_number": "..." }
```

Issued by DHL Express consultants against an active DHL Express account. The developer portal provides test credentials.

## Verification status — READ BEFORE ENABLING

- **UNVERIFIED against a live sandbox.** The exact resource paths and payload field names were implemented from the portal documentation, not a captured response. The portal reference is the source of truth.
- Exercised by `tests/Feature/Carriers/DhlCarrierTest.php` with **representative** `Http::fake` fixtures (rates → CarrierRate, shipment → label bytes, tracking → normalized events, bad creds → CarrierAuthException).
- **Before flipping DHL on in production:** capture a real sandbox response for `/rates`, `/shipments`, and `/tracking`, diff it against the fixtures here, and correct any field-name drift. Store the captured fixtures next to this doc.

## Status map

Seeded by `CarrierStatusMapSeeder` keyed on the MyDHL tracking `typeCode` (`PU`, `PL`, `DF`, `AF`, `AR`, `CC`, `WC`, `OK`, `OH`, `RT`). Any unmapped code falls back to `exception` and raises a warning (spec §6.8 rule 2).
