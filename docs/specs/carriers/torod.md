# Carrier: Torod (`torod`) — aggregator

**Driver:** `app/Services/Shipping/Carriers/TorodCarrier.php`
**Status:** implemented against an assumed REST+bearer shape, fixture-tested — **not production-enabled until partner docs + a captured response** (§6.8 r4). Highest-leverage single integration: one connection yields many couriers.

- **REST + bearer token.** The *actual* courier (SMSA/Aramex/iMile…) comes back per shipment and is stored in `shipments.underlying_carrier` ("SMSA via Torod").
- Capabilities: `cod`, `cancel`.
- Credentials: `{ "api_token": "...", "base_url"?: "..." }`.
- **UNVERIFIED:** no public API reference was found — every endpoint shape is assumed until partner docs land. Fixtures: `BreadthCarriersTest`.
- **Before enabling:** obtain partner API docs + a sandbox token; capture a create + tracking response.
