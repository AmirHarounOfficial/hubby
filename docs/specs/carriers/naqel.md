# Carrier: Naqel Express (`naqel`)

**Driver:** `app/Services/Shipping/Carriers/NaqelCarrier.php` (uses `SoapCarrierClient`)
**Status:** implemented against documented shape, fixture-tested — **not production-enabled until a captured create-waybill response** (§6.8 r4). The third domestic Saudi option; matters for merchants with negotiated Naqel rates.

- **SOAP over HTTPS.** Auth: `client_id` + `password`. Ops (UNVERIFIED): `CreateWaybill`, `GetWaybseStatus`.
- Capabilities: `cod`, `cancel`, `pickup`. No confirmed rate API → estimate `rate_table` fallback.
- Credentials: `{ "client_id": "...", "password": "..." }` (encrypted:array; never persisted to raw_*).
- **UNVERIFIED:** operation names, endpoint hosts (prod/sandbox), label formats. The official Naqel PDF is the source of truth — obtain it during onboarding. Fixtures: `NaqelCarrierTest`.
- **Before enabling:** capture a create-waybill + status response; correct field drift; store fixtures here.
