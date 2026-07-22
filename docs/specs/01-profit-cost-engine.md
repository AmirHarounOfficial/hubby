# Spec 01 — Profit & Cost Engine

**Status:** Draft / implementation-ready
**Owner:** Backend
**Target:** Phase 0, item 2 of `docs/COMPETITIVE_STRATEGY.md`
**Repo state verified at:** branch `main`, commit `9e638a2`

> Everything below marked **[verified]** was read out of the repo. Everything marked
> **[assumption]** or **[unverified]** is a design decision or an external-API claim that
> has *not* been confirmed against a live account and must be validated before build.

---

## 1. Why this exists

Hubby today can tell a merchant *how much they sold*. It cannot tell them *whether they made
money*. That is the single most valuable question in the product, and we currently answer it
with a number (`orders.total`) that is gross revenue with VAT still inside it.

**[verified] The schema proves the gap:**

- `database/migrations/2026_05_06_090840_fix_products_table_columns.php` adds `sku` and
  `price` to `products`. There is no cost column anywhere in the repo.
- `database/migrations/2026_05_05_202920_create_orders_table.php` gives `orders` exactly:
  `store_id, external_id, status, total, currency, customer_name, customer_email, raw_data`.
  No fees, no shipping, no tax, no discount, no refund.
- `database/migrations/2026_05_05_202921_create_order_items_table.php` gives `order_items`:
  `order_id, sku, quantity, price, name`. No cost, no tax, no discount.
- `app/Http/Controllers/AnalyticsController.php` computes `total_revenue` as
  `SUM(orders.total)` and `avg_order_value` as revenue/orders. Both are gross, VAT-inclusive,
  fee-blind numbers.

### Competitive rationale

| | Linnworks | Sellerboard | Rithum | Hubby after this spec |
|---|---|---|---|---|
| True net profit | ❌ requires paid partner **Conjura** | ✅ **the benchmark** | ✅ enterprise-only | ✅ included in every plan |
| COGS methods | FIFO/LIFO/weighted-avg (inventory module) | FIFO, fixed, batch, period, per-marketplace | proprietary | FIFO, fixed, batch, period, per-store |
| Cross-channel P&L | n/a | ❌ **structurally impossible** — Shopify is a separate app with separate billing | ✅ | ✅ **7 channels, one statement** |
| MENA channels in the P&L | ❌ none | ❌ none | ❌ none | ✅ Salla, Zid, Noon, Trendyol, Amazon.sa/.ae |
| VAT-inclusive pricing | ❌ | disputed accuracy (Trustpilot: "miscalculated VAT") | ✅ | ✅ first-class, KSA/UAE default |
| Arabic P&L | ❌ | ❌ | ❌ | ✅ |

**Sellerboard is the benchmark and the thing to beat on exactly two axes:**

1. **Cross-channel.** Sellerboard's Shopify product is a separate app with separate billing, so
   a merchant selling on Amazon.sa *and* Salla *and* Noon cannot get one net-profit number from
   them. We can. This is not a feature we build better — it is one they cannot build without
   re-architecting their business model.
2. **MENA-native accounting.** VAT-inclusive storefront pricing (KSA 15%, UAE 5%), SAR/AED/TRY
   multi-currency, and (Phase 1) COD/RTO cost — none of the three model any of it.

**Where we accept parity, not superiority:** Sellerboard models 100+ Amazon fee types from
settlement data. We will not match that in v1. We will match the *structure* (typed fee lines,
settlement reconciliation, estimated-vs-actual flagging) and let Amazon fee coverage deepen over
time.

**The non-negotiable design consequence:** every number we show must be labelled as *actual* or
*estimated*. Sellerboard's worst reviews are data-accuracy disputes. A confidently wrong margin
destroys trust faster than a visibly incomplete one. `is_estimated` is therefore a first-class
column, not an afterthought.

This engine is also a prerequisite for later roadmap items: break-even ACOS, reorder economics,
COD reconciliation, and margin-aware automation rules all read from these tables.

---

## 2. Scope

### In scope

- `product_costs` — cost per SKU, four methods (fixed / FIFO / period / batch), `valid_from`
  history, currency, optional per-store override, landed-cost components (unit + freight + duty
  + prep + other).
- `cost_layers` + `cost_layer_consumptions` — FIFO inventory layers and their consumption/reversal ledger.
- `order_fees` — typed fee lines at order level **and** order-item level.
- `expenses` + `expense_allocations` — recurring and one-off business expenses, amortized to days.
- `ad_spend` — per channel / campaign / day (/ SKU where the channel provides it).
- `fee_rules` — estimated-fee fallback rules per platform/category when the API gives us nothing.
- `fx_rates` — daily FX so a multi-currency merchant gets one P&L in one base currency.
- `order_profits` — materialized per-order rollup so reporting does not recompute on read.
- Schema additions to `orders`, `order_items`, `products`, `product_variants`, `organizations`, `stores`.
- Derived metrics: net profit per order / SKU / channel / period, margin after all fees, refund
  cost including lost COGS, VAT-aware throughout.
- Backfill of historical orders from `orders.raw_data` (already stored **[verified]**), plus
  estimation for what raw_data cannot supply.
- Dashboard: P&L statement, per-SKU profit table, cost entry + CSV import, expense manager,
  ad-spend entry, per-order profit waterfall. EN + AR.
- Mobile: read-only profit KPIs + per-order profit breakdown.
- Permissions: cost data is sensitive; role-gated.

### Out of scope (explicitly, for this spec)

