# Spec 04 — Shipping & Labels

**Status:** Draft, implementation-ready
**Owner:** Backend / Fulfilment
**Depends on:** Spec 03 (Returns/RMA) for reverse shipments; Profit & Cost Engine spec for `order_fees`
**Repo baseline verified:** `backend/app/Services/Integrations/{IntegrationServiceInterface,IntegrationFactory,BaseIntegrationService,ShopifyService,TrendyolService}.php`, `backend/app/Models/{Order,OrderItem,Store,Integration}.php`, `backend/app/Http/Controllers/{OrderController,WebhookController}.php`, `backend/routes/api.php`, `backend/database/migrations/*`, `frontend/src/i18n/dictionary.ts`, `mobile/lib/l10n/strings.dart`

---

## 0. Baseline facts and explicit assumptions

**Verified today:**

| Fact | Source |
| --- | --- |
| Integration services implement `IntegrationServiceInterface` and are built by a `match`-based `IntegrationFactory::make(string $platform)` that throws on unknown platforms | `IntegrationFactory.php` |
| `BaseIntegrationService` is an abstract class implementing the interface with a shared `getHttpClient(Integration)` returning `Http::withToken(...)`; subclasses override it (Shopify uses a custom header, Trendyol uses `Http::withBasicAuth` + a `User-Agent`) | `BaseIntegrationService.php`, `ShopifyService.php`, `TrendyolService.php` |
| Credentials live in `integrations` (`store_id`, `access_token`, `refresh_token`, `expires_at`, `shop_domain`, `platform_id`) — **stored in plain columns, no cast, no encryption** | `2026_05_05_202922_create_integrations_table.php`, `App\Models\Integration` |
| `orders` has no address, no shipping cost, no payment method, no COD flag. The only place that data exists is `orders.raw_data` (JSON) | `create_orders_table` migration |
| `orders.status` is a free-form `string`; the dashboard renders a fixed set (`pending`, `processing`, `paid`, `shipped`, `delivered`, `cancelled`, `authorized`) | `frontend/src/i18n/dicts/orders.ts` |
| Webhooks land on `POST /api/webhooks/{platform}` behind `VerifyWebhookSignature` middleware | `routes/api.php` |
| Multi-tenancy = `X-Organization-Id` header + `org.member` (`EnsureOrganizationMember`); roles `owner`/`admin`/`viewer` | `EnsureOrganizationMember.php`, `OrganizationController` |
| Jobs pattern: `implements ShouldQueue`, `use Dispatchable, InteractsWithQueue, Queueable, SerializesModels`, `SyncLog` + `Notification` rows | `SyncOrdersJob.php` |

**ASSUMPTIONS (confirm before coding):**

1. **No address data is persisted today.** This spec creates `order_addresses` and a backfill from `orders.raw_data`. If the Profit spec or another spec also introduces addresses, reconcile before both ship.
2. **No encryption at rest for integration credentials.** Carrier credentials are materially more sensitive (they can create billable shipments). This spec therefore introduces `encrypted` casts for `carrier_accounts` credentials and recommends retrofitting `integrations` the same way — see §9. **This is a security prerequisite, not a nice-to-have.**
3. Queue driver is database (`0001_01_01_000002_create_jobs_table.php` exists). Label generation is therefore async with polling, not synchronous.
4. There is no object storage configured that I verified. Labels need durable binary storage; this spec assumes an S3-compatible disk configured as `config('filesystems.disks.labels')`, defaulting to the `local` disk in dev. **Confirm S3/Spaces availability before M1.**
5. Weight is in kilograms and dimensions in centimetres throughout — the region's carriers all use metric. Imperial input is converted at the edge, never stored.
6. `products`/`product_variants` have no weight or dimension columns. This spec adds them (§3.11) because rate shopping is meaningless without them.

---

## 1. Why this exists (competitive rationale)

Shipping is the second half of the order lifecycle and we currently have none of it. A merchant using Hubby today syncs an order, then leaves Hubby to go book a shipment somewhere else. That's the moment we lose the session, and it's the moment competitors keep it.

**What the competition has:** Linnworks ships with a large courier catalogue and label printing as a core primitive. Rithum/ChannelAdvisor treats fulfilment and carrier routing as a first-class module. Even the lightweight tools integrate with a Western carrier set.

**What none of them has: the Gulf.** This is the wedge.

Linnworks, Sellerboard, ShipStation, and every other incumbent were built for US/UK/EU merchants. Their carrier catalogues are DHL/FedEx/UPS/Royal Mail/USPS and dozens of European regionals. **Aramex, SMSA, Naqel, J&T, and the Saudi aggregators are either absent or a bolt-on afterthought.** A Riyadh merchant shipping 400 orders a month on SMSA and Aramex cannot use Linnworks for shipping at all — they use a separate local aggregator, or they use the carrier's own portal and copy-paste.

That is a structural gap, not a feature gap, and it compounds with three other regional realities:

1. **COD is the default payment method.** A shipment isn't just a parcel — it's a *collection instruction*. The carrier must be told the exact amount to collect, the merchant must reconcile what the carrier actually remitted, and the failure mode (RTO, Spec 03) is a pure loss. Western shipping tools model COD as an exotic flag. In MENA it's the main path.
2. **Arabic tracking is table stakes for the end customer, and nobody provides it.** Carrier tracking pages are frequently English-only, unbranded, and hostile on mobile. A branded, RTL, Arabic tracking page under the merchant's own domain is a visible, demoable differentiator that costs us a few days and that no incumbent will bother building for this market.
3. **Rate shopping across local carriers is real money.** Aramex vs SMSA vs Naqel vs J&T on the same Riyadh→Jeddah 2 kg parcel is a meaningful spread. Nobody is showing MENA merchants that comparison inside their order workflow.

So the pitch is not "Hubby also does shipping." It's: **"Hubby is the only multi-channel platform that speaks Aramex, SMSA, Naqel, J&T and Torod, handles COD end-to-end, and gives your customer an Arabic tracking page."** DHL and FedEx are in scope so the cross-border and premium cases are covered — but they are the *supporting cast*, not the pitch.

Without this feature: Hubby stays a reporting tool. With it, Hubby becomes the place the merchant works all day.

---

## 2. Scope — in / out

### In scope (v1)

- `ShippingCarrierInterface` + `BaseShippingCarrier` + `CarrierFactory`, mirroring the existing integration pattern exactly.
- Per-organization carrier accounts with encrypted credentials, sandbox/production toggle, and a credential-validation probe.
- Data model: `order_addresses`, `shipments`, `shipment_packages`, `shipment_items`, `shipping_labels`, `tracking_events`, `shipping_rates`, `manifests`, `pickup_requests`, `carrier_accounts`, `carrier_status_map`.
- Rate shopping across all configured carriers for an order, with caching and a deterministic ranking.
- Label / AWB generation in **PDF** and **ZPL** (thermal), stored durably, printable single or batch.
- Multi-package shipments (one shipment, n packages, n AWBs where the carrier supports it).
- Manifests (end-of-day handover documents) and pickup requests.
- Tracking: carrier webhooks where available, polling where not, normalized into a single status vocabulary with ordered event history.
- Packing slips (bilingual, per shipment, batch-printable).
- Address validation and normalization (carrier-provided where available; a Saudi/UAE city + region normalizer where not).
- **COD amount** propagated to the carrier and reconciled against remittance.
- **Arabic branded public tracking page** under the merchant's branding, RTL-first.
- Push of tracking number + carrier back to the source platform via the existing `updateOrderStatus`/fulfilment path.
- Shipping cost posted to `order_fees` (Profit spec).
- Reverse/return shipments for Spec 03.

### Out of scope (v1)

- Automated carrier selection *rules engine* (weight/zone/value → carrier). v1 ships manual selection + a rate-sorted list; rules are v1.1.
- Customs documents / commercial invoices for cross-border (DHL/FedEx international beyond a basic description field).
- Dangerous goods, dry ice, temperature-controlled.
- Freight / LTL / pallet shipping.
- Carrier rate *contracts* stored in Hubby (we always ask the carrier for live rates; we do not model negotiated tariff tables).
- Insurance purchase flows.
- Shipping-zone / rate-table configuration pushed *to* the storefronts (Shopify carrier service callbacks etc.).
- Warehouse/location model — one ship-from address per store in v1 (see Open Questions).
- Automatic COD remittance reconciliation from carrier settlement files (we record expected vs received; parsing carrier settlement CSVs is v1.1).

---

## 3. Data model

Same conventions as Spec 03: migrations `YYYY_MM_DD_NNNNNN_verb_noun_table.php`, `decimal(15,2)` money, `string(3)` currency, denormalized `organization_id` on hot tables with a documented rationale.

### 3.1 `carrier_accounts`

Migration: `2026_07_24_000001_create_carrier_accounts_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations.id` `cascadeOnDelete` | no | — | |
| `carrier_code` | `string(32)` | no | — | `aramex`, `smsa`, `naqel`, `jnt`, `torod`, `dhl`, `fedex` |
| `label` | `string(120)` | no | — | merchant-visible name, e.g. "Aramex — Riyadh account" |
| `environment` | `enum('sandbox','production')` | no | `'sandbox'` | |
| `credentials` | `text` | no | — | JSON, **`encrypted:array` cast** — shape differs per carrier |
| `account_number` | `string(64)` | yes | `null` | non-secret, shown in UI |
| `settings` | `json` | yes | `null` | default service code, package type, insurance, label format |
| `ship_from_address_id` | `foreignId` → `order_addresses.id` `nullOnDelete` | yes | `null` | default origin |
| `supported_services` | `json` | yes | `null` | cached service catalogue |
| `is_active` | `boolean` | no | `true` | |
| `is_default` | `boolean` | no | `false` | one default per org (app-enforced) |
| `cod_enabled` | `boolean` | no | `false` | carrier account is COD-approved |
| `last_validated_at` | `timestamp` | yes | `null` | |
| `last_error` | `text` | yes | `null` | last credential/API failure |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `unique(['organization_id','carrier_code','label'])`, `index(['organization_id','is_active'])`, `index('carrier_code')`.

`credentials` shapes (documented in `config/shipping.php`, validated per carrier by a `CredentialSchema`):

```
aramex: { username, password, account_number, account_pin, account_entity, account_country_code }
smsa:   { passkey }                          // or { api_key } for the newer REST surface — see §6.2
naqel:  { client_id, password, sandbox_base_url? }
jnt:    { api_account, private_key, customer_code, customer_password?, country_code }
torod:  { api_token }                        // bearer
dhl:    { api_key, api_secret, account_number }   // MyDHL API basic auth
fedex:  { client_id, client_secret, account_number }  // OAuth2 client credentials
```

### 3.2 `order_addresses`

Migration: `2026_07_24_000002_create_order_addresses_table.php`

Introduced here because nothing persists addresses today. Also used as the ship-from record for carrier accounts and stores (hence `order_id` nullable).

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations.id` `cascadeOnDelete` | no | — | |
| `order_id` | `foreignId` → `orders.id` `cascadeOnDelete` | **yes** | `null` | null ⇒ a ship-from / warehouse address |
| `type` | `enum('ship_to','bill_to','ship_from','return_to')` | no | `'ship_to'` | |
| `name` | `string(255)` | yes | `null` | |
| `company` | `string(255)` | yes | `null` | |
| `phone` | `string(32)` | yes | `null` | E.164 normalized where possible |
| `phone_alt` | `string(32)` | yes | `null` | second number — MENA carriers use it heavily |
| `email` | `string(255)` | yes | `null` | |
| `line1` | `string(255)` | yes | `null` | |
| `line2` | `string(255)` | yes | `null` | |
| `district` | `string(120)` | yes | `null` | حي — required by Saudi carriers |
| `city` | `string(120)` | yes | `null` | |
| `city_normalized` | `string(120)` | yes | `null` | canonical latin key, e.g. `riyadh` |
| `state` | `string(120)` | yes | `null` | region/emirate |
| `postal_code` | `string(20)` | yes | `null` | |
| `country_code` | `string(2)` | no | `'SA'` | ISO-3166-1 alpha-2 |
| `short_address` | `string(16)` | yes | `null` | Saudi National Address code (e.g. `RRRD2929`) |
| `latitude` | `decimal(10,7)` | yes | `null` | |
| `longitude` | `decimal(10,7)` | yes | `null` | |
| `is_validated` | `boolean` | no | `false` | |
| `validation_source` | `string(32)` | yes | `null` | `carrier:aramex`, `internal`, `manual` |
| `validation_notes` | `json` | yes | `null` | warnings/corrections |
| `raw` | `json` | yes | `null` | original platform address |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `index(['order_id','type'])`, `index(['organization_id','type'])`, `index('city_normalized')`, `index('country_code')`, `index('phone')`.

### 3.3 `shipments`

Migration: `2026_07_24_000003_create_shipments_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations.id` `cascadeOnDelete` | no | — | |
| `store_id` | `foreignId` → `stores.id` `cascadeOnDelete` | no | — | |
| `order_id` | `foreignId` → `orders.id` `cascadeOnDelete` | **yes** | `null` | null for standalone/return shipments |
| `return_request_id` | `unsignedBigInteger` | yes | `null` | FK added once Spec 03 tables exist |
| `carrier_account_id` | `foreignId` → `carrier_accounts.id` `restrictOnDelete` | yes | `null` | null while `draft` |
| `carrier_code` | `string(32)` | yes | `null` | snapshot |
| `service_code` | `string(64)` | yes | `null` | e.g. `DOM`, `PPX`, `EXP` |
| `service_name` | `string(120)` | yes | `null` | |
| `direction` | `enum('outbound','return','rto')` | no | `'outbound'` | |
| `reference` | `string(40)` | no | — | our human key, `SHP-2026-000912` |
| `tracking_number` | `string(64)` | yes | `null` | master AWB |
| `carrier_shipment_id` | `string(120)` | yes | `null` | carrier's own id |
| `status` | `string(32)` | no | `'draft'` | normalized, §4.2 |
| `carrier_status_raw` | `string(120)` | yes | `null` | last raw carrier status |
| `carrier_status_code` | `string(40)` | yes | `null` | |
| `ship_from_address_id` | `foreignId` → `order_addresses.id` `nullOnDelete` | yes | `null` | |
| `ship_to_address_id` | `foreignId` → `order_addresses.id` `nullOnDelete` | yes | `null` | |
| `return_to_address_id` | `foreignId` → `order_addresses.id` `nullOnDelete` | yes | `null` | |
| `package_count` | `unsignedSmallInteger` | no | `1` | |
| `total_weight_kg` | `decimal(10,3)` | no | `0` | |
| `declared_value` | `decimal(15,2)` | no | `0` | for customs/insurance |
| `currency` | `string(3)` | no | `'SAR'` | |
| `is_cod` | `boolean` | no | `false` | |
| `cod_amount` | `decimal(15,2)` | no | `0` | what the carrier must collect |
| `cod_currency` | `string(3)` | yes | `null` | |
| `cod_collected_amount` | `decimal(15,2)` | yes | `null` | reconciliation |
| `cod_collected_at` | `timestamp` | yes | `null` | |
| `cod_remitted_at` | `timestamp` | yes | `null` | |
| `shipping_cost` | `decimal(15,2)` | yes | `null` | what we pay the carrier |
| `shipping_cost_currency` | `string(3)` | yes | `null` | |
| `charged_to_customer` | `decimal(15,2)` | no | `0` | what the buyer paid for shipping |
| `insurance_amount` | `decimal(15,2)` | no | `0` | |
| `incoterm` | `string(8)` | yes | `null` | `DDP`/`DDU` for cross-border |
| `contents_description` | `string(255)` | yes | `null` | |
| `pieces_description` | `string(255)` | yes | `null` | |
| `special_instructions` | `string(500)` | yes | `null` | |
| `manifest_id` | `foreignId` → `manifests.id` `nullOnDelete` | yes | `null` | |
| `pickup_request_id` | `foreignId` → `pickup_requests.id` `nullOnDelete` | yes | `null` | |
| `rate_id` | `foreignId` → `shipping_rates.id` `nullOnDelete` | yes | `null` | rate chosen at purchase |
| `label_format` | `enum('pdf','zpl','png')` | no | `'pdf'` | requested format |
| `tracking_url` | `string(500)` | yes | `null` | carrier's own URL |
| `public_tracking_slug` | `string(48)` | yes | `null` | our branded page key |
| `estimated_delivery_at` | `timestamp` | yes | `null` | |
| `shipped_at` | `timestamp` | yes | `null` | |
| `delivered_at` | `timestamp` | yes | `null` | |
| `cancelled_at` | `timestamp` | yes | `null` | |
| `last_tracked_at` | `timestamp` | yes | `null` | last successful poll |
| `tracking_poll_attempts` | `unsignedSmallInteger` | no | `0` | backoff control |
| `pushed_to_platform_at` | `timestamp` | yes | `null` | tracking number pushed upstream |
| `created_by_user_id` | `foreignId` → `users.id` `nullOnDelete` | yes | `null` | |
| `error_code` | `string(48)` | yes | `null` | last failure |
| `error_message` | `text` | yes | `null` | |
| `raw_request` | `json` | yes | `null` | redacted |
| `raw_response` | `json` | yes | `null` | redacted |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes:
- `unique(['organization_id','reference'])`
- `unique(['carrier_code','tracking_number'])` — a tracking number is unique per carrier; nullable while draft
- `unique('public_tracking_slug')`
- `index(['organization_id','status'])`
- `index(['store_id','status'])`
- `index('order_id')`
- `index('return_request_id')`
- `index(['carrier_account_id','status'])`
- `index('manifest_id')`
- `index(['is_cod','cod_remitted_at'])` — the COD reconciliation query
- `index(['status','last_tracked_at'])` — the polling query
- `index('created_at')`

### 3.4 `shipment_packages`

Migration: `2026_07_24_000004_create_shipment_packages_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `shipment_id` | `foreignId` → `shipments.id` `cascadeOnDelete` | no | — | |
| `sequence` | `unsignedSmallInteger` | no | `1` | 1..n, "piece 2 of 3" |
| `tracking_number` | `string(64)` | yes | `null` | per-piece AWB where supported |
| `carrier_package_id` | `string(120)` | yes | `null` | |
| `package_type` | `string(32)` | no | `'box'` | `box`, `envelope`, `pallet`, `custom` |
| `weight_kg` | `decimal(10,3)` | no | `0` | actual |
| `length_cm` | `decimal(8,2)` | yes | `null` | |
| `width_cm` | `decimal(8,2)` | yes | `null` | |
| `height_cm` | `decimal(8,2)` | yes | `null` | |
| `volumetric_weight_kg` | `decimal(10,3)` | yes | `null` | computed, §4.5 |
| `chargeable_weight_kg` | `decimal(10,3)` | yes | `null` | `max(actual, volumetric)` |
| `declared_value` | `decimal(15,2)` | no | `0` | |
| `contents_description` | `string(255)` | yes | `null` | |
| `reference` | `string(64)` | yes | `null` | merchant's own box ref |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `index('shipment_id')`, `unique(['shipment_id','sequence'])`, `index('tracking_number')`.

