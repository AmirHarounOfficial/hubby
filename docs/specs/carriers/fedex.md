# Carrier: FedEx (`fedex`)

**Driver:** `app/Services/Shipping/Carriers/FedexCarrier.php`
**Status:** implemented against documented OAuth + Ship/Rate/Track shapes, fixture-tested — **not production-enabled until a captured sandbox response** (§6.8 r4). Lowest strategic priority; validates the interface handles OAuth carriers.

- **REST + OAuth2 (client_credentials).** Exchange `client_id`+`client_secret` at `/oauth/token` for a short-lived bearer. The token is cached per `carrier_account` (Laravel Cache, ~50 min) so a burst of label buys doesn't stampede the token endpoint.
- Endpoints: `/rate/v1/rates/quotes`, `/ship/v1/shipments` (label inline base64), `/track/v1/trackingnumbers`. Sandbox host `apis-sandbox.fedex.com`, prod `apis.fedex.com`.
- Capabilities: `rates`, `cancel`, `multi_package`. Positioning: cross-border, US/EU-bound.
- Credentials: `{ "client_id": "...", "client_secret": "...", "account_number": "..." }`.
- **UNVERIFIED:** exact per-version request/response schemas. Fixtures: `BreadthCarriersTest`.
- **Before enabling:** capture sandbox rate/ship/track responses; correct field drift; store fixtures here.