- **Purchase orders / suppliers.** FIFO layers can be created manually or by CSV in v1. The
  PO → auto-close → auto-update-COGS mechanic (Sellerboard's best trick) lands in Phase 2 and
  will *write into* `cost_layers` created here.
- **Returns / RMA workflow.** We model the *accounting* of a refund (lost COGS, non-refundable
  fees). The request→approve→restock UI is spec 03.
- **Forecasting, reorder points, break-even ACOS.** They consume this data; they are not this.
- **Accounting-package sync** (QuickBooks / Xero / Qoyod / Wafeq).
- **ZATCA e-invoicing.** Separate spec; shares the VAT model defined here.
- **COD reconciliation.** `orders.cod_amount` is added here as a hook; the reconciliation
  workflow is Phase 1.
- **Amazon Ads API ingestion.** `ad_spend` supports manual + CSV in v1; the Ads API is a
  separate OAuth app and a separate integration.
- **Implementing `ZidService` / `WooCommerceService`.** **[verified]** both are stubs returning
  empty arrays. Their fee capture is specified but blocked on the base integration.
- **Changing `IntegrationServiceInterface`.** See §5.4 — we add a *new* optional interface
  rather than break all 7 implementers.

---

## 3. Data model

### 3.0 Conventions taken from the repo

**[verified]** from existing migrations and models:

- Money columns are `decimal(15, 2)` (`orders.total`, `products.price`, `product_variants.price`).
  **This spec introduces `decimal(15, 4)` for anything that is divided, allocated, or accumulated**
  (unit costs, fee allocations, FX-converted amounts) — 2 dp loses cents on per-unit division.
  Customer-facing totals stay at 2 dp.
- `$table->id()` (bigint auto-increment). No UUIDs anywhere in the repo.
- FKs: `$table->foreignId('x')->constrained()->onDelete('cascade')`.
- Currency: `$table->string('currency', 3)->default('USD')` on `orders`. **New tables default to
  `'SAR'`** — the frontend `Money.tsx` and mobile `format.dart` both treat SAR as the default and
  render the riyal glyph **[verified]**.
- Migration filenames: `YYYY_MM_DD_NNNNNN_verb_noun_table.php`. Recent ones use a `0000NN`
  sequence (e.g. `2026_07_02_000004_add_trendyol_to_stores_platform.php`) **[verified]**.
- Enum columns on MySQL need a raw `ALTER TABLE ... MODIFY` to widen, and sqlite (tests) stores
  them as strings — see `2026_07_02_000004_...` for the exact guarded pattern **[verified]**.
  **Decision: all new type/status columns are `string` with application-level validation, not
  DB enums.** This avoids the widening dance entirely and matches how `orders.status` and
  `stores.status`-adjacent code already behaves in practice.
- Tables scoped to a tenant carry `organization_id` directly (`products`, `stores`,
  `notifications`) **[verified]**.

### 3.1 Critical org-scoping decision

**[verified]** `orders` has **no** `organization_id`. Every controller reaches it via
`Order::whereHas('store', fn($q) => $q->where('organization_id', $orgId))`
(`OrderController::applyFilters`, `AnalyticsController::dashboard`).

That is a correlated subquery on every single profit aggregate. For a P&L over 90 days across
100k orders it is the difference between 40 ms and 4 s.

**Decision: denormalize `organization_id` onto `orders` and onto every new profit table.**
It is added nullable, backfilled, then made non-nullable — expand/contract, §12.

### 3.2 Pre-existing schema defects this spec must work around

Found while reading; each is **[verified]** and each affects profit correctness.

| # | Defect | Evidence | Impact on profit | Handling |
|---|---|---|---|---|
| D1 | `SyncOrdersJob::handle()` writes `external_id` and `product_name` to `order_items`, but the table has neither column (it has `name`) | `app/Jobs/SyncOrdersJob.php` vs `2026_05_05_202921_create_order_items_table.php` | Per-line fee attribution needs a stable external line id | Add `order_items.external_id`; add `name` to the job's write. Fix shipped **as part of this spec** (§3.5) |
| D2 | `Order::updateOrCreate` keys on `external_id` **only**, not `['store_id','external_id']`, despite the unique index being `['store_id','external_id']` | `app/Jobs/SyncOrdersJob.php` | Two stores with colliding external ids overwrite each other → profit attributed to the wrong channel | Fix the key in the same PR; backfill dedupe check in §12 |
| D3 | `product_variants.sku` is **globally unique** (`$table->string('sku')->unique()`) | `2026_05_05_202910_create_product_variants_table.php` | Org A's SKU blocks org B's identical SKU. Cost data keyed by SKU would leak or collide across tenants | New cost tables key on **`(organization_id, sku)`**, never SKU alone. Changing the variant index to `unique(['product_id','sku'])` is recommended but **out of scope** — flagged in §15 |
| D4 | Analytics groups by `orders.created_at`, which is our **insert** timestamp, not the marketplace order date | `AnalyticsController::ordersTimeline`, `dashboard` | A 90-day backfill would dump 90 days of profit onto one day | Add `orders.placed_at`; all profit reporting uses `placed_at` |
| D5 | `products.sku` is nullable and non-unique; `order_items.sku` is nullable | migrations | SKU-less lines cannot be costed | Explicit fallback chain, §4.2; surfaced in the coverage report |

### 3.3 New tables

Order of creation matters (FKs). Migration filenames listed in §3.6.

---

#### `product_costs`

Cost **definitions** per SKU. The authoritative record of what a merchant says a unit costs.
FIFO actual layers live in `cost_layers`; this table holds the method choice and the fixed/period/batch figures.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned PK | no | auto | |
| `organization_id` | bigint unsigned FK→`organizations` cascade | no | — | tenant key |
| `product_variant_id` | bigint unsigned FK→`product_variants` nullOnDelete | yes | null | resolved link, may be null for costs entered before the product syncs |
| `product_id` | bigint unsigned FK→`products` nullOnDelete | yes | null | set when the SKU maps to a simple product |
| `sku` | string(191) | no | — | the resolution key; always paired with `organization_id` (see D3) |
| `store_id` | bigint unsigned FK→`stores` cascade | yes | null | **null = applies to all stores**; set = per-channel override |
| `method` | string(16) | no | `'fixed'` | `fixed` \| `fifo` \| `period` \| `batch` |
| `unit_cost` | decimal(15,4) | no | 0 | ex-VAT purchase price per unit |
| `freight_cost` | decimal(15,4) | no | 0 | inbound freight per unit |
| `duty_cost` | decimal(15,4) | no | 0 | customs/duty per unit |
| `prep_cost` | decimal(15,4) | no | 0 | labelling/prep/packaging per unit |
| `other_cost` | decimal(15,4) | no | 0 | catch-all per unit |
| `landed_unit_cost` | decimal(15,4) | no | 0 | maintained by observer = sum of the five above. **Not** a generated column — sqlite in tests and MySQL disagree on `storedAs` syntax; an observer keeps both engines identical |
| `currency` | char(3) | no | `'SAR'` | |
| `fx_rate_to_base` | decimal(18,8) | no | 1 | snapshot at entry; see §4.5 |
| `landed_unit_cost_base` | decimal(15,4) | no | 0 | `landed_unit_cost * fx_rate_to_base` |
| `valid_from` | date | no | — | inclusive |
| `valid_to` | date | yes | null | exclusive; maintained by `CostResolver` when a newer row is inserted |
| `batch_ref` | string(64) | yes | null | used when `method = 'batch'` |
| `period_end` | date | yes | null | used when `method = 'period'` |
| `source` | string(16) | no | `'manual'` | `manual` \| `import` \| `purchase_order` \| `api` \| `estimated` |
| `note` | string(255) | yes | null | |
| `created_by` | bigint unsigned FK→`users` nullOnDelete | yes | null | audit |
| `created_at` / `updated_at` | timestamp | yes | null | |
| `deleted_at` | timestamp | yes | null | soft delete — never hard-delete cost history |

**Indexes**

```php
$table->index(['organization_id', 'sku', 'valid_from'], 'px_costs_lookup');
$table->index(['organization_id', 'store_id', 'sku'], 'px_costs_store');
$table->index('product_variant_id');
$table->unique(['organization_id', 'sku', 'store_id', 'valid_from'], 'ux_costs_scope');
```

> **Caveat, must be handled in code:** MySQL treats `NULL` as distinct in a unique index, so
> `ux_costs_scope` does **not** prevent two org-wide (`store_id = null`) rows for the same
> `(sku, valid_from)`. `ProductCostService::upsert()` therefore does a
> `->lockForUpdate()` existence check inside the transaction before insert. Documented rather
> than worked around with a sentinel column, because a `store_id = 0` sentinel breaks the FK.

---

#### `cost_layers`

FIFO inventory layers. One row per receipt of stock at a known cost.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned PK | no | auto | |
| `organization_id` | bigint unsigned FK cascade | no | — | |
| `product_variant_id` | bigint unsigned FK nullOnDelete | yes | null | |
| `sku` | string(191) | no | — | |
| `store_id` | bigint unsigned FK cascade | yes | null | null = shared org-wide pool (default) |
| `source` | string(24) | no | `'manual'` | `opening` \| `purchase_order` \| `manual` \| `import` \| `return_restock` \| `adjustment` \| `estimated` |
| `source_ref` | string(191) | yes | null | PO number, import batch id |
| `acquired_at` | datetime | no | — | FIFO ordering key |
| `qty_received` | integer | no | 0 | |
| `qty_remaining` | integer | no | 0 | `>= 0`, decremented on consumption |
| `unit_cost` | decimal(15,4) | no | 0 | landed, in `currency` |
| `currency` | char(3) | no | `'SAR'` | |
| `fx_rate_to_base` | decimal(18,8) | no | 1 | frozen at acquisition — a layer's cost never re-rates |
| `unit_cost_base` | decimal(15,4) | no | 0 | |
| `is_estimated` | boolean | no | false | true when synthesised by the shortfall path (§4.3) |
| `created_by` | bigint unsigned FK→`users` nullOnDelete | yes | null | |
| timestamps | | yes | null | |

**Indexes**

```php
$table->index(['organization_id', 'sku', 'acquired_at', 'id'], 'px_layers_fifo');
$table->index(['organization_id', 'sku', 'qty_remaining'], 'px_layers_open');
$table->index(['organization_id', 'store_id']);
```

`px_layers_fifo` includes `id` because two receipts can share an `acquired_at`; the tiebreak
must be deterministic or FIFO is not reproducible.

---

#### `cost_layer_consumptions`

The ledger. Every unit of COGS ever recognised, and every reversal, is a row here. This is what
makes a margin number auditable.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned PK | no | auto | |
| `organization_id` | bigint unsigned FK cascade | no | — | |
| `cost_layer_id` | bigint unsigned FK→`cost_layers` restrictOnDelete | no | — | restrict: a consumed layer must never vanish |
| `order_id` | bigint unsigned FK→`orders` cascade | no | — | |
| `order_item_id` | bigint unsigned FK→`order_items` cascade | no | — | |
| `qty` | integer | no | 0 | **negative for reversals** |
| `unit_cost_base` | decimal(15,4) | no | 0 | copied from the layer at consumption time |
| `amount_base` | decimal(15,4) | no | 0 | `qty * unit_cost_base`, signed |
| `consumed_at` | datetime | no | — | |
| `reason` | string(24) | no | `'sale'` | `sale` \| `refund_restock` \| `refund_writeoff` \| `correction` |
| `reversal_of_id` | bigint unsigned FK→`cost_layer_consumptions` nullOnDelete | yes | null | |
| `consumption_key` | string(191) | no | — | idempotency, see below |
| timestamps | | yes | null | |

**Indexes**

```php
$table->unique(['organization_id', 'consumption_key'], 'ux_consumption_key');
$table->index(['order_item_id']);
$table->index(['cost_layer_id']);
$table->index(['organization_id', 'consumed_at']);
```

`consumption_key` format (deterministic, so a re-run is a no-op):

```
sale:{order_item_id}:{cost_layer_id}
rev:{original_consumption_id}
corr:{order_item_id}:{cost_layer_id}:{calc_version}
```

---

#### `order_fees`

Typed fee lines. Order-level when `order_item_id` is null, item-level otherwise.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned PK | no | auto | |
| `organization_id` | bigint unsigned FK cascade | no | — | denormalized (orders lacks it) |
| `order_id` | bigint unsigned FK→`orders` cascade | no | — | |
| `order_item_id` | bigint unsigned FK→`order_items` cascade | yes | null | null = order-level |
| `store_id` | bigint unsigned FK→`stores` cascade | no | — | denormalized for channel rollups |
| `type` | string(24) | no | — | see enumeration below |
| `subtype` | string(64) | yes | null | platform-native label, e.g. `fba_pick_pack`, `referral`, `cod_handling`, `rto_shipping` |
| `amount` | decimal(15,4) | no | 0 | **signed: positive = cost to the merchant**, negative = credit/reimbursement |
| `currency` | char(3) | no | `'SAR'` | |
| `fx_rate_to_base` | decimal(18,8) | no | 1 | |
| `amount_base` | decimal(15,4) | no | 0 | |
| `is_estimated` | boolean | no | false | true when produced by `FeeEstimator` |
| `source` | string(16) | no | `'manual'` | `api` \| `settlement` \| `webhook` \| `raw_data` \| `rule` \| `manual` \| `import` |
| `external_id` | string(191) | yes | null | marketplace fee/transaction id |
| `settlement_id` | string(191) | yes | null | groups fees to a payout |
| `posted_at` | datetime | yes | null | when the marketplace charged it — often ≠ order date |
| `raw_data` | json | yes | null | mirrors the `orders.raw_data` convention |
| `created_by` | bigint unsigned FK→`users` nullOnDelete | yes | null | |
| timestamps | | yes | null | |

**Fee `type` enumeration** (application constant `OrderFee::TYPES`):

`commission` · `fulfilment` · `shipping` · `payment` · `refund` · `storage` · `advertising` ·
`tax` · `discount` · `other`

> `tax` and `discount` lines are recorded for reconciliation but are **excluded** from
> `total_fees` in the profit formula — VAT is handled by the VAT model (§4.4) and discounts are
> already netted out of revenue. Double-counting them is the most likely arithmetic bug in this
> whole feature; §11 has a test for exactly that.

**Indexes**

```php
$table->unique(['store_id', 'fee_key'], 'ux_fees_key');
$table->index(['order_id']);
$table->index(['order_item_id']);
$table->index(['organization_id', 'posted_at']);
$table->index(['store_id', 'type']);
$table->index(['settlement_id']);
```

`fee_key` — `string(191)`, not null. Deterministic idempotency key so a settlement re-import
never duplicates:

```
{order_external_id}:{type}:{subtype ?? '-'}:{external_id ?? md5(amount|posted_at)}
```

---

#### `expenses`

Business expenses that are not attributable to a single order.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned PK | no | auto | |
| `organization_id` | bigint unsigned FK cascade | no | — | |
| `name` | string(191) | no | — | |
| `category` | string(24) | no | `'other'` | `software` \| `salary` \| `rent` \| `marketing` \| `logistics` \| `packaging` \| `bank` \| `tax` \| `other` |
| `type` | string(16) | no | `'one_off'` | `one_off` \| `recurring` |
| `amount` | decimal(15,2) | no | 0 | the charged amount per occurrence |
| `currency` | char(3) | no | `'SAR'` | |
| `fx_rate_to_base` | decimal(18,8) | no | 1 | |
| `amount_base` | decimal(15,2) | no | 0 | |
| `recurrence` | string(16) | yes | null | `daily` \| `weekly` \| `monthly` \| `quarterly` \| `yearly`; required when `type = recurring` |
| `starts_on` | date | no | — | |
| `ends_on` | date | yes | null | null = open-ended |
| `amortize` | boolean | no | true | false = charge fully on `starts_on` |
| `allocation_method` | string(16) | no | `'revenue'` | `none` \| `revenue` \| `orders` \| `units` — how it splits across channels/SKUs in reports |
| `store_id` | bigint unsigned FK cascade | yes | null | pin to one channel; null = spread by `allocation_method` |
| `note` | text | yes | null | |
| `created_by` | bigint unsigned FK→`users` nullOnDelete | yes | null | |
| timestamps + `deleted_at` | | yes | null | soft delete |

**Indexes**

```php
$table->index(['organization_id', 'starts_on']);
$table->index(['organization_id', 'category']);
$table->index(['organization_id', 'type']);
```

---

#### `expense_allocations`

Materialized daily slices, written by `AmortizeExpensesJob`. Reporting sums this, never the
recurrence rules — a report must never depend on a date-math loop.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned PK | no | auto | |
| `organization_id` | bigint unsigned FK cascade | no | — | |
| `expense_id` | bigint unsigned FK→`expenses` cascade | no | — | |
| `date` | date | no | — | |
| `amount_base` | decimal(15,4) | no | 0 | daily slice |
| `store_id` | bigint unsigned FK cascade | yes | null | copied from the expense when pinned |
| timestamps | | yes | null | |

**Indexes**

```php
$table->unique(['expense_id', 'date'], 'ux_expense_day');
$table->index(['organization_id', 'date']);
```

---

#### `ad_spend`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned PK | no | auto | |
| `organization_id` | bigint unsigned FK cascade | no | — | |
| `store_id` | bigint unsigned FK cascade | yes | null | null = off-platform channel (Meta, TikTok, Snap) |
| `channel` | string(32) | no | — | `amazon_ads` \| `noon_ads` \| `salla_ads` \| `trendyol_ads` \| `meta` \| `google` \| `tiktok` \| `snapchat` \| `other` |
| `campaign_name` | string(191) | yes | null | |
| `campaign_external_id` | string(191) | yes | null | |
| `sku` | string(191) | yes | null | set only when the channel attributes to SKU |
| `date` | date | no | — | |
| `spend` | decimal(15,4) | no | 0 | |
| `currency` | char(3) | no | `'SAR'` | |
| `fx_rate_to_base` | decimal(18,8) | no | 1 | |
| `spend_base` | decimal(15,4) | no | 0 | |
| `impressions` | bigint unsigned | yes | null | |
| `clicks` | bigint unsigned | yes | null | |
| `orders_attributed` | integer | yes | null | |
| `sales_attributed` | decimal(15,4) | yes | null | in base currency |
| `source` | string(16) | no | `'manual'` | `manual` \| `csv` \| `api` |
| `spend_key` | char(64) | no | — | `sha1(channel|campaign_external_id|sku|date|store_id)` |
| `created_by` | bigint unsigned FK→`users` nullOnDelete | yes | null | |
| timestamps | | yes | null | |

**Indexes**

```php
$table->unique(['organization_id', 'spend_key'], 'ux_adspend_key');
$table->index(['organization_id', 'date']);
$table->index(['organization_id', 'channel', 'date']);
$table->index(['organization_id', 'sku', 'date']);
```

---

#### `fee_rules`

The honest fallback. When a marketplace does not tell us what it charged, the merchant tells us
the rule once and we apply it — clearly flagged as estimated.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned PK | no | auto | |
| `organization_id` | bigint unsigned FK cascade | yes | null | **null = system-wide default** shipped in a seeder |
| `platform` | string(32) | no | — | matches `stores.platform` values |
| `store_id` | bigint unsigned FK cascade | yes | null | narrow to one store |
| `category_id` | bigint unsigned FK→`categories` nullOnDelete | yes | null | |
| `sku` | string(191) | yes | null | most specific match |
| `type` | string(24) | no | — | same enumeration as `order_fees.type` |
| `subtype` | string(64) | yes | null | |
| `basis` | string(24) | no | `'percent_of_item'` | `percent_of_item` \| `percent_of_order` \| `flat_per_order` \| `flat_per_unit` \| `per_kg` |
| `rate` | decimal(9,4) | no | 0 | percent stored as `15.0000` = 15% |
| `min_amount` | decimal(15,4) | yes | null | floor |
| `max_amount` | decimal(15,4) | yes | null | cap |
| `currency` | char(3) | yes | null | required for flat bases |
| `effective_from` | date | no | — | |
| `effective_to` | date | yes | null | |
| `priority` | integer | no | 100 | lower wins |
| `is_active` | boolean | no | true | |
| timestamps | | yes | null | |

**Indexes**

```php
$table->index(['organization_id', 'platform', 'type', 'effective_from'], 'px_feerules_match');
$table->index(['platform', 'type', 'is_active']);
```

Match order (most specific first): `sku` → `category_id` → `store_id` → `platform` +
org-specific → `platform` + system default. Ties break on `priority` then newest `effective_from`.

---

#### `fx_rates`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned PK | no | auto | |
| `base` | char(3) | no | — | e.g. `SAR` |
| `quote` | char(3) | no | — | e.g. `TRY` |
| `date` | date | no | — | |
| `rate` | decimal(18,8) | no | — | 1 `quote` = `rate` `base` |
| `source` | string(32) | no | `'manual'` | `manual` \| `ecb` \| `openexchange` |
| timestamps | | yes | null | |

**Indexes:** `$table->unique(['base', 'quote', 'date'], 'ux_fx_day');`

---

#### `order_profits`

Materialized per-order rollup. All reporting reads this; nothing recomputes on request.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned PK | no | auto | |
| `organization_id` | bigint unsigned FK cascade | no | — | |
| `order_id` | bigint unsigned FK→`orders` cascade | no | — | unique |
| `store_id` | bigint unsigned FK cascade | no | — | |
| `placed_on` | date | no | — | denormalized from `orders.placed_at`, the report grouping key |
| `base_currency` | char(3) | no | `'SAR'` | |
| `gross_revenue_base` | decimal(15,4) | no | 0 | items, VAT-inclusive as sold |
| `discounts_base` | decimal(15,4) | no | 0 | positive number |
| `shipping_revenue_base` | decimal(15,4) | no | 0 | ex-VAT |
| `net_revenue_base` | decimal(15,4) | no | 0 | ex-VAT, after discounts, incl. shipping charged |
| `vat_base` | decimal(15,4) | no | 0 | VAT collected — never profit |
| `cogs_base` | decimal(15,4) | no | 0 | |
| `total_fees_base` | decimal(15,4) | no | 0 | excludes `tax`/`discount` types |
| `fees_by_type` | json | yes | null | `{"commission": 12.50, "fulfilment": 8.00, ...}` |
| `ad_spend_base` | decimal(15,4) | no | 0 | order-attributed ads only |
| `refund_revenue_base` | decimal(15,4) | no | 0 | ex-VAT revenue reversed |
| `refund_cogs_base` | decimal(15,4) | no | 0 | COGS reversed (restocked) |
| `lost_cogs_base` | decimal(15,4) | no | 0 | COGS **not** recovered (written off) |
| `net_profit_base` | decimal(15,4) | no | 0 | |
| `margin_pct` | decimal(9,4) | yes | null | null when net revenue is 0 |
| `is_estimated` | boolean | no | false | any input estimated |
| `estimated_share` | decimal(9,4) | no | 0 | share of `total_fees_base + cogs_base` that is estimated, 0–1 |
| `missing_cost` | boolean | no | false | at least one line had no resolvable cost |
| `calc_version` | smallint unsigned | no | 1 | bump to force recompute |
| `computed_at` | datetime | no | — | |
| timestamps | | yes | null | |

**Indexes**

```php
$table->unique('order_id');
$table->index(['organization_id', 'placed_on'], 'px_profit_period');
$table->index(['organization_id', 'store_id', 'placed_on']);
$table->index(['organization_id', 'is_estimated']);
$table->index(['calc_version']);
```

---

#### `order_item_profits`

Per-line rollup — required for per-SKU profit without re-running allocation on read.

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | bigint unsigned PK | no | auto |
| `organization_id` | bigint unsigned FK cascade | no | — |
| `order_id` | bigint unsigned FK cascade | no | — |
| `order_item_id` | bigint unsigned FK cascade | no | — (unique) |
| `store_id` | bigint unsigned FK cascade | no | — |
| `sku` | string(191) | yes | null |
| `product_variant_id` | bigint unsigned FK nullOnDelete | yes | null |
| `placed_on` | date | no | — |
| `quantity` | integer | no | 0 |
| `net_revenue_base` | decimal(15,4) | no | 0 |
| `vat_base` | decimal(15,4) | no | 0 |
| `cogs_base` | decimal(15,4) | no | 0 |
| `direct_fees_base` | decimal(15,4) | no | 0 | fees booked to this line |
| `allocated_fees_base` | decimal(15,4) | no | 0 | share of order-level fees |
| `ad_spend_base` | decimal(15,4) | no | 0 |
| `net_profit_base` | decimal(15,4) | no | 0 |
| `margin_pct` | decimal(9,4) | yes | null |
| `is_estimated` | boolean | no | false |
| timestamps | | yes | null |

**Indexes**

```php
$table->unique('order_item_id');
$table->index(['organization_id', 'sku', 'placed_on'], 'px_itemprofit_sku');
$table->index(['organization_id', 'placed_on']);
$table->index(['organization_id', 'store_id', 'placed_on']);
```

### 3.4 Changes to existing tables

#### `orders`

```php
$table->foreignId('organization_id')->nullable()->after('id')
      ->constrained()->nullOnDelete();          // backfilled, then made non-null (§12)
$table->timestamp('placed_at')->nullable()->after('currency');   // D4
$table->decimal('subtotal', 15, 2)->nullable()->after('total');
$table->decimal('discount_total', 15, 2)->default(0)->after('subtotal');
$table->decimal('shipping_total', 15, 2)->default(0)->after('discount_total');
$table->decimal('tax_total', 15, 2)->default(0)->after('shipping_total');
$table->boolean('tax_inclusive')->default(true)->after('tax_total');
$table->decimal('tax_rate', 6, 4)->nullable()->after('tax_inclusive');   // 0.1500
$table->decimal('refund_total', 15, 2)->default(0)->after('tax_rate');
$table->decimal('cod_amount', 15, 2)->nullable()->after('refund_total');
$table->string('financial_status', 32)->nullable()->after('status');
$table->string('fulfilment_channel', 32)->nullable()->after('financial_status'); // fbm|fba|platform|merchant
$table->char('base_currency', 3)->nullable()->after('currency');
$table->decimal('fx_rate_to_base', 18, 8)->nullable()->after('base_currency');
$table->boolean('financials_captured')->default(false)->after('fx_rate_to_base');

$table->index(['organization_id', 'placed_at'], 'px_orders_org_placed');
$table->index(['organization_id', 'financials_captured'], 'px_orders_capture');
```

`tax_inclusive` defaults **true** — KSA and UAE storefronts quote VAT-inclusive prices, and
Salla/Zid/Noon are the dominant channels for our first cohort. Shopify/Woo merchants outside the
Gulf get it flipped per store (§3.4 `stores.prices_include_vat`).

#### `order_items`

```php
$table->string('external_id', 191)->nullable()->after('order_id');   // D1
$table->foreignId('product_variant_id')->nullable()->after('sku')->constrained()->nullOnDelete();
$table->decimal('discount_total', 15, 2)->default(0)->after('price');
$table->decimal('tax_total', 15, 2)->default(0)->after('discount_total');
$table->boolean('tax_inclusive')->default(true)->after('tax_total');
$table->decimal('tax_rate', 6, 4)->nullable()->after('tax_inclusive');
$table->integer('refunded_quantity')->default(0)->after('quantity');
$table->decimal('cogs_unit_base', 15, 4)->nullable()->after('tax_rate');
$table->decimal('cogs_total_base', 15, 4)->nullable()->after('cogs_unit_base');
$table->string('cogs_method', 16)->nullable()->after('cogs_total_base');

$table->unique(['order_id', 'external_id'], 'ux_items_external');
$table->index(['order_id', 'sku'], 'px_items_sku');
```

> `ux_items_external` unblocks the `updateOrCreate` in `SyncOrdersJob` (D1). Because MySQL
> treats NULLs as distinct, lines with no external id still insert freely — acceptable, and the
> sync job is changed to fall back to `['order_id','sku','name']` when `external_id` is null.

#### `products` and `product_variants`

Read-cache only. The source of truth stays `product_costs` / `cost_layers`.

```php
// both tables
$table->decimal('current_unit_cost', 15, 4)->nullable();
$table->char('cost_currency', 3)->nullable();
$table->timestamp('cost_updated_at')->nullable();
$table->string('cost_method', 16)->nullable();
```

Maintained by `ProductCostObserver`. Lets the products list show a cost/margin column without a
join, which is the difference between a usable and an unusable cost-entry screen.

#### `organizations`

```php
$table->char('base_currency', 3)->default('SAR');
$table->decimal('default_vat_rate', 6, 4)->default(0.1500);   // KSA
$table->boolean('prices_include_vat')->default(true);
$table->string('cost_visibility_role', 16)->default('admin'); // minimum role that may see costs
$table->string('default_cost_method', 16)->default('fixed');
$table->boolean('allocate_ads_to_orders')->default(false);
```

#### `stores`

```php
$table->char('currency', 3)->nullable();              // channel settlement currency
$table->decimal('vat_rate', 6, 4)->nullable();        // override
$table->boolean('prices_include_vat')->nullable();    // override (null = inherit org)
$table->timestamp('settlements_synced_at')->nullable();
```

### 3.5 Fix migrations for pre-existing defects

Shipped in this spec because profit correctness depends on them (§3.2):

- **D1** — `order_items.external_id` added above; `SyncOrdersJob` corrected to write `name`
  (not `product_name`) and to include `external_id` in the `updateOrCreate` attributes.
- **D2** — `SyncOrdersJob` key changed to `['store_id' => $this->store->id, 'external_id' => ...]`.
- **D4** — `orders.placed_at` added and populated by the sync job from the platform payload.

### 3.6 Migration filenames

Following the repo convention (`2026_07_02_000004_...`):

```
database/migrations/
  2026_07_22_000001_add_profit_settings_to_organizations_table.php
  2026_07_22_000002_add_profit_settings_to_stores_table.php
  2026_07_22_000003_add_financial_columns_to_orders_table.php
  2026_07_22_000004_add_financial_columns_to_order_items_table.php
  2026_07_22_000005_add_cost_cache_to_products_tables.php
  2026_07_22_000006_create_fx_rates_table.php
  2026_07_22_000007_create_product_costs_table.php
  2026_07_22_000008_create_cost_layers_table.php
  2026_07_22_000009_create_cost_layer_consumptions_table.php
  2026_07_22_000010_create_order_fees_table.php
  2026_07_22_000011_create_fee_rules_table.php
  2026_07_22_000012_create_expenses_table.php
  2026_07_22_000013_create_expense_allocations_table.php
  2026_07_22_000014_create_ad_spend_table.php
  2026_07_22_000015_create_order_profits_table.php
  2026_07_22_000016_create_order_item_profits_table.php
  2026_07_22_000017_add_organization_id_to_orders_table.php        // nullable + index
  2026_07_22_000018_backfill_orders_organization_id.php            // data migration, chunked
  2026_07_23_000001_make_orders_organization_id_required.php       // contract step, ships later
```

Every `Schema::table` block uses the `Schema::hasColumn` guard already established in
`2026_05_06_090717_fix_orders_table_columns.php` and `2026_05_06_140000_add_image_and_sync_fields_to_tables.php`
**[verified]**, so `migrate:fresh` and an upgrade of a live database both work.

---

## 4. Domain logic

Notation: `B` = the organization's base currency (`organizations.base_currency`, default `SAR`).
All `_base` columns are in `B`. `r` = applicable VAT rate as a decimal (0.15 for KSA).

### 4.1 COGS valuation methods

| Method | Cost of a unit sold on date `d` | Where it comes from | Best for |
|---|---|---|---|
| `fixed` | `product_costs.landed_unit_cost_base` of the row where `valid_from <= d < coalesce(valid_to, ∞)` | manual entry / import | stable-price private label |
| `fifo` | consumed from `cost_layers` oldest-first (§4.3) | receipts, POs, imports | imported goods with volatile landed cost |
| `period` | average landed cost across the period containing `d`: `Σ(qty_received × unit_cost_base) / Σ qty_received` for layers with `acquired_at` in `[valid_from, period_end]` | computed nightly, cached on the `product_costs` row | merchants who buy in seasons |
| `batch` | `product_costs` row matching the line's `batch_ref` (resolved from `cost_layers.source_ref` when the layer is known, else the newest batch valid on `d`) | batch/lot tracking | perishables, cosmetics, pharma |

Method is resolved per SKU: `product_costs.method` for the most specific applicable row, falling
back to `organizations.default_cost_method`.

**Method changes are not retroactive.** Changing a SKU from `fixed` to `fifo` inserts a new
`product_costs` row with a new `valid_from`; orders before that date keep the cost that was
recognised at the time. Retroactive recalculation is an explicit, audited action
(`POST /api/analytics/profit/recalculate` with `from`), never a side effect of editing a cost.

### 4.2 Cost resolution order

`CostResolver::resolve(int $orgId, string $sku, ?int $storeId, CarbonInterface $at): ResolvedCost`

1. `product_costs` where `organization_id = $orgId` and `sku = $sku` and `store_id = $storeId`
   and `valid_from <= $at` — newest `valid_from`. *(per-store override)*
2. Same, with `store_id IS NULL`. *(org-wide)*
3. If the SKU resolves to a `product_variant` whose parent product has a variant-level cost, use it.
4. `fee_rules`-style estimated cost is **not** a thing — we never invent a COGS number.
   Instead return `ResolvedCost::missing()`, which sets `order_item_profits.is_estimated = true`,
   `order_profits.missing_cost = true`, `cogs_base = 0`, and surfaces the SKU in the coverage
   report (§5.5).

**SKU-less lines** (`order_items.sku IS NULL`, allowed by the schema, D5): resolution is skipped
and the line is counted in the coverage report as `unmatched_lines`.

Returning zero rather than guessing is deliberate. A zero COGS produces an obviously-too-good
margin that a merchant will question; a guessed COGS produces a plausible wrong number they will
trust. Sellerboard's accuracy complaints come from the second failure mode.

### 4.3 FIFO layer consumption

**Trigger.** COGS is recognised when the order reaches a *committed* state, not on creation. A
cart that never pays must not consume inventory cost. Committed = `orders.financial_status` in
`{paid, partially_paid}` **or** `orders.status` in `{shipped, fulfilled, delivered, completed}`.
The exact per-platform mapping lives in `OrderProfitCalculator::isCommitted()`; **[assumption]**
the status vocabulary today is whatever each platform sends (`SyncOrdersJob::mapOrderData` passes
`financial_status` through for Shopify, a Salla status name, and a lowercased Trendyol status
**[verified]**) — normalising it is a prerequisite task, listed in §14 dependencies.

**Algorithm** (`FifoLedger::consume(OrderItem $item)`), inside a DB transaction:

```
qty_needed  := item.quantity - item.refunded_quantity
recognised  := sum(qty) of existing non-reversed consumptions for this item
qty_needed  -= recognised
if qty_needed <= 0: return   // idempotent no-op

layers := SELECT * FROM cost_layers
          WHERE organization_id = ? AND sku = ?
            AND (store_id IS NULL OR store_id = ?)
            AND qty_remaining > 0
            AND acquired_at <= order.placed_at
          ORDER BY acquired_at ASC, id ASC
          FOR UPDATE

foreach layer in layers:
    take := min(qty_needed, layer.qty_remaining)
    insert cost_layer_consumptions {
        consumption_key: "sale:{item.id}:{layer.id}",
        qty: take,
        unit_cost_base: layer.unit_cost_base,
        amount_base: take * layer.unit_cost_base,
        reason: 'sale',
    }  ON DUPLICATE KEY UPDATE nothing      // idempotency
    layer.qty_remaining -= take
    qty_needed -= take
    if qty_needed == 0: break

if qty_needed > 0:                          // shortfall
    fallback := CostResolver.resolve(org, sku, store, order.placed_at)
    if fallback.missing:
        mark item missing_cost; return
    layer := create cost_layers {
        source: 'estimated', is_estimated: true,
        qty_received: qty_needed, qty_remaining: 0,
        unit_cost_base: fallback.landed_unit_cost_base,
        acquired_at: order.placed_at,
    }
    insert consumption for qty_needed against that layer
    mark item is_estimated
```

`FOR UPDATE` on the layer select is what makes concurrent order processing safe. Without it two
workers both read `qty_remaining = 5` and both consume 5. `SELECT ... FOR UPDATE` is a no-op on
sqlite (tests run serially), which is acceptable; the concurrency test (§11) runs against MySQL
in CI or is skipped with a documented reason.

**Reversal on refund** (`FifoLedger::reverse(OrderItem $item, int $qty, bool $restocked)`):

- Walk this item's consumptions **newest-first** (LIFO of consumption — the units most recently
  taken are the ones coming back).