### 3.5 `shipment_items`

Migration: `2026_07_24_000005_create_shipment_items_table.php`

Which order lines are in which box. Required for partial fulfilment and for accurate packing slips.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `shipment_id` | `foreignId` → `shipments.id` `cascadeOnDelete` | no | — | |
| `shipment_package_id` | `foreignId` → `shipment_packages.id` `cascadeOnDelete` | yes | `null` | null ⇒ single-package shipment |
| `order_item_id` | `foreignId` → `order_items.id` `nullOnDelete` | yes | `null` | |
| `return_item_id` | `unsignedBigInteger` | yes | `null` | Spec 03, FK added later |
| `sku` | `string(255)` | yes | `null` | snapshot |
| `name` | `string(255)` | no | — | snapshot |
| `quantity` | `unsignedInteger` | no | — | |
| `unit_weight_kg` | `decimal(10,3)` | yes | `null` | |
| `unit_value` | `decimal(15,2)` | no | `0` | customs value |
| `hs_code` | `string(16)` | yes | `null` | cross-border |
| `country_of_origin` | `string(2)` | yes | `null` | |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `index('shipment_id')`, `index('shipment_package_id')`, `index('order_item_id')`, `index('sku')`.

### 3.6 `shipping_labels`

Migration: `2026_07_24_000006_create_shipping_labels_table.php`

Separate from `shipments` because one shipment can have many label artefacts (per package, per format, reprints).

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `shipment_id` | `foreignId` → `shipments.id` `cascadeOnDelete` | no | — | |
| `shipment_package_id` | `foreignId` → `shipment_packages.id` `cascadeOnDelete` | yes | `null` | |
| `type` | `enum('label','packing_slip','manifest','commercial_invoice','return_label')` | no | `'label'` | |
| `format` | `enum('pdf','zpl','png','epl','html')` | no | `'pdf'` | |
| `disk` | `string(32)` | no | `'labels'` | filesystem disk |
| `path` | `string(500)` | no | — | `org/{org}/shipments/{id}/label-{uuid}.pdf` |
| `size_bytes` | `unsignedInteger` | yes | `null` | |
| `checksum` | `string(64)` | yes | `null` | sha256, dedupe reprints |
| `page_count` | `unsignedSmallInteger` | yes | `null` | |
| `carrier_label_id` | `string(120)` | yes | `null` | |
| `printed_count` | `unsignedSmallInteger` | no | `0` | |
| `last_printed_at` | `timestamp` | yes | `null` | |
| `voided_at` | `timestamp` | yes | `null` | |
| `expires_at` | `timestamp` | yes | `null` | some carriers expire label URLs |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `index(['shipment_id','type'])`, `index('shipment_package_id')`, `index('checksum')`.

**Never** store a carrier's temporary label URL as the only copy. Every label is downloaded and persisted to our disk at creation time; the carrier URL goes in `shipments.raw_response` for forensics only.

### 3.7 `tracking_events`

Migration: `2026_07_24_000007_create_tracking_events_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `shipment_id` | `foreignId` → `shipments.id` `cascadeOnDelete` | no | — | |
| `shipment_package_id` | `foreignId` → `shipment_packages.id` `cascadeOnDelete` | yes | `null` | |
| `status` | `string(32)` | no | — | normalized, §4.2 |
| `raw_status` | `string(160)` | yes | `null` | carrier's text |
| `raw_code` | `string(40)` | yes | `null` | carrier's code |
| `description_en` | `string(500)` | yes | `null` | |
| `description_ar` | `string(500)` | yes | `null` | translated where we have a mapping |
| `location` | `string(160)` | yes | `null` | |
| `city` | `string(120)` | yes | `null` | |
| `country_code` | `string(2)` | yes | `null` | |
| `signed_by` | `string(160)` | yes | `null` | POD name |
| `event_at` | `timestamp` | no | — | carrier's timestamp, UTC |
| `received_at` | `timestamp` | no | — | when we ingested it |
| `source` | `enum('webhook','poll','manual')` | no | `'poll'` | |
| `is_exception` | `boolean` | no | `false` | |
| `fingerprint` | `string(64)` | no | — | `sha1(shipment_id|raw_code|event_at|location)` — dedupe |
| `payload` | `json` | yes | `null` | redacted raw |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `unique(['shipment_id','fingerprint'])`, `index(['shipment_id','event_at'])`, `index(['status','event_at'])`, `index('is_exception')`.

### 3.8 `shipping_rates`

Migration: `2026_07_24_000008_create_shipping_rates_table.php`

Rate-shop results. Persisted (not just cached) so we can show "you picked the 3rd cheapest" and audit cost decisions.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations.id` `cascadeOnDelete` | no | — | |
| `order_id` | `foreignId` → `orders.id` `cascadeOnDelete` | yes | `null` | |
| `shipment_id` | `foreignId` → `shipments.id` `cascadeOnDelete` | yes | `null` | set once a draft exists |
| `request_hash` | `string(64)` | no | — | hash of origin+dest+weight+dims+cod — cache key |
| `carrier_account_id` | `foreignId` → `carrier_accounts.id` `cascadeOnDelete` | no | — | |
| `carrier_code` | `string(32)` | no | — | |
| `service_code` | `string(64)` | no | — | |
| `service_name` | `string(120)` | yes | `null` | |
| `amount` | `decimal(15,2)` | no | — | |
| `currency` | `string(3)` | no | `'SAR'` | |
| `cod_fee` | `decimal(15,2)` | no | `0` | |
| `fuel_surcharge` | `decimal(15,2)` | no | `0` | |
| `vat_amount` | `decimal(15,2)` | no | `0` | |
| `total_amount` | `decimal(15,2)` | no | — | what we actually compare on |
| `transit_days_min` | `unsignedTinyInteger` | yes | `null` | |
| `transit_days_max` | `unsignedTinyInteger` | yes | `null` | |
| `estimated_delivery_at` | `timestamp` | yes | `null` | |
| `is_estimate` | `boolean` | no | `false` | true when derived from a local table, not the carrier |
| `rank` | `unsignedTinyInteger` | yes | `null` | 1 = recommended |
| `is_selected` | `boolean` | no | `false` | |
| `expires_at` | `timestamp` | no | — | typically `now()+30min` |
| `raw` | `json` | yes | `null` | |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `index(['request_hash','expires_at'])`, `index(['organization_id','created_at'])`, `index('order_id')`, `index('shipment_id')`, `index(['carrier_code','service_code'])`.

Retention: a scheduled `PruneShippingRatesJob` deletes rows older than 90 days.

### 3.9 `manifests`

Migration: `2026_07_24_000009_create_manifests_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations.id` `cascadeOnDelete` | no | — | |
| `carrier_account_id` | `foreignId` → `carrier_accounts.id` `cascadeOnDelete` | no | — | |
| `carrier_code` | `string(32)` | no | — | |
| `reference` | `string(40)` | no | — | `MAN-2026-000112` |
| `carrier_manifest_id` | `string(120)` | yes | `null` | |
| `status` | `enum('draft','submitted','confirmed','failed')` | no | `'draft'` | |
| `shipment_count` | `unsignedInteger` | no | `0` | |
| `total_weight_kg` | `decimal(12,3)` | no | `0` | |
| `manifest_date` | `date` | no | — | |
| `submitted_at` | `timestamp` | yes | `null` | |
| `confirmed_at` | `timestamp` | yes | `null` | |
| `error_message` | `text` | yes | `null` | |
| `raw_response` | `json` | yes | `null` | |
| `created_by_user_id` | `foreignId` → `users.id` `nullOnDelete` | yes | `null` | |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `unique(['organization_id','reference'])`, `index(['carrier_account_id','manifest_date'])`, `index('status')`.

Shipments join a manifest via `shipments.manifest_id` (one manifest per shipment).

### 3.10 `pickup_requests`

Migration: `2026_07_24_000010_create_pickup_requests_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations.id` `cascadeOnDelete` | no | — | |
| `carrier_account_id` | `foreignId` → `carrier_accounts.id` `cascadeOnDelete` | no | — | |
| `carrier_code` | `string(32)` | no | — | |
| `reference` | `string(40)` | no | — | `PKP-2026-000045` |
| `carrier_pickup_id` | `string(120)` | yes | `null` | confirmation number |
| `status` | `enum('requested','confirmed','cancelled','completed','failed')` | no | `'requested'` | |
| `pickup_address_id` | `foreignId` → `order_addresses.id` `nullOnDelete` | yes | `null` | |
| `pickup_date` | `date` | no | — | |
| `ready_at` | `time` | yes | `null` | window open |
| `close_at` | `time` | yes | `null` | window close |
| `contact_name` | `string(160)` | yes | `null` | |
| `contact_phone` | `string(32)` | yes | `null` | |
| `pieces` | `unsignedSmallInteger` | no | `1` | |
| `total_weight_kg` | `decimal(12,3)` | no | `0` | |
| `instructions` | `string(500)` | yes | `null` | |
| `error_message` | `text` | yes | `null` | |
| `raw_response` | `json` | yes | `null` | |
| `created_by_user_id` | `foreignId` → `users.id` `nullOnDelete` | yes | `null` | |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `unique(['organization_id','reference'])`, `index(['carrier_account_id','pickup_date'])`, `index('status')`.

### 3.11 `carrier_status_map`

Migration: `2026_07_24_000011_create_carrier_status_map_table.php`

Data-driven normalization so a new carrier status doesn't need a deploy.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `carrier_code` | `string(32)` | no | — | |
| `raw_code` | `string(64)` | yes | `null` | carrier status code |
| `raw_status` | `string(160)` | yes | `null` | carrier status text (lowercased match) |
| `normalized_status` | `string(32)` | no | — | §4.2 vocabulary |
| `is_exception` | `boolean` | no | `false` | |
| `is_final` | `boolean` | no | `false` | terminal for polling |
| `description_en` | `string(255)` | yes | `null` | |
| `description_ar` | `string(255)` | yes | `null` | customer-facing Arabic copy |
| `priority` | `unsignedSmallInteger` | no | `100` | lower wins when both code and text match |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `index(['carrier_code','raw_code'])`, `index(['carrier_code','raw_status'])`, `unique(['carrier_code','raw_code','raw_status'], 'carrier_status_map_unique')`.

Seeded per carrier by `CarrierStatusMapSeeder`. Unmapped statuses fall back to `exception` **and** raise a `Notification(type: 'warning')` plus a log line, so we discover new codes instead of silently mis-rendering them.

### 3.12 Changes to existing tables

Migration: `2026_07_24_000012_add_fulfillment_fields_to_orders_table.php` (guarded with `Schema::hasColumn`, matching `2026_05_06_090717_fix_orders_table_columns.php`)

```
orders:
  + payment_method       string(32)  nullable        // 'cod','card','wallet','bank_transfer','marketplace'
  + is_cod               boolean not null default false
  + cod_amount           decimal(15,2) not null default 0
  + shipping_total       decimal(15,2) not null default 0   // charged to the buyer
  + fulfillment_status   string(24) nullable         // null|'unfulfilled'|'partial'|'fulfilled'|'shipped'|'delivered'|'rto'
  + shipments_count      unsignedSmallInteger not null default 0
  + placed_at            timestamp nullable          // platform order date, distinct from created_at
  index(['store_id','fulfillment_status'])
  index('placed_at')
  index(['is_cod','fulfillment_status'])
```

> **Coordination note:** the Profit & Cost Engine spec may also want `shipping_total` / `placed_at`. Whichever ships first owns the migration; the second must `Schema::hasColumn`-guard. Do not add these twice.

Migration: `2026_07_24_000013_add_dimensions_to_products_and_variants.php`

```
products:          + weight_kg decimal(10,3) nullable
                   + length_cm / width_cm / height_cm decimal(8,2) nullable
                   + hs_code string(16) nullable
                   + country_of_origin string(2) nullable
product_variants:  + weight_kg decimal(10,3) nullable
                   + length_cm / width_cm / height_cm decimal(8,2) nullable
                   + barcode string(64) nullable index
```

Variant values win; product values are the fallback; a per-org default parcel weight is the last resort (§4.5).

Migration: `2026_07_24_000014_add_shipping_settings_to_stores_table.php`

```
stores: + default_ship_from_address_id foreignId nullable -> order_addresses.id nullOnDelete
        + shipping_settings json nullable   // default carrier, default service, packing slip logo, tracking page branding
```

### 3.13 ER summary

```
organizations 1─* carrier_accounts 1─* shipments
                                         ├─* shipment_packages 1─* shipment_items
                                         ├─* shipping_labels
                                         ├─* tracking_events
                                         ├─0..1 manifests
                                         ├─0..1 pickup_requests
                                         └─0..1 shipping_rates (selected)
orders 1─* order_addresses          orders 1─* shipments
orders 1─* order_items 1─* shipment_items
return_requests (Spec 03) 0..1─1 shipments (direction = 'return')
shipments ──* order_fees (Profit spec: shipping, cod_fee, rto_shipping)
carrier_status_map ──(carrier_code, raw_code)── tracking_events.status
```

---

## 4. Domain logic

### 4.1 Shipment lifecycle

`shipments.status` vocabulary is the **same** vocabulary as normalized tracking (§4.2) plus three pre-transit states we own:

```
draft ──► rated ──► label_purchased ──► awaiting_pickup ──► picked_up ──► in_transit
                                                                              │
                          ┌───────────────────────────────────────────────────┤
                          ▼                                                   ▼
                 out_for_delivery ──► delivered (final)              exception / held
                          │                                                   │
                          ▼                                                   ▼
                delivery_attempted ──► (retry) ──► out_for_delivery    returned_to_origin
                          │                                                   │
                          └────────► rto_in_transit ──────────────────────────┘
                                                                              ▼
                                                                    rto_delivered (final)
draft/rated/label_purchased ──► cancelled (final)      any ──► lost (final)  any ──► damaged
```

Merchant-triggered transitions:

| From | To | Trigger | Guard |
| --- | --- | --- | --- |
| `draft` | `rated` | `POST /shipments/{id}/rates` | valid addresses + ≥1 package with weight > 0 |
| `draft`/`rated` | `label_purchased` | `POST /shipments/{id}/label` | carrier account active; COD amount ≤ order total; address validated or override acknowledged |
| `label_purchased` | `awaiting_pickup` | manifested or pickup booked | |
| `label_purchased`/`awaiting_pickup` | `cancelled` | `POST /shipments/{id}/cancel` | carrier supports void; not yet scanned |
| any pre-`picked_up` | `cancelled` | merchant | label voided with carrier, `shipping_labels.voided_at` set |

Everything from `picked_up` onward is **carrier-driven only** — no merchant endpoint may set those states, except a `POST /shipments/{id}/tracking-events` manual-entry endpoint (owner/admin) that writes a `source = 'manual'` event, for carriers with no API at all.

Illegal transitions → `422 INVALID_SHIPMENT_TRANSITION`.

Order rollup (`orders.fulfillment_status`), maintained by `ShipmentObserver`:
- no shipments → `unfulfilled`
- some order-item quantities shipped → `partial`
- all shipped, none delivered → `shipped`
- all delivered → `delivered`
- any shipment `returned_to_origin`/`rto_delivered` → `rto`

### 4.2 Normalized tracking status vocabulary

