# Carrier: J&T Express (`jnt`)

**Driver:** `app/Services/Shipping/Carriers/JntCarrier.php`
**Status:** implemented against the documented signing scheme, fixture-tested — **not production-enabled until the launch country's portal spec + a captured signed request** (§6.8 r4).

- **REST/JSON, signed:** `digest = base64(md5(json_body + private_key))`, header `apiAccount`.
- **Country-fragmented (structural):** Indonesian/Saudi/Egyptian/UAE operations do NOT share endpoints or field names. `credentials.country_code` selects the base URL from `config/shipping.php` (`shipping.carriers.jnt.countries`). Do NOT assume one API region-wide.
- Capabilities: `cod`, `cancel`.
- Credentials: `{ "api_account": "...", "private_key": "...", "customer_code": "...", "country_code": "sa" }`.
- **UNVERIFIED:** per-country endpoint paths, field names, signing-version. Fixtures: `BreadthCarriersTest`.
- **Before enabling:** get the launch country's portal spec + a captured signed create-order request.