- For each, insert `{ qty: -n, amount_base: -n * unit_cost_base, reason: restocked ? 'refund_restock' : 'refund_writeoff', reversal_of_id: original.id, consumption_key: "rev:{original.id}" }`.
- If `restocked` **and** the goods are sellable: `cost_layers.qty_remaining += n` on the original
  layer, so the stock returns to the pool at its original cost.
- If **not** restocked (damaged, customer keeps it, marketplace disposes): `qty_remaining` is
  **not** restored. The reversal still zeroes the item's COGS, and the same amount is booked to
  `order_profits.lost_cogs_base`. Net effect on profit is identical to never reversing — but the
  P&L now shows *why*, which is the whole point.

### 4.4 VAT handling

Per line, `r` resolves as: `order_items.tax_rate` → `orders.tax_rate` → `stores.vat_rate` →
`organizations.default_vat_rate`. `tax_inclusive` resolves as: `order_items.tax_inclusive` →
`orders.tax_inclusive` → `stores.prices_include_vat` → `organizations.prices_include_vat`.

```
gross_line          = order_items.price × order_items.quantity
line_after_discount = gross_line − order_items.discount_total

if tax_inclusive:
    net_line_ex_vat = line_after_discount / (1 + r)
    vat_line        = line_after_discount − net_line_ex_vat
else:
    net_line_ex_vat = line_after_discount
    vat_line        = net_line_ex_vat × r
```

Shipping charged to the customer is de-VAT'd identically using the order-level `r`.

**Rules that must hold everywhere:**

- VAT collected is never revenue and never profit. It is a liability.
- Fees are recorded **ex-VAT** when the marketplace reports them ex-VAT, and de-VAT'd on capture
  when reported inclusive. Each per-platform capture adapter declares which
  (`FeeCaptureInterface::feesIncludeVat(): bool`). Getting this wrong inflates costs by exactly
  15% in KSA — §11 has a test.
- COGS is ex-VAT (input VAT is reclaimable for a registered merchant).
- **[assumption]** we treat every merchant as VAT-registered. Non-registered merchants (below the
  SAR 375,000 threshold) should book input VAT as a cost. Flagged in §15.

### 4.5 Multi-currency / FX

- Each organization has one `base_currency` **[new]**. Every profit figure is stored and reported
  in it.
- **Rates are frozen at the event.** `orders.fx_rate_to_base` is stamped when the order is
  captured, `cost_layers.fx_rate_to_base` at acquisition, `order_fees.fx_rate_to_base` at posting.
  A P&L for March never changes because the lira moved in July.
- Rate lookup: `FxConverter::rate(string $from, string $to, CarbonInterface $on)` →
  `fx_rates` exact date → most recent prior date within 7 days → `1.0` with a logged warning and
  `is_estimated` set on the consuming record.
- Same-currency short circuit: `from === to` returns `1.0` without a query. For a single-currency
  SAR merchant — the majority of our first cohort — the FX layer costs nothing.
- **[assumption]** `fx_rates` is populated by a daily job from a free source (ECB or
  exchangerate.host). No provider is currently configured in `config/services.php` **[verified]** —
  choosing and configuring one is a dependency (§14). Until then rows are entered manually and
  the `1.0` fallback applies.
- Marketplace-reported settlement amounts are stored in the **settlement currency** with the rate
  the marketplace itself used when it supplies one (Amazon settlement reports do; §6.3). A
  marketplace's own rate always beats ours.

### 4.6 Profit formulas

Written out in full. These are the definitions the tests in §11 assert against.

**Per order item `i`:**

```
net_revenue(i)      = net_line_ex_vat(i)                                    // §4.4
cogs(i)             = Σ cost_layer_consumptions.amount_base for i           // FIFO
                    | quantity(i) × resolved_landed_unit_cost_base          // fixed/period/batch
direct_fees(i)      = Σ order_fees.amount_base
                        WHERE order_item_id = i AND type ∉ {tax, discount}

allocation_weight(i)= net_revenue(i) / Σ_j net_revenue(j)                   // over the order
allocated_fees(i)   = allocation_weight(i) × Σ order_fees.amount_base
                        WHERE order_item_id IS NULL AND type ∉ {tax, discount}

ad_spend(i)         = order-attributed ad spend × allocation_weight(i)      // usually 0

net_profit(i)       = net_revenue(i) − cogs(i) − direct_fees(i)
                      − allocated_fees(i) − ad_spend(i)

margin_pct(i)       = net_revenue(i) > 0 ? net_profit(i) / net_revenue(i) : null
```

If `Σ_j net_revenue(j) = 0` (fully discounted order), `allocation_weight` falls back to
`quantity(i) / Σ_j quantity(j)`. Division by zero here is the second most likely bug; tested.

**Per order `o`:**

```
gross_revenue(o)    = Σ_i price(i) × quantity(i)
discounts(o)        = Σ_i discount_total(i) + orders.discount_total
shipping_revenue(o) = de_vat(orders.shipping_total)
net_revenue(o)      = Σ_i net_revenue(i) + shipping_revenue(o)
vat(o)              = Σ_i vat_line(i) + vat_on_shipping(o)

cogs(o)             = Σ_i cogs(i)
total_fees(o)       = Σ order_fees.amount_base WHERE order_id = o
                                             AND type ∉ {tax, discount}
ad_spend(o)         = order-attributed ad spend (0 unless the channel attributes per order)

refund_revenue(o)   = de_vat(Σ refunded line amounts)
refund_cogs(o)      = Σ |amount_base| of reversals with reason = 'refund_restock'
lost_cogs(o)        = Σ |amount_base| of reversals with reason = 'refund_writeoff'

net_profit(o)       = net_revenue(o)
                      − cogs(o)
                      − total_fees(o)
                      − ad_spend(o)
                      − refund_revenue(o)
                      + refund_cogs(o)          // COGS recovered → add back
                                                // lost_cogs is NOT added back, by construction

margin_pct(o)       = net_revenue(o) > 0 ? net_profit(o) / net_revenue(o) : null
```

Note `refund_cogs` is **added**: the revenue reversal already removed the sale, so leaving the
COGS charged would double-penalise a restocked return.

**Per SKU, over period `[a, b]`:**

```
net_profit(sku)     = Σ order_item_profits.net_profit_base
                      WHERE sku = ? AND placed_on BETWEEN a AND b
                      − Σ ad_spend.spend_base WHERE sku = ? AND date BETWEEN a AND b
                                                AND not already order-attributed
units(sku)          = Σ order_item_profits.quantity
profit_per_unit     = net_profit(sku) / units(sku)
```

**Per channel (store), over `[a, b]`:**

```
net_profit(store)   = Σ order_profits.net_profit_base WHERE store_id = ? AND placed_on ∈ [a,b]
                      − Σ ad_spend.spend_base WHERE store_id = ? AND date ∈ [a,b]
                                                AND orders_attributed IS NULL
                      − allocated_expenses(store, a, b)

allocated_expenses(store) =
      Σ expense_allocations.amount_base WHERE store_id = ?          // pinned
    + Σ expense_allocations.amount_base WHERE store_id IS NULL
        × share(store)                                              // by allocation_method
```

`share(store)` = that store's fraction of org net revenue / orders / units in the period,
per `expenses.allocation_method`. `allocation_method = 'none'` means the expense appears only in
the org-level P&L and is never pushed down to a channel.

**Period P&L (the statement in §7.1):**

```
Gross revenue                              Σ order_profits.gross_revenue_base
− Discounts                                Σ discounts_base
= Gross sales
− VAT collected                            Σ vat_base
= Net revenue (ex-VAT)                     Σ net_revenue_base
− COGS                                     Σ cogs_base
= Gross profit
− Marketplace commission                   fees_by_type.commission
− Fulfilment                               fees_by_type.fulfilment
− Shipping                                 fees_by_type.shipping
− Payment processing                       fees_by_type.payment
− Storage                                  fees_by_type.storage
− Refund fees                              fees_by_type.refund
− Other fees                               fees_by_type.other
= Contribution margin
− Advertising                              Σ ad_spend.spend_base (period)
− Refunded revenue                         Σ refund_revenue_base
+ Recovered COGS                           Σ refund_cogs_base
= Operating profit before expenses
− Operating expenses                       Σ expense_allocations.amount_base (period)
= NET PROFIT
```

Every line is clickable in the UI and resolves to the underlying rows. A P&L nobody can drill
into is a P&L nobody believes.

### 4.7 Refund and return accounting

Three distinct cases, and they produce three different numbers:

| Case | Revenue | COGS | Fees |
|---|---|---|---|
| Refund, goods returned and resellable | reversed | reversed, stock returns to layer at original cost | commission usually refunded (credit line, negative amount); refund administration fee charged (positive) |
| Refund, goods returned damaged / written off | reversed | **not** reversed → `lost_cogs_base` | same |
| Refund, no return (goodwill / marketplace-issued) | reversed | **not** reversed → `lost_cogs_base` | same |

Fee treatment: a marketplace refund produces new `order_fees` rows, not edits to existing ones —
a credit is `type='commission', amount = -12.50, subtype='referral_refund'`, and the admin charge
is `type='refund', amount = +2.50`. Never mutate a captured fee; the ledger must reconstruct.

**Partial refunds** are supported at line granularity via `order_items.refunded_quantity`. A
partial refund reverses `refunded_quantity` units of COGS, not the whole line.

---

## 5. Backend

Namespace convention follows the repo: controllers in `app/Http/Controllers` (flat, no `Api`
subdirectory) **[verified]**; models in `app/Models`; jobs in `app/Jobs`; integration code in
`app/Services/Integrations`.

### 5.1 Models — `app/Models/`