One vocabulary for every carrier. This is the contract the dashboard, mobile, tracking page and Spec 03's RTO detector all code against.

| Status | Meaning | Final | Exception |
| --- | --- | --- | --- |
| `draft` | Created in Hubby, no carrier record | no | no |
| `rated` | Rates fetched, not purchased | no | no |
| `label_purchased` | AWB exists, parcel not handed over | no | no |
| `awaiting_pickup` | Manifested / pickup booked | no | no |
| `picked_up` | Carrier has physical custody | no | no |
| `in_transit` | Moving | no | no |
| `at_origin_hub` | Scanned at origin facility | no | no |
| `at_destination_hub` | Scanned at destination facility | no | no |
| `customs_clearance` | Held in customs (cross-border) | no | no |
| `out_for_delivery` | On the courier's van today | no | no |
| `delivery_attempted` | Failed attempt, will retry | no | yes |
| `held` | At a facility awaiting customer action / address fix | no | yes |
| `delivered` | POD recorded | **yes** | no |
| `returned_to_origin` | Being sent back — **the RTO trigger** | no | yes |
| `rto_in_transit` | On the way back | no | yes |
| `rto_delivered` | Back with the merchant | **yes** | yes |
| `cancelled` | Voided before pickup | **yes** | no |
| `lost` | Carrier declared it lost | **yes** | yes |
| `damaged` | Carrier declared it damaged | **yes** | yes |
| `exception` | Anything unmapped or genuinely exceptional | no | yes |

**Ordering rule (critical):** tracking events arrive out of order — webhooks retry, polls overlap, carriers backfill. So:
- `tracking_events` is append-only, deduped by `fingerprint`.
- `shipments.status` is recomputed as **the status of the event with the greatest `event_at`**, ties broken by the greatest `id`. Never "the last event we received".
- A final status is sticky: once `delivered`/`rto_delivered`/`cancelled`/`lost`, a later non-final event with an *earlier* `event_at` is stored but does not move `shipments.status`. A later *final* event with a *later* `event_at` does (e.g. `delivered` → `returned_to_origin` genuinely happens when a customer refuses at the door after a mis-scan).
- `delivered_at`, `shipped_at`, `cancelled_at` are set from the event that first produced the status, not from `now()`.

### 4.3 Rate shopping

`ShippingRateService::shop(RateRequest $req): RateCollection`

1. Build `request_hash = sha1(json([from.city_normalized, from.country, to.city_normalized, to.district, to.country, packages(weight+dims), declared_value, is_cod, cod_amount, service_filter]))`.
2. Return cached rows from `shipping_rates` where `request_hash` matches and `expires_at > now()` — avoids hammering carriers when a merchant reopens the modal.
3. Otherwise fan out to every active `carrier_accounts` row for the org **concurrently** using `Http::pool()`, each with a **6 s timeout**. This is a user-facing synchronous call, so the total budget is 8 s.
4. Carriers that time out or error are **omitted, not failed** — the response includes an `errors[]` array so the UI can say "SMSA didn't respond". One dead carrier must never block the rate modal.
5. Normalize each rate to `total_amount = amount + cod_fee + fuel_surcharge + vat_amount` in the carrier's currency. **Do not convert currencies in v1**; if carriers quote in different currencies, group and warn (§10).
6. Rank: default `cheapest` (by `total_amount`, tie-break faster `transit_days_max`). Org setting `shipping.rate_ranking` also allows `fastest` and `preferred_carrier_first`. `rank = 1` gets `is_recommended` in the API response.
7. Persist all rates with `expires_at = now()->addMinutes(30)`.

**Estimate fallback:** carriers with no rate API (see §6) return `is_estimate = true` rows derived from a per-org, per-carrier flat/zone table in `carrier_accounts.settings.rate_table`. The UI must visibly mark these as estimates — showing a guessed price as a quoted price is how you lose a merchant's trust permanently.

### 4.4 Label & AWB generation

`ShippingService::purchaseLabel(Shipment $shipment, array $opts): Shipment`

Flow, all inside a guarded sequence:

1. **Validate**: addresses present and (validated or explicitly overridden), ≥1 package, every package `weight_kg > 0`, carrier account active and `environment` matches the app environment policy, COD rules satisfied (§4.7).
2. **Idempotency**: a `shipments.reference`-derived idempotency key. If `tracking_number` is already set, return the existing shipment (`200`, not a new label). Double-clicking "Buy label" must never buy two labels — every carrier bills per AWB.
3. **Call the carrier** via `ShippingCarrierInterface::createShipment()`, 30 s timeout, **no automatic retry on timeout** (a timeout may mean the AWB *was* created). Instead, on timeout the shipment goes to `error_code = 'CARRIER_TIMEOUT'` and a reconcile job queries the carrier by our reference before any retry.
4. **Persist** `tracking_number`, per-package tracking numbers, `carrier_shipment_id`, `shipping_cost`, `estimated_delivery_at`, `tracking_url`.
5. **Download and store** the label bytes to the `labels` disk, one `shipping_labels` row per artefact. If the carrier returns base64, decode; if it returns a URL, fetch it now (URLs expire).
6. **Generate `public_tracking_slug`** = 12 random url-safe chars, unique.
7. **Status** → `label_purchased`; write a `tracking_events` row (`source = 'manual'`, status `label_purchased`).
8. **Dispatch** `PushTrackingToPlatformJob` and `PostShippingCostJob` (→ `order_fees`).

**Formats.** `pdf` (A6 and A4-with-4-up), `zpl` (203 dpi and 300 dpi variants — the carrier decides; we pass `label_format` + `dpi` through), `png` fallback. Where a carrier returns only PDF but the merchant wants ZPL, we do **not** convert (raster-converted ZPL prints badly); the UI states which formats each carrier offers.

**Printing.**
- Single: `GET /api/shipments/{id}/label` streams the stored file with the right content type (`application/pdf`, `application/octet-stream` for ZPL) and `Content-Disposition: inline` for PDF.
- Batch: `POST /api/shipments/labels/batch` with up to 100 shipment ids → queues `BuildLabelBatchJob`, which merges PDFs (one page per label) or concatenates ZPL streams, stores a single artefact, and returns a job id; the client polls `GET /api/shipments/labels/batch/{id}`. Synchronous merging of 100 PDFs will time out — this must be async.
- Thermal printing in-browser: the dashboard offers "Download ZPL" and (v1.1) a QZ Tray / print-agent hook. Direct browser→thermal-printer printing is out of scope for v1.

### 4.5 Weight, dimensions, volumetric

```
volumetric_weight_kg = (length_cm * width_cm * height_cm) / divisor
chargeable_weight_kg = max(actual_weight_kg, volumetric_weight_kg)
```

`divisor` is per carrier account (`carrier_accounts.settings.volumetric_divisor`), default **5000** (the common air-freight divisor). Aramex, DHL and FedEx all publish their own; do not hardcode one globally.

Weight resolution order for auto-packing: `product_variants.weight_kg` → `products.weight_kg` → org default per-item weight (`shipping.default_item_weight_kg`, default 0.5) → error if the org has disabled the default.

Auto-pack (v1, deliberately simple): **one package containing everything**, weight = Σ(item weight × qty) + org packaging tare (`shipping.packaging_tare_kg`, default 0.2). The merchant can split into multiple packages manually. A real 3D bin-packing algorithm is out of scope; pretending we have one produces wrong rates.

### 4.6 Multi-package shipments