| Model | Table | Key relationships / casts |
|---|---|---|
| `ProductCost` | `product_costs` | `belongsTo` Organization, ProductVariant, Product, Store, User(`createdBy`); `SoftDeletes`; casts `valid_from`/`valid_to` → `date`, money → `decimal:4` |
| `CostLayer` | `cost_layers` | `belongsTo` Organization, ProductVariant, Store; `hasMany` CostLayerConsumption; cast `acquired_at` → `datetime` |
| `CostLayerConsumption` | `cost_layer_consumptions` | `belongsTo` CostLayer, Order, OrderItem, `reversalOf` (self) |
| `OrderFee` | `order_fees` | `belongsTo` Order, OrderItem, Store, Organization; casts `raw_data` → `array`, `posted_at` → `datetime`, `is_estimated` → `bool`; const `TYPES` |
| `FeeRule` | `fee_rules` | `belongsTo` Organization, Store, Category; scope `matching()` |
| `Expense` | `expenses` | `belongsTo` Organization, Store; `hasMany` ExpenseAllocation; `SoftDeletes` |
| `ExpenseAllocation` | `expense_allocations` | `belongsTo` Expense |
| `AdSpend` | `ad_spend` | `belongsTo` Organization, Store; `protected $table = 'ad_spend'` (Laravel would pluralise to `ad_spends`) |
| `FxRate` | `fx_rates` | none |
| `OrderProfit` | `order_profits` | `belongsTo` Order, Store, Organization; cast `fees_by_type` → `array` |
| `OrderItemProfit` | `order_item_profits` | `belongsTo` OrderItem, Order, Store |

**Additions to existing models:**

```php
// app/Models/Order.php — add to $fillable
'organization_id', 'placed_at', 'subtotal', 'discount_total', 'shipping_total',
'tax_total', 'tax_inclusive', 'tax_rate', 'refund_total', 'cod_amount',
'financial_status', 'fulfilment_channel', 'base_currency', 'fx_rate_to_base',
'financials_captured',

// $casts
'placed_at' => 'datetime', 'tax_inclusive' => 'boolean',
'financials_captured' => 'boolean',

// relationships
public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
public function fees(): HasMany { return $this->hasMany(OrderFee::class); }
public function profit(): HasOne { return $this->hasOne(OrderProfit::class); }
```

```php
// app/Models/OrderItem.php — add to $fillable
'external_id', 'product_variant_id', 'discount_total', 'tax_total', 'tax_inclusive',
'tax_rate', 'refunded_quantity', 'cogs_unit_base', 'cogs_total_base', 'cogs_method',

public function fees(): HasMany { return $this->hasMany(OrderFee::class); }
public function consumptions(): HasMany { return $this->hasMany(CostLayerConsumption::class); }
public function profit(): HasOne { return $this->hasOne(OrderItemProfit::class); }
public function variant(): BelongsTo { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
```

```php
// app/Models/ProductVariant.php and Product.php
'current_unit_cost', 'cost_currency', 'cost_updated_at', 'cost_method',   // $fillable
public function costs(): HasMany { return $this->hasMany(ProductCost::class); }
```

```php
// app/Models/Organization.php
'base_currency', 'default_vat_rate', 'prices_include_vat',
'cost_visibility_role', 'default_cost_method', 'allocate_ads_to_orders',
public function expenses(): HasMany { ... }
public function productCosts(): HasMany { ... }
```

**Observers** — `app/Observers/ProductCostObserver.php`, registered in `AppServiceProvider::boot()`
(the repo has no `EventServiceProvider`; Laravel 12 auto-discovers, but explicit registration is
clearer here):

- `saving`: recompute `landed_unit_cost` and `landed_unit_cost_base`.
- `saved`: close the previous row's `valid_to`; refresh the `product_variants` / `products` cost
  cache; dispatch `ProductCostChanged`.

### 5.2 Services — `app/Services/Profit/`

New namespace. Existing service code lives under `app/Services` (`EdfaPayService`) and
`app/Services/Integrations` **[verified]**, so a `Profit` sub-namespace fits.

| Class | Responsibility | Key method |
|---|---|---|
| `CostResolver` | resolve landed unit cost for (org, sku, store, date) | `resolve(): ResolvedCost` |
| `ResolvedCost` | value object: `landedUnitCostBase`, `method`, `currency`, `isMissing`, `isEstimated`, `sourceId` | — |
| `FifoLedger` | consume / reverse layers | `consume(OrderItem)`, `reverse(OrderItem, int $qty, bool $restocked)` |
| `VatCalculator` | inclusive/exclusive split, per-line resolution of `r` | `split(float $amount, float $rate, bool $inclusive): [net, vat]` |
| `FxConverter` | rate lookup + conversion, frozen-rate semantics | `rate()`, `toBase()` |
| `FeeEstimator` | apply `fee_rules` where the API gave nothing | `estimate(Order): Collection<OrderFee>` |
| `FeeNormaliser` | map a platform fee payload → `OrderFee` attributes, de-VAT if needed | `normalise(array, Store): array` |
| `OrderProfitCalculator` | the whole per-order computation → `order_profits` + `order_item_profits` | `calculate(Order): OrderProfit` |
| `ExpenseAmortizer` | expand `expenses` → `expense_allocations` for a date range | `amortize(Organization, $from, $to)` |
| `ProfitReportService` | all read queries for §5.5 endpoints | `summary()`, `timeline()`, `bySku()`, `byChannel()`, `coverage()` |
| `SettlementImporter` | CSV settlement/statement ingestion | `import(Store, UploadedFile): ImportResult` |
| `CostImporter` | CSV cost import | `import(Organization, UploadedFile): ImportResult` |

`OrderProfitCalculator::calculate()` runs inside one transaction and is **idempotent**: re-running
it on an unchanged order produces an identical `order_profits` row (same `calc_version`) and
inserts no new consumptions.

### 5.3 Jobs — `app/Jobs/`

Following the existing pattern (`implements ShouldQueue`, `use Dispatchable, InteractsWithQueue, Queueable, SerializesModels`, writes a `SyncLog`, creates a `Notification` on failure) **[verified]** from `SyncOrdersJob`.

| Job | Trigger | Notes |
|---|---|---|
| `CalculateOrderProfitJob(Order $order)` | `OrderSynced`, `OrderFeesCaptured`, `OrderRefunded` | `ShouldBeUnique` on `order_id` for 60 s to collapse sync storms |
| `SyncSettlementsJob(?Store $store)` | scheduled hourly; null store fans out like `SyncOrdersJob` | writes `SyncLog(type: 'settlements')` |
| `RecalculateProfitJob(int $orgId, $from, $to)` | manual endpoint, cost change, fee-rule change | chunks 500 orders, dispatches `CalculateOrderProfitJob` per chunk via `Bus::batch` |
| `AmortizeExpensesJob` | scheduled daily 00:15 | idempotent via `ux_expense_day` |
| `BackfillOrderFinancialsJob(int $orgId, $from, $to)` | artisan command | parses `orders.raw_data`, §12 |
| `SyncAdSpendJob(?Store $store)` | scheduled daily; no-op in v1 | placeholder for Ads API |

`routes/console.php` additions (matching the existing `Schedule::job(...)` style **[verified]**):

```php
Schedule::job(new SyncSettlementsJob)->hourly();
Schedule::job(new AmortizeExpensesJob)->dailyAt('00:15');
Schedule::job(new SyncAdSpendJob)->dailyAt('01:00');
```

### 5.4 Events — `app/Events/` (new directory)

`OrderSynced`, `OrderRefunded`, `OrderFeesCaptured`, `ProductCostChanged`, `CostLayersDepleted`.
Listeners in `app/Listeners/` dispatch the recalculation jobs. `CostLayersDepleted` feeds a
`Notification` — the existing notification table and `NotificationController` are reused
**[verified]**.

**Integration interface — do not break `IntegrationServiceInterface`.** It has 9 methods
implemented by all 7 services **[verified]**. Adding a 10th breaks every one, including the two
stubs. Instead:

```php
// app/Services/Integrations/SettlementCapableInterface.php
interface SettlementCapableInterface
{
    /** @return array raw settlement/fee records */
    public function fetchSettlements(Store $store, array $params = []): array;

    /** Does this platform report fee amounts VAT-inclusive? */
    public function feesIncludeVat(): bool;
}
```

Implemented only by services that can. `FeeCaptureFactory` mirrors `IntegrationFactory`'s `match`
**[verified]** and returns a per-platform `FeeCapture` adapter, falling back to
`NullFeeCapture` (which emits nothing and lets `FeeEstimator` take over):

```php
// app/Services/Integrations/Fees/FeeCaptureFactory.php
return match (strtolower($platform)) {
    'shopify'  => new ShopifyFeeCapture(),
    'amazon'   => new AmazonFeeCapture(),
    'trendyol' => new TrendyolFeeCapture(),
    'salla'    => new SallaFeeCapture(),
    'noon'     => new NoonFeeCapture(),
    'woocommerce' => new WooCommerceFeeCapture(),
    'zid'      => new ZidFeeCapture(),
    default    => new NullFeeCapture(),
};
```

### 5.5 API endpoints

All new routes go **inside** the existing `Route::middleware('auth:sanctum')` →
`Route::middleware('org.member')` group in `routes/api.php` **[verified]**, plus a new
`cost.access` middleware on everything that exposes cost or profit figures.

Auth on every endpoint: Sanctum bearer token + `X-Organization-Id` header (validated by
`EnsureOrganizationMember` **[verified]**) + role check (§9).

```php
Route::middleware(['org.member', 'feature:profit_engine'])->group(function () {

    // ---- Costs (cost.access) ----
    Route::middleware('cost.access')->group(function () {
        Route::get   ('/costs',                 [ProductCostController::class, 'index']);
        Route::post  ('/costs',                 [ProductCostController::class, 'store']);
        Route::get   ('/costs/template',        [ProductCostController::class, 'template']);
        Route::post  ('/costs/import',          [ProductCostController::class, 'import']);
        Route::get   ('/costs/{sku}/history',   [ProductCostController::class, 'history']);
        Route::put   ('/costs/{id}',            [ProductCostController::class, 'update']);
        Route::delete('/costs/{id}',            [ProductCostController::class, 'destroy']);

        Route::get   ('/cost-layers',           [CostLayerController::class, 'index']);
        Route::post  ('/cost-layers',           [CostLayerController::class, 'store']);
        Route::post  ('/cost-layers/{id}/adjust',[CostLayerController::class, 'adjust']);

        Route::get   ('/expenses',              [ExpenseController::class, 'index']);
        Route::post  ('/expenses',              [ExpenseController::class, 'store']);
        Route::put   ('/expenses/{id}',         [ExpenseController::class, 'update']);
        Route::delete('/expenses/{id}',         [ExpenseController::class, 'destroy']);

        Route::get   ('/ad-spend',              [AdSpendController::class, 'index']);
        Route::post  ('/ad-spend',              [AdSpendController::class, 'store']);
        Route::post  ('/ad-spend/import',       [AdSpendController::class, 'import']);
        Route::delete('/ad-spend/{id}',         [AdSpendController::class, 'destroy']);

        Route::get   ('/fee-rules',             [FeeRuleController::class, 'index']);
        Route::post  ('/fee-rules',             [FeeRuleController::class, 'store']);
        Route::put   ('/fee-rules/{id}',        [FeeRuleController::class, 'update']);
        Route::delete('/fee-rules/{id}',        [FeeRuleController::class, 'destroy']);

        Route::get   ('/orders/{id}/fees',      [OrderFeeController::class, 'index']);
        Route::post  ('/orders/{id}/fees',      [OrderFeeController::class, 'store']);
        Route::put   ('/order-fees/{id}',       [OrderFeeController::class, 'update']);
        Route::delete('/order-fees/{id}',       [OrderFeeController::class, 'destroy']);
        Route::post  ('/order-fees/import',     [OrderFeeController::class, 'import']);

        // ---- Reporting ----
        Route::get ('/analytics/profit',            [ProfitController::class, 'summary']);
        Route::get ('/analytics/profit/timeline',   [ProfitController::class, 'timeline']);
        Route::get ('/analytics/profit/by-sku',     [ProfitController::class, 'bySku']);
        Route::get ('/analytics/profit/by-channel', [ProfitController::class, 'byChannel']);
        Route::get ('/analytics/profit/coverage',   [ProfitController::class, 'coverage']);
        Route::get ('/analytics/profit/export',     [ProfitController::class, 'export']);
        Route::post('/analytics/profit/recalculate',[ProfitController::class, 'recalculate']);
        Route::get ('/orders/{id}/profit',          [ProfitController::class, 'order']);
    });
});
```

> **Route-ordering note:** `/orders/export` is already registered before `/orders/{id}`
> **[verified]**. `/orders/{id}/fees` and `/orders/{id}/profit` are deeper paths and do not
> collide. `/costs/template` **must** stay above `/costs/{id}` — it would otherwise be captured
> as an id.

#### Endpoint contracts

**`POST /api/costs`** — create or supersede a cost.

Request:
```json
{
  "sku": "TSHIRT-BLK-M",
  "store_id": null,
  "method": "fixed",
  "unit_cost": 42.5,
  "freight_cost": 3.25,
  "duty_cost": 2.10,
  "prep_cost": 1.00,
  "other_cost": 0,
  "currency": "SAR",
  "valid_from": "2026-07-01",
  "batch_ref": null,
  "note": "Supplier A, PO-1043"
}
```

Validation:
```php
'sku'          => ['required', 'string', 'max:191'],
'store_id'     => ['nullable', 'integer', Rule::exists('stores','id')->where('organization_id', $orgId)],
'method'       => ['required', Rule::in(['fixed','fifo','period','batch'])],
'unit_cost'    => ['required', 'numeric', 'min:0'],
'freight_cost' => ['nullable', 'numeric', 'min:0'],
'duty_cost'    => ['nullable', 'numeric', 'min:0'],
'prep_cost'    => ['nullable', 'numeric', 'min:0'],
'other_cost'   => ['nullable', 'numeric', 'min:0'],
'currency'     => ['required', 'string', 'size:3'],
'valid_from'   => ['required', 'date'],
'period_end'   => ['nullable', 'date', 'after:valid_from', 'required_if:method,period'],
'batch_ref'    => ['nullable', 'string', 'max:64', 'required_if:method,batch'],
'note'         => ['nullable', 'string', 'max:255'],
```

Response `201`:
```json
{
  "message": "Cost saved.",
  "cost": {
    "id": 91, "sku": "TSHIRT-BLK-M", "store_id": null, "method": "fixed",
    "unit_cost": "42.5000", "freight_cost": "3.2500", "duty_cost": "2.1000",
    "prep_cost": "1.0000", "other_cost": "0.0000",
    "landed_unit_cost": "48.8500", "landed_unit_cost_base": "48.8500",
    "currency": "SAR", "valid_from": "2026-07-01", "valid_to": null,
    "source": "manual", "created_at": "..."
  },
  "affected_orders": 128,
  "recalculation_queued": true
}
```

Side effect: dispatches `RecalculateProfitJob` for orders with `placed_at >= valid_from`.
`affected_orders` is the count, so the UI can warn before a large recompute.

**`GET /api/costs?search=&store_id=&missing_only=&per_page=`** — paginated, matching the
`ProductController::index` paginate style **[verified]**. `missing_only=1` returns SKUs seen in
orders with no resolvable cost — the onboarding worklist.

**`POST /api/costs/import`** — `multipart/form-data`, field `file` (`required|file|mimes:csv,txt|max:5120`),
optional `dry_run` boolean. Columns: `sku,store,method,unit_cost,freight_cost,duty_cost,prep_cost,other_cost,currency,valid_from,batch_ref,note`.

Response `200`:
```json
{
  "dry_run": true,
  "rows": 1240, "created": 1190, "updated": 12, "skipped": 38,
  "errors": [{ "row": 17, "sku": "X-1", "message": "unit_cost must be numeric" }]
}
```

**`POST /api/orders/{id}/fees`** — manual fee line.

```json
{ "type": "payment", "subtype": "mada", "amount": 4.75, "currency": "SAR",
  "order_item_id": null, "posted_at": "2026-07-14T10:00:00Z" }
```
Validation: `type` in `OrderFee::TYPES`, `amount` `required|numeric`, `order_item_id` must belong
to the order, `currency` `size:3`. Response `201` with the fee and
`{"profit_recalculated": true}`.

**`GET /api/analytics/profit?start_date=&end_date=&store_id=&channel=`**

Response `200`:
```json
{
  "currency": "SAR",
  "period": { "start": "2026-06-01", "end": "2026-06-30" },
  "statement": {
    "gross_revenue": 412300.00, "discounts": 18400.00, "gross_sales": 393900.00,
    "vat_collected": 51378.26, "net_revenue": 342521.74,
    "cogs": 158900.10, "gross_profit": 183621.64,
    "fees": {
      "commission": 41230.00, "fulfilment": 12800.00, "shipping": 9400.00,
      "payment": 6180.00, "storage": 1200.00, "refund": 890.00, "other": 320.00
    },
    "total_fees": 72020.00,
    "contribution_margin": 111601.64,
    "advertising": 24500.00,
    "refunded_revenue": 11200.00, "recovered_cogs": 4300.00,
    "operating_profit": 80201.64,
    "expenses": 18000.00,
    "net_profit": 62201.64,
    "margin_pct": 0.1816
  },
  "comparison": { "net_profit": 51100.20, "margin_pct": 0.1602, "change_pct": 21.7 },
  "coverage": { "orders": 1840, "with_actual_fees": 1502, "estimated": 338,
                "missing_cost_lines": 41, "confidence": "medium" }
}
```

`comparison` uses the same prior-equal-length-period logic as
`AnalyticsController::dashboard`, including its `$prevOrders >= 3` baseline guard **[verified]** —
that guard exists for a good reason and should not be reinvented differently here.

**`GET /api/analytics/profit/by-sku?start_date=&end_date=&sort=net_profit&direction=desc&per_page=25`**

```json
{
  "data": [{
    "sku": "TSHIRT-BLK-M", "name": "Black Tee / M", "product_id": 44,
    "units": 312, "net_revenue": 28080.00, "cogs": 15241.20,
    "fees": 3902.00, "ad_spend": 1100.00,
    "net_profit": 7836.80, "margin_pct": 0.2791,
    "profit_per_unit": 25.12, "is_estimated": false
  }],
  "links": {...}, "meta": {...}
}
```

**`GET /api/orders/{id}/profit`** — the waterfall for one order.

```json
{
  "order_id": 8821, "external_id": "1002", "placed_at": "2026-06-14T09:12:00Z",
  "store": { "id": 3, "name": "Salla Main", "platform": "salla" },
  "currency": "SAR", "is_estimated": true, "estimated_share": 0.31,
  "waterfall": [
    { "key": "gross_revenue", "amount": 299.00, "sign": "+" },
    { "key": "discounts",     "amount": 20.00,  "sign": "-" },
    { "key": "vat",           "amount": 36.39,  "sign": "-" },
    { "key": "cogs",          "amount": 96.00,  "sign": "-", "estimated": false },
    { "key": "commission",    "amount": 0.00,   "sign": "-" },
    { "key": "payment",       "amount": 7.85,   "sign": "-", "estimated": true },
    { "key": "shipping",      "amount": 18.00,  "sign": "-" },
    { "key": "net_profit",    "amount": 120.76, "sign": "=" }
  ],
  "items": [{ "order_item_id": 19022, "sku": "TSHIRT-BLK-M", "quantity": 2,
              "net_revenue": 242.61, "cogs": 96.00, "direct_fees": 0.00,
              "allocated_fees": 25.85, "net_profit": 120.76, "margin_pct": 0.4977 }],
  "fees": [{ "id": 5501, "type": "payment", "subtype": "mada", "amount": 7.85,
             "is_estimated": true, "source": "rule" }]
}
```

**`POST /api/analytics/profit/recalculate`**

```json
{ "start_date": "2026-01-01", "end_date": "2026-06-30", "store_id": null, "reason": "cost import" }
```
Validation: range max 366 days, `start_date` `required|date`, `end_date` `required|date|after_or_equal:start_date`.
Response `202`: `{ "message": "Recalculation queued.", "orders": 4210, "batch_id": "9c1..." }`.
Owner/admin only.

**`GET /api/analytics/profit/coverage`** — the honesty endpoint. Powers the data-quality banner.

```json
{
  "skus_total": 812, "skus_with_cost": 640, "skus_missing_cost": 172,
  "orders_total": 1840, "orders_with_actual_fees": 1502, "orders_estimated_fees": 338,
  "unmatched_lines": 12,
  "by_platform": [{ "platform": "salla", "orders": 900, "actual_fee_coverage": 0.12 }],
  "confidence": "medium",
  "top_missing_skus": [{ "sku": "X-1", "units_sold": 240, "revenue": 18000.00 }]
}
```

Error envelope: Laravel's default `{"message": "..."}` / `422` with `errors` — matching the rest
of the API **[verified]**. `402` from `CheckSubscription` and `403` from role checks.

---

## 6. Per-platform fee capture

Honesty policy for this section: **verified** = read in this repo or in official public API
docs I can name; **unverified** = plausible from the platform's model but not confirmed against
a live merchant account. Anything unverified must be validated in a sandbox before its adapter
is written, and until then that platform runs on `fee_rules` + manual entry.

Every platform, regardless of API coverage, gets three things: (a) whatever the order payload
already gives us — parsed from `orders.raw_data`, which we **already store** **[verified]**;
(b) a CSV settlement/statement importer; (c) `fee_rules` estimation with an `is_estimated` badge.

### 6.1 Shopify

**Current code:** `ShopifyService` calls REST `2024-01` `orders.json`, `products.json`,
`inventory_levels.json`, `locations.json`, `fulfillments.json` **[verified]**. Requested scopes
are exactly `read_orders,write_orders,read_products,write_products,read_inventory,write_inventory`
**[verified]** — note what is missing below.

**Exposed (verified, already in `raw_data`):** `total_price`, `subtotal_price`, `total_tax`,
`total_discounts`, `tax_lines[]` (rate + price), `shipping_lines[]` (price, discounted_price),
`line_items[].price/quantity/sku/total_discount/tax_lines`, `refunds[]` with
`refund_line_items[]` (incl. `restock_type`) and `transactions[]`, `current_total_price`.
This alone fills `orders.subtotal/discount_total/shipping_total/tax_total/refund_total` and
per-item tax/discount **for every Shopify order we have already synced** — no new API call.

**Exposed (verified, needs a new call + a new scope):** Shopify Payments fees.
`GET /admin/api/2024-01/shopify_payments/balance/transactions.json` returns `fee`, `net`,
`amount`, `source_order_id`, `payout_id` — this is the authoritative payment-processing fee and
maps 1:1 to `order_fees{type: payment, source: settlement, settlement_id: payout_id}`.
Also `GET /admin/api/2024-01/orders/{id}/transactions.json` exposes a `receipt` object that
carries the processing fee for Shopify Payments orders.
**Blocker:** both require the `read_shopify_payments_payouts` scope, which the current
`getAuthUrl()` scope string does **not** request **[verified]**. Adding it forces every connected
merchant to re-authorise. Plan: add the scope, and prompt for re-auth in the store settings UI
with a clear reason.

**Not exposed:** third-party gateway fees. In our market most Shopify merchants use Tap, HyperPay,
PayTabs, Moyasar or Mada rails, not Shopify Payments — those fees appear nowhere in the Shopify
API. Also not exposed: Shopify's own subscription/app charges per order, and 3PL fulfilment cost.

**Fallback:** `fee_rules` — `payment / percent_of_order` (e.g. 2.75% + SAR 1.00 for Mada) plus a
manual gateway statement CSV. Merchant configures once in the cost settings screen.

**Confidence: high.**

### 6.2 Salla

**Current code:** `SallaService` hits `https://api.salla.dev/admin/v2/orders` and `/products`
**[verified]**. `SyncOrdersJob::mapOrderData` already reads `$data['amounts']['total']['amount']`
and `['currency']` **[verified]**, which confirms the `amounts` envelope exists in the payloads
we receive.

**Exposed (verified from our own mapper + the `amounts` shape):** `amounts.sub_total`,
`amounts.shipping_cost`, `amounts.cash_on_delivery`, `amounts.discount`, `amounts.tax`,
`amounts.total` — each `{amount, currency}`. That fills the order financial columns and gives us
`cod_amount` for free, which nobody else in the market models.

**Not exposed:** Salla's merchant economics are subscription-based, not per-order commission, so
there is generally no commission to capture — the absence is correct, not a gap. Payment
processing fees (Salla Pay / Tap) and shipping-carrier cost as billed to the merchant are
**[unverified]** as per-order API fields; the public Admin API v2 order resource is not documented
to expose a merchant-side fee breakdown.

**Fallback:** `fee_rules` for payment % and a flat COD handling fee; carrier cost from a manual
shipping statement CSV. Salla's shipping companies bill the merchant directly, so a CSV importer
here is genuinely the right answer, not a cop-out.

**Confidence: medium — order totals verified, fee lines unverified.**

### 6.3 Amazon (SP-API)

The one platform where we can be genuinely excellent, and the one requiring the most work.

**Current code:** `AmazonService` implements LWA token exchange/refresh, `/orders/v0/orders`,
`/fba/inventory/v1/summaries`, and a Listings PATCH **[verified]**. Its own docblock states that
**AWS SigV4 signing is not implemented** and must be added before production SP-API calls
**[verified]** — that is a hard prerequisite for everything below.

**Exposed (verified, official SP-API):**
- **Finances API** `GET /finances/v0/financialEvents` (and
  `/finances/v0/orders/{orderId}/financialEvents`) returns `ShipmentEventList` with
  `ItemFeeList[]` and `ShipmentFeeList[]` — `FeeType` values including `Commission`,
  `FBAPerUnitFulfillmentFee`, `ShippingHB`, `GiftwrapChargeback`, `VariableClosingFee` — plus
  `RefundEventList` (with `FeeComponent` reversals and `RefundCommission`),
  `ServiceFeeEventList` (storage, long-term storage, subscription),
  `AdjustmentEventList` (reimbursements — negative fees), and `ProductAdsPaymentEventList`.
  This is a near-complete per-order fee picture and maps directly onto `order_fees`.
- **Reports API** settlement reports `GET_V2_SETTLEMENT_REPORT_DATA_FLAT_FILE_V2` — the
  authoritative payout reconciliation, including Amazon's own FX rate for cross-currency
  settlements. Use it to reconcile and to correct anything the Finances API missed.
- `GET_FBA_STORAGE_FEE_CHARGES_DATA` — monthly storage per ASIN → allocate to SKU as an
  `advertising`-style period cost (`order_fees` is per-order, so storage lands in `expenses`
  with `allocation_method='units'` unless it can be attributed per SKU, in which case it goes to
  `ad_spend`-style SKU-period costs — **decision: storage is an `expenses` row with
  `category='logistics'`, allocated by units**).
- `GET_FBA_ESTIMATED_FBA_FEES_TXT_DATA` — forward-looking fee estimates, useful for pricing but
  **not** for historical P&L.

**Not exposed by SP-API:** advertising spend. Amazon Ads is a **separate API with separate
credentials and a separate developer application**. `ProductAdsPaymentEventList` in Finances
gives the aggregate charge, not per-campaign or per-keyword detail. v1 uses that aggregate; the
Ads API is out of scope (§2).

**Constraints to design around:** Finances API date-window and rate limits (aggressive; the job
must page and back off), settlement report retention (roughly 2 years), and the fact that a fee
can post days after the order — hence `order_fees.posted_at` being separate from the order date,
and `RecalculateProfitJob` re-running on settlement arrival.

**Fallback:** none needed for connected accounts. For orders predating the connection, settlement
report backfill, then `fee_rules` (referral % by category) for anything older.

**Confidence: high on API capability, low on our current readiness — SigV4 is a blocker.**

### 6.4 Noon

**Current code:** `NoonService` is fully config-driven with **placeholder defaults** —
`services.noon.base_url` defaults to `https://api.noon.partners` and endpoints are `/v1/orders`,
`/v1/products`, `/v1/products/{sku}/stock` **[verified]**. The class docblock itself says the
hosts must be filled in from a real noon partner account **[verified]**.

**Honest status: the noon endpoints in this repo are assumed, not verified.** I will not
pretend to know noon's fee payload shape. What is known about noon's model: it charges a
category-based commission, plus fulfilment fees that differ between FBN (fulfilled by noon) and
FBP (fulfilled by partner), plus payment/COD handling. Merchants receive periodic statements and
tax invoices in Seller Lab.

**Plan:**
1. Ingest whatever `/v1/orders` returns into `raw_data` (already happens) and write a mapper only
   after inspecting a real payload.
2. Ship the **statement CSV importer first** for noon — it is the only path we can guarantee.
   `SettlementImporter` with a noon column profile, mapping commission / fulfilment / payment /
   COD lines to `order_fees` matched by order reference.
3. `fee_rules` seeded with published noon commission bands by category as `is_estimated` defaults,
   and a `fulfilment` flat-per-unit rule split by FBN/FBP (`orders.fulfilment_channel`).

**Confidence: low. Treat every noon fee number as estimated until a real account is connected.**

### 6.5 Zid

**Current code:** `ZidService` is a **stub** — every method returns `[]`, `''`, or `true`
**[verified]**. There is no Zid data flowing into the system at all today.

**Consequence:** fee capture for Zid is blocked on implementing the base integration, which is
out of scope here. Zid's Merchant API does expose orders with totals and a VAT breakdown
**[unverified]** — not confirmed against an account.

**Plan for v1:** Zid orders entered/imported by any other means get `fee_rules` treatment
identical to Salla (subscription model, so no commission; payment + COD + shipping via rules and
manual CSV). The `ZidFeeCapture` adapter is written as a `NullFeeCapture` subclass that documents
the gap, so the wiring exists the day `ZidService` becomes real.

**Confidence: n/a — no integration to capture from.**

### 6.6 WooCommerce

**Current code:** `WooCommerceService` is a **stub** — same as Zid **[verified]**. The class
comment notes Woo uses consumer keys or Application Passwords **[verified]**.

**Exposed once implemented (WooCommerce REST API v3, well documented and stable):** the order
resource carries `line_items[]` with `subtotal`, `total`, `subtotal_tax`, `total_tax`, and
`taxes[]`; `shipping_lines[]`; `fee_lines[]`; `coupon_lines[]`; `tax_lines[]`; `refunds[]`;
`prices_include_tax`. That last field is a direct, authoritative source for our `tax_inclusive`
flag — better than any other platform gives us.

**Not exposed:** there is no marketplace commission (self-hosted), which is correct. Payment
processing fees live in gateway plugin metadata (`_stripe_fee`, `_paypal_transaction_fee`,
`_tap_charge_fee`) inside `meta_data[]` — availability depends entirely on which plugin the
merchant installed, so this is **best-effort**: read known meta keys, otherwise fall back to rules.

**Fallback:** `fee_rules` for payment; manual for anything else.

**Confidence: high on the order resource, low on payment fees (plugin-dependent).**

### 6.7 Trendyol

**Current code:** `TrendyolService` uses HTTP Basic (apiKey/apiSecret) against
`/sapigw/suppliers/{supplierId}/orders` and `/products`, paging under `content` **[verified]**.
`SyncOrdersJob::mapOrderData` reads `totalPrice`/`grossAmount` and defaults currency to `TRY`
**[verified]** — so Trendyol is our first genuinely multi-currency channel, and the FX layer in
§4.5 exists largely for it.

**Exposed (partially verified):** the order/package payload includes `totalPrice`,
`grossAmount`, `totalDiscount`, `lines[]` with per-line amounts and discounts. Line-level
`commissionRate` / `commission` fields are commonly present on Trendyol order lines
**[unverified]** — our own mapper does not read them, so I cannot confirm from this repo.

**Exposed (unverified):** Trendyol's finance/settlement endpoint family
(`/finance/settlements`, "other financials") is documented to return commission, shipping and
deduction records per settlement period. This is the right source for `order_fees` but must be
confirmed against a live supplier account before the adapter is written.

**Not exposed:** Trendyol advertising spend (separate advertising panel).

**Fallback:** `fee_rules` with per-category commission rates (Trendyol publishes them), a flat
per-order shipping deduction, and manual settlement CSV. FX: settlement in TRY, converted to the
org base at the settlement date's frozen rate.

**Confidence: medium — order shape verified from our mapper, finance endpoints unverified.**

### 6.8 Summary

| Platform | Order financials | Per-order fees | Settlement API | v1 fee source | Confidence |
|---|---|---|---|---|---|
| Shopify | ✅ in `raw_data` today | 🟡 Shopify Payments only, needs new scope | ✅ payouts API | API + rules for 3rd-party gateways | high |
| Salla | ✅ `amounts` envelope | ❌ | ❌ unverified | rules + manual CSV | medium |
| Amazon | ✅ | ✅ **best in class** | ✅ Finances + settlement reports | API (blocked on SigV4) | high / not ready |
| Noon | 🟡 unverified shape | ❌ unverified | ❌ unverified | **statement CSV first**, then rules | low |
| Zid | ❌ stub | ❌ stub | ❌ | rules + manual (blocked on integration) | n/a |
| WooCommerce | ✅ once implemented | 🟡 gateway plugin meta | ❌ n/a | REST + rules | high / not ready |
| Trendyol | ✅ order totals | 🟡 line commission unverified | 🟡 unverified | rules + manual CSV | medium |

**The strategic read:** we ship day-one accuracy on Shopify and Amazon, day-one *structure*
everywhere else, and we are transparent about the difference in the UI. That is still further
than Linnworks (no native margin at all) and broader than Sellerboard (Amazon-only in one app).

---

## 7. Dashboard

Next.js 16 App Router, Tailwind v4, pages under `frontend/src/app/(dashboard)` **[verified]**.
Money always through `<Money amount currency />` from `src/components/ui/Money.tsx` **[verified]** —
it renders the official riyal glyph via CSS mask and falls back to `formatCurrency` for other
currencies. **No profit screen may format currency by hand.**

### 7.1 Navigation

Add one item to `src/components/layout/Sidebar.tsx` (existing shape:
`{ icon, key, href }`, label from `common.nav[key]` **[verified]**), placed between `inventory`
and `stores`:

```ts
{ icon: TrendingUp, key: 'profit', href: '/profit' },
```

### 7.2 Screens