- One `shipments` row, n `shipment_packages`. `package_count` is denormalized for list queries.
- Carriers differ: some return one master AWB plus child AWBs, some return n independent AWBs, some don't support multi-piece at all. `ShippingCarrierInterface::supports('multi_package')` gates the UI.
- When unsupported, "3 packages" creates **3 separate shipments** linked by `shipments.reference` prefix, and the UI says so explicitly.
- Tracking: package-level events attach to `tracking_events.shipment_package_id`; the shipment status is the **least advanced** non-exception package status (a shipment isn't delivered until every box is), except that any package in an exception state raises the shipment to that exception.

### 4.7 COD handling

This is the part Western tools get wrong, so it is specified tightly.

- `shipments.is_cod` defaults from `orders.is_cod`, which is set at sync time from the platform payload (per-platform mapping in `config/shipping.php`, since every platform names it differently).
- `cod_amount` defaults to the **unpaid balance** of the order: `orders.total − already-paid`. For a fully-COD order that's `orders.total`. For a partial shipment the merchant chooses whether COD is collected on the first or last parcel; default is **all COD on the first shipment**, and the UI forces an explicit choice when the order is split.
- Validation before purchase: `Σ(cod_amount over all non-cancelled shipments for the order) ≤ orders.total`. Violation → `422 COD_EXCEEDS_ORDER_TOTAL`.
- `cod_currency` must equal `orders.currency`; carriers reject mismatches. Violation → `422 COD_CURRENCY_MISMATCH`.
- The carrier account must have `cod_enabled = true`; otherwise `422 COD_NOT_ENABLED_ON_ACCOUNT` with a link to carrier settings. Silently dropping the COD flag would ship a parcel with no collection instruction — the merchant loses the entire order value. This validation is not optional.
- Reconciliation: `cod_collected_amount` / `cod_collected_at` come from tracking (some carriers report collection in the POD event) or manual entry; `cod_remitted_at` is set when the merchant confirms the money landed. `GET /api/shipping/cod-reconciliation` lists delivered COD shipments with no remittance and the aging bucket. Automatic settlement-file parsing is v1.1.
- Fees: the carrier's COD fee is part of `shipping_cost` and is posted as an `order_fees` row of type `cod_fee`.

### 4.8 Address validation and normalization

Three tiers, applied in order by `AddressValidator`:

1. **Structural** (always, local): required fields per destination country (`SA`: name, phone, city, district; `AE`: name, phone, city, area; `EG`: name, phone, city, governorate), phone normalized to E.164 with the destination's country code, whitespace/diacritic cleanup, Arabic-Indic digits (`٠١٢٣`) converted to ASCII in phone and postal fields.
2. **City normalization** (always, local): a seeded lookup mapping Arabic and English spelling variants to a canonical key — `الرياض`/`Riyadh`/`Riyad`/`Ar Riyad` → `riyadh`. This is the single highest-value piece of local logic in the spec: carriers reject or mis-route on unrecognised city strings, and Saudi/UAE address data is spelled a dozen ways. Seeded in `database/seeders/CityAliasSeeder.php`, extendable per org.
3. **Carrier validation** (optional, per carrier): `ShippingCarrierInterface::validateAddress()` where the carrier exposes it. Results go to `validation_notes` with a severity; `error` blocks purchase unless the merchant clicks "ship anyway" (recorded in the shipment's `raw_request`).

Saudi **short address** (`short_address`, e.g. `RRRD2929`) is captured when present — some carriers accept it as a precise destination and it dramatically reduces failed deliveries.

### 4.9 Packing slips

Generated by us (never the carrier): a Blade view → PDF, bilingual, one per shipment.

Contents: merchant logo + name (from `stores.shipping_settings`), order number, order date, ship-to block (Arabic if the address is Arabic), per-line SKU/name/quantity (name in the order's language), package "1 of n", a barcode of `shipments.reference` (Code128), COD amount prominently in a box when `is_cod`, and a merchant-configurable footer/returns blurb.

Stored as a `shipping_labels` row with `type = 'packing_slip'`. Batch printing shares the `BuildLabelBatchJob` path.

Deliberately **not** included: prices (many merchants ship gifts), internal cost data.

### 4.10 Manifests and pickups

- **Manifest** = end-of-day handover doc listing every shipment given to a carrier. Flow: select shipments (or "all `label_purchased` for carrier X today") → `POST /manifests` → `CreateManifestJob` calls `createManifest()` → store the returned document as a `shipping_labels` row (`type = 'manifest'`) → set `shipments.manifest_id` and move them to `awaiting_pickup`.
- Carriers without a manifest API get a **locally generated** manifest PDF (our own template, driver signs it). Marked `carrier_manifest_id = null` so we know it isn't carrier-acknowledged.
- **Pickup request** = "send a driver". `POST /pickups` with date, ready/close window, address, pieces, weight. Cancellation via `DELETE /pickups/{id}` where supported. Some carriers use a scheduled daily pickup instead of per-request; `supports('pickup')` gates the UI.

### 4.11 Public tracking page

- URL: `https://{app}/track/{public_tracking_slug}` (and optionally the merchant's own domain via a CNAME — v1.1).
- Backed by `GET /api/public/track/{slug}` — **no auth**, `throttle:30,1`, cached 60 s.
- Slug is unguessable (12 random chars ⇒ ~71 bits); an incrementing id here would leak volume and expose other merchants' shipments.
- Response contains: normalized status + Arabic/English label, event timeline (status, `description_ar`/`description_en`, city, `event_at`), estimated delivery, carrier name + logo, merchant branding (name, logo URL, brand colour, support phone/WhatsApp), COD amount **only when the shipment is COD and not yet delivered** (the customer needs to know what to have ready), and the order reference.
- Response **never** contains: the full address, the customer's phone/email, line items, prices, our shipping cost, or any other shipment.
- Also reachable by tracking number: `GET /api/public/track/by-awb?carrier={code}&number={awb}` — same throttle, plus a per-IP daily cap, because AWB numbers are sequential and therefore enumerable. Requires the last 4 digits of the recipient phone as a second factor. **Do not expose an unauthenticated AWB lookup without that second factor.**
- Arabic-first: `dir="rtl"`, Arabic status copy from `carrier_status_map.description_ar`, Arabic-Indic or ASCII digits per locale preference, hijri date optional (v1.1). Language switcher EN/AR, defaulting to the browser locale then to the merchant's default.

### 4.12 Pushing tracking back to the source platform

`PushTrackingToPlatformJob` runs after label purchase. There is no fulfilment method on `IntegrationServiceInterface` today — `ShopifyService::updateOrderStatus()` special-cases `shipped` into `fulfillOrder()` but sends **no tracking number**.

So: add a capability interface `SupportsFulfillmentInterface` (§5.3) with `createFulfillment(Store, string $externalOrderId, array $payload)`. Carriers → platforms mapping (`tracking_number`, `carrier_code`, `tracking_url`, line items). Platforms that don't support it fall back to the existing `updateOrderStatus($store, $id, 'shipped')`, and we record in `shipments.raw_response` that tracking wasn't pushed.

### 4.13 Cost posting

On `label_purchased` and on cost adjustments, write `order_fees` rows (Profit spec owns the table):

| `order_fees.type` | Source |
| --- | --- |
| `shipping` | `shipments.shipping_cost` |
| `cod_fee` | rate's `cod_fee` component |
| `insurance` | `shipments.insurance_amount` |
| `rto_shipping` | outbound + return leg of an RTO shipment (Spec 03 §4.6) |
| `shipping_surcharge` | post-delivery carrier adjustments (dimensional reweigh) |

Carriers frequently re-bill after the fact (reweigh, remote-area surcharge). `POST /api/shipments/{id}/adjust-cost` records the corrected cost and writes a delta fee row rather than mutating history.

---

## 5. Backend

### 5.1 The carrier abstraction

`backend/app/Services/Shipping/ShippingCarrierInterface.php` — deliberately shaped like `IntegrationServiceInterface` (flat, concrete, no over-abstraction) so it reads as native to this codebase.

```php
<?php

namespace App\Services\Shipping;

use App\Models\CarrierAccount;
use App\Models\Shipment;
use App\Models\Manifest;
use App\Models\PickupRequest;
use App\Services\Shipping\Data\RateRequest;

interface ShippingCarrierInterface
{
    /** Stable code: 'aramex', 'smsa', 'naqel', 'jnt', 'torod', 'dhl', 'fedex'. */
    public function code(): string;

    /** Capability probe: 'rates','multi_package','cod','pickup','manifest','return_label','address_validation','tracking_webhook','zpl','cancel'. */
    public function supports(string $capability): bool;

    /** Cheap credential probe. Throws CarrierAuthException on bad credentials. */
    public function validateCredentials(CarrierAccount $account): bool;

    /** @return array{is_valid:bool,normalized:array<string,mixed>,notes:array<int,array{severity:string,message:string}>} */
    public function validateAddress(CarrierAccount $account, array $address): array;

    /** @return array<int,\App\Services\Shipping\Data\CarrierRate> */
    public function getRates(CarrierAccount $account, RateRequest $request): array;

    /** @return array{tracking_number:string,carrier_shipment_id:?string,packages:array<int,array{sequence:int,tracking_number:?string}>,label:?array{format:string,content_base64:?string,url:?string},cost:?array{amount:float,currency:string},estimated_delivery_at:?string,raw:array<string,mixed>} */
    public function createShipment(CarrierAccount $account, Shipment $shipment): array;

    /** Fetch (or re-fetch) label bytes. Returns raw binary. */
    public function getLabel(CarrierAccount $account, Shipment $shipment, string $format = 'pdf'): string;

    public function cancelShipment(CarrierAccount $account, Shipment $shipment): bool;

    /** @return array<int,\App\Services\Shipping\Data\CarrierTrackingEvent> */
    public function track(CarrierAccount $account, string $trackingNumber): array;

    /** @return array{carrier_pickup_id:?string,confirmed:bool,raw:array<string,mixed>} */
    public function createPickup(CarrierAccount $account, PickupRequest $pickup): array;

    public function cancelPickup(CarrierAccount $account, PickupRequest $pickup): bool;

    /** @return array{carrier_manifest_id:?string,document_base64:?string,document_url:?string,raw:array<string,mixed>} */
    public function createManifest(CarrierAccount $account, Manifest $manifest): array;

    /** Reverse logistics: a label the customer uses to send goods back. */
    public function createReturnShipment(CarrierAccount $account, Shipment $shipment): array;

    /** Map a raw carrier status to the normalized vocabulary (§4.2). */
    public function normalizeStatus(?string $rawStatus, ?string $rawCode = null): string;

    /** Verify an inbound tracking webhook. Returns the shipments it concerns. */
    public function parseWebhook(array $payload, array $headers): array;
}
```

`BaseShippingCarrier` (abstract) provides: `getHttpClient(CarrierAccount)` with per-carrier auth, timeout defaults (connect 10 s / total 30 s), a `logCall()` helper writing redacted request/response into the shipment, `normalizeStatus()` implemented once against `carrier_status_map` (subclasses rarely override), volumetric-weight helpers, and `unsupported(string $capability)` which throws `CarrierCapabilityException` so every carrier can safely stub what it can't do.

`CarrierFactory` mirrors `IntegrationFactory` exactly:

```php
class CarrierFactory
{
    public static function make(string $carrier): ShippingCarrierInterface
    {
        return match (strtolower($carrier)) {
            'aramex' => new AramexCarrier(),
            'smsa'   => new SmsaCarrier(),
            'naqel'  => new NaqelCarrier(),
            'jnt'    => new JntCarrier(),
            'torod'  => new TorodCarrier(),
            'dhl'    => new DhlCarrier(),
            'fedex'  => new FedexCarrier(),
            default  => throw new \Exception("Carrier [{$carrier}] not supported"),
        };
    }
}
```

Value objects in `App\Services\Shipping\Data\`: `RateRequest`, `CarrierRate`, `CarrierTrackingEvent`, `AddressData`, `PackageData` — plain readonly PHP 8.3 classes, no package dependency.

### 5.2 Services — `backend/app/Services/Shipping/`

| Class | Responsibility |
| --- | --- |
| `ShippingService` | Orchestrator: `createDraft()`, `purchaseLabel()`, `cancel()`, `adjustCost()`, `createReturnShipment()`. Owns transactions + state transitions. |
| `ShipmentStateMachine` | §4.1 transition table. Pure. |
| `ShippingRateService` | §4.3, including `Http::pool()` fan-out and caching. |
| `LabelStorageService` | Download, checksum, store, stream, void. Enforces "we always keep our own copy". |
| `PackingSlipRenderer` | Blade → PDF, bilingual. |
| `LabelBatchBuilder` | PDF merge / ZPL concat for batch printing. |
| `TrackingIngestService` | `ingest(Shipment, CarrierTrackingEvent[])`: dedupe by fingerprint, insert, recompute status by max `event_at`, fire events. The single entry point for both webhooks and polling — never two code paths for the same job. |
| `AddressValidator` | §4.8 three-tier validation. |
| `CityNormalizer` | Alias table lookup + fuzzy fallback (Levenshtein over the canonical list, threshold 2). |
| `CodService` | COD validation, reconciliation queries, aging buckets. |
| `AutoPacker` | §4.5 single-package packing + weight resolution. |
| `ManifestService`, `PickupService` | §4.10. |
| `PublicTrackingService` | Builds the redacted public DTO. Has its own serializer so a future `shipments` column can never leak by accident. |

### 5.3 Integration-side capability interface

`backend/app/Services/Integrations/SupportsFulfillmentInterface.php`

```php
interface SupportsFulfillmentInterface
{
    /** @return array{fulfillment_id:?string,pushed:bool} */
    public function createFulfillment(Store $store, string $externalOrderId, array $payload): array;

    public function updateFulfillmentTracking(Store $store, string $fulfillmentId, array $payload): bool;

    public function supportsFulfillmentCapability(string $capability): bool; // 'tracking','partial','multi_package'
}
```

Implemented by `ShopifyService` (real fulfilment with tracking), `WooCommerceService` (order meta + note — Woo core has no fulfilment object), `SallaService`/`ZidService` (shipment/status endpoints — **unverified**), and the marketplaces where seller-side shipment confirmation exists (Amazon `confirmShipment`, Trendyol package update — both unverified against current docs). Call sites always `instanceof`-guard, exactly as in Spec 03.

### 5.4 Models — `backend/app/Models/`

`CarrierAccount`, `OrderAddress`, `Shipment`, `ShipmentPackage`, `ShipmentItem`, `ShippingLabel`, `TrackingEvent`, `ShippingRate`, `Manifest`, `PickupRequest`, `CarrierStatusMap`.

`CarrierAccount::$casts` includes `'credentials' => 'encrypted:array'` and `'settings' => 'array'`. **Never** add `credentials` to a JSON resource; the API returns only `has_credentials: bool` plus non-secret fields.

Additions: `Order` gains `shipments()`, `addresses()`, `shipToAddress()`; `$fillable` gains the §3.12 columns. `Store` gains `defaultShipFromAddress()` and `shipping_settings` cast.

### 5.5 Jobs

| Job | Trigger | Notes |
| --- | --- | --- |
| `PurchaseLabelJob(Shipment)` | async label purchase | Used for batch purchase; the single-shipment path is synchronous with a 30 s budget. `tries = 1` — never auto-retry a billable call. |
| `ReconcileShipmentJob(Shipment)` | after `CARRIER_TIMEOUT` | Queries the carrier by our reference to find out whether the AWB exists. Runs before any manual retry is allowed. |
| `TrackShipmentsJob` | scheduler, every 15 min | Selects non-final shipments where `last_tracked_at` is older than the backoff window, chunked at 200, one carrier at a time to respect rate limits. |
| `TrackShipmentJob(Shipment)` | on demand / from the above | Single shipment poll → `TrackingIngestService`. |
| `PushTrackingToPlatformJob(Shipment)` | after label purchase | `tries = 5`, backoff `[60,300,900,3600,7200]`. |
| `BuildLabelBatchJob(array $ids, string $format)` | batch print | Writes one artefact, notifies on completion. |
| `CreateManifestJob(Manifest)` | manifest submit | |
| `CreatePickupJob(PickupRequest)` | pickup submit | |
| `PostShippingCostJob(Shipment)` | after purchase / cost adjust | Writes `order_fees`. |
| `DetectRtoJob(Shipment)` | on `returned_to_origin` ingest | **Defined in Spec 03**; dispatched from here. |
| `PruneShippingRatesJob` | daily | 90-day retention. |
| `PruneVoidedLabelsJob` | weekly | Deletes label files for shipments cancelled > 90 days ago; keeps the DB rows. |
| `ValidateCarrierAccountsJob` | daily | Re-probes credentials, sets `last_validated_at` / `last_error`, notifies on failure **before** the merchant discovers it mid-shipping. |

**Polling backoff** (`TrackShipmentsJob`): age since `shipped_at` → interval. `< 24 h` → 30 min; `1–3 d` → 2 h; `3–10 d` → 6 h; `> 10 d` → 24 h and raise a `stale shipment` notification at 14 days. Carriers with real webhooks (§6) poll at the slowest tier as a safety net only.

### 5.6 Events

`ShipmentCreated`, `LabelPurchased`, `LabelPurchaseFailed`, `ShipmentPickedUp`, `ShipmentInTransit`, `ShipmentOutForDelivery`, `ShipmentDelivered`, `ShipmentException`, `ShipmentReturnedToOrigin`, `ShipmentLost`, `CodCollected`, `ManifestConfirmed`, `PickupConfirmed`.

Listeners: `NotifyOrganizationOnShipmentEvent`, `UpdateOrderFulfillmentStatus`, `TriggerRtoDetection` (→ Spec 03), `PostShippingFees`, `NotifyCustomerOnShipment` (v1.1 — needs a customer messaging channel decision).

### 5.7 API endpoints

Inside the existing `auth:sanctum` + `org.member` group in `backend/routes/api.php`:

```php
// Carrier accounts
Route::get('/shipping/carriers', [CarrierController::class, 'catalog']);          // static capability catalogue
Route::get('/shipping/accounts', [CarrierAccountController::class, 'index']);
Route::post('/shipping/accounts', [CarrierAccountController::class, 'store']);
Route::put('/shipping/accounts/{id}', [CarrierAccountController::class, 'update']);
Route::delete('/shipping/accounts/{id}', [CarrierAccountController::class, 'destroy']);
Route::post('/shipping/accounts/{id}/validate', [CarrierAccountController::class, 'validateCredentials']);
Route::get('/shipping/accounts/{id}/services', [CarrierAccountController::class, 'services']);

// Shipments
Route::get('/shipments', [ShipmentController::class, 'index']);
Route::get('/shipments/export', [ShipmentController::class, 'export']);
Route::post('/shipments', [ShipmentController::class, 'store']);
Route::get('/shipments/{id}', [ShipmentController::class, 'show']);
Route::put('/shipments/{id}', [ShipmentController::class, 'update']);
Route::delete('/shipments/{id}', [ShipmentController::class, 'destroy']);          // draft only
Route::post('/shipments/{id}/rates', [ShipmentController::class, 'rates']);
Route::post('/shipments/{id}/label', [ShipmentController::class, 'purchaseLabel']);
Route::get('/shipments/{id}/label', [ShipmentController::class, 'downloadLabel']);
Route::get('/shipments/{id}/packing-slip', [ShipmentController::class, 'packingSlip']);
Route::post('/shipments/{id}/cancel', [ShipmentController::class, 'cancel']);
Route::post('/shipments/{id}/track', [ShipmentController::class, 'refreshTracking']);
Route::get('/shipments/{id}/tracking', [ShipmentController::class, 'tracking']);
Route::post('/shipments/{id}/tracking-events', [ShipmentController::class, 'addManualEvent']);
Route::post('/shipments/{id}/adjust-cost', [ShipmentController::class, 'adjustCost']);
Route::post('/shipments/{id}/packages', [ShipmentPackageController::class, 'store']);
Route::put('/shipments/{id}/packages/{packageId}', [ShipmentPackageController::class, 'update']);
Route::delete('/shipments/{id}/packages/{packageId}', [ShipmentPackageController::class, 'destroy']);

// Batch
Route::post('/shipments/labels/batch', [ShipmentBatchController::class, 'labels']);
Route::get('/shipments/labels/batch/{batchId}', [ShipmentBatchController::class, 'status']);
Route::post('/shipments/packing-slips/batch', [ShipmentBatchController::class, 'packingSlips']);

// Rates (order-level, before a shipment exists)
Route::post('/orders/{id}/rates', [ShippingRateController::class, 'forOrder']);
Route::post('/orders/{id}/shipments', [ShipmentController::class, 'storeForOrder']);

// Manifests & pickups
Route::get('/manifests', [ManifestController::class, 'index']);
Route::post('/manifests', [ManifestController::class, 'store']);
Route::get('/manifests/{id}', [ManifestController::class, 'show']);
Route::get('/manifests/{id}/document', [ManifestController::class, 'document']);
Route::get('/pickups', [PickupController::class, 'index']);
Route::post('/pickups', [PickupController::class, 'store']);
Route::delete('/pickups/{id}', [PickupController::class, 'destroy']);

// Addresses
Route::post('/addresses/validate', [AddressController::class, 'validateAddress']);
Route::get('/addresses', [AddressController::class, 'index']);                     // ship-from book
Route::post('/addresses', [AddressController::class, 'store']);
Route::put('/addresses/{id}', [AddressController::class, 'update']);

// COD & analytics
Route::get('/shipping/cod-reconciliation', [CodController::class, 'index']);
Route::post('/shipments/{id}/cod-collected', [CodController::class, 'markCollected']);
Route::post('/shipments/{id}/cod-remitted', [CodController::class, 'markRemitted']);
Route::get('/analytics/shipping', [ShippingAnalyticsController::class, 'summary']);
Route::get('/analytics/shipping/by-carrier', [ShippingAnalyticsController::class, 'byCarrier']);
Route::get('/analytics/shipping/by-city', [ShippingAnalyticsController::class, 'byCity']);
Route::get('/analytics/shipping/delivery-performance', [ShippingAnalyticsController::class, 'deliveryPerformance']);
```

Public (outside auth), next to the existing public routes:

```php
Route::get('/public/track/{slug}', [PublicTrackingController::class, 'show'])->middleware('throttle:30,1');
Route::post('/public/track/by-awb', [PublicTrackingController::class, 'byAwb'])->middleware('throttle:10,1');
Route::post('/shipping/webhooks/{carrier}', [ShippingWebhookController::class, 'handle'])
    ->middleware(\App\Http\Middleware\VerifyCarrierWebhook::class);
```

#### Key contracts

**`POST /api/orders/{id}/rates`** — role any.
```php
'ship_from_address_id' => ['nullable','integer','exists:order_addresses,id'],
'packages'             => ['nullable','array','max:20'],
'packages.*.weight_kg' => ['required_with:packages','numeric','min:0.01','max:1000'],
'packages.*.length_cm' => ['nullable','numeric','min:1','max:400'],
'packages.*.width_cm'  => ['nullable','numeric','min:1','max:400'],
'packages.*.height_cm' => ['nullable','numeric','min:1','max:400'],
'is_cod'               => ['nullable','boolean'],
'cod_amount'           => ['nullable','numeric','min:0'],
'carrier_codes'        => ['nullable','array'],
'refresh'              => ['nullable','boolean'],   // bypass cache
```
Response `200`:
```json
{
  "request_hash": "8f1c…",
  "expires_at": "2026-07-24T11:32:00Z",
  "rates": [{
    "id": 5521, "carrier_code": "smsa", "carrier_name": "SMSA Express",
    "service_code": "DOM", "service_name": "Domestic Express",
    "amount": "21.00", "cod_fee": "10.00", "vat_amount": "4.65",
    "total_amount": "35.65", "currency": "SAR",
    "transit_days_min": 1, "transit_days_max": 2,
    "estimated_delivery_at": "2026-07-26T00:00:00Z",
    "is_estimate": false, "rank": 1, "is_recommended": true
  }],
  "errors": [{ "carrier_code": "naqel", "code": "CARRIER_TIMEOUT", "message": "No response within 6s" }]
}
```

**`POST /api/shipments`** — role `owner|admin`.
```php
'order_id'              => ['nullable','integer','exists:orders,id'],
'return_request_id'     => ['nullable','integer'],
'direction'             => ['nullable', Rule::in(['outbound','return','rto'])],
'carrier_account_id'    => ['nullable','integer','exists:carrier_accounts,id'],
'service_code'          => ['nullable','string','max:64'],
'rate_id'               => ['nullable','integer','exists:shipping_rates,id'],
'ship_from_address_id'  => ['nullable','integer','exists:order_addresses,id'],
'ship_to_address'       => ['nullable','array'],           // inline creation
'is_cod'                => ['nullable','boolean'],
'cod_amount'            => ['nullable','numeric','min:0'],
'label_format'          => ['nullable', Rule::in(['pdf','zpl','png'])],
'packages'              => ['required','array','min:1','max:20'],
'packages.*.weight_kg'  => ['required','numeric','min:0.01'],
'packages.*.items'      => ['nullable','array'],
'packages.*.items.*.order_item_id' => ['required_with:packages.*.items','integer'],
'packages.*.items.*.quantity'      => ['required_with:packages.*.items','integer','min:1'],
'contents_description'  => ['nullable','string','max:255'],
'special_instructions'  => ['nullable','string','max:500'],
```
Cross-field rules in `StoreShipmentRequest::withValidator()`: the order belongs to the org; Σ shipped quantity per order line ≤ ordered quantity; COD rules §4.7; carrier account belongs to the org and is active.
`201` with the draft shipment.

**`POST /api/shipments/{id}/label`** — role `owner|admin`.
```php
'rate_id'      => ['nullable','integer','exists:shipping_rates,id'],
'label_format' => ['nullable', Rule::in(['pdf','zpl','png'])],
'dpi'          => ['nullable','integer','in:203,300'],
'override_address_warnings' => ['nullable','boolean'],
```
`200` (idempotent when a label already exists) / `201`:
```json
{
  "id": 912, "reference": "SHP-2026-000912", "status": "label_purchased",
  "carrier_code": "aramex", "tracking_number": "4712398812",
  "packages": [{ "sequence": 1, "tracking_number": "4712398812" }],
  "label": { "id": 3311, "format": "pdf", "url": "/api/shipments/912/label" },
  "shipping_cost": "27.60", "shipping_cost_currency": "SAR",
  "public_tracking_url": "https://app.hubby.example/track/k3Jd82nQpL5x",
  "estimated_delivery_at": "2026-07-26T00:00:00Z"
}
```
Errors: `422 COD_EXCEEDS_ORDER_TOTAL`, `422 COD_NOT_ENABLED_ON_ACCOUNT`, `422 ADDRESS_INVALID` (with `notes[]`), `422 PACKAGE_WEIGHT_REQUIRED`, `409 LABEL_ALREADY_PURCHASED` (only when the format differs and reprint isn't possible), `502 CARRIER_ERROR` (with `carrier_message`), `504 CARRIER_TIMEOUT` (shipment left recoverable, reconcile job dispatched), `503 CARRIER_CREDENTIALS_INVALID`.

**`GET /api/public/track/{slug}`** — no auth.
```json
{
  "reference": "1002345",
  "status": "out_for_delivery",
  "status_label": { "en": "Out for delivery", "ar": "خارج للتوصيل" },
  "carrier": { "code": "smsa", "name_en": "SMSA Express", "name_ar": "سمسا" },
  "estimated_delivery_at": "2026-07-25",
  "is_cod": true, "cod_amount": "349.00", "cod_currency": "SAR",
  "merchant": { "name": "متجر نور", "logo_url": "...", "brand_color": "#0F766E", "support_phone": "+9665…", "whatsapp": "+9665…" },
  "events": [
    { "status": "out_for_delivery", "label": { "en": "Out for delivery", "ar": "خارج للتوصيل" }, "city": "الرياض", "event_at": "2026-07-25T06:10:00Z" },
    { "status": "at_destination_hub", "label": { "en": "Arrived at Riyadh hub", "ar": "وصلت إلى فرع الرياض" }, "city": "الرياض", "event_at": "2026-07-24T22:40:00Z" }
  ]
}
```

**Error envelope** (all endpoints): `{"message": "...", "code": "...", "carrier_message": "..."}`; validation keeps Laravel's `{"message","errors"}`.

---

## 6. Per-carrier notes

**Verification status is stated honestly per carrier.** Where I write UNVERIFIED, treat the detail as a research task with a named artefact ("capture a sandbox response"), not as a spec to code against. Every carrier ships behind its own feature flag and does not merge without a captured fixture.

Capability matrix (target for v1; the `supports()` values each `Carrier` class returns):

| Carrier | API style | Auth | Rates | Label | ZPL | Multi-pkg | COD | Pickup | Manifest | Tracking webhook | Return label | Address validation |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| Aramex | SOAP (+ some JSON) | Credentials in body (`ClientInfo`) | yes | yes | likely | yes | yes | yes | yes | unlikely (poll) | yes | yes (Location API) |
| SMSA | SOAP (legacy) / REST (newer) | `passKey` / API key | unverified | yes | yes | yes | yes | yes | yes | unverified | yes | limited |
| Naqel | SOAP | ClientID + password | unverified | yes | unverified | yes | yes | yes | unverified | unverified | unverified | limited |
| J&T | REST + signed digest | account + private key (MD5+base64) | unverified | yes | unverified | unverified | yes | yes | unverified | yes (country-dependent) | unverified | no |
| Torod | REST | Bearer token | yes (aggregated) | yes | via underlying carrier | depends | yes | yes | yes | likely | yes | depends |
| DHL Express | REST (MyDHL API) + legacy XML/SOAP | HTTP Basic | yes | yes | yes | yes | limited | yes | yes | yes (push) | yes | yes |
| FedEx | REST | OAuth2 client credentials | yes | yes | yes | yes | limited | yes | yes | yes | yes | yes |

### 6.1 Aramex — `AramexCarrier`

**Why it matters:** the default cross-Gulf carrier. If Hubby ships one carrier first, it's this one.

- **Verified from public developer material:** Aramex exposes a *Shipping Services API* covering shipment creation, label printing, pickup creation/cancellation, and shipment-number range control, and the APIs are **SOAP over HTTPS**. Every call carries a `ClientInfo` block. There is also a Rate Calculator, a Tracking API, and a Location/Address validation API in the same family, plus a Shipments Preparation API.
- **`ClientInfo` fields** (`UserName`, `Password`, `Version`, `AccountNumber`, `AccountPin`, `AccountEntity`, `AccountCountryCode`) — this is why `carrier_accounts.credentials` for Aramex has that exact shape.
- **UNVERIFIED and must be confirmed against the live WSDL:** exact operation names (`CreateShipments`, `PrintLabel`, `CalculateRate`, `TrackShipments`, `CreatePickup`, `ValidateAddress`), the current endpoint hosts, the label report ids, and whether the newer JSON endpoints cover everything the SOAP ones do. Aramex has been publishing JSON variants alongside SOAP; **check before committing to SOAP**, because a SOAP client is real work in this codebase (no SOAP usage exists today — `ext-soap` or a hand-rolled XML client would be a new dependency).
- **COD:** Aramex supports Cash on Delivery as a shipment "additional service" with a value + currency. Field naming unverified.
- **Tracking:** pull-based. Assume **polling**, not webhooks. Our backoff schedule (§5.5) is designed for this.
- **Implementation note:** `BaseShippingCarrier::getHttpClient()` is HTTP-JSON-shaped; Aramex needs a `SoapCarrierClient` sibling. Build it generically — SMSA and Naqel need it too, so it's one investment for three carriers.
- **Artefact required before merge:** captured request/response XML for create-shipment, rate, track, and pickup from an Aramex test account.

### 6.2 SMSA Express — `SmsaCarrier`

**Why it matters:** the domestic Saudi workhorse. Between SMSA and Aramex you cover the large majority of Saudi e-commerce parcels.

- **Verified from public material:** SMSA has historically exposed an ASMX **SOAP web service** at `track.smsaexpress.com/SECOM/SMSAwebService.asmx` with operations including `addShipment` (creating an AWB) and status/cancel operations, authenticated by a **`passKey`** obtained from SMSA. Community SDKs (PHP) exist against exactly this surface.
- **`addShipment` parameters** observed publicly include `passKey`, `refNo`, `sentDate`, consignee fields (`cName`, `cntry`, `cCity`, …), shipment type, weight, declared value, and item description — which is why our `carrier_accounts.credentials.smsa = { passkey }` and why `shipments.contents_description` exists.
- **UNVERIFIED — important:** SMSA has been migrating merchants to a **newer REST API** with API-key header auth. Which surface a given merchant gets depends on their contract and onboarding date. **Design `SmsaCarrier` with two drivers behind one class** (`credentials.mode = 'secom_soap' | 'rest'`), selected per account. Do not assume every merchant is on the same one — getting this wrong means half your SMSA customers can't connect.
- **Rating:** UNVERIFIED whether a rate API is exposed at all on the legacy surface. Plan for `is_estimate` rate-table fallback (§4.3) on SMSA from day one.
- **Label:** the legacy service returns a printable AWB (PDF/image); ZPL availability unverified.
- **Tracking:** `getStatus`-style pull. Poll.
- **Artefact required before merge:** captured `addShipment` request/response and a real AWB PDF, from an SMSA test passKey.

### 6.3 Naqel Express — `NaqelCarrier`

- **Verified from public material:** Naqel publishes a Shipping API described as **SOAP over HTTPS**, covering waybill creation and pickup booking, with an official PHP SDK (`naqel/sdk`) and first-party plugins for WooCommerce, OpenCart and Shopify. Auth is a client id + password pair.
- **UNVERIFIED:** operation names, endpoint hosts (production vs sandbox), rate API existence, label formats, tracking push. The published PDF integration guides are the source of truth and should be obtained directly from Naqel during onboarding.
- **Positioning:** Naqel is the third domestic Saudi option and matters most for merchants with negotiated Naqel rates. Lower priority than Aramex/SMSA but cheap once the SOAP client exists.
- **Artefact required before merge:** the official Naqel API PDF + a captured create-waybill response.

### 6.4 J&T Express — `JntCarrier`

- **Verified from public material:** J&T runs regional developer portals (e.g. `developer.jet.co.id` for Indonesia, a separate portal for Singapore) with **REST/JSON** APIs. Requests are signed with a **digest**: `base64_encode(md5($json_body . $private_key))`, sent alongside an API account identifier. Credentials are issued per country by J&T.
- **UNVERIFIED and structurally important:** J&T's API is **country-fragmented**. The Indonesian, Singaporean, Malaysian, and Gulf/Egypt operations do not necessarily share endpoints, field names, or even the signing scheme version. `carrier_accounts.credentials.jnt.country_code` exists precisely because of this, and `JntCarrier` must resolve base URL + payload dialect per country from `config/shipping.php`. **Do not build a single "J&T API" and assume it works region-wide.**
- **Relevance:** J&T is a fast-growing low-cost option in Egypt, the UAE and Saudi, and it's exactly the kind of carrier a Western tool will never add.
- **Webhooks:** J&T does push tracking callbacks in some markets — worth confirming, since it would be our first real push-tracking carrier.
- **Artefact required before merge:** the developer-portal spec for the *specific country* we launch in, plus a captured signed create-order request.

### 6.5 Torod — `TorodCarrier` (aggregator)

- **Verified:** Torod is a Saudi shipping **aggregator** (founded 2022, raised a pre-Series A led by Wa'ed Ventures and Elm) that lets merchants ship, track and manage returns across multiple 3PLs from one interface, and it already integrates with Shopify, WooCommerce, Magento, Salla and Zid on the store side and with carriers including SMSA, Aramex and iMile on the logistics side.
- **UNVERIFIED:** the public developer documentation. I did not find a public API reference. Assume a **REST API with a bearer token**, and treat every endpoint shape as unknown until we have partner docs.
- **Strategic value — this is the highest-leverage single integration in the spec.** One Torod integration plausibly yields *many* carriers for merchants who already have a Torod account, and it side-steps building SOAP clients for the long tail. The trade-off is a dependency on a third party's uptime and margin, plus less control over label formats and error semantics.
- **Recommendation:** build **Aramex + SMSA natively first** (they're the two that matter most and give us direct-carrier credibility), then Torod as the breadth play, then Naqel/J&T natively only if merchant demand justifies it.
- **Design note:** because Torod fronts other carriers, `shipments.carrier_code` would be `torod` while the *actual* carrier is in `service_code`/`raw_response`. Add `shipments.underlying_carrier` (string, nullable) in the Torod migration so tracking pages and analytics can show "SMSA (via Torod)". **This column is added when Torod is built, not in the base migration.**
- **Artefact required before merge:** partner API docs + sandbox token from Torod.

### 6.6 DHL Express — `DhlCarrier`

- **Verified from the DHL developer portal:** DHL Group runs a public API developer portal (`developer.dhl.com`) with an API catalogue. **MyDHL API** is the current DHL Express integration surface, available in REST plus legacy XML and SOAP variants. Authentication is **HTTP Basic**, sent pre-emptively. Access requires an active DHL Express account; credentials are issued by DHL Express consultants.
- **Well-established capability set:** rating, shipment creation with label (PDF and ZPL), pickup booking, tracking (including push notifications), electronic trade documents for customs, and address validation.
- **UNVERIFIED here:** exact resource paths and payload field names — the portal reference is the source of truth and should be read at implementation time rather than trusted from memory.
- **Positioning:** cross-border and premium domestic. DHL's domestic COD support in the Gulf is limited; do not present DHL as a COD option unless the merchant's account is explicitly enabled for it (`carrier_accounts.cod_enabled` gates this).
- Sandbox: the developer portal provides test credentials — the easiest carrier to develop against, which makes it a good **second** implementation to prove the abstraction (after Aramex) even though it is not the strategic wedge.

### 6.7 FedEx — `FedexCarrier`

- **Verified from the FedEx developer portal:** FedEx's current platform is a **REST API with OAuth 2.0**. You create a project on the developer portal, get a **Client ID + Client Secret** ("API credentials"), and exchange them at the `/oauth/token` endpoint with `grant_type=client_credentials` for a bearer access token with a stated expiry. The **Ship API** creates shipments and produces labels and manifests; Rate, Track and Pickup APIs exist alongside it. Sandbox and production hosts differ.
- **Implementation detail that matters:** the access token is short-lived, so `FedexCarrier` needs a token cache (Laravel `Cache` keyed by `carrier_account_id`, TTL = `expires_in − 60 s`) with a single-flight lock so a burst of label purchases doesn't stampede the token endpoint. This is the same problem `RefreshTokenJob` solves for integrations; reuse the pattern, don't invent a new one.
- **UNVERIFIED here:** exact endpoint paths and request schemas per API version — read the portal reference at implementation time.
- **Positioning:** cross-border, US/EU-bound parcels. Lowest strategic priority of the seven; include it because enterprise merchants ask, and because it validates that the interface handles OAuth carriers (Aramex/SMSA/Naqel don't).

### 6.8 Cross-carrier implementation rules

1. **No carrier-specific logic outside its `Carrier` class.** If a controller or service needs `if ($carrier === 'aramex')`, the interface is wrong — add a `supports()` capability instead.
2. **Every carrier ships with a status map seed.** An unmapped status must surface as a warning notification, never as a silently wrong customer-facing label.
3. **Every carrier's create-shipment call is non-retryable.** Timeouts go through `ReconcileShipmentJob`.
4. **Every carrier has a recorded sandbox story** in `docs/specs/carriers/{code}.md` (created during implementation): how to get credentials, what the sandbox does and doesn't simulate, and where the captured fixtures live.
5. **Redact before persisting.** `raw_request`/`raw_response` are stripped of `password`, `passKey`, `private_key`, `Authorization`, and any `client_secret` before being written to `shipments`.

---

## 7. Dashboard

Under `frontend/src/app/(dashboard)/shipping/` plus additions to `orders`.

### 7.1 Routes & screens

| Route | Screen | Notes |
| --- | --- | --- |
| `/shipping` | Shipments list | Tabs: `Ready to ship` (orders with no shipment), `Label purchased`, `In transit`, `Exceptions`, `Delivered`, `RTO`. Bulk select → buy labels, print labels, print packing slips, add to manifest. |
| `/shipping/[id]` | Shipment detail | Header (reference, status, carrier + service, tracking number with copy button, public tracking link). Sections: Packages, Items, Tracking timeline, Label & documents, COD panel, Cost, Raw carrier log (owner only). |
| `/shipping/new` | Create shipment | Order picker → addresses (with validation warnings inline) → packages (auto-packed, editable) → **rate comparison table** → buy. |
| `/shipping/rates` | Rate calculator | Standalone what-if tool: origin, destination, weight, dims, COD → live comparison. Great demo surface; also the thing that proves the Gulf-carrier wedge in 10 seconds. |
| `/shipping/manifests` | Manifests | List + create + download. |
| `/shipping/pickups` | Pickups | List + book + cancel. |
| `/shipping/cod` | COD reconciliation | Delivered COD shipments, expected vs collected vs remitted, aging buckets (0–7, 8–14, 15–30, 30+ days), mark-remitted action. |
| `/settings/shipping` | Carrier accounts | Add/edit/remove accounts, credential form per carrier (schema-driven), Test connection, default carrier/service, ship-from address book, label format + printer prefs, packaging defaults, tracking page branding. |
| `/orders/[id]` (existing) | + Shipping panel | Shipments for the order, "Create shipment", tracking summary, COD status. |
| `/analytics` (existing) | + Shipping tab | Cost per order by carrier, on-time delivery rate, avg transit days by city, exception rate, RTO rate by carrier, COD collection rate. |
| `/track/[slug]` | Public tracking page | Route group `frontend/src/app/(public)/track/[slug]/` — no dashboard chrome, merchant-branded, **AR default**, RTL-first, mobile-first. |

### 7.2 Components — `frontend/src/components/shipping/`

`ShipmentStatusBadge`, `ShipmentTable`, `ShipmentFilters`, `RateComparisonTable` (columns: carrier logo, service, transit, total, badges `Cheapest`/`Fastest`/`Estimate`), `PackageEditor`, `AddressForm` (RTL-aware, district field for SA), `AddressValidationNotice`, `TrackingTimeline` (vertical, RTL-mirrored), `LabelPreview`, `PrintQueueDrawer`, `CodPanel`, `CarrierAccountForm` (schema-driven from `GET /shipping/carriers`), `CarrierCapabilityChips`, `ManifestBuilder`, `TrackingPageBrandingPreview`.

### 7.3 States

- **loading** — skeletons; the rate table shows a per-carrier row skeleton that resolves independently as carriers answer (they're fetched concurrently, so show progressive results rather than one blocking spinner).
- **empty (no carrier accounts)** — a prominent onboarding card: "Connect a carrier to start shipping" with carrier logos, Aramex and SMSA first.
- **empty (no shipments)** / **empty (filters)** — distinct copy.
- **partial rates** — the `errors[]` array renders as a muted row: "Naqel didn't respond — try again".
- **estimate rates** — an explicit "Estimate" chip and a tooltip explaining it isn't a live quote.
- **address warnings** — inline, with a "ship anyway" confirm that records the override.
- **purchasing** — the buy button locks and shows a spinner for up to 30 s; a timeout shows "We're confirming with the carrier" and polls the shipment, never a bare error (the label may exist).
- **error (carrier)** — shows `carrier_message` verbatim in a collapsible panel; merchants forward these to carrier support.
- **credential invalid** — a persistent banner on `/shipping` linking to settings.

### 7.4 i18n — `frontend/src/i18n/dicts/shipping.ts`

Registered in `dictionary.ts` as `shipping: shipping.en` / `shipping.ar`.

```ts
export const shipping = {
  en: {
    title: 'Shipping',
    subtitle: 'Create labels, compare carrier rates and track every parcel.',
    connectDescription: 'Connect a carrier account to start creating shipping labels.',
    searchPlaceholder: 'Search by tracking number, order or reference...',
    emptyState: 'No shipments match your filters.',
    emptyFirstTime: 'No shipments yet. Create one from an order to get started.',
    createShipment: 'Create shipment',
    exportCsv: 'Export CSV',
    tabs: { readyToShip: 'Ready to ship', labelPurchased: 'Label purchased', inTransit: 'In transit', exceptions: 'Exceptions', delivered: 'Delivered', rto: 'RTO' },
    filters: { carrier: 'Carrier:', status: 'Status:', store: 'Store:', cod: 'COD only', city: 'City:', all: 'All' },
    columns: { reference: 'Shipment', order: 'Order', carrier: 'Carrier', tracking: 'Tracking', destination: 'Destination', packages: 'Packages', weight: 'Weight', cost: 'Cost', cod: 'COD', status: 'Status', shipped: 'Shipped', actions: 'Actions' },
    status: {
      draft: 'Draft', rated: 'Rated', label_purchased: 'Label ready', awaiting_pickup: 'Awaiting pickup',
      picked_up: 'Picked up', in_transit: 'In transit', at_origin_hub: 'At origin hub', at_destination_hub: 'At destination hub',
      customs_clearance: 'In customs', out_for_delivery: 'Out for delivery', delivery_attempted: 'Delivery attempted',
      held: 'Held', delivered: 'Delivered', returned_to_origin: 'Returning to sender', rto_in_transit: 'Returning to sender',
      rto_delivered: 'Returned to sender', cancelled: 'Cancelled', lost: 'Lost', damaged: 'Damaged', exception: 'Exception',
    },
    actions: { buyLabel: 'Buy label', printLabel: 'Print label', downloadZpl: 'Download ZPL', packingSlip: 'Packing slip', track: 'Track', refreshTracking: 'Refresh tracking', cancelShipment: 'Cancel shipment', addToManifest: 'Add to manifest', bookPickup: 'Book pickup', copyTracking: 'Copy tracking number', openPublicPage: 'Open tracking page', shipAnyway: 'Ship anyway', adjustCost: 'Adjust cost' },
    rates: {
      title: 'Compare rates', refresh: 'Refresh rates', cheapest: 'Cheapest', fastest: 'Fastest', recommended: 'Recommended',
      estimate: 'Estimate', estimateHint: 'This carrier does not provide live rates. This is your configured estimate.',
      transitDays: '{min}–{max} days', noRates: 'No rates available for this destination.',
      carrierFailed: '{carrier} did not respond.', total: 'Total', codFee: 'COD fee', vat: 'VAT', expiresIn: 'Rates expire in {minutes} min',
    },
    packages: { title: 'Packages', add: 'Add package', package: 'Package {n}', weight: 'Weight (kg)', dimensions: 'Dimensions (cm)', length: 'L', width: 'W', height: 'H', volumetric: 'Volumetric', chargeable: 'Chargeable weight', contents: 'Contents', autoPacked: 'Auto-packed from order items', splitHint: 'This carrier does not support multi-package shipments — separate shipments will be created.' },
    address: { shipFrom: 'Ship from', shipTo: 'Ship to', validate: 'Validate address', validated: 'Address validated', district: 'District', shortAddress: 'Short address', city: 'City', region: 'Region', country: 'Country', phone: 'Mobile number', altPhone: 'Alternative number', warningsTitle: 'Address warnings', overrideHint: 'You can ship anyway, but delivery may fail.' },
    cod: { title: 'Cash on delivery', amount: 'Amount to collect', enabled: 'Collect on delivery', expected: 'Expected', collected: 'Collected', remitted: 'Remitted', outstanding: 'Outstanding', markCollected: 'Mark collected', markRemitted: 'Mark remitted', reconciliation: 'COD reconciliation', aging: 'Days outstanding', notEnabled: 'This carrier account is not enabled for COD.', exceedsTotal: 'COD amount cannot exceed the order total.' },
    tracking: { title: 'Tracking', timeline: 'Timeline', lastUpdate: 'Last update', estimatedDelivery: 'Estimated delivery', noEvents: 'No tracking events yet.', publicPage: 'Customer tracking page', copyLink: 'Copy link', signedBy: 'Signed by', manualEvent: 'Add tracking update' },
    labels: { title: 'Label & documents', format: 'Format', printBatch: 'Print {count} labels', building: 'Preparing your labels...', ready: 'Labels ready', reprint: 'Reprint', voided: 'Voided' },
    manifest: { title: 'Manifests', create: 'Create manifest', forCarrier: 'Carrier', date: 'Date', shipments: '{count} shipments', download: 'Download manifest', submitted: 'Submitted', confirmed: 'Confirmed', localOnly: 'Generated by Hubby — not confirmed by the carrier.' },
    pickup: { title: 'Pickups', book: 'Book pickup', date: 'Pickup date', window: 'Ready between', pieces: 'Pieces', contact: 'Contact', confirmation: 'Confirmation number', cancel: 'Cancel pickup' },
    settings: {
      title: 'Carriers & shipping', addAccount: 'Add carrier account', testConnection: 'Test connection', connected: 'Connected',
      invalid: 'Credentials invalid', environment: 'Environment', sandbox: 'Sandbox', production: 'Production',
      defaultCarrier: 'Default carrier', defaultService: 'Default service', labelFormat: 'Label format', shipFromAddresses: 'Ship-from addresses',
      packagingTare: 'Packaging weight (kg)', defaultItemWeight: 'Default item weight (kg)', volumetricDivisor: 'Volumetric divisor',
      trackingPage: 'Tracking page', brandColor: 'Brand colour', supportPhone: 'Support phone', whatsapp: 'WhatsApp number', capabilities: 'What this carrier supports',
    },
    analytics: { title: 'Shipping analytics', costPerOrder: 'Cost per order', totalShippingCost: 'Total shipping cost', onTimeRate: 'On-time delivery', avgTransitDays: 'Avg. transit days', exceptionRate: 'Exception rate', rtoRate: 'RTO rate', codCollectionRate: 'COD collection rate', byCarrier: 'By carrier', byCity: 'By city' },
    toast: { labelPurchased: 'Label purchased.', labelFailed: 'Could not buy the label.', cancelled: 'Shipment cancelled.', trackingRefreshed: 'Tracking updated.', copied: 'Copied to clipboard.', manifestCreated: 'Manifest created.', pickupBooked: 'Pickup booked.', credentialsValid: 'Connection successful.', credentialsInvalid: 'Could not connect with these credentials.' },
    errors: { carrierTimeout: "The carrier didn't respond in time. We're confirming whether the label was created.", carrierError: 'The carrier rejected this request.', addressInvalid: 'This address is missing information the carrier requires.', weightRequired: 'Every package needs a weight.', noCarrierAccount: 'Add a carrier account before creating shipments.' },
  },
  ar: {
    title: 'الشحن',
    subtitle: 'أنشئ بوالص الشحن، وقارن أسعار شركات الشحن، وتابع كل شحنة.',
    connectDescription: 'اربط حساب شركة شحن لتبدأ إنشاء بوالص الشحن.',
    searchPlaceholder: 'ابحث برقم التتبع أو الطلب أو رقم الشحنة...',
    emptyState: 'لا توجد شحنات مطابقة لبحثك.',
    emptyFirstTime: 'لا توجد شحنات بعد. أنشئ شحنة من أحد الطلبات للبدء.',
    createShipment: 'إنشاء شحنة',
    exportCsv: 'تصدير CSV',
    tabs: { readyToShip: 'جاهزة للشحن', labelPurchased: 'البوليصة جاهزة', inTransit: 'قيد الشحن', exceptions: 'استثناءات', delivered: 'تم التسليم', rto: 'مرتجع للمصدر' },
    filters: { carrier: 'شركة الشحن:', status: 'الحالة:', store: 'المتجر:', cod: 'الدفع عند الاستلام فقط', city: 'المدينة:', all: 'الكل' },
    columns: { reference: 'الشحنة', order: 'الطلب', carrier: 'شركة الشحن', tracking: 'رقم التتبع', destination: 'الوجهة', packages: 'الطرود', weight: 'الوزن', cost: 'التكلفة', cod: 'الدفع عند الاستلام', status: 'الحالة', shipped: 'تاريخ الشحن', actions: 'الإجراءات' },
    status: {
      draft: 'مسودة', rated: 'تم التسعير', label_purchased: 'البوليصة جاهزة', awaiting_pickup: 'بانتظار الاستلام',
      picked_up: 'تم الاستلام من المتجر', in_transit: 'قيد الشحن', at_origin_hub: 'في فرع الإرسال', at_destination_hub: 'في فرع الوجهة',
      customs_clearance: 'في الجمارك', out_for_delivery: 'خارج للتوصيل', delivery_attempted: 'محاولة تسليم',
      held: 'محتجزة', delivered: 'تم التسليم', returned_to_origin: 'في طريقها للمرسل', rto_in_transit: 'في طريقها للمرسل',
      rto_delivered: 'أعيدت للمرسل', cancelled: 'ملغاة', lost: 'مفقودة', damaged: 'تالفة', exception: 'استثناء',
    },
    actions: { buyLabel: 'شراء البوليصة', printLabel: 'طباعة البوليصة', downloadZpl: 'تحميل ملف ZPL', packingSlip: 'قائمة التعبئة', track: 'تتبع', refreshTracking: 'تحديث التتبع', cancelShipment: 'إلغاء الشحنة', addToManifest: 'إضافة إلى كشف التسليم', bookPickup: 'حجز استلام', copyTracking: 'نسخ رقم التتبع', openPublicPage: 'فتح صفحة التتبع', shipAnyway: 'الشحن على أي حال', adjustCost: 'تعديل التكلفة' },
    rates: {
      title: 'مقارنة الأسعار', refresh: 'تحديث الأسعار', cheapest: 'الأرخص', fastest: 'الأسرع', recommended: 'المقترح',
      estimate: 'تقديري', estimateHint: 'هذه الشركة لا توفر أسعارًا مباشرة. هذا السعر التقديري الذي أعددته.',
      transitDays: '{min}–{max} يوم', noRates: 'لا توجد أسعار متاحة لهذه الوجهة.',
      carrierFailed: 'لم تستجب {carrier}.', total: 'الإجمالي', codFee: 'رسوم الدفع عند الاستلام', vat: 'ضريبة القيمة المضافة', expiresIn: 'تنتهي صلاحية الأسعار خلال {minutes} دقيقة',
    },
    packages: { title: 'الطرود', add: 'إضافة طرد', package: 'الطرد {n}', weight: 'الوزن (كجم)', dimensions: 'الأبعاد (سم)', length: 'الطول', width: 'العرض', height: 'الارتفاع', volumetric: 'الوزن الحجمي', chargeable: 'الوزن المحتسب', contents: 'المحتويات', autoPacked: 'تم التعبئة تلقائيًا من أصناف الطلب', splitHint: 'هذه الشركة لا تدعم الشحنات متعددة الطرود — سيتم إنشاء شحنات منفصلة.' },
    address: { shipFrom: 'الشحن من', shipTo: 'الشحن إلى', validate: 'التحقق من العنوان', validated: 'تم التحقق من العنوان', district: 'الحي', shortAddress: 'العنوان المختصر', city: 'المدينة', region: 'المنطقة', country: 'الدولة', phone: 'رقم الجوال', altPhone: 'رقم بديل', warningsTitle: 'تنبيهات العنوان', overrideHint: 'يمكنك الشحن على أي حال، لكن قد يفشل التسليم.' },
    cod: { title: 'الدفع عند الاستلام', amount: 'المبلغ المطلوب تحصيله', enabled: 'تحصيل عند التسليم', expected: 'المتوقع', collected: 'المحصّل', remitted: 'المحوّل', outstanding: 'المتبقي', markCollected: 'تسجيل التحصيل', markRemitted: 'تسجيل التحويل', reconciliation: 'تسوية الدفع عند الاستلام', aging: 'أيام التأخير', notEnabled: 'حساب شركة الشحن هذا غير مفعّل للدفع عند الاستلام.', exceedsTotal: 'لا يمكن أن يتجاوز مبلغ التحصيل إجمالي الطلب.' },
    tracking: { title: 'التتبع', timeline: 'السجل الزمني', lastUpdate: 'آخر تحديث', estimatedDelivery: 'التسليم المتوقع', noEvents: 'لا توجد تحديثات تتبع بعد.', publicPage: 'صفحة تتبع العميل', copyLink: 'نسخ الرابط', signedBy: 'استلمها', manualEvent: 'إضافة تحديث تتبع' },
    labels: { title: 'البوليصة والمستندات', format: 'الصيغة', printBatch: 'طباعة {count} بوليصة', building: 'جارٍ تجهيز البوالص...', ready: 'البوالص جاهزة', reprint: 'إعادة الطباعة', voided: 'ملغاة' },
    manifest: { title: 'كشوف التسليم', create: 'إنشاء كشف', forCarrier: 'شركة الشحن', date: 'التاريخ', shipments: '{count} شحنة', download: 'تحميل الكشف', submitted: 'تم الإرسال', confirmed: 'تم التأكيد', localOnly: 'تم إنشاؤه بواسطة Hubby — غير مؤكد من شركة الشحن.' },
    pickup: { title: 'طلبات الاستلام', book: 'حجز استلام', date: 'تاريخ الاستلام', window: 'الجاهزية بين', pieces: 'عدد القطع', contact: 'جهة الاتصال', confirmation: 'رقم التأكيد', cancel: 'إلغاء الاستلام' },
    settings: {
      title: 'شركات الشحن والإعدادات', addAccount: 'إضافة حساب شركة شحن', testConnection: 'اختبار الاتصال', connected: 'متصل',
      invalid: 'بيانات الاعتماد غير صحيحة', environment: 'البيئة', sandbox: 'تجريبية', production: 'إنتاجية',
      defaultCarrier: 'شركة الشحن الافتراضية', defaultService: 'الخدمة الافتراضية', labelFormat: 'صيغة البوليصة', shipFromAddresses: 'عناوين الشحن',
      packagingTare: 'وزن التغليف (كجم)', defaultItemWeight: 'الوزن الافتراضي للصنف (كجم)', volumetricDivisor: 'معامل الوزن الحجمي',
      trackingPage: 'صفحة التتبع', brandColor: 'لون العلامة التجارية', supportPhone: 'رقم الدعم', whatsapp: 'رقم واتساب', capabilities: 'ما تدعمه هذه الشركة',
    },
    analytics: { title: 'تحليلات الشحن', costPerOrder: 'تكلفة الشحن لكل طلب', totalShippingCost: 'إجمالي تكلفة الشحن', onTimeRate: 'التسليم في الوقت', avgTransitDays: 'متوسط أيام التوصيل', exceptionRate: 'نسبة الاستثناءات', rtoRate: 'نسبة المرتجع للمصدر', codCollectionRate: 'نسبة تحصيل الدفع عند الاستلام', byCarrier: 'حسب شركة الشحن', byCity: 'حسب المدينة' },
    toast: { labelPurchased: 'تم شراء البوليصة.', labelFailed: 'تعذّر شراء البوليصة.', cancelled: 'تم إلغاء الشحنة.', trackingRefreshed: 'تم تحديث التتبع.', copied: 'تم النسخ.', manifestCreated: 'تم إنشاء الكشف.', pickupBooked: 'تم حجز الاستلام.', credentialsValid: 'تم الاتصال بنجاح.', credentialsInvalid: 'تعذّر الاتصال بهذه البيانات.' },
    errors: { carrierTimeout: 'لم تستجب شركة الشحن في الوقت المحدد. نتحقق الآن مما إذا تم إنشاء البوليصة.', carrierError: 'رفضت شركة الشحن هذا الطلب.', addressInvalid: 'هذا العنوان تنقصه بيانات تطلبها شركة الشحن.', weightRequired: 'كل طرد يحتاج إلى وزن.', noCarrierAccount: 'أضف حساب شركة شحن قبل إنشاء الشحنات.' },
  },
} as const;
```

Public tracking page strings live in a separate `frontend/src/i18n/dicts/track.ts` because the public bundle must not import the whole dashboard dictionary:

```ts
export const track = {
  en: { title: 'Track your order', orderRef: 'Order', status: 'Status', estimated: 'Estimated delivery', timeline: 'Shipment updates', codNotice: 'Please have {amount} ready in cash.', carrier: 'Carrier', help: 'Need help?', contact: 'Contact the store', notFound: 'We could not find this shipment.', delivered: 'Delivered', lastUpdated: 'Last updated', enterPhone: 'Enter the last 4 digits of your mobile number', verify: 'Verify' },
  ar: { title: 'تتبع طلبك', orderRef: 'الطلب', status: 'الحالة', estimated: 'التسليم المتوقع', timeline: 'تحديثات الشحنة', codNotice: 'يرجى تجهيز مبلغ {amount} نقدًا.', carrier: 'شركة الشحن', help: 'تحتاج مساعدة؟', contact: 'تواصل مع المتجر', notFound: 'لم نتمكن من العثور على هذه الشحنة.', delivered: 'تم التسليم', lastUpdated: 'آخر تحديث', enterPhone: 'أدخل آخر 4 أرقام من رقم جوالك', verify: 'تحقق' },
} as const;
```

Also add `nav.shipping` to `common.ts` (`'Shipping'` / `'الشحن'`).

**RTL notes:** the tracking timeline's connector line and dots must mirror (use logical properties, `inline-start`); tracking numbers and money stay LTR inside RTL text (`<bdi>` or `dir="ltr"` spans); the rate comparison table's numeric columns stay LTR-aligned; carrier logos are not mirrored.

---

## 8. Mobile

`mobile/lib/features/shipping/`. The mobile app covers the pack-bench and the owner checking on parcels — not carrier configuration.

| Screen | File | Purpose |
| --- | --- | --- |
| Shipments list | `shipments_page.dart` | Filter by status; default "Ready to ship". Pull to refresh. |
| Shipment detail | `shipment_detail_page.dart` | Status, tracking timeline, packages, COD panel, share tracking link (WhatsApp share is the realistic MENA use case), copy tracking number. |
| Quick ship | `quick_ship_page.dart` | From an order: confirm address → confirm weight → pick a rate → buy label → **share/print**. The whole point is that a merchant can ship from a phone in under 30 seconds. |
| Scan to track | `scan_tracking_page.dart` | Scan an AWB barcode → jump to the shipment. Same scanner assumption as Spec 03. |
| COD | inside shipments list filter | "COD outstanding" filter + mark-remitted action. |
| Orders detail (existing) | `order_detail_page.dart` | Add a shipping section + "Ship this order". |
| Dashboard (existing) | tiles | In-transit count, exceptions count, COD outstanding amount. |

**Not on mobile in v1:** carrier account setup/credentials, manifests, rate calculator, analytics, multi-package editing (mobile assumes one package; multi-package redirects to desktop).

**Printing on mobile:** we don't drive printers. "Buy label" offers *share* (system share sheet → WhatsApp/email/AirPrint) and *save PDF*. This matches how MENA merchants actually work.

**Strings** — `mobile/lib/l10n/strings.dart`:

```dart
// en
'nav.shipping': 'Shipping',
'shipping.title': 'Shipments',
'shipping.readyToShip': 'Ready to ship',
'shipping.inTransit': 'In transit',
'shipping.exceptions': 'Exceptions',
'shipping.empty': 'No shipments yet.',
'shipping.buyLabel': 'Buy label',
'shipping.shipOrder': 'Ship this order',
'shipping.selectRate': 'Select a rate',
'shipping.weight': 'Weight (kg)',
'shipping.cod': 'Cash on delivery',
'shipping.codAmount': 'Collect',
'shipping.tracking': 'Tracking',
'shipping.trackingNumber': 'Tracking number',
'shipping.copy': 'Copy',
'shipping.shareLabel': 'Share label',
'shipping.savePdf': 'Save PDF',
'shipping.shareTracking': 'Share tracking link',
'shipping.scanAwb': 'Scan tracking barcode',
'shipping.estimate': 'Estimate',
'shipping.carrierFailed': 'Some carriers did not respond',
'shipping.purchasing': 'Buying label...',
'shipping.purchased': 'Label ready',
'shipping.failed': 'Could not buy the label',
'shipping.markRemitted': 'Mark COD remitted',
'shipping.noPermission': 'You do not have permission to do this',

// ar
'nav.shipping': 'الشحن',
'shipping.title': 'الشحنات',
'shipping.readyToShip': 'جاهزة للشحن',
'shipping.inTransit': 'قيد الشحن',
'shipping.exceptions': 'استثناءات',
'shipping.empty': 'لا توجد شحنات بعد.',
'shipping.buyLabel': 'شراء البوليصة',
'shipping.shipOrder': 'شحن هذا الطلب',
'shipping.selectRate': 'اختر السعر',
'shipping.weight': 'الوزن (كجم)',
'shipping.cod': 'الدفع عند الاستلام',
'shipping.codAmount': 'التحصيل',
'shipping.tracking': 'التتبع',
'shipping.trackingNumber': 'رقم التتبع',
'shipping.copy': 'نسخ',
'shipping.shareLabel': 'مشاركة البوليصة',
'shipping.savePdf': 'حفظ PDF',
'shipping.shareTracking': 'مشاركة رابط التتبع',
'shipping.scanAwb': 'مسح باركود التتبع',
'shipping.estimate': 'تقديري',
'shipping.carrierFailed': 'بعض شركات الشحن لم تستجب',
'shipping.purchasing': 'جارٍ شراء البوليصة...',
'shipping.purchased': 'البوليصة جاهزة',
'shipping.failed': 'تعذّر شراء البوليصة',
'shipping.markRemitted': 'تسجيل تحويل مبلغ التحصيل',
'shipping.noPermission': 'ليس لديك صلاحية لتنفيذ هذا الإجراء',
```

---

## 9. Permissions & multi-tenancy

**Tenancy.** Same two-layer approach as Spec 03: `org.member` middleware plus explicit `where('organization_id', $orgId)` on every query root. `carrier_accounts` is org-scoped, so one org can never spend another's carrier balance — a shipment purchase validates that `carrier_account_id`, `order_id`, `ship_from_address_id` and `rate_id` **all** belong to the header org before any carrier call. A cross-tenant `rate_id` would let a tenant buy on another tenant's account; there is a mandatory test for exactly this.

**Roles.**

| Ability | owner | admin | viewer |
| --- | --- | --- | --- |
| view shipments / tracking | ✔ | ✔ | ✔ |
| create draft shipment, edit packages | ✔ | ✔ | ✖ |
| **buy label (spends money)** | ✔ | ✔ (org setting `shipping.purchase_min_role`, default `admin`) | ✖ |
| cancel/void shipment | ✔ | ✔ | ✖ |
| print label / packing slip | ✔ | ✔ | ✔ (read-only print is safe) |
| create manifest / book pickup | ✔ | ✔ | ✖ |
| mark COD collected/remitted | ✔ | ✔ | ✖ |
| adjust cost | ✔ | ✖ | ✖ |
| manage carrier accounts / credentials | ✔ | ✖ | ✖ |
| view raw carrier request/response | ✔ | ✖ | ✖ |

Policies: `ShipmentPolicy`, `CarrierAccountPolicy`, `ManifestPolicy`, deriving the role the same way `OrganizationController::roleOf()` does.

**Credential security — this is the section that must not be skipped.**
- `carrier_accounts.credentials` uses Laravel's `encrypted:array` cast. Rotating `APP_KEY` therefore breaks them; document the rotation procedure (decrypt-with-old, re-encrypt-with-new command) before go-live.
- Credentials are **write-only** over the API: `POST`/`PUT` accept them, no endpoint ever returns them. Responses expose `has_credentials`, `account_number` (last 4 for anything secret-looking), `last_validated_at`, `last_error`.
- `raw_request`/`raw_response` on `shipments` are redacted before persistence (§6.8 rule 5) and truncated to 32 KB.
- Carrier credentials are never written to `laravel.log`. `BaseShippingCarrier::logCall()` redacts by key pattern.
- **Recommendation, tracked as a separate task:** retrofit the same `encrypted` cast onto `integrations.access_token` / `refresh_token`, which are plaintext today. Shipping is the feature that makes plaintext credential storage indefensible — a leaked carrier credential lets an attacker create billable shipments against the merchant's account.
- Webhook endpoints are signature-verified per carrier by `VerifyCarrierWebhook`, mirroring the existing `VerifyWebhookSignature`. Carriers without signing get a **secret path token** (`/shipping/webhooks/{carrier}?t={per-account-secret}`) plus an IP allowlist where the carrier publishes one. Never accept an unauthenticated status push — a forged `delivered` event closes an order and, with Spec 03 wired in, could suppress a legitimate RTO.

**PII.** Addresses are PII. Excluded from list endpoints (detail only), redacted in the public tracking DTO, redacted in `tracking_events.payload`, excluded from CSV export for `viewer`. `order_addresses.phone` is indexed for lookup but never returned to unauthenticated callers.

---

## 10. Edge cases & failure modes

| # | Case | Handling |
| --- | --- | --- |
| 1 | Merchant double-clicks "Buy label" | Idempotency on `shipments.reference` + a `lockForUpdate` on the shipment row; the second call returns the existing label. Every carrier bills per AWB, so this must be airtight. |
| 2 | Carrier times out after creating the AWB | `504 CARRIER_TIMEOUT`, no auto-retry, `ReconcileShipmentJob` queries by our reference. Manual retry is blocked in the UI until reconciliation reports back. |
| 3 | Carrier returns success but no label bytes | Shipment keeps `tracking_number` and goes to `label_purchased` with `error_code = 'LABEL_FETCH_FAILED'`; a retry-fetch action calls `getLabel()` separately. Never lose the AWB because the PDF failed. |
| 4 | Label URL expires before we download it | We always download at purchase time (§4.4 step 5). If that fetch fails, retry immediately (3×) and record the failure loudly. |
| 5 | Tracking events arrive out of order | Status is computed from `max(event_at)`, never from arrival order (§4.2). |
| 6 | Duplicate webhook delivery | `unique(shipment_id, fingerprint)`; duplicates are silently ignored (200 to the carrier so they stop retrying). |
| 7 | Webhook for a shipment we don't own / unknown AWB | Log to `webhook_logs`, return `200`, do nothing. Never 404 to a carrier — some disable webhooks after repeated errors. |
| 8 | Unmapped carrier status string | Normalized to `exception`, stored raw, warning notification raised, and a weekly digest of unmapped codes so we extend `carrier_status_map` from data. |
| 9 | Carrier reports `delivered` then `returned_to_origin` | Allowed: a later final status with a later `event_at` wins. Spec 03's RTO detector fires on the second event. |
| 10 | COD amount exceeds order total (split shipments) | `422 COD_EXCEEDS_ORDER_TOTAL`, computed as a sum across all non-cancelled shipments for the order. |
| 11 | Carrier account not COD-enabled but shipment is COD | `422 COD_NOT_ENABLED_ON_ACCOUNT`. Never silently drop the flag. |
| 12 | Rate expired when the merchant clicks buy | `rate_id` past `expires_at` → re-shop transparently and, if the price moved by more than the org's tolerance (`shipping.rate_drift_tolerance`, default 10 %), stop and ask for confirmation. |
| 13 | Carriers quote in different currencies | Grouped by currency in the rate table with a visible notice; no FX conversion, no cross-currency "cheapest" claim. |
| 14 | Package with zero or missing weight | `422 PACKAGE_WEIGHT_REQUIRED`; auto-pack falls back to the org default and flags the shipment as `weight_estimated` in `raw_request` so reweigh surcharges are explicable later. |
| 15 | Address missing district (Saudi) | Structural validation warns; carriers that require it get a hard `ADDRESS_INVALID`. The UI puts district right under city with the Arabic label حي. |
| 16 | Arabic-Indic digits in a phone number | Normalized to ASCII at ingest (§4.8 tier 1). Carriers reject `٠٥٥…`. |
| 17 | Same city spelled 5 different ways | `CityNormalizer` alias table + fuzzy fallback; unknown cities are stored raw with `is_validated = false` and surfaced in a "cities we couldn't recognise" report so the alias table improves from real data. |
| 18 | Partial fulfilment: 2 of 5 items shipped | `shipment_items` records exactly what shipped; `orders.fulfillment_status = 'partial'`; a second shipment covers the rest; Σ shipped ≤ ordered is enforced. |
| 19 | Carrier doesn't support multi-package | `supports('multi_package') === false` → n separate shipments, explicit UI notice. |
| 20 | Shipment cancelled after the carrier already scanned it | `cancelShipment()` returns false → `409 SHIPMENT_ALREADY_IN_TRANSIT`, and we tell the merchant to contact the carrier. Never mark it cancelled locally when the parcel is physically moving. |
| 21 | Carrier credentials revoked mid-day | `ValidateCarrierAccountsJob` catches it within 24 h; a failing label purchase catches it immediately → `503 CARRIER_CREDENTIALS_INVALID`, `carrier_accounts.last_error` set, persistent dashboard banner. |
| 22 | Sandbox credentials in a production org | `carrier_accounts.environment` is shown as a bright badge everywhere, and purchase from a `sandbox` account in a production org requires an explicit confirm. Sandbox AWBs that reach a real courier are a support nightmare. |
| 23 | Batch print of 100 labels | Async `BuildLabelBatchJob`; the synchronous path is capped at 10. |
| 24 | Label storage disk unavailable | Purchase still completes (the AWB exists); label rows get `path = null` + `error_code`, and a retry job re-fetches from the carrier. Losing the disk must not lose the shipment. |
| 25 | Two orgs' shipments with the same carrier AWB number | `unique(carrier_code, tracking_number)` would collide. Carriers do recycle numbers over long periods — so the index is `unique(carrier_code, tracking_number)` and a collision triggers a warning + suffixed storage rather than a hard failure. **Decide before build** (see Open Questions). |
| 26 | Public tracking slug guessed | 12 random chars; rate-limited; the DTO carries no address or contact data, so the blast radius of a guess is "someone sees a parcel status". |
| 27 | AWB enumeration on the by-AWB lookup | Requires the last 4 phone digits + per-IP daily cap. |
| 28 | Order deleted while a shipment is in transit | `orders` cascade deletes shipments — **that is wrong here**. Change: `shipments.order_id` uses `nullOnDelete`, not cascade, so a shipment record (and its cost) survives. Update §3.3 accordingly during implementation and cover with a test. |
| 29 | Carrier reweighs and re-bills 3 days later | `POST /shipments/{id}/adjust-cost` writes a delta `order_fees` row; the original cost is never mutated. |
| 30 | Clock skew between carrier and us | All `event_at` stored UTC; events dated more than 24 h in the future are clamped to `received_at` and flagged. |
| 31 | Very large org: 50k open shipments to poll | `TrackShipmentsJob` chunks at 200, respects per-carrier concurrency limits from `config/shipping.php`, and uses the age-based backoff so old shipments don't dominate the queue. |

**Reliability budgets.** Rate shopping: 6 s per carrier, 8 s total, partial results allowed. Label purchase: 30 s, no retry, reconcile on timeout. Tracking poll: 15 s, 3 retries with backoff. Webhook handler: must respond in under 2 s — it validates, writes `webhook_logs`, dispatches a job, and returns; it never calls a carrier synchronously.

---

## 11. Testing

### Unit
1. `ShipmentStateMachineTest` — table-driven legal/illegal transitions; carrier-driven states unreachable from merchant endpoints.
2. `VolumetricWeightTest` — divisor per account, chargeable = max(actual, volumetric), rounding.
3. `AutoPackerTest` — weight resolution order (variant → product → org default), tare added, error when no weight is resolvable and defaults are off.
4. `CityNormalizerTest` — `الرياض`/`Riyadh`/`Riyad`/`AR RIYADH` → `riyadh`; unknown city preserved raw; fuzzy threshold doesn't collapse `Dammam`/`Damietta`.
5. `AddressValidatorTest` — per-country required fields, E.164 normalization, Arabic-Indic digit conversion.
6. `StatusNormalizationTest` — per carrier, a fixture list of raw codes → expected normalized status; unmapped → `exception` + warning.
7. `TrackingOrderingTest` — out-of-order events produce the max-`event_at` status; final statuses are sticky; a later final status wins.
8. `RateRankingTest` — cheapest/fastest/preferred rankings, `total_amount` composition, estimates ranked but flagged.
9. `CodValidationTest` — over-total, currency mismatch, account not enabled, split-shipment summation.

### Feature — `ShipmentTest.php` (following `OrderTest.php` structure)
10. `test_user_can_list_shipments` — paginator envelope.
11. `test_shipments_are_scoped_to_organization` — cross-tenant 404. **Mandatory.**
12. `test_cannot_purchase_label_with_another_orgs_carrier_account` — 403/404, no carrier call made (`Http::fake()` asserts zero requests). **Mandatory.**
13. `test_create_draft_shipment_from_order`.
14. `test_shipped_quantity_cannot_exceed_ordered_quantity` — 422.
15. `test_viewer_cannot_purchase_label` — 403.
16. `test_purchase_label_is_idempotent` — two calls, one carrier request, one `shipping_labels` row.
17. `test_purchase_label_stores_label_file_and_tracking_number` — `Storage::fake('labels')`.
18. `test_purchase_label_generates_unique_public_tracking_slug`.
19. `test_carrier_timeout_does_not_retry_and_dispatches_reconcile_job` — `Bus::fake()`.
20. `test_purchase_label_dispatches_push_tracking_and_cost_jobs`.
21. `test_cancel_before_pickup_voids_label`, `test_cancel_after_pickup_returns_409`.
22. `test_order_fulfillment_status_rolls_up_from_shipments` — partial → shipped → delivered.

### Feature — `ShippingRateTest.php`
23. `test_rate_shop_returns_sorted_rates_from_multiple_carriers` — `Http::fake()` for 3 carriers.
24. `test_rate_shop_omits_failing_carrier_and_reports_error`.
25. `test_rate_shop_uses_cache_within_ttl` — second call makes zero HTTP requests.
26. `test_expired_rate_triggers_reshop_and_price_drift_confirmation`.

### Feature — `TrackingTest.php`
27. `test_webhook_creates_tracking_event_and_updates_status`.
28. `test_duplicate_webhook_is_deduped_by_fingerprint`.
29. `test_unsigned_webhook_is_rejected` — 401, no event written.
30. `test_webhook_for_unknown_awb_returns_200_and_logs`.
31. `test_returned_to_origin_dispatches_rto_detection` — `Bus::fake()`, asserts Spec 03's `DetectRtoJob`.
32. `test_polling_backoff_selects_correct_shipments` — time-travel with `Carbon::setTestNow`.

### Feature — `CarrierAccountTest.php`
33. `test_credentials_are_encrypted_at_rest` — raw DB value is not the plaintext.
34. `test_credentials_are_never_returned_by_the_api` — response body assertion on every account endpoint.
35. `test_only_owner_can_manage_carrier_accounts` — 403 for admin/viewer.
36. `test_validate_credentials_sets_last_validated_at_or_last_error`.

### Feature — `PublicTrackingTest.php`
37. `test_public_tracking_returns_redacted_payload` — asserts absence of address, phone, email, line items, cost. **This is a leak test; it must assert absence explicitly, not just presence of expected keys.**
38. `test_public_tracking_shows_cod_amount_only_when_undelivered_cod`.
39. `test_public_tracking_is_rate_limited` — 429.
40. `test_by_awb_lookup_requires_phone_last_four`.
41. `test_unknown_slug_returns_generic_404`.

### Feature — `LabelBatchTest.php`
42. `test_batch_label_job_merges_pdfs_and_stores_single_artifact`.
43. `test_batch_is_capped_at_100_shipments`.

### Per-carrier contract tests — `tests/Feature/Carriers/{Carrier}Test.php`
44. For each of the 7: `Http::fake()` against a **captured fixture**, asserting the mapped `createShipment()` return shape, the normalized status for a fixture tracking response, and that credentials appear in the request in the right place. Carriers without a captured fixture are marked `@group unverified-carrier` and **excluded from CI green** — a carrier that has never seen a real response does not count as implemented.
45. `test_carrier_factory_throws_on_unknown_carrier` — mirrors the existing integration-factory behaviour.
46. `test_every_carrier_implements_every_interface_method` — reflection test over `CarrierFactory`'s match arms, so adding a carrier can't silently skip a method.
47. `test_unsupported_capability_throws_carrier_capability_exception`.

### Frontend
48. i18n parity test for `shipping` and `track` dictionaries (EN keys ≡ AR keys).
49. `ShipmentStatusBadge` renders all 19 statuses in both locales.
50. RTL smoke test on `/shipping` and `/track/[slug]` — no horizontal overflow, timeline mirrors.
51. Rate table renders progressive results and an error row for a failing carrier.

### Mobile
52. Widget test: quick-ship flow renders rate list and disables buy for `viewer`.
53. Widget test: shipment detail shows the COD block only when `is_cod`.

---

## 12. Rollout

### 12.1 Migration plan

**Phase 1 — expand.** Ship migrations `2026_07_24_000001` … `000014`. All new tables plus guarded additive columns on `orders`, `products`, `product_variants`, `stores`. Seed `carrier_status_map` and `city_aliases`. No behaviour change; the nav item stays hidden behind the flag.

**Phase 2 — backfill.**
- `php artisan hubby:backfill-order-addresses` — parses `orders.raw_data` per platform into `order_addresses`. Chunked at 500, resumable, idempotent (`updateOrCreate` on `order_id` + `type`). Report per-platform extraction success rate; expect high for Shopify/Salla/Woo, lower for marketplaces that mask the buyer address.
- `php artisan hubby:backfill-order-cod-flags` — sets `payment_method`, `is_cod`, `cod_amount`, `shipping_total`, `placed_at` from `raw_data` using the per-platform map.
- Reconciliation: report the % of orders with a usable ship-to address per platform. **Below 80 % for a platform means the mapping is wrong, not that the data is missing** — investigate before enabling shipping for that platform.

**Phase 3 — carrier by carrier.** Each carrier has its own flag and its own go-live: fixture captured → sandbox label created end-to-end → one real production label with a real parcel → enable for design partners → general.

**Phase 4 — contract.** After 60 days: add the `shipments.return_request_id` FK to `return_requests` (Spec 03), drop the backfill commands, and enable the `unique(carrier_code, tracking_number)` index if the collision analysis (edge case 25) says it's safe.

**Rollback.** All migrations have working `down()`. The additive `orders` columns must **not** be rolled back once shipping is live (`is_cod`/`cod_amount` become load-bearing). Carrier tables are self-contained; dropping them loses shipping data only. Document the one-way door in the migration docblocks.

### 12.2 Feature flags

| Flag | Default | Gates |
| --- | --- | --- |
| `shipping` | off | Whole feature: nav, routes, jobs, settings section. |
| `shipping.carrier.aramex` … `.fedex` | off (per carrier) | Carrier appears in the account-creation catalogue. |
| `shipping.rate_shopping` | on with `shipping` | The comparison table; off ⇒ single default carrier only. |
| `shipping.public_tracking` | off | Public routes + branding settings. |
| `shipping.batch_printing` | off | Batch endpoints. |
| `shipping.cod_reconciliation` | off | COD screen. |
| `shipping.push_tracking_to_platform` | off | Outbound fulfilment pushes. **Ship off**: run for two weeks writing what *would* be pushed into `shipments.raw_request` so we can diff against what merchants push manually. |

Rollout order: internal org → 2 design partners in Saudi (one Aramex, one SMSA) → 10 % → all. Watch: label purchase success rate, duplicate-label incidents (target zero), carrier timeout rate, unmapped-status rate, address validation failure rate by city, and rate-shop p95 latency.

### 12.3 Sandbox & credentials

| Carrier | Sandbox availability | Lead time | Notes |
| --- | --- | --- | --- |
| DHL Express | Developer portal test credentials | short | Easiest to develop against — use it to prove the abstraction. |
| FedEx | Developer portal sandbox project | short | OAuth path validation. |
| Aramex | Test account via account manager | **medium** | Request early; needs a commercial relationship. |
| SMSA | Test `passKey` via SMSA | **medium** | Also clarify SOAP-vs-REST for our merchants. |
| Naqel | Docs + test creds via Naqel | **medium/long** | Official PDF is the source of truth. |
| J&T | Country-specific developer portal | **long** | Must pick the launch country first. |
| Torod | Partner API docs + token | **long, blocking** | No public docs found; needs a partnership conversation. Start now. |

Rules:
- **A carrier does not merge without a captured fixture** from its sandbox (or, where no sandbox exists, from a real low-value production call).
- Sandbox credentials never live in the repo. `.env` gets nothing carrier-specific; all credentials are per-org rows in `carrier_accounts`. Developer testing uses a seeded dev org.
- `config/shipping.php` holds only non-secret config: base URLs per environment, timeouts, concurrency limits, volumetric divisors, capability matrix, per-platform COD field maps.
- Each carrier gets `docs/specs/carriers/{code}.md` written during implementation: credential acquisition steps, sandbox limitations, fixture locations, known quirks.
- **APP_KEY rotation procedure** must be written and tested before the first production carrier account is created (§9).

---

## 13. Acceptance criteria

- [ ] `ShippingCarrierInterface`, `BaseShippingCarrier` and `CarrierFactory` exist and mirror the `IntegrationServiceInterface`/`IntegrationFactory` pattern; a reflection test proves every registered carrier implements every method.
- [ ] All 14 migrations apply cleanly on `migrate:fresh` and as an upgrade from the current production schema.
- [ ] `carrier_accounts.credentials` is encrypted at rest, is never returned by any endpoint, and never appears in logs or `raw_request`/`raw_response`.
- [ ] `order_addresses` is populated for ≥ 80 % of existing orders per platform after backfill, with the actual per-platform number reported.
- [ ] Rate shopping queries all active carriers concurrently, returns within 8 s, degrades gracefully when a carrier times out, and marks estimate-only rates distinctly.
- [ ] A label can be purchased for a real order and produces: a `tracking_number`, a stored PDF on our disk, a `shipping_labels` row, a `public_tracking_slug`, a `tracking_events` row, and an `order_fees` cost row.
- [ ] Buying a label twice produces exactly one AWB (verified by test and by a carrier request-count assertion).
- [ ] A carrier timeout never auto-retries and always dispatches reconciliation.
- [ ] ZPL is available for every carrier that offers it, and the UI accurately states which formats each carrier supports.
- [ ] Batch printing of 100 labels completes asynchronously and returns a single merged artefact.
- [ ] Multi-package shipments produce per-package tracking where supported and split into separate shipments where not, with the UI saying which.
- [ ] Tracking webhooks are signature-verified, deduped, and out-of-order-safe; shipment status always reflects the greatest `event_at`.
- [ ] Every carrier has a seeded `carrier_status_map`; an unmapped status produces `exception` plus a warning notification, never a wrong customer-facing label.
- [ ] A `returned_to_origin` event dispatches Spec 03's RTO detection.
- [ ] COD amount is transmitted to the carrier, validated against the order total and currency, blocked on non-COD-enabled accounts, and reconcilable through the COD screen.
- [ ] Address validation normalizes Saudi/UAE cities and Arabic-Indic digits, requires district for Saudi destinations, and allows an explicit, recorded override.
- [ ] Packing slips render bilingually with the merchant's branding and contain no prices.
- [ ] Manifests and pickup requests work for every carrier that supports them; carriers that don't get a locally generated manifest clearly marked as such.
- [ ] The public tracking page renders in Arabic RTL by default, is branded per merchant, is rate-limited, and leaks no address, contact, line-item or cost data (verified by an explicit absence test).
- [ ] `/shipping`, `/shipping/[id]`, `/shipping/new`, `/shipping/rates`, `/shipping/cod`, `/settings/shipping` and `/track/[slug]` render in EN and AR with correct RTL and no missing keys.
- [ ] Mobile can ship an order end-to-end (rate → buy → share label) and scan an AWB to open a shipment.
- [ ] `viewer` cannot buy labels; only `owner` can manage carrier accounts; cross-tenant carrier-account use is impossible (tested).
- [ ] At least **Aramex and SMSA** are production-verified with a real parcel before the feature is announced. DHL/FedEx may ship earlier as abstraction proofs.
- [ ] Every UNVERIFIED item in §6 is either confirmed with a captured fixture or the carrier remains behind its flag.
- [ ] All tests in §11 pass; `@group unverified-carrier` tests are excluded from the green build and tracked as debt.

---

## 14. Effort estimate + dependencies

One senior full-stack engineer, ideal engineering days.

| Workstream | Days |
| --- | --- |
| Migrations (14), models, seeders (status map, city aliases), backfill commands | 5 |
| `ShippingCarrierInterface`, `BaseShippingCarrier`, `CarrierFactory`, value objects, capability system | 3 |
| `SoapCarrierClient` (shared by Aramex/SMSA/Naqel) | 3 |
| `ShippingService` + state machine + observers + events | 4 |
| `ShippingRateService` (concurrent fan-out, caching, ranking, estimate fallback) | 3 |
| `LabelStorageService`, batch builder (PDF merge / ZPL concat), packing slip renderer | 4 |
| `TrackingIngestService`, webhook controller + verification middleware, polling jobs + backoff | 4 |
| `AddressValidator` + `CityNormalizer` + alias seed | 3 |
| COD service + reconciliation | 2 |
| Manifests + pickups | 2 |
| API layer (~45 endpoints), form requests, resources, error codes | 5 |
| `SupportsFulfillmentInterface` + Shopify/Woo tracking push | 2.5 |
| **Aramex** (SOAP, rates, label, pickup, tracking, address) | 6 |
| **SMSA** (dual SOAP/REST driver, label, tracking) | 5 |
| **DHL** (REST, rates, label, tracking, pickup) | 4 |
| **FedEx** (OAuth + token cache, rates, label, tracking) | 4 |
| **Naqel** (SOAP, label, tracking) | 4 |
| **J&T** (signed REST, country dialects) | 4 |
| **Torod** (aggregator REST) | 4 |
| Dashboard: list, detail, create wizard, rate table, COD, manifests, pickups, settings | 12 |
| Public tracking page (Next.js route group, branding, RTL) | 3 |
| i18n EN/AR + RTL polish | 2 |
| Mobile: list, detail, quick-ship, scan | 5 |
| Tests (53 listed) + fixture capture | 7 |
| Rollout, flags, carrier docs, sandbox onboarding | 3 |
| **Total** | **≈ 109 days** |

Phased delivery:
- **M1 — Foundation + one carrier (≈ 30 d):** data model, interface, `ShippingService`, labels, tracking ingest, dashboard list/detail/create, **DHL** (fastest sandbox) as the proof.
- **M2 — The wedge (≈ 20 d):** SOAP client + **Aramex** + **SMSA**, COD end-to-end, address validation, city normalizer. This is the milestone that makes the competitive claim true.
- **M3 — Customer-facing (≈ 12 d):** public Arabic tracking page, packing slips, batch printing, manifests, pickups.
- **M4 — Breadth (≈ 16 d):** Torod, Naqel, J&T, FedEx.
- **M5 — Mobile + analytics (≈ 10 d).**

**Hard dependencies:**
1. **Durable object storage** for labels (S3/Spaces). Blocking for M1. Local disk is not acceptable for multi-server production.
2. **A PDF toolchain** for merging and for packing slips (`dompdf`/`snappy` for rendering; a merge library or a `pdftk`-class binary). Decide early — it affects the deploy image.
3. **A SOAP client** — new to this codebase. Blocking for Aramex/SMSA/Naqel (M2).
4. **`order_addresses` backfill quality** — blocking for shipping any historical order.
5. **Carrier credentials with real lead times** (§12.3). Aramex and SMSA test accounts must be requested at project kickoff, not at M2 start, or M2 slips.
6. **Torod partnership conversation** — blocking for M4 and it has the longest lead time of all.
7. **Encrypted credential storage + APP_KEY rotation runbook** — blocking for any production carrier account.
8. **Spec 03 (Returns)** — bidirectional: Spec 03 needs `createReturnShipment()` and RTO tracking from here; this spec needs `return_requests` to exist for the reverse-shipment FK. Ship Spec 04's shipment tables first, wire the FK second.
9. **Profit & Cost Engine** — `order_fees` is the write target for shipping cost. Shipping degrades gracefully without it.
10. **Queue workers with adequate concurrency** — tracking polling at scale is the heaviest recurring job we will have run.

---

## 15. Open questions

1. **Warehouses.** One ship-from address per store is v1. Merchants with two warehouses need per-shipment origin selection and origin-aware rate shopping. Do we add a `locations` table now (it also affects Spec 03's restock destination) or accept the rework? This is the single biggest architectural fork in both specs.
2. **Build carriers natively, or lead with Torod?** Torod plausibly delivers many carriers for one integration and would compress M2+M4 dramatically — but it makes us dependent on a competitor-adjacent third party, adds their margin, and weakens the "we speak Aramex directly" story. Needs a commercial decision, not an engineering one.
3. **Which J&T country first?** The API is country-fragmented. Saudi, UAE and Egypt are all plausible. Picking wrong wastes the whole 4-day estimate.
4. **SMSA: how many merchants are on the legacy SOAP surface vs the newer REST one?** Determines whether the dual-driver design is essential (assumed yes) or over-engineering.
5. **Tracking-number uniqueness.** `unique(carrier_code, tracking_number)` is right in principle, but carriers recycle AWB numbers over multi-year windows and aggregators may surface an underlying carrier's number. Hard unique index, or unique-per-org, or a soft-warning non-unique index?
6. **Rate caching across tenants.** Rates are account-specific (negotiated tariffs), so the cache is correctly per-`carrier_account`. But that makes the cache far less effective for orgs with low volume. Is a coarse, clearly-labelled "indicative rate" cache across accounts of the same carrier acceptable for the standalone rate calculator?
7. **Do we ever pay carriers on the merchant's behalf?** Everything here assumes the merchant's own carrier account and the merchant's own bill. A future "Hubby-negotiated rates" model (we hold the carrier contract, merchants ship under it) is a completely different commercial and data model — worth knowing now whether it's on the roadmap, because it changes whether `carrier_accounts` is per-org or shared.
8. **Custom tracking domain.** `track.merchant.com` via CNAME is a strong branding win and a certificate-management burden. In v1.1, or never?
9. **Customer notifications.** Tracking page + WhatsApp share covers the manual case. Automatic "your order shipped" SMS/WhatsApp is a much bigger deal in MENA than email — but it needs a messaging provider, per-country sender registration, and Arabic templates. Whose roadmap?
10. **Do we surface shipping cost to `viewer` role?** Currently yes (it's on the shipment). Some merchants treat carrier rates as confidential from staff.
11. **Reweigh/surcharge reconciliation.** We support manual cost adjustment. Automatic ingestion of carrier invoices (PDF/CSV) is the thing merchants actually want and is a genuinely large project. Signal now, build when?
12. **Label retention.** How long do we keep label PDFs? Storage grows linearly with volume and labels are useless after delivery, but merchants occasionally need them for claims. Proposal: keep 12 months, then keep only the DB row. Needs a product decision.