| Route | File | Purpose |
|---|---|---|
| `/profit` | `src/app/(dashboard)/profit/page.tsx` | P&L statement + KPI row + trend chart |
| `/profit/skus` | `src/app/(dashboard)/profit/skus/page.tsx` | per-SKU profit table |
| `/profit/costs` | `src/app/(dashboard)/profit/costs/page.tsx` | cost entry, history, CSV import |
| `/profit/expenses` | `src/app/(dashboard)/profit/expenses/page.tsx` | expense manager |
| `/profit/ad-spend` | `src/app/(dashboard)/profit/ad-spend/page.tsx` | ad spend entry/import |
| `/profit/settings` | `src/app/(dashboard)/profit/settings/page.tsx` | base currency, VAT, fee rules, cost visibility |
| `/orders/[id]` | existing page, new panel | per-order profit waterfall |
| `/products/[productId]/edit` | existing page, new field group | inline landed-cost editor |

### 7.3 Components — `src/components/profit/`

| Component | Notes |
|---|---|
| `PnlStatement.tsx` | the §4.6 statement, every row expandable; subtotal rows in a heavier weight; negative amounts in the destructive colour |
| `ProfitKpis.tsx` | Net profit / Margin % / COGS / Total fees / Ad spend, each with the prior-period delta (null-safe — the API returns `null` when there is no baseline) |
| `ProfitWaterfall.tsx` | per-order bar waterfall; reuses the chart primitives in `src/components/charts` |
| `ProfitTrendChart.tsx` | revenue vs net profit over time, dual series |
| `SkuProfitTable.tsx` | sortable, paginated; margin badge; "no cost" warning pill |
| `CostEntryModal.tsx` | landed-cost component inputs (unit/freight/duty/prep/other) with a live landed total; method selector; `valid_from` |
| `CostImportDrawer.tsx` | CSV upload, dry-run preview table, per-row errors, confirm |
| `CostHistoryTimeline.tsx` | `valid_from` → `valid_to` bands per SKU |
| `ExpenseForm.tsx` | recurring vs one-off toggle, recurrence, amortization preview ("SAR 40.98/day") |
| `AdSpendTable.tsx` | inline add row, CSV import |
| `FeeRuleEditor.tsx` | rules list grouped by platform |
| `DataQualityBanner.tsx` | coverage %, "N SKUs missing cost →" CTA. **Always visible when confidence < high** |
| `EstimatedBadge.tsx` | small amber pill next to any estimated figure, tooltip explaining the source rule |
| `ProfitGate.tsx` | renders the no-permission state when the role cannot see costs |

### 7.4 States (every screen)

- **Loading** — skeleton rows, never a spinner over stale numbers.
- **Empty / not set up** — reuse `ConnectPrompt.tsx` **[verified]** pattern: "Add your first
  product cost to see profit" with a direct CTA to `/profit/costs`.
- **Partial** — the default reality. Numbers render with `EstimatedBadge` + `DataQualityBanner`.
- **No permission** — `ProfitGate`, explains who to ask.
- **Recalculating** — banner with the batch progress; numbers stay visible and marked stale.
- **Error** — toast via the existing `Toast.tsx` **[verified]**.
- **RTL** — the whole app already supports RTL **[verified]**; waterfall and chart axes must
  mirror. Numbers stay LTR inside RTL text (`dir="ltr"` on numeric spans), which `Money.tsx`
  already achieves via `tabular-nums` + inline-flex.

### 7.5 i18n

New file `frontend/src/i18n/dicts/profit.ts`, exporting `{ en, ar }`, registered in
`src/i18n/dictionary.ts` under the `profit` namespace — exactly matching the existing pattern
**[verified]**. Plus `nav.profit` added to `common.ts`.

```ts
export const profit = {
  en: {
    title: 'Profit',
    subtitle: 'True net profit after every cost, fee and refund.',
    loading: 'Loading...',

    kpis: {
      netProfit: 'Net Profit',
      margin: 'Net Margin',
      cogs: 'Cost of Goods',
      totalFees: 'Total Fees',
      adSpend: 'Ad Spend',
      contributionMargin: 'Contribution Margin',
    },

    statement: {
      title: 'Profit & Loss',
      grossRevenue: 'Gross revenue',
      discounts: 'Discounts',
      grossSales: 'Gross sales',
      vat: 'VAT collected',
      netRevenue: 'Net revenue (excl. VAT)',
      cogs: 'Cost of goods sold',
      grossProfit: 'Gross profit',
      feesHeading: 'Fees',
      commission: 'Marketplace commission',
      fulfilment: 'Fulfilment',
      shipping: 'Shipping',
      payment: 'Payment processing',
      storage: 'Storage',
      refundFees: 'Refund fees',
      otherFees: 'Other fees',
      totalFees: 'Total fees',
      contributionMargin: 'Contribution margin',
      advertising: 'Advertising',
      refundedRevenue: 'Refunded revenue',
      recoveredCogs: 'Recovered cost of goods',
      operatingProfit: 'Operating profit',
      expenses: 'Operating expenses',
      netProfit: 'Net profit',
      marginPct: 'Net margin',
    },

    quality: {
      title: 'Data quality',
      confidenceHigh: 'High confidence',
      confidenceMedium: 'Partly estimated',
      confidenceLow: 'Mostly estimated',
      estimated: 'Estimated',
      estimatedTooltip: 'This figure uses a fee rule because the platform does not report the actual fee.',
      missingCosts: '{count} SKUs have no cost yet',
      addCosts: 'Add costs',
      feeCoverage: '{percent}% of orders have actual fees from the platform',
      recalculating: 'Recalculating profit…',
    },

    costs: {
      title: 'Product Costs',
      subtitle: 'What each SKU actually costs you, landed.',
      addCost: 'Add cost',
      importCsv: 'Import CSV',
      downloadTemplate: 'Download template',
      sku: 'SKU',
      method: 'Cost method',
      methodFixed: 'Fixed',
      methodFifo: 'FIFO',
      methodPeriod: 'Period average',
      methodBatch: 'Batch',
      unitCost: 'Unit cost',
      freight: 'Freight',
      duty: 'Duty',
      prep: 'Prep & packaging',
      other: 'Other',
      landedCost: 'Landed cost',
      validFrom: 'Valid from',
      storeOverride: 'Store override',
      allStores: 'All stores',
      history: 'Cost history',
      noCost: 'No cost set',
      saved: 'Cost saved.',
      willRecalculate: 'This will recalculate profit for {count} orders.',
      importPreview: 'Preview',
      importRows: '{rows} rows · {created} new · {updated} updated · {skipped} skipped',
      importConfirm: 'Import',
    },

    layers: {
      title: 'Cost layers',
      subtitle: 'FIFO batches and what remains of each.',
      received: 'Received',
      remaining: 'Remaining',
      acquiredAt: 'Acquired',
      addLayer: 'Add stock at cost',
      depleted: 'Depleted',
    },

    fees: {
      title: 'Fees',
      addFee: 'Add fee',
      type: 'Type',
      subtype: 'Detail',
      amount: 'Amount',
      source: 'Source',
      sourceApi: 'From platform',
      sourceRule: 'Estimated',
      sourceManual: 'Manual',
      importSettlement: 'Import settlement CSV',
      typeCommission: 'Commission',
      typeFulfilment: 'Fulfilment',
      typeShipping: 'Shipping',
      typePayment: 'Payment',
      typeRefund: 'Refund',
      typeStorage: 'Storage',
      typeAdvertising: 'Advertising',
      typeOther: 'Other',
    },

    expenses: {
      title: 'Expenses',
      subtitle: 'Fixed and recurring costs, spread across the period.',
      addExpense: 'Add expense',
      name: 'Name',
      category: 'Category',
      typeOneOff: 'One-off',
      typeRecurring: 'Recurring',
      recurrence: 'Repeats',
      startsOn: 'Starts',
      endsOn: 'Ends',
      amortize: 'Spread across the period',
      amortizeHint: 'Charged as {amount} per day',
      allocation: 'Split across channels by',
      allocationRevenue: 'Revenue',
      allocationOrders: 'Orders',
      allocationUnits: 'Units',
      allocationNone: "Don't split",
      pinToStore: 'Only this store',
    },

    adSpend: {
      title: 'Ad Spend',
      subtitle: 'What you paid to acquire the sale.',
      channel: 'Channel',
      campaign: 'Campaign',
      date: 'Date',
      spend: 'Spend',
      addSpend: 'Add spend',
      importCsv: 'Import CSV',
      roas: 'ROAS',
      acos: 'ACOS',
    },

    bySku: {
      title: 'Profit by SKU',
      units: 'Units',
      revenue: 'Revenue',
      cogs: 'COGS',
      fees: 'Fees',
      netProfit: 'Net profit',
      margin: 'Margin',
      perUnit: 'Profit / unit',
      losers: 'Losing money',
      noData: 'No sales in this period.',
    },

    byChannel: {
      title: 'Profit by Channel',
      channel: 'Channel',
      share: 'Share of profit',
    },

    order: {
      title: 'Order profit',
      waterfall: 'How this order made money',
      itemBreakdown: 'Per item',
      allocatedFees: 'Allocated fees',
      directFees: 'Direct fees',
      noProfitYet: 'Profit for this order has not been calculated yet.',
    },

    settings: {
      title: 'Profit settings',
      baseCurrency: 'Reporting currency',
      vatRate: 'VAT rate',
      pricesIncludeVat: 'Storefront prices include VAT',
      defaultMethod: 'Default cost method',
      costVisibility: 'Who can see cost & profit data',
      roleOwner: 'Owners only',
      roleAdmin: 'Owners and admins',
      roleViewer: 'Everyone in the organization',
      feeRules: 'Fee rules',
      recalculate: 'Recalculate profit',
      recalculateHint: 'Recompute every order in the selected range.',
    },

    permissions: {
      denied: 'You do not have access to cost and profit data.',
      deniedHint: 'Ask an owner or admin of this organization for access.',
    },

    empty: {
      title: 'No profit data yet',
      body: 'Add costs for your products and connect a store to see true net profit.',
      cta: 'Add product costs',
    },
  },

  ar: {
    title: 'الأرباح',
    subtitle: 'صافي الربح الحقيقي بعد كل التكاليف والرسوم والمرتجعات.',
    loading: 'جارٍ التحميل...',

    kpis: {
      netProfit: 'صافي الربح',
      margin: 'هامش الربح الصافي',
      cogs: 'تكلفة البضاعة',
      totalFees: 'إجمالي الرسوم',
      adSpend: 'الإنفاق الإعلاني',
      contributionMargin: 'هامش المساهمة',
    },

    statement: {
      title: 'قائمة الأرباح والخسائر',
      grossRevenue: 'إجمالي الإيرادات',
      discounts: 'الخصومات',
      grossSales: 'صافي المبيعات',
      vat: 'ضريبة القيمة المضافة المحصلة',
      netRevenue: 'صافي الإيرادات (بدون الضريبة)',
      cogs: 'تكلفة البضاعة المباعة',
      grossProfit: 'الربح الإجمالي',
      feesHeading: 'الرسوم',
      commission: 'عمولة المنصة',
      fulfilment: 'رسوم التجهيز',
      shipping: 'الشحن',
      payment: 'رسوم الدفع',
      storage: 'التخزين',
      refundFees: 'رسوم الاسترجاع',
      otherFees: 'رسوم أخرى',
      totalFees: 'إجمالي الرسوم',
      contributionMargin: 'هامش المساهمة',
      advertising: 'الإعلانات',
      refundedRevenue: 'الإيرادات المستردة',
      recoveredCogs: 'تكلفة البضاعة المستردة',
      operatingProfit: 'الربح التشغيلي',
      expenses: 'المصاريف التشغيلية',
      netProfit: 'صافي الربح',
      marginPct: 'هامش الربح الصافي',
    },

    quality: {
      title: 'جودة البيانات',
      confidenceHigh: 'دقة عالية',
      confidenceMedium: 'تقديرية جزئيًا',
      confidenceLow: 'تقديرية في الغالب',
      estimated: 'تقديري',
      estimatedTooltip: 'هذا الرقم محسوب بقاعدة رسوم لأن المنصة لا توفر الرسوم الفعلية.',
      missingCosts: '{count} منتج بدون تكلفة',
      addCosts: 'إضافة التكاليف',
      feeCoverage: '{percent}٪ من الطلبات لديها رسوم فعلية من المنصة',
      recalculating: 'جارٍ إعادة حساب الأرباح…',
    },

    costs: {
      title: 'تكاليف المنتجات',
      subtitle: 'التكلفة الحقيقية لكل منتج حتى وصوله إليك.',
      addCost: 'إضافة تكلفة',
      importCsv: 'استيراد ملف CSV',
      downloadTemplate: 'تنزيل النموذج',
      sku: 'رمز المنتج',
      method: 'طريقة احتساب التكلفة',
      methodFixed: 'ثابتة',
      methodFifo: 'الوارد أولاً يصرف أولاً',
      methodPeriod: 'متوسط الفترة',
      methodBatch: 'حسب الدفعة',
      unitCost: 'تكلفة الوحدة',
      freight: 'الشحن',
      duty: 'الجمارك',
      prep: 'التجهيز والتغليف',
      other: 'أخرى',
      landedCost: 'التكلفة النهائية',
      validFrom: 'سارية من',
      storeOverride: 'تخصيص لمتجر',
      allStores: 'كل المتاجر',
      history: 'سجل التكاليف',
      noCost: 'لا توجد تكلفة',
      saved: 'تم حفظ التكلفة.',
      willRecalculate: 'سيتم إعادة حساب الأرباح لـ {count} طلب.',
      importPreview: 'معاينة',
      importRows: '{rows} صف · {created} جديد · {updated} محدّث · {skipped} متجاوز',
      importConfirm: 'استيراد',
    },

    layers: {
      title: 'دفعات التكلفة',
      subtitle: 'دفعات الوارد أولاً والمتبقي من كل دفعة.',
      received: 'الوارد',
      remaining: 'المتبقي',
      acquiredAt: 'تاريخ الاستلام',
      addLayer: 'إضافة مخزون بتكلفته',
      depleted: 'منتهية',
    },

    fees: {
      title: 'الرسوم',
      addFee: 'إضافة رسم',
      type: 'النوع',
      subtype: 'التفصيل',
      amount: 'المبلغ',
      source: 'المصدر',
      sourceApi: 'من المنصة',
      sourceRule: 'تقديري',
      sourceManual: 'يدوي',
      importSettlement: 'استيراد كشف التسوية',
      typeCommission: 'عمولة',
      typeFulfilment: 'تجهيز',
      typeShipping: 'شحن',
      typePayment: 'دفع',
      typeRefund: 'استرجاع',
      typeStorage: 'تخزين',
      typeAdvertising: 'إعلانات',
      typeOther: 'أخرى',
    },

    expenses: {
      title: 'المصاريف',
      subtitle: 'التكاليف الثابتة والمتكررة موزعة على الفترة.',
      addExpense: 'إضافة مصروف',
      name: 'الاسم',
      category: 'التصنيف',
      typeOneOff: 'لمرة واحدة',
      typeRecurring: 'متكرر',
      recurrence: 'التكرار',
      startsOn: 'يبدأ في',
      endsOn: 'ينتهي في',
      amortize: 'توزيع على الفترة',
      amortizeHint: 'يُحتسب {amount} يوميًا',
      allocation: 'التوزيع على القنوات حسب',
      allocationRevenue: 'الإيرادات',
      allocationOrders: 'الطلبات',
      allocationUnits: 'الوحدات',
      allocationNone: 'بدون توزيع',
      pinToStore: 'هذا المتجر فقط',
    },

    adSpend: {
      title: 'الإنفاق الإعلاني',
      subtitle: 'ما دفعته للحصول على البيع.',
      channel: 'القناة',
      campaign: 'الحملة',
      date: 'التاريخ',
      spend: 'الإنفاق',
      addSpend: 'إضافة إنفاق',
      importCsv: 'استيراد ملف CSV',
      roas: 'العائد على الإنفاق',
      acos: 'نسبة تكلفة الإعلان',
    },

    bySku: {
      title: 'الأرباح حسب المنتج',
      units: 'الوحدات',
      revenue: 'الإيرادات',
      cogs: 'التكلفة',
      fees: 'الرسوم',
      netProfit: 'صافي الربح',
      margin: 'الهامش',
      perUnit: 'الربح لكل وحدة',
      losers: 'منتجات خاسرة',
      noData: 'لا توجد مبيعات في هذه الفترة.',
    },

    byChannel: {
      title: 'الأرباح حسب القناة',
      channel: 'القناة',
      share: 'الحصة من الأرباح',
    },

    order: {
      title: 'ربح الطلب',
      waterfall: 'كيف حقق هذا الطلب ربحه',
      itemBreakdown: 'تفصيل المنتجات',
      allocatedFees: 'رسوم موزعة',
      directFees: 'رسوم مباشرة',
      noProfitYet: 'لم يتم حساب ربح هذا الطلب بعد.',
    },

    settings: {
      title: 'إعدادات الأرباح',
      baseCurrency: 'عملة التقارير',
      vatRate: 'نسبة ضريبة القيمة المضافة',
      pricesIncludeVat: 'أسعار المتجر شاملة الضريبة',
      defaultMethod: 'طريقة التكلفة الافتراضية',
      costVisibility: 'من يمكنه الاطلاع على التكاليف والأرباح',
      roleOwner: 'المالكون فقط',
      roleAdmin: 'المالكون والمشرفون',
      roleViewer: 'كل أعضاء المؤسسة',
      feeRules: 'قواعد الرسوم',
      recalculate: 'إعادة حساب الأرباح',
      recalculateHint: 'إعادة حساب كل الطلبات في الفترة المحددة.',
    },

    permissions: {
      denied: 'ليس لديك صلاحية الاطلاع على بيانات التكاليف والأرباح.',
      deniedHint: 'اطلب الصلاحية من مالك أو مشرف المؤسسة.',
    },

    empty: {
      title: 'لا توجد بيانات أرباح بعد',
      body: 'أضف تكاليف منتجاتك واربط متجرًا لرؤية صافي الربح الحقيقي.',
      cta: 'إضافة تكاليف المنتجات',
    },
  },
};
```

Also add to `common.ts` under `nav`: `en: { profit: 'Profit' }`, `ar: { profit: 'الأرباح' }`.

---

## 8. Mobile

Flutter, `mobile/lib/features/<feature>/<feature>_page.dart`, flat string keys in
`mobile/lib/l10n/strings.dart` looked up with `context.t('key')` **[verified]**. Money via
`formatMoney` / `MoneyText` in `lib/core/format.dart` **[verified]**.

**Principle: mobile is read-only for profit in v1.** Cost entry is a data-heavy, error-prone task
that belongs on a keyboard. A merchant checking margin between meetings is the real mobile job.

**In scope:**

1. **`lib/features/profit/profit_page.dart`** — reached from the existing `more` feature
   **[verified]** and from a new KPI tile on `dashboard_page.dart`.
   - KPI cards: net profit, net margin, total fees, ad spend for the selected period.
   - Period selector matching the existing analytics page.
   - Condensed P&L list (statement rows, no drill-down).
   - Top 5 / bottom 5 SKUs by profit.
   - Estimated badge + a one-line data-quality note.
2. **`lib/features/orders/order_detail_page.dart`** — add a "Profit" section: net profit, margin,
   and a compact fee list. This is the highest-value mobile surface: a merchant looking at an
   order can immediately see whether it was worth fulfilling.
3. **`lib/features/dashboard/dashboard_page.dart`** — replace or supplement the revenue tile with
   a net-profit tile (revenue without profit is the exact thing this spec exists to fix).
4. **Repository** `lib/features/profit/repositories/profit_repository.dart`, following the
   `lib/features/orders/repositories` pattern **[verified]**, hitting
   `/analytics/profit`, `/analytics/profit/by-sku`, `/orders/{id}/profit`.
5. **Permission handling** — a `403` from the profit endpoints renders the same "no access" state
   as web, not an error.

**Out of scope for mobile v1:** cost entry, expense management, ad-spend entry, fee editing, CSV
import, recalculation trigger.

**Strings** — add to `lib/l10n/strings.dart` in both `en` and `ar` maps, flat-key style:

```
'nav.profit', 'profit.title', 'profit.netProfit', 'profit.margin', 'profit.cogs',
'profit.fees', 'profit.adSpend', 'profit.expenses', 'profit.grossRevenue',
'profit.netRevenue', 'profit.vat', 'profit.estimated', 'profit.estimatedHint',
'profit.topSkus', 'profit.losingSkus', 'profit.orderProfit', 'profit.noAccess',
'profit.noData', 'profit.perUnit'
```

Arabic values mirror the web dictionary above (`الأرباح`, `صافي الربح`, `هامش الربح الصافي`, …).

---

## 9. Permissions & multi-tenancy

### 9.1 Org scoping rules

**[verified]** the current mechanism: `EnsureOrganizationMember` (`org.member`) reads the
`X-Organization-Id` header, 400s if absent, 403s if the authenticated user is not a member. Every
controller then filters manually on that id.

Rules for this feature, non-negotiable:

1. Every new table carries `organization_id` and **every** query filters on it — no exceptions,
   including admin/debug endpoints.
2. Never scope by SKU alone. `product_variants.sku` is globally unique today (D3), so a
   SKU-keyed lookup without `organization_id` is a cross-tenant data leak. All resolution is
   `(organization_id, sku)`.
3. Anything touching `orders` uses the new `orders.organization_id` once backfilled; until the
   contract migration lands, keep the existing `whereHas('store', ...)` form so both paths agree.
4. Validation rules that reference other tenant rows must be scoped:
   `Rule::exists('stores','id')->where('organization_id', $orgId)` — a bare `exists:stores,id`
   lets a member of org A attach a cost to org B's store. The existing `ProductController::update`
   uses bare `exists:stores,id` **[verified]**; do not copy that.
5. `RecalculateProfitJob` and every queued job take `organization_id` explicitly and re-scope
   inside `handle()` — a job payload is not a trust boundary.

### 9.2 Roles

**[verified]** roles are `owner`, `admin`, `viewer` (`OrganizationController::ROLES`), stored on
the `organization_user` pivot.

Cost data is the most commercially sensitive data in the product — supplier pricing and margin
are exactly what a departing employee or an agency contractor should not walk away with.

| Capability | owner | admin | viewer |
|---|---|---|---|
| View P&L, per-SKU profit, order profit | ✅ | ✅ | only if `organizations.cost_visibility_role = 'viewer'` |
| View raw unit costs / cost history | ✅ | ✅ | ❌ (even when profit is visible) |
| Create / edit / delete costs, layers | ✅ | ✅ | ❌ |
| Create / edit fees | ✅ | ✅ | ❌ |
| Manage expenses, ad spend, fee rules | ✅ | ✅ | ❌ |
| Change profit settings (base currency, VAT, visibility) | ✅ | ❌ | ❌ |
| Trigger recalculation | ✅ | ✅ | ❌ |

Note the deliberate split: a viewer may be allowed to see *margin* without seeing *supplier cost*.
`ProfitReportService` therefore has a `redactCosts` mode that returns margin and profit but nulls
`cogs` and `unit_cost` fields. Cheap to build, and it is the difference between a feature a
merchant can share with staff and one they cannot.

### 9.3 Implementation

- **Middleware** `app/Http/Middleware/EnsureCostAccess.php`, aliased `cost.access` in
  `bootstrap/app.php` next to the existing `org.member` alias **[verified]**. Resolves the actor's
  pivot role, compares against `organizations.cost_visibility_role`, returns
  `403 {"message": "You do not have access to cost data."}`.
- **Policies** `ProductCostPolicy`, `OrderFeePolicy`, `ExpensePolicy`, `AdSpendPolicy`,
  `FeeRulePolicy` in `app/Policies/`, each checking org membership **and** role. The repo has no
  policies today — this introduces the directory.
- **Gate** `Gate::define('view-costs', ...)` for the `redactCosts` decision.
- Frontend mirrors it: `/me` gains the actor's role and a `can_view_costs` boolean so the sidebar
  item and screens can gate without a round trip. **Client-side gating is UX only** — the server
  check is the security boundary.
- Audit: every write to `product_costs`, `order_fees`, `expenses` records `created_by`. A full
  audit-log table is out of scope but the columns make it addable later.

---

## 10. Edge cases & failure modes

| # | Case | Behaviour |
|---|---|---|
| E1 | SKU has no cost | `cogs = 0`, `missing_cost = true`, SKU listed in coverage report, margin badge shows a warning. **Never guess.** |
| E2 | `order_items.sku` is null | line skipped for costing, counted as `unmatched_lines` |
| E3 | FIFO layers exhausted mid-order | consume what exists, synthesise an estimated layer for the remainder at the last known cost, flag `is_estimated` |
| E4 | No cost at all and FIFO selected | falls to E1 |
| E5 | Cost edited retroactively | historical `order_profits` are **not** silently changed; the API returns `affected_orders` and the user explicitly confirms a recalculation |
| E6 | Order synced twice | `order_fees.fee_key` and `cost_layer_consumptions.consumption_key` unique constraints make both operations no-ops |
| E7 | Fee arrives after profit was computed (Amazon settlement, days later) | `OrderFeesCaptured` → `CalculateOrderProfitJob` recomputes; `computed_at` moves |
| E8 | Refund of an order whose COGS was never recognised | reversal is a no-op on COGS; revenue reversal still applies; logged |
| E9 | Partial refund | `refunded_quantity` on the line; only that many units reversed |
| E10 | Refund larger than the order (goodwill overpay) | allowed; produces negative profit; not clamped — clamping hides a real accounting event |
| E11 | Currency missing on an order | falls back to `stores.currency` → `organizations.base_currency`; logged |
| E12 | FX rate missing for a date | nearest prior within 7 days, else `1.0` + warning + `is_estimated` |
| E13 | Org changes `base_currency` | **hard reject** if any `order_profits` rows exist, unless the user confirms a full recalculation; all `_base` values are otherwise meaningless |
| E14 | VAT rate changes mid-period | `orders.tax_rate` is stamped per order at capture, so historical orders keep their rate |
| E15 | VAT-inclusive flag wrong for a store | fixable in profit settings; triggers recalculation. This is the highest-impact user error (15% swing) — the settings screen shows a worked example ("SAR 115 → net 100 + VAT 15") |
| E16 | Order with zero net revenue (100% discount) | allocation falls back to quantity weighting; `margin_pct = null`, UI shows "—" not "0%" |
| E17 | Negative profit | rendered in the destructive colour, sorted to the top of the "losing money" view — this is a feature |
| E18 | Store disconnected/deleted | `store_id` cascade would delete profit history. **Mitigation:** `stores` are soft-deleted going forward, or store deletion is blocked when `order_profits` exist. Flagged in §15 |
| E19 | Product deleted, orders remain | costs key on `(organization_id, sku)` and survive; `product_variant_id` nulls out |
| E20 | Two stores, same SKU, different costs | per-store `product_costs` override; resolution order in §4.2 |
| E21 | Bundle / kit sold as one line | **not supported in v1** — the bundle SKU needs its own cost. Documented limitation; §15 |
| E22 | Duplicate settlement CSV upload | `fee_key` uniqueness makes it idempotent; import result reports `skipped` |
| E23 | Settlement references an order we never synced | fee is parked with `order_id = null`… **not possible** (`order_id` is non-null). Decision: park unmatched rows in the import result as errors with a "sync orders first" hint, do not create orphan fees |
| E24 | Recalculation of a huge range | capped at 366 days per request; chunked via `Bus::batch`; concurrent recalcs for the same org rejected with `409` |
| E25 | Concurrent FIFO consumption | `SELECT ... FOR UPDATE` on layers inside the transaction |
| E26 | Clock skew / `placed_at` in the future | clamped to `now()` on capture, warning logged |
| E27 | `raw_data` missing or malformed during backfill | order skipped, counted, listed in the backfill report; never fatal |
| E28 | Merchant not VAT-registered | **[assumption]** unhandled in v1 — input VAT is treated as reclaimable. §15 |
| E29 | Fee reported VAT-inclusive but adapter says exclusive | 15% cost overstatement; caught by settlement reconciliation, and each adapter's `feesIncludeVat()` has a dedicated test |
| E30 | Order in a currency with no `fx_rates` row and no marketplace rate | E12 path; the P&L shows the order with `is_estimated` |

---

## 11. Testing

Tests go in `backend/tests/Feature` and `backend/tests/Unit`, using `RefreshDatabase` and the
`makeOrganization()` helper on `Tests\TestCase` **[verified]**. Auth in feature tests is
`Bearer` + `X-Organization-Id`, matching `OrderTest` **[verified]**. Factories: the repo has only
`UserFactory` **[verified]** — this feature needs `OrganizationFactory`, `StoreFactory`,
`OrderFactory`, `OrderItemFactory`, `ProductFactory`, `ProductVariantFactory`,
`ProductCostFactory`, `CostLayerFactory`, `OrderFeeFactory`, `ExpenseFactory`, `AdSpendFactory`.
Building them is part of the estimate.

Money assertions compare numerically, never against a formatted string — `OrderTest` already
documents why (`total` serializes as `"100.00"`) **[verified]**.

### Unit — `tests/Unit/Profit/`

`VatCalculatorTest`
- `test_inclusive_price_splits_net_and_vat_at_15_percent` — 115 → 100.00 / 15.00
- `test_exclusive_price_adds_vat` — 100 → 100.00 / 15.00
- `test_uae_five_percent_rate`
- `test_zero_rate_returns_full_net`
- `test_rounding_is_stable_across_many_lines` (sum of split lines equals the split of the sum, within 0.01)

`CostResolverTest`
- `test_store_override_beats_org_wide_cost`
- `test_newest_valid_from_wins`
- `test_cost_valid_from_after_order_date_is_ignored`
- `test_missing_cost_returns_missing_not_zero_guess`
- `test_resolution_is_scoped_to_organization` — same SKU, two orgs, different costs (D3 guard)

`FifoLedgerTest`
- `test_consumes_oldest_layer_first`
- `test_spans_multiple_layers_when_quantity_exceeds_one`
- `test_deterministic_tiebreak_when_acquired_at_is_identical`
- `test_shortfall_creates_estimated_layer_and_flags_item`
- `test_consumption_is_idempotent_on_rerun`
- `test_restocked_refund_returns_quantity_to_original_layer`
- `test_writeoff_refund_does_not_restore_layer_and_books_lost_cogs`
- `test_partial_refund_reverses_only_refunded_quantity`
- `test_reversals_consume_newest_first`

`FeeEstimatorTest`
- `test_sku_rule_beats_category_rule_beats_platform_default`
- `test_percent_of_item_applies_per_line`
- `test_min_and_max_clamp_the_fee`
- `test_expired_rule_is_not_applied`
- `test_estimated_fees_are_flagged`

`FxConverterTest`
- `test_same_currency_returns_one_without_query`
- `test_exact_date_rate_used`
- `test_falls_back_to_most_recent_prior_rate_within_seven_days`
- `test_missing_rate_returns_one_and_flags_estimated`
- `test_stored_rate_is_frozen_and_survives_later_rate_changes`

`OrderProfitCalculatorTest` — the core arithmetic
- `test_net_profit_matches_worked_example` (a full fixture: 2 lines, VAT-inclusive, commission +
  payment + shipping fees, known expected profit to the fils)
- `test_tax_and_discount_fee_types_are_excluded_from_total_fees` ← the double-count guard
- `test_order_level_fees_allocate_by_net_revenue_share`
- `test_allocation_falls_back_to_quantity_when_net_revenue_is_zero`
- `test_margin_is_null_not_zero_when_no_revenue`
- `test_recalculation_is_idempotent`
- `test_vat_never_appears_in_profit`
- `test_estimated_share_is_computed_correctly`

`ExpenseAmortizerTest`
- `test_monthly_expense_spreads_evenly_across_days`
- `test_one_off_non_amortized_charges_on_start_date`
- `test_leap_year_february_allocates_29_days`
- `test_expense_ending_mid_period_stops_allocating`
- `test_amortization_is_idempotent`

### Feature — `tests/Feature/`

`ProductCostTest`
- `test_admin_can_create_a_cost`
- `test_viewer_cannot_create_a_cost`
- `test_cost_from_another_organization_is_not_visible`
- `test_creating_a_cost_closes_the_previous_valid_to`
- `test_landed_cost_is_the_sum_of_components`
- `test_store_id_from_another_org_is_rejected_by_validation`
- `test_csv_import_dry_run_reports_errors_without_writing`
- `test_csv_import_creates_costs`

`OrderFeeTest`
- `test_manual_fee_can_be_added_to_an_order`
- `test_fee_triggers_profit_recalculation`
- `test_fee_on_another_orgs_order_returns_404`
- `test_duplicate_settlement_import_is_idempotent`
- `test_item_level_fee_must_belong_to_the_order`

`ProfitReportTest`
- `test_pnl_statement_lines_sum_to_net_profit`
- `test_period_filter_uses_placed_at_not_created_at` ← D4 guard
- `test_by_sku_sorts_and_paginates`
- `test_by_channel_totals_match_the_org_total`
- `test_coverage_endpoint_reports_missing_costs`
- `test_orders_from_another_organization_are_excluded`
- `test_viewer_without_permission_gets_403`
- `test_viewer_with_permission_sees_margin_but_not_unit_cost` (redaction)
- `test_comparison_period_delta_is_null_without_a_baseline` (mirrors the existing `>= 3` guard)

`ProfitPermissionTest`
- `test_cost_visibility_role_owner_blocks_admin` … through the full matrix in §9.2

`ProfitBackfillTest`
- `test_backfill_extracts_shopify_totals_from_raw_data`
- `test_backfill_extracts_salla_amounts_envelope`
- `test_backfill_sets_placed_at_from_payload`
- `test_backfill_is_idempotent_across_two_runs`
- `test_backfill_skips_malformed_raw_data_without_failing`
- `test_dry_run_writes_nothing`

`ProfitFeatureFlagTest`
- `test_endpoints_return_404_when_flag_disabled`
- `test_endpoints_work_when_flag_enabled`

### Integration adapter tests — `tests/Feature/Integrations/`

Using `Http::fake()` with recorded fixture payloads (no live calls):

- `ShopifyFeeCaptureTest::test_maps_payout_transaction_to_payment_fee`
- `ShopifyFeeCaptureTest::test_parses_raw_data_totals_without_an_api_call`
- `AmazonFeeCaptureTest::test_maps_item_fee_list_to_typed_order_fees`
- `AmazonFeeCaptureTest::test_refund_event_creates_negative_commission_and_positive_refund_fee`
- `TrendyolFeeCaptureTest::test_converts_try_to_base_currency_at_frozen_rate`
- `SallaFeeCaptureTest::test_extracts_cod_amount`
- `NullFeeCaptureTest::test_falls_through_to_fee_estimator`
- `FeeCaptureFactoryTest::test_every_platform_resolves_to_an_adapter` (all 7 + default)

### Regression tests for the D-series defects

- `SyncOrdersJobTest::test_order_items_persist_external_id_and_name` (D1)
- `SyncOrdersJobTest::test_orders_are_keyed_by_store_and_external_id` (D2 — two stores, same
  external id, two rows)
- `SyncOrdersJobTest::test_placed_at_comes_from_the_platform_payload` (D4)

### Concurrency (MySQL only)

`FifoConcurrencyTest::test_two_workers_cannot_over_consume_a_layer` — skipped on sqlite with a
documented `markTestSkipped`, matching how the repo already handles engine differences
**[verified]** in `2026_07_02_000004_add_trendyol_to_stores_platform.php`.

---

## 12. Rollout

### 12.1 Feature flag

Two layers:

1. **Config kill switch** — `config/features.php` → `'profit_engine' => env('HUBBY_PROFIT_ENGINE', false)`,
   enforced by a new `EnsureFeatureEnabled` middleware aliased `feature` in `bootstrap/app.php`.
   Routes return `404` when off (not `403` — an unreleased feature should not announce itself).
2. **Per-plan entitlement** — `plans.features` is already a JSON column **[verified]**. Add
   `"profit_engine": true`. **Per the competitive strategy, it ships in every plan** — Linnworks
   charging separately for analytics is a weakness we are attacking, not copying. The entitlement
   exists for staged rollout, not for upsell.

Frontend: sidebar item and routes hidden unless `/me` reports the feature and the permission.

### 12.2 Migration sequence (expand → migrate → contract)

**Phase A — expand (safe, zero downtime).** Ship migrations `000001`–`000017`. All additive: new
tables, nullable columns with defaults. No behaviour change. `orders.organization_id` nullable.

**Phase B — code deploy, flag off.** Sync jobs start populating the new order/item financial
columns and `placed_at` for *new* orders. The D1/D2/D4 fixes land here. Nothing is read yet.

**Phase C — backfill.** New artisan command:

```
php artisan hubby:backfill-profit
    [--org=]            # single org, omit for all
    [--from=] [--to=]   # placed_at range
    [--chunk=500]
    [--stage=orgid|financials|placed_at|fees|profit|all]
    [--dry-run]
```

Stages, run in order, each independently re-runnable:

1. **`orgid`** — populate `orders.organization_id` from `stores.organization_id`. Chunked update.
   Report any order whose store is missing.
2. **`placed_at`** — from `raw_data`: Shopify `created_at`, Salla `date.date`, Trendyol
   `orderDate`, Amazon `PurchaseDate`; fallback `orders.created_at` with a `placed_at_estimated`
   note in the report. **[assumption]** exact key names per platform must be confirmed against
   stored payloads — the command prints a sample before writing when `--dry-run`.
3. **`financials`** — parse `raw_data` into `subtotal`, `discount_total`, `shipping_total`,
   `tax_total`, `refund_total`, per-item `discount_total`/`tax_total`, and `tax_inclusive`.
   This is the highest-value stage: **we already store the full original payload for every order
   ever synced** **[verified]**, so historical accuracy here is real, not estimated.
4. **`fees`** — (a) fee lines derivable from `raw_data` (Shopify refund transactions, Salla COD);
   (b) `FeeEstimator` for everything else, all marked `is_estimated`, `source = 'rule'`.
5. **`profit`** — dispatch `CalculateOrderProfitJob` in batches of 500.

Reporting: the command writes a `SyncLog` row (`type = 'profit_backfill'`) per org **[verified
pattern]** and prints a summary table: orders processed / financials extracted / fees created /
estimated share / skipped with reasons.

**Phase D — enable for internal orgs**, verify against a manual spreadsheet for at least one real
merchant per platform. This reconciliation is the acceptance gate, not the test suite.

**Phase E — staged enable** by plan/org, monitoring the coverage endpoint.

**Phase F — contract.** `2026_07_23_000001_make_orders_organization_id_required.php` sets
`organization_id` non-nullable once the backfill reports 100%. Ships at least one release after
Phase C.

### 12.3 Data-integrity risks

| Risk | Severity | Mitigation |
|---|---|---|
| Backfilled `placed_at` wrong → profit lands on the wrong day | high | dry-run sample inspection per platform; keep `created_at` untouched; report estimated count |
| Estimated fees mistaken for actual | high | `is_estimated` on every row, `EstimatedBadge` everywhere, coverage endpoint, confidence label on the P&L |
| VAT-inclusive flag defaulting wrong for a non-Gulf merchant | high | per-store override in profit settings, worked example in the UI, surfaced during onboarding |
| Cost edit silently rewrites history | high | explicit confirmation + `affected_orders` count; cost rows soft-deleted, never overwritten |
| Recalculation storm on a large org | medium | `ShouldBeUnique` on `CalculateOrderProfitJob`, `Bus::batch` chunking, 409 on concurrent org recalcs |
| FIFO double-consumption | medium | `FOR UPDATE` + `consumption_key` uniqueness |
| Settlement re-import duplicates fees | medium | `fee_key` uniqueness |
| `orders.organization_id` divergence from `store.organization_id` (store moved orgs) | low | nightly integrity check job; the contract migration adds no FK cycle |
| Query regression on the orders list from new indexes | low | indexes are additive and narrow; `EXPLAIN` the two heaviest report queries before merge |
| Base-currency change invalidating every `_base` value | high | E13 — hard block with forced recalculation |

### 12.4 Rollback

Flag off → endpoints 404, sidebar hidden, jobs stop being dispatched. The new tables and columns
stay (they are additive and harmless). No down-migration is required for an incident; the
`down()` methods exist for local development only. The D1/D2/D4 sync-job fixes are **not** behind
the flag and are not rolled back — they are correctness fixes that stand on their own.

---

## 13. Acceptance criteria

**Data model**
- [ ] All 12 new tables exist with the columns, types, nullability, defaults, indexes and FKs in §3.3
- [ ] `migrate:fresh` and an incremental `migrate` on a populated database both succeed
- [ ] Every new table has `organization_id` with an index, and no query reads any of them unscoped
- [ ] `orders` and `order_items` carry the financial columns in §3.4; `orders.placed_at` is populated for new syncs
- [ ] D1, D2 and D4 are fixed with regression tests

**Costing**
- [ ] All four methods (`fixed`, `fifo`, `period`, `batch`) resolve a cost for a SKU
- [ ] FIFO consumes oldest-first, is idempotent, and is safe under concurrent workers on MySQL
- [ ] A restocked refund returns quantity to the original layer at the original cost
- [ ] A written-off refund books `lost_cogs_base` and does not restore the layer
- [ ] A SKU with no cost yields `cogs = 0` **and** `missing_cost = true` — never a guessed number
- [ ] Cost history is versioned by `valid_from`/`valid_to` and never destroyed

**Profit**
- [ ] Net profit per order, per SKU, per channel and per period match hand-computed fixtures to within 0.01
- [ ] VAT never appears in any profit figure, inclusive or exclusive
- [ ] `tax` and `discount` fee types are excluded from `total_fees`
- [ ] Order-level fees allocate by net-revenue share, with a quantity fallback at zero revenue
- [ ] `margin_pct` is `null`, not `0`, when net revenue is zero
- [ ] FX rates are frozen at the event and a historical P&L does not move when rates change
- [ ] Recalculating an unchanged order produces an identical result and no new ledger rows

**Fee capture**
- [ ] Shopify order financials are extracted from existing `raw_data` with no new API call
- [ ] Shopify Payments fees are captured once the new scope is granted
- [ ] Amazon Finances API events map to typed `order_fees` (behind SigV4 readiness)
- [ ] Salla `amounts` envelope populates order financials including `cod_amount`
- [ ] Every one of the 7 platforms resolves to a fee-capture adapter, `NullFeeCapture` included
- [ ] Settlement CSV import is idempotent and reports unmatched rows
- [ ] Every estimated fee is flagged `is_estimated` and attributable to a `fee_rule`

**API**
- [ ] All endpoints in §5.5 exist with the documented method, path, validation and response shape
- [ ] All require Sanctum + `X-Organization-Id` + the role check
- [ ] A user of org A gets 404/403 for every org B resource
- [ ] `/analytics/profit/coverage` reports honest, correct counts

**Dashboard**
- [ ] `/profit`, `/profit/skus`, `/profit/costs`, `/profit/expenses`, `/profit/ad-spend`, `/profit/settings` all render
- [ ] Order detail shows the profit waterfall
- [ ] Every monetary value renders via `<Money />`
- [ ] Every string is in `profit.ts` for both `en` and `ar`, with no hard-coded copy
- [ ] RTL renders correctly, including charts and the waterfall
- [ ] Loading, empty, partial, no-permission, recalculating and error states all exist
- [ ] `DataQualityBanner` appears whenever confidence is below high

**Mobile**
- [ ] Profit page with KPIs and a condensed P&L
- [ ] Order detail shows net profit and margin
- [ ] Dashboard shows a net-profit tile
- [ ] All strings in `strings.dart` for `en` and `ar`
- [ ] A 403 renders the no-access state, not an error

**Permissions**
- [ ] The §9.2 matrix is enforced server-side and covered by tests
- [ ] A viewer with profit access sees margin but not unit cost
- [ ] Only owners can change profit settings

**Rollout**
- [ ] Feature flag hides everything when off, including routes (404)
- [ ] `hubby:backfill-profit` runs per stage, supports `--dry-run`, and is idempotent
- [ ] Backfill reports coverage and never fails on one malformed order
- [ ] At least one real merchant's month reconciles against a manual spreadsheet per platform

---

## 14. Effort estimate + dependencies

Estimates in engineer-days for one experienced full-stack engineer, including tests and review.

| Workstream | Days | Notes |
|---|---|---|
| Migrations (17 files) + model/observer wiring | 4 | includes the D1/D2/D4 fixes |
| Factories for 11 models | 2 | the repo has only `UserFactory` today |
| `CostResolver`, `ProductCost` CRUD + CSV import | 5 | import UI contract included |
| `FifoLedger` + layers + consumption ledger | 6 | the hardest correctness surface; concurrency work |
| `VatCalculator`, `FxConverter`, `fx_rates` | 3 | FX provider selection is a dependency |
| `OrderProfitCalculator` + `order_profits` / `order_item_profits` | 6 | the core |
| `FeeEstimator` + `fee_rules` + seeder of platform defaults | 4 | |
| `SettlementImporter` (CSV, 3 column profiles) | 3 | noon, Trendyol, generic |
| Shopify fee capture (raw_data + payouts + scope change) | 4 | scope change forces re-auth UX |
| Amazon fee capture (Finances API + settlement report) | 8 | **excludes SigV4**, see dependencies |
| Salla / Trendyol / Woo / Zid / Noon adapters + `NullFeeCapture` | 5 | mostly raw_data parsing + rules |
| Expenses + amortizer + allocations | 3 | |
| Ad spend + CSV import | 2 | |
| `ProfitReportService` + 8 report endpoints | 6 | query tuning included |
| Permissions: middleware, policies, redaction | 3 | |
| Backfill command (5 stages) + reporting | 5 | |
| Feature flag plumbing | 1 | |
| Backend tests (§11) | 8 | ~90 tests |
| **Backend subtotal** | **78** | |
| Dashboard: P&L, KPIs, trend chart | 5 | |
| Dashboard: per-SKU table + order waterfall | 4 | |
| Dashboard: cost entry + history + CSV drawer | 5 | |
| Dashboard: expenses + ad spend + fee rules + settings | 5 | |
| Dashboard: states, `EstimatedBadge`, `DataQualityBanner`, gating | 3 | |
| i18n en + ar (web) | 2 | Arabic financial terminology needs a native review |
| **Frontend subtotal** | **24** | |
| Mobile: profit page + repository | 3 | |
| Mobile: order detail section + dashboard tile | 2 | |
| Mobile: strings en + ar | 1 | |
| **Mobile subtotal** | **6** | |
| Reconciliation against real merchant data | 4 | the real acceptance gate |
| Docs + rollout | 2 | |
| **Total** | **≈114 engineer-days** | ~5.5 months solo, ~2 months for a team of three with the split below |

**Suggested parallel split (3 engineers, ~8 weeks):**
- **E1 (backend core):** migrations, cost resolution, FIFO, calculator, reports
- **E2 (backend integrations):** fee capture adapters, settlement import, backfill, fee rules
- **E3 (frontend + mobile):** all UI, i18n, gating — can start against a mocked API contract on day 3

### Dependencies and blockers

| # | Dependency | Blocks | Owner |
|---|---|---|---|
| DEP1 | **AWS SigV4 signing for SP-API** — `AmazonService`'s own docblock says it is missing **[verified]** | all Amazon fee capture (8 days of the estimate) | backend |
| DEP2 | **Shopify `read_shopify_payments_payouts` scope** + a re-authorisation flow for connected stores | Shopify payment fees | backend + frontend |
| DEP3 | **Real noon partner credentials** — the endpoints in `NoonService` are placeholders **[verified]** | any noon fee capture beyond CSV | product/BD |
| DEP4 | **Trendyol supplier account** to verify the finance/settlement endpoints | Trendyol fee capture beyond rules | product/BD |
| DEP5 | **Order status normalisation** across 7 platforms — needed for `isCommitted()` (§4.3) | COGS recognition timing | backend, small |
| DEP6 | **FX rate provider** selection + config (`config/services.php` has none **[verified]**) | multi-currency accuracy | backend, small |
| DEP7 | **`ZidService` / `WooCommerceService` implementation** — both stubs **[verified]** | fee capture on those channels | separate spec |
| DEP8 | **Native Arabic review** of financial terminology | i18n quality | product |
| DEP9 | **Model factories** — only `UserFactory` exists **[verified]** | all testing | backend, included above |
| DEP10 | **A real merchant's settlement statements** per platform | the reconciliation acceptance gate | product/BD |

---

## 15. Open questions

1. **`product_variants.sku` global uniqueness (D3).** Should the index change to
   `unique(['product_id','sku'])` in this spec, or a separate migration? It is a genuine
   multi-tenant defect. Cost data is safe either way because it keys on `(organization_id, sku)`,
   but the underlying bug remains. **Recommendation: fix it in a separate, dedicated PR before
   this feature ships**, since it needs its own data audit.
2. **Store deletion vs profit history (E18).** `stores` cascade-deletes `orders` today
   **[verified]**, which would destroy P&L history. Do we soft-delete stores, block deletion when
   profit data exists, or accept the loss? **Recommendation: block deletion, offer "disconnect".**
3. **Non-VAT-registered merchants (E28).** Input VAT is currently treated as reclaimable. Below
   the SAR 375,000 registration threshold it is a real cost. Add an
   `organizations.vat_registered` flag in v1, or defer?
4. **Bundles and kits (E21).** A bundle sold as one line has no meaningful single SKU cost.
   Component-level costing needs a bundle/BOM model, which does not exist. Defer to the
   Phase 2 inventory work, or ship a simple "bundle SKU has its own cost" rule now?
5. **Storage fees: expense or fee?** Amazon monthly storage is per-ASIN but not per-order. This
   spec routes it to `expenses` with `allocation_method='units'`. Sellerboard attributes it per
   SKU. Should we add a SKU-period cost table instead? **Recommendation: ship as an expense,
   revisit if merchants complain.**
6. **Ad spend attribution.** `organizations.allocate_ads_to_orders` exists as a flag but v1 only
   subtracts ad spend at the period/channel level. Do we want per-order attribution when Amazon
   Ads provides it, and does that distort per-order margin in a way merchants find confusing?
7. **COGS recognition timing (DEP5).** Recognise at paid, at shipped, or at delivered? Different
   accounting conventions, different cash-flow implications, and MENA's high COD/RTO rate makes
   "paid" ambiguous. **Needs a merchant conversation, not an engineering decision.**
8. **Historical depth of backfill.** How far back do we go? Amazon settlement reports cap around
   two years; `raw_data` goes back as far as we have synced. Do we cap at 24 months for
   consistency across channels?
9. **Base-currency change (E13).** Hard-block with forced recalculation, or store both original
   and base and allow re-basing? The former is simpler and safer; the latter is friendlier for a
   merchant expanding from KSA to Türkiye.
10. **Should `order_profits` be a table or a materialized view?** Chosen as a table for write
    control and index freedom. Revisit if row counts pass ~50M.
11. **Does the free/entry plan include the profit engine?** The strategy says never gate
    capability by tier. Confirm that includes this, given it is the single most valuable feature
    we will have built.
12. **Arabic financial terminology.** Several terms above have more than one accepted rendering
    (`هامش المساهمة` for contribution margin, `الوارد أولاً يصرف أولاً` for FIFO). Needs sign-off
    from a native Arabic-speaking accountant, not a translator.
