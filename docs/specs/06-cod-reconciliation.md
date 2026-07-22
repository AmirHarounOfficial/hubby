# Spec 06 — COD Reconciliation

> Status: draft · Owner: Backend Architecture · Target: Laravel 12 / PHP 8.3 backend, Next.js 16 dashboard, Flutter mobile
> Related specs (referenced, not redefined): **Profit & Cost Engine** (`order_fees`), **Shipping & Labels** (`carriers`, `shipments`), **Automation Rules Engine**.

---

## 1. Why this exists (competitive rationale)

Cash on Delivery is not a payment method in MENA — it is the *default*. Across Saudi Arabia, Egypt, UAE, Jordan and Iraq, COD share of e-commerce orders typically sits somewhere between 40% and 70% depending on category and country (**assumption — directional, not sourced; do not put a number in marketing copy without a citation**). For a merchant in Riyadh or Cairo, "revenue" and "cash" are two different things separated by two to six weeks of carrier float.

Every incumbent in our competitive set models the Western prepaid flow, where money arrives at the moment of checkout:

| Product | COD model | Consequence |
|---|---|---|
| Linnworks | None. Orders are "paid" when the channel says paid. | A COD order looks like realised revenue on day 0. Cash forecasting is fiction. |
| Sellerboard | None. Amazon/marketplace settlement-centric. | Cannot represent "carrier is holding my money". |
| Rithum (ChannelAdvisor) | None. | Same. |

Nobody models RTO either. Return-to-origin on COD orders in MENA commonly runs 15–30% for fashion and impulse categories (**assumption — directional**), and each RTO costs the merchant a forward leg, a return leg, a restock, and sometimes the item. A P&L that ignores RTO overstates margin by a material amount, and a P&L that treats a COD order as revenue on day 0 overstates cash by weeks.

**The wedge.** Hubby becomes the only multi-channel ops platform where a MENA merchant can answer four questions no competitor can answer at all:

1. How much of my money is sitting in carriers' hands right now, and when is it due?
2. Which carrier remittance is short, by how much, and against which orders?
3. What is my true RTO rate by SKU, by city, by carrier — and what is it costing me?
4. Which customers should be forced to prepay because they habitually refuse COD?

This spec turns COD from an invisible liability into a first-class financial object.

---

## 2. Scope — in / out

### In scope

- A COD financial lifecycle attached to orders: expected → collected → remitted → reconciled.
- `cod_transactions` — one row per COD order, the merchant's ledger of what is owed to them.
- `cod_remittances` + `cod_remittance_lines` — carrier settlement batches and their raw statement rows.
- Statement import: CSV / XLSX upload, per-carrier column mappers, deduplication by file hash.
- A deterministic, tiered **reconciliation engine**: auto-match, tolerance-match, partial/shortfall, over-payment, ambiguous, unmatched — with a human resolution queue for everything that is not auto-matched.
- Variance and shortfall detection, aging, and overdue flagging.
- RTO capture (`cod_rto_events`) and RTO analytics by SKU / city / carrier / reason.
- Posting carrier COD fees, RTO fees and written-off shortfalls into `order_fees` so the Profit & Cost Engine is accurate.
- Cash-flow view: in-transit / collected / remitted / overdue with aging buckets.
- Customer-level COD risk scoring, exposed to the Automation Rules Engine as a condition.
- Per-org, per-carrier configuration: fee model, remittance cycle, tolerance thresholds, mapper.

### Out of scope

- Creating shipments, buying labels, or calling carrier booking APIs — that is the **Shipping & Labels** spec. This spec *consumes* `shipments` when present and degrades gracefully to AWB-string matching when not.
- Defining the `order_fees` table, profit formulas, or COGS — **Profit & Cost Engine** owns those. We only write rows.
- Bank feed ingestion / open banking. Bank receipt is recorded manually (amount + date + bank reference) in v1.
- Accounting-system export (Zoho Books, QuickBooks, Xero). Deliberately deferred; the data model is designed so it can be added later.
- Automatically forcing prepayment at the storefront. We *compute and expose* risk; the Automation Rules Engine and the merchant's storefront decide what to do with it.
- Multi-currency FX conversion. Each remittance is single-currency and must match the transactions' currency; cross-currency is rejected in v1.

---

## 3. Data model

### 3.0 Conventions observed from the repo

- Migrations are anonymous-class `return new class extends Migration`, named `YYYY_MM_DD_NNNNNN_verb_noun.php`. Recent files use a hand-assigned sequence (`2026_07_02_000004_...`), so this spec uses `2026_07_22_0000NN_...`.
- Money is `decimal(15,2)`, matching `orders.total`. Currency is `char(3)` (repo uses `string('currency', 3)`).
- **`orders` has no `organization_id`.** Tenancy is resolved through `orders.store_id → stores.organization_id` (see `OrderController::applyFilters`). COD queries are financial aggregates over hundreds of thousands of rows, and a `whereHas` join on every dashboard tile is unacceptable. **Decision: every COD table carries a denormalised `organization_id` FK.** It is populated on write from `$order->store->organization_id` and is immutable. This is a deliberate deviation from the existing convention, justified by query cost; it is enforced by a model observer and by a test.
- FKs use `foreignId(...)->constrained()->onDelete('cascade')` where the child is meaningless without the parent, `nullOnDelete()` otherwise.
- Secrets: the existing `integrations.access_token` is stored **in plaintext** (`text`, no cast). That is a pre-existing gap. New tables in this spec store carrier API credentials with Laravel's `encrypted:array` cast, and the spec explicitly does *not* follow the existing plaintext precedent.

### 3.1 `cod_carrier_profiles`

Per-organization, per-carrier configuration. Migration: `2026_07_22_000001_create_cod_carrier_profiles_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete` |
| `carrier_code` | `string(40)` | no | — | `aramex`, `smsa`, `naqel`, `jt`, `torod`, `other` |
| `display_name` | `string(120)` | no | — | shown in UI |
| `mapper_key` | `string(40)` | no | `'generic_csv'` | resolves a `StatementMapper` |
| `column_map` | `json` | yes | `null` | header→canonical overrides for `generic_csv` |
| `date_format` | `string(32)` | no | `'Y-m-d'` | PHP format used to parse statement dates |
| `decimal_separator` | `char(1)` | no | `'.'` | some Arabic-locale exports use `,` |
| `file_encoding` | `string(20)` | no | `'UTF-8'` | `Windows-1256` seen in the wild |
| `cod_fee_model` | `string(16)` | no | `'flat'` | `flat` \| `percent` \| `tiered` \| `carrier_reported` |
| `cod_fee_flat` | `decimal(15,2)` | no | `0.00` | |
| `cod_fee_percent` | `decimal(6,3)` | no | `0.000` | e.g. `1.500` = 1.5% |
| `cod_fee_min` | `decimal(15,2)` | yes | `null` | floor for percent/tiered |
| `cod_fee_max` | `decimal(15,2)` | yes | `null` | ceiling |
| `cod_fee_tiers` | `json` | yes | `null` | `[{"upto":500,"fee":15},{"upto":null,"fee":25}]` |
| `remittance_cycle_days` | `unsignedSmallInteger` | no | `14` | drives `due_at` |
| `tolerance_absolute` | `decimal(15,2)` | no | `1.00` | currency units |
| `tolerance_percent` | `decimal(6,3)` | no | `0.500` | percent of expected |
| `auto_match_enabled` | `boolean` | no | `true` | |
| `auto_post_fees` | `boolean` | no | `true` | write `order_fees` on reconcile |
| `api_enabled` | `boolean` | no | `false` | pull statements via carrier API |
| `api_credentials` | `text` | yes | `null` | **`encrypted:array` cast** |
| `api_last_pulled_at` | `timestamp` | yes | `null` | |
| `is_active` | `boolean` | no | `true` | |
| `timestamps` | | | | |

Indexes: `unique(['organization_id','carrier_code'])`, `index(['organization_id','is_active'])`.

### 3.2 `cod_transactions`

The merchant-side ledger. **One row per COD order.** Migration: `2026_07_22_000002_create_cod_transactions_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete`, denormalised |
| `store_id` | `foreignId` → `stores` | no | — | `cascadeOnDelete` |
| `order_id` | `foreignId` → `orders` | no | — | `cascadeOnDelete` |
| `shipment_id` | `unsignedBigInteger` | yes | `null` | FK to `shipments` added conditionally — see note |
| `remittance_id` | `foreignId` → `cod_remittances` | yes | `null` | `nullOnDelete` |
| `carrier_code` | `string(40)` | yes | `null` | denormalised from shipment |
| `awb_number` | `string(64)` | yes | `null` | **primary match key**; stored normalised (upper, alnum only) |
| `carrier_order_ref` | `string(120)` | yes | `null` | carrier's own reference if different from AWB |
| `currency` | `char(3)` | no | `'SAR'` | copied from `orders.currency` |
| `expected_amount` | `decimal(15,2)` | no | — | cash the carrier must collect |
| `collected_amount` | `decimal(15,2)` | yes | `null` | per carrier statement |
| `remitted_amount` | `decimal(15,2)` | yes | `null` | net paid to merchant |
| `carrier_cod_fee` | `decimal(15,2)` | no | `0.00` | |
| `carrier_shipping_fee` | `decimal(15,2)` | no | `0.00` | as deducted on the statement |
| `carrier_rto_fee` | `decimal(15,2)` | no | `0.00` | |
| `variance_amount` | `decimal(15,2)` | no | `0.00` | `collected − expected` (negative = shortfall) |
| `status` | `string(32)` | no | `'pending'` | see §4.1 |
| `match_type` | `string(20)` | yes | `null` | `auto_awb` \| `auto_ref` \| `auto_external_id` \| `auto_heuristic` \| `manual` |
| `match_confidence` | `decimal(5,2)` | yes | `null` | 0–100 |
| `dispatched_at` | `timestamp` | yes | `null` | |
| `collected_at` | `timestamp` | yes | `null` | cash in carrier's hands |
| `due_at` | `timestamp` | yes | `null` | `collected_at + remittance_cycle_days` |
| `remitted_at` | `timestamp` | yes | `null` | |
| `reconciled_at` | `timestamp` | yes | `null` | |
| `attempt_count` | `unsignedTinyInteger` | no | `0` | delivery attempts |
| `rto_reason_code` | `string(64)` | yes | `null` | set when `status = rto` |
| `customer_key` | `string(191)` | yes | `null` | normalised phone (E.164) else lowercased email — joins to risk |
| `delivery_city` | `string(120)` | yes | `null` | denormalised for analytics |
| `is_disputed` | `boolean` | no | `false` | |
| `dispute_note` | `text` | yes | `null` | |
| `fees_posted_at` | `timestamp` | yes | `null` | idempotency guard for `order_fees` |
| `metadata` | `json` | yes | `null` | |
| `timestamps` | | | | |

Indexes:

```
unique(['order_id'])                                  // one COD ledger row per order
index(['organization_id','status'])                   // dashboard tiles
index(['organization_id','carrier_code','status'])    // per-carrier views
index(['organization_id','due_at'])                   // aging / overdue sweep
index(['organization_id','customer_key'])             // risk rollup
index(['awb_number'])                                 // matcher probe
index(['remittance_id'])
index(['organization_id','collected_at'])             // cash-flow timeline
```

> **`shipment_id` FK note.** The `shipments` table is owned by the Shipping & Labels spec and may land after this one. The column is created as a plain `unsignedBigInteger` + index. A follow-up guarded migration (`2026_07_22_000009_add_shipment_fk_to_cod_tables.php`) adds the real constraint inside `if (Schema::hasTable('shipments'))` — matching the guarded style already used in `2026_05_06_090717_fix_orders_table_columns.php`.

### 3.3 `cod_remittances`

A carrier settlement batch. Migration: `2026_07_22_000003_create_cod_remittances_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete` |
| `carrier_code` | `string(40)` | no | — | |
| `carrier_account_ref` | `string(64)` | yes | `null` | merchant's account no. at the carrier |
| `reference` | `string(120)` | no | — | carrier batch / settlement number |
| `statement_date` | `date` | yes | `null` | |
| `period_start` | `date` | yes | `null` | |
| `period_end` | `date` | yes | `null` | |
| `currency` | `char(3)` | no | `'SAR'` | |
| `expected_amount` | `decimal(15,2)` | no | `0.00` | Σ `expected_amount` of matched txns |
| `statement_gross_amount` | `decimal(15,2)` | no | `0.00` | Σ collected per statement |
| `statement_fee_amount` | `decimal(15,2)` | no | `0.00` | Σ COD + shipping + RTO fees |
| `statement_net_amount` | `decimal(15,2)` | no | `0.00` | what the carrier claims it paid |
| `received_amount` | `decimal(15,2)` | yes | `null` | what actually hit the bank |
| `received_at` | `timestamp` | yes | `null` | |
| `bank_reference` | `string(120)` | yes | `null` | |
| `variance_amount` | `decimal(15,2)` | no | `0.00` | `received − statement_net` |
| `line_count` | `unsignedInteger` | no | `0` | |
| `matched_count` | `unsignedInteger` | no | `0` | |
| `partial_count` | `unsignedInteger` | no | `0` | |
| `unmatched_count` | `unsignedInteger` | no | `0` | |
| `ambiguous_count` | `unsignedInteger` | no | `0` | |
| `status` | `string(24)` | no | `'draft'` | see §4.3 |
| `source` | `string(16)` | no | `'csv'` | `csv` \| `xlsx` \| `api` \| `manual` |
| `import_id` | `foreignId` → `cod_statement_imports` | yes | `null` | `nullOnDelete` |
| `created_by` | `foreignId` → `users` | yes | `null` | `nullOnDelete` |
| `closed_at` | `timestamp` | yes | `null` | |
| `metadata` | `json` | yes | `null` | |
| `timestamps` | | | | |

Indexes: `unique(['organization_id','carrier_code','reference'])`, `index(['organization_id','status'])`, `index(['organization_id','statement_date'])`.

### 3.4 `cod_statement_imports`

Migration: `2026_07_22_000004_create_cod_statement_imports_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete` |
| `carrier_code` | `string(40)` | no | — | |
| `mapper_key` | `string(40)` | no | — | resolved mapper |
| `original_filename` | `string(255)` | no | — | |
| `stored_path` | `string(500)` | no | — | private disk; retained 90 days |
| `file_hash` | `char(64)` | no | — | SHA-256 of raw bytes |
| `mime_type` | `string(100)` | yes | `null` | |
| `size_bytes` | `unsignedBigInteger` | no | `0` | |
| `row_count` | `unsignedInteger` | no | `0` | data rows detected |
| `parsed_count` | `unsignedInteger` | no | `0` | |
| `error_count` | `unsignedInteger` | no | `0` | |
| `status` | `string(20)` | no | `'pending'` | `pending`\|`parsing`\|`parsed`\|`failed` |
| `errors` | `json` | yes | `null` | `[{row, column, message}]`, capped at 200 entries |
| `detected_headers` | `json` | yes | `null` | for the mapping UI |
| `uploaded_by` | `foreignId` → `users` | yes | `null` | `nullOnDelete` |
| `timestamps` | | | | |

Indexes: `unique(['organization_id','file_hash'])` — a re-upload of the same file is rejected with 409, which is the single cheapest defence against double-counting cash. `index(['organization_id','status'])`.

### 3.5 `cod_remittance_lines`

The raw statement rows, preserved verbatim. This is the audit trail; we never mutate `raw`. Migration: `2026_07_22_000005_create_cod_remittance_lines_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete` |
| `remittance_id` | `foreignId` → `cod_remittances` | no | — | `cascadeOnDelete` |
| `import_id` | `foreignId` → `cod_statement_imports` | yes | `null` | `nullOnDelete` |
| `cod_transaction_id` | `foreignId` → `cod_transactions` | yes | `null` | `nullOnDelete` |
| `row_number` | `unsignedInteger` | no | — | 1-based, excluding header |
| `raw` | `json` | no | — | original row as `{header: value}` |
| `awb_number` | `string(64)` | yes | `null` | normalised |
| `carrier_order_ref` | `string(120)` | yes | `null` | |
| `external_order_id` | `string(120)` | yes | `null` | merchant order no. as printed by carrier |
| `customer_phone` | `string(32)` | yes | `null` | normalised to E.164 when possible |
| `collected_amount` | `decimal(15,2)` | yes | `null` | |
| `cod_fee` | `decimal(15,2)` | no | `0.00` | |
| `shipping_fee` | `decimal(15,2)` | no | `0.00` | |
| `rto_fee` | `decimal(15,2)` | no | `0.00` | |
| `net_amount` | `decimal(15,2)` | yes | `null` | |
| `collected_at` | `date` | yes | `null` | |
| `delivery_status` | `string(40)` | yes | `null` | carrier's own wording |
| `delivery_city` | `string(120)` | yes | `null` | |
| `match_status` | `string(20)` | no | `'unmatched'` | see §4.2 |
| `match_score` | `decimal(5,2)` | yes | `null` | |
| `match_type` | `string(20)` | yes | `null` | |
| `variance_amount` | `decimal(15,2)` | no | `0.00` | |
| `resolution` | `string(32)` | yes | `null` | `accepted`\|`written_off`\|`disputed`\|`ignored`\|`deferred` |
| `resolved_by` | `foreignId` → `users` | yes | `null` | `nullOnDelete` |
| `resolved_at` | `timestamp` | yes | `null` | |
| `note` | `text` | yes | `null` | |
| `timestamps` | | | | |

Indexes: `index(['remittance_id','match_status'])`, `index(['organization_id','awb_number'])`, `index(['cod_transaction_id'])`, `unique(['remittance_id','row_number'])`.

### 3.6 `cod_rto_events`

Migration: `2026_07_22_000006_create_cod_rto_events_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete` |
| `order_id` | `foreignId` → `orders` | no | — | `cascadeOnDelete` |
| `cod_transaction_id` | `foreignId` → `cod_transactions` | yes | `null` | `nullOnDelete` |
| `shipment_id` | `unsignedBigInteger` | yes | `null` | see FK note in §3.2 |
| `carrier_code` | `string(40)` | yes | `null` | |
| `event_type` | `string(32)` | no | — | `failed_attempt`\|`refused`\|`unreachable`\|`address_invalid`\|`out_of_coverage`\|`rto_initiated`\|`rto_in_transit`\|`rto_received`\|`restocked`\|`lost` |
| `attempt_number` | `unsignedTinyInteger` | no | `1` | |
| `reason_code` | `string(64)` | yes | `null` | normalised (§6.6) |
| `reason_text` | `string(255)` | yes | `null` | carrier's raw wording |
| `occurred_at` | `timestamp` | no | — | |
| `delivery_city` | `string(120)` | yes | `null` | |
| `delivery_region` | `string(120)` | yes | `null` | |
| `rto_fee` | `decimal(15,2)` | no | `0.00` | |
| `goods_value` | `decimal(15,2)` | no | `0.00` | order total at RTO time |
| `restocked` | `boolean` | no | `false` | |
| `restocked_at` | `timestamp` | yes | `null` | |
| `inventory_log_id` | `foreignId` → `inventory_logs` | yes | `null` | `nullOnDelete`; links restock movement |
| `customer_key` | `string(191)` | yes | `null` | |
| `raw` | `json` | yes | `null` | |
| `timestamps` | | | | |

Indexes: `index(['organization_id','occurred_at'])`, `index(['organization_id','event_type'])`, `index(['organization_id','delivery_city'])`, `index(['organization_id','carrier_code','event_type'])`, `index(['order_id'])`, `index(['organization_id','customer_key'])`.

### 3.7 `cod_customer_risk`

Rolled-up, denormalised customer COD behaviour. Migration: `2026_07_22_000007_create_cod_customer_risk_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete` |
| `customer_key` | `string(191)` | no | — | E.164 phone, else `email:<lowercased>` |
| `customer_phone` | `string(32)` | yes | `null` | |
| `customer_email` | `string(255)` | yes | `null` | |
| `customer_name` | `string(255)` | yes | `null` | last seen |
| `cod_orders_total` | `unsignedInteger` | no | `0` | |
| `cod_orders_delivered` | `unsignedInteger` | no | `0` | |
| `cod_orders_rto` | `unsignedInteger` | no | `0` | |
| `cod_orders_refused` | `unsignedInteger` | no | `0` | subset of RTO |
| `cod_value_delivered` | `decimal(15,2)` | no | `0.00` | |
| `cod_value_lost` | `decimal(15,2)` | no | `0.00` | RTO fees + unrecovered goods |
| `rto_rate` | `decimal(6,4)` | no | `0.0000` | raw, not smoothed |
| `risk_score` | `unsignedTinyInteger` | no | `0` | 0–100, see §4.6 |
| `risk_band` | `string(16)` | no | `'unknown'` | `unknown`\|`low`\|`medium`\|`high`\|`blocked` |
| `last_order_at` | `timestamp` | yes | `null` | |
| `last_rto_at` | `timestamp` | yes | `null` | |
| `manual_override_band` | `string(16)` | yes | `null` | wins over computed band |
| `override_reason` | `string(255)` | yes | `null` | |
| `override_by` | `foreignId` → `users` | yes | `null` | `nullOnDelete` |
| `override_until` | `timestamp` | yes | `null` | |
| `computed_at` | `timestamp` | yes | `null` | |
| `timestamps` | | | | |

Indexes: `unique(['organization_id','customer_key'])`, `index(['organization_id','risk_band'])`, `index(['organization_id','risk_score'])`.

### 3.8 Additive column on `orders`

Migration: `2026_07_22_000008_add_cod_flags_to_orders_table.php` — guarded with `Schema::hasColumn`, matching repo style.

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `payment_method` | `string(40)` | yes | `null` | `cod`, `card`, `apple_pay`, `mada`, `tabby`, … |
| `is_cod` | `boolean` | no | `false` | derived; indexed with `store_id` |
| `customer_phone` | `string(32)` | yes | `null` | `orders` currently stores only name + email; COD matching and WhatsApp both need phone |
| `shipping_city` | `string(120)` | yes | `null` | |
| `shipping_country` | `char(2)` | yes | `null` | |

Index: `index(['store_id','is_cod'])`.

> `payment_method` / phone / city are extracted from `orders.raw_data` by a backfill command (§12.2). Extraction per platform is listed in §6.7.

### 3.9 `order_fees` rows written by this spec

Owned by the Profit & Cost Engine; we only insert. Fee types used:

| `fee_type` | Source | Sign |
|---|---|---|
| `cod_fee` | `cod_transactions.carrier_cod_fee` | negative (cost) |
| `cod_shipping_fee` | `carrier_shipping_fee` when the statement deducts it and Shipping & Labels has not already posted it | negative |
| `rto_fee` | `cod_rto_events.rto_fee` | negative |
| `cod_shortfall` | `variance_amount` when negative and resolution = `written_off` | negative |
| `cod_overage` | `variance_amount` when positive and accepted | positive |

Idempotency: fees are posted once per `cod_transaction`, guarded by `fees_posted_at` and an upsert on `(order_id, fee_type, source_ref)` where `source_ref = "cod_txn:{id}"`. **Assumption:** `order_fees` exposes a `source_ref` (or equivalent) string column; if it does not, the Profit spec must add one, otherwise re-running reconciliation double-posts.

---

## 4. Domain logic

### 4.1 `cod_transactions.status` state machine

```
                    ┌──────────────┐
  order is COD ───► │   pending    │  no shipment yet
                    └──────┬───────┘
                           │ shipment dispatched
                           ▼
                    ┌──────────────┐
        ┌───────────│  in_transit  │───────────┐
        │           └──────┬───────┘           │
        │ delivery failed  │ delivered + cash  │ order cancelled
        ▼                  ▼                   ▼
  ┌───────────┐      ┌───────────┐       ┌───────────┐
  │    rto    │      │ collected │       │ cancelled │  (terminal)
  └─────┬─────┘      └─────┬─────┘       └───────────┘
        │ goods back        │ appears on a remittance statement
        ▼                   ▼
  ┌───────────┐      ┌──────────────────────┐
  │ rto_closed│      │ remitted             │ (statement says paid)
  └───────────┘      └──────┬───────────────┘
   (terminal)               │
                            │ bank receipt recorded + variance within tolerance
                            ▼
                     ┌───────────┐
                     │reconciled │ (terminal, happy path)
                     └───────────┘

  Off-path, reachable from collected / remitted:
   short_paid   — statement collected < expected − tolerance
   over_paid    — statement collected > expected + tolerance
   disputed     — merchant raised it with the carrier
   written_off  — merchant absorbed the loss (terminal)
   overdue      — due_at passed and status still collected  (a *flag*, see below)
```

**`overdue` is a derived flag, not a status.** Storing it as a status would destroy the real state. The nightly `AgeCodTransactionsJob` computes `is_overdue = status = 'collected' AND due_at < now()` on read; the API exposes it as a boolean and an `aging_bucket`. This avoids a status column that flip-flops.

Legal transitions are enforced in `CodTransactionService::transition()`; an illegal transition throws `InvalidCodTransitionException` and is logged, never silently swallowed.

### 4.2 `cod_remittance_lines.match_status`

| Value | Meaning |
|---|---|
| `unmatched` | no candidate transaction found |
| `matched` | exactly one candidate, amount within tolerance |
| `tolerance` | exactly one candidate, amount differs but within tolerance |
| `partial` | exactly one candidate, `collected < expected − tolerance` (shortfall) |
| `over` | exactly one candidate, `collected > expected + tolerance` |
| `ambiguous` | ≥2 candidates scored above threshold; needs a human |
| `duplicate` | the same AWB already matched on an earlier line of any remittance |
| `ignored` | human said "not mine" (e.g. carrier's own adjustment row) |

### 4.3 `cod_remittances.status`

`draft` → `importing` → `imported` → `reconciling` → (`reconciled` | `discrepancy`) → `closed`
`failed` is reachable from `importing`/`reconciling`.

- `reconciled`: every line is `matched`/`tolerance`/`ignored`, **and** `received_amount` is recorded and within tolerance of `statement_net_amount`.
- `discrepancy`: imported and matched, but at least one of {unmatched lines, partial lines, ambiguous lines, bank variance beyond tolerance} remains.
- `closed`: a human has explicitly closed it, with every non-clean line carrying a `resolution`. Closing is irreversible via the API (reopen requires a support action) — this is the accounting cut-off.

### 4.4 The reconciliation engine

Input: a `cod_remittance` with parsed `cod_remittance_lines`. Output: each line assigned a `match_status` and, when matched, a `cod_transaction_id`.

**Candidate universe.** Restricted before any scoring, to keep this a bounded query rather than a cross-join:

```sql
SELECT * FROM cod_transactions
WHERE organization_id = :org
  AND carrier_code    = :carrier          -- or IS NULL, to catch mis-tagged rows
  AND currency        = :currency
  AND status IN ('in_transit','collected','rto','short_paid','over_paid','pending')
  AND remittance_id IS NULL               -- not already settled
  AND dispatched_at BETWEEN :period_start - 90 days AND :period_end + 7 days
```

**Tiered matching.** Tiers are evaluated in order; the first tier that yields exactly one candidate wins. Confidence is fixed per tier, not fudged.

| Tier | Key | Confidence | `match_type` |
|---|---|---|---|
| T0 | `normalise(line.awb_number) == normalise(txn.awb_number)` | 100 | `auto_awb` |
| T1 | `line.carrier_order_ref == txn.carrier_order_ref` | 95 | `auto_ref` |
| T2 | `line.external_order_id == order.external_id` (exact, then with common prefixes `#`, `SA-`, `ORD-` stripped) | 90 | `auto_external_id` |
| T3 | heuristic composite (below) | 60–85 | `auto_heuristic` |

`normalise()` = uppercase, strip everything outside `[A-Z0-9]`, drop leading zeros. Carriers routinely export `aramex-1234 5678` for AWB `12345678`.

**T3 heuristic score** (only runs if T0–T2 produced nothing, and only if `auto_match_enabled`):

```
score =  40 · [last9(line.customer_phone) == last9(order.customer_phone)]
      +  30 · [ |line.collected_amount − txn.expected_amount| ≤ tolerance ]
      +  15 · [ |line.collected_at − txn.dispatched_at| ≤ 30 days ]
      +  10 · [ normalise(line.delivery_city) == normalise(txn.delivery_city) ]
      +   5 · [ line.currency == txn.currency ]
```

- `score ≥ 85` and exactly one candidate at that score → auto-match.
- `score ≥ 60` with one or more candidates → `ambiguous`, surfaced with the top 5 candidates ranked, for a one-click human match.
- `score < 60` → `unmatched`.

Ties at the top score are always `ambiguous`. We never coin-flip on money.

**Tolerance** for a transaction:

```
tolerance = max(profile.tolerance_absolute,
                expected_amount × profile.tolerance_percent / 100)
```

Default `max(1.00, 0.5%)` — a 300 SAR order tolerates 1.50 SAR, which absorbs carrier rounding without hiding real shortfalls.

**Amount classification** once matched:

```
variance = collected_amount − expected_amount
|variance| ≤ tolerance   → matched (or tolerance if variance ≠ 0)
variance < −tolerance    → partial       → txn.status = short_paid
variance > +tolerance    → over          → txn.status = over_paid
collected_amount == 0 and delivery_status ∈ RTO set → line is an RTO row, not a payment row
```

**Duplicate guard.** Before assigning, the engine checks whether the target `cod_transaction` already has a non-null `remittance_id`. If so the line becomes `duplicate` and is never auto-accepted — a carrier re-sending a batch must not pay the merchant twice on paper.

**Idempotency.** `ReconcileCodRemittanceJob` is safe to re-run: it resets only lines whose `resolution IS NULL` and re-scores them, leaving human decisions intact.

**Atomicity.** Each remittance reconciles inside a single DB transaction, with `SELECT … FOR UPDATE` on the candidate transactions (`lockForUpdate()`), so two concurrent imports for the same carrier cannot both claim the same order.

### 4.5 Cash-flow model

Given a date range and optional carrier filter, all sums scoped to `organization_id`:

```
cash_in_transit  = Σ expected_amount  where status ∈ {pending, in_transit}
cash_collected   = Σ collected_amount where status ∈ {collected, short_paid, over_paid}
                                        and remittance_id IS NULL
cash_remitted    = Σ remitted_amount  where status ∈ {remitted, reconciled}
cash_overdue     = Σ collected_amount where status = 'collected' and due_at < now()
cash_at_risk     = Σ expected_amount  where status ∈ {rto, disputed}
shortfall_open   = Σ |variance_amount| where status = 'short_paid'
                                        and (resolution IS NULL or 'disputed')
```

**Aging buckets** are computed from `collected_at` for uncollected-from-carrier money, and from `due_at` for overdue money:

| Bucket | Definition |
|---|---|
| `current` | `due_at ≥ today` |
| `1_7` | 1–7 days past `due_at` |
| `8_14` | 8–14 |
| `15_30` | 15–30 |
| `31_60` | 31–60 |
| `60_plus` | > 60 |

`due_at` is set when the transaction enters `collected`: `due_at = collected_at + profile.remittance_cycle_days`. If `collected_at` is unknown (carrier statement lacks a date), fall back to `dispatched_at + remittance_cycle_days + 3`.

**DSO for COD** (surface as a headline metric, because no competitor has it):

```
cod_dso = mean(remitted_at − collected_at) over transactions reconciled in the window
```

### 4.6 RTO analytics

```
rto_rate(dimension) = rto_orders(dimension) / cod_orders_shipped(dimension)
```

where `cod_orders_shipped` counts transactions that reached `in_transit` or beyond in the window, and `rto_orders` counts those whose terminal state in the window is `rto` or `rto_closed`.

Dimensions: `sku` (via `order_items.sku`), `city` (`delivery_city`), `carrier` (`carrier_code`), `reason_code`, `region`, `store`.

**Cost of an RTO:**

```
rto_cost = forward_shipping_fee + rto_fee + handling_cost
         + (restocked ? 0 : goods_cost)
```

`handling_cost` comes from the org's COD settings (`handling_cost_per_rto`, stored in `cod_carrier_profiles.metadata` or org settings — **open question §15.4**). `goods_cost` comes from the Profit & Cost Engine's COGS. If COGS is unavailable, the cost is reported without the goods component and flagged `partial: true` in the response rather than silently understated.

**Statistical guard.** Any dimension slice with fewer than 20 shipped COD orders is returned with `low_confidence: true` and the UI greys it. A SKU with 1 order and 1 RTO is not a 100% RTO rate; it is noise, and presenting it as a fact would train merchants to distrust the whole feature.

### 4.7 COD fee calculation

Used when `cod_fee_model ≠ carrier_reported` (i.e. we predict the fee before the statement arrives, so profit is right on day 1):

```
flat     → fee = cod_fee_flat
percent  → fee = clamp(expected_amount × cod_fee_percent / 100, cod_fee_min, cod_fee_max)
tiered   → fee = first tier where expected_amount ≤ tier.upto (null upto = catch-all)
carrier_reported → fee = 0 until the statement arrives, then line.cod_fee
```

When the statement arrives, the **carrier-reported fee always wins** and the predicted `order_fees` row is replaced (same `source_ref`, upserted). A `cod_fee_estimate_variance` metric tracks how wrong our prediction was per carrier — that number is itself a selling point.

### 4.8 Risk scoring

Computed per `(organization_id, customer_key)` by `RecalculateCodRiskJob`:

```
smoothed_rto  = (cod_orders_rto + 1) / (cod_orders_total + 5)   // Laplace prior ≈ 20%
recency       = cod_orders_rto > 0 ? exp(−days_since_last_rto / 90) : 0
confidence    = min(cod_orders_total / 5, 1.0)
raw           = 0.70 · smoothed_rto + 0.30 · recency
risk_score    = round(100 · raw · (0.5 + 0.5 · confidence))
```

The Laplace prior means a brand-new customer scores ~14, not 0 and not 100 — we neither vouch for nor condemn someone we have never shipped to. The `confidence` term keeps a 1-order customer from being branded on a single data point.

Bands: `0–24 low` · `25–49 medium` · `50–74 high` · `75–100 blocked`.
Hard rule that overrides the score: `cod_orders_rto ≥ 3 AND rto_rate ≥ 0.50 → blocked`.
`manual_override_band` (with `override_until`) always wins over both.

Exposed to the **Automation Rules Engine** as conditions `cod_risk_score`, `cod_risk_band`, `customer_rto_count`, so a merchant can write: *"when a new COD order arrives and `cod_risk_band` ∈ {high, blocked}, tag the order `verify-payment` and send the COD confirmation WhatsApp template"* (see Spec 07).

**Privacy note.** `customer_key` is a normalised phone/email and is PII. It is scoped to a single organization and never shared across tenants — a customer who refuses COD at merchant A carries no reputation to merchant B. That is both a legal position and a correct one; cross-tenant reputation would need explicit consent and a very different legal review.

### 4.9 Edge-case rules baked into the engine

| Case | Rule |
|---|---|
| Partial delivery (2 of 3 items accepted) | Carrier collects less than `expected_amount` by design. Line matches as `partial`; merchant resolves with `accepted` + a reason, which posts a `cod_shortfall` fee and updates the order — it is **not** a carrier debt. |
| Order edited after dispatch | `expected_amount` is frozen at dispatch (`dispatched_at`). Later edits to `orders.total` do not retroactively change what the carrier was told to collect. A `expected_amount_locked_at` is stored in `metadata`. |
| Split shipment, one order | v1 assumption: one `cod_transaction` per order. If two shipments each carry COD for the same order, the second is recorded as a `cod_remittance_line` matched manually with a note. **Open question §15.1.** |
| Currency mismatch | Line currency ≠ transaction currency → never auto-matched, forced `ambiguous`. |
| Negative statement rows | Carriers post adjustment/claw-back rows with negative amounts. These match to a transaction and are recorded as `over`/`partial` reversals, or `ignored` if they are carrier-level (fuel surcharge, monthly fee). Carrier-level rows are detected by "no AWB and no order ref" and default to `ignored` with a visible count. |
| Statement arrives before we know the order | Line stays `unmatched` and is re-scored automatically for 30 days by the nightly job before it is escalated. |
| Cash collected but carrier never remits | Transaction ages past `due_at` → `overdue` flag, aging bucket climbs, `CodOverdueDetected` event fires at 7/30/60 days. |
| Order cancelled while in transit | Transaction → `cancelled`; if a statement later shows collection, it is `ambiguous`, never auto-matched. |

---

## 5. Backend

### 5.1 Models — `backend/app/Models/`

| Model | Notes |
|---|---|
| `CodTransaction` | `$fillable` per §3.2; casts: money → `decimal:2`, timestamps → `datetime`, `metadata` → `array`, `is_disputed` → `boolean`. Relations: `order()`, `store()`, `organization()`, `remittance()`, `lines()` (`hasMany CodRemittanceLine`), `rtoEvents()`. Scopes: `scopeForOrganization($q,$id)`, `scopeOverdue`, `scopeUnsettled`. Accessors: `getIsOverdueAttribute()`, `getAgingBucketAttribute()`. |
| `CodRemittance` | relations `lines()`, `import()`, `creator()`; `scopeForOrganization`. |
| `CodRemittanceLine` | relations `remittance()`, `codTransaction()`, `resolver()`; casts `raw` → `array`. |
| `CodStatementImport` | casts `errors`, `detected_headers` → `array`. |
| `CodRtoEvent` | casts `raw` → `array`, `restocked` → `boolean`. |
| `CodCustomerRisk` | casts numeric; accessor `getEffectiveBandAttribute()` applying override + expiry. |
| `CodCarrierProfile` | **`protected $casts = ['api_credentials' => 'encrypted:array', …]`**. `$hidden = ['api_credentials']`. |

`Order` gains: `codTransaction(): HasOne`, `rtoEvents(): HasMany`, and `$fillable` additions `payment_method`, `is_cod`, `customer_phone`, `shipping_city`, `shipping_country`, with `'is_cod' => 'boolean'` cast.

**Tenant guard.** A `BelongsToOrganization` trait adds a `booted()` hook that (a) refuses to save a model whose `organization_id` is null and (b) provides `forOrganization()`. We deliberately do **not** add a global scope keyed off a request header, because jobs run outside a request; scoping stays explicit at the query site, as the existing controllers do.

### 5.2 Services — `backend/app/Services/Cod/`

| Class | Responsibility |
|---|---|
| `CodTransactionService` | Create a transaction from an order (`createForOrder`), `transition()` with the §4.1 state machine, `attachShipment()`, `markCollected()`, `recordRto()`. |
| `CodStatementImportService` | Store the upload, hash it, resolve the mapper, dispatch parsing, create the remittance + lines. |
| `Statements\StatementMapperInterface` | `key(): string`, `sniff(array $headers): bool`, `map(array $row, CodCarrierProfile $p): array` (canonical shape), `requiredHeaders(): array`. |
| `Statements\AramexStatementMapper`, `SmsaStatementMapper`, `NaqelStatementMapper`, `JtExpressStatementMapper`, `TorodStatementMapper`, `GenericCsvStatementMapper` | Per-carrier column mapping (§6). |
| `Statements\StatementMapperFactory` | `make(string $mapperKey)`; `detect(array $headers)` tries each mapper's `sniff()` and returns the best. Mirrors the existing `IntegrationFactory` pattern. |
| `CodReconciliationService` | The §4.4 engine. `reconcile(CodRemittance $r): ReconciliationResult`. Pure-ish: takes lines + candidates, returns decisions; persistence happens in the job. |
| `CodMatchScorer` | The T3 heuristic. Unit-testable in isolation with no DB. |
| `CodFeePostingService` | Posts/updates `order_fees` rows (§3.9), idempotent on `source_ref`. |
| `CodCashFlowService` | §4.5 aggregates. All queries indexed; no `whereHas`. |
| `RtoAnalyticsService` | §4.6 with the low-confidence guard. |
| `CodRiskScoringService` | §4.8. `scoreFor(string $customerKey, int $orgId)` and `recalculateOrganization(int $orgId)`. |
| `Carriers\CarrierStatementApiInterface` | `supportsStatements(): bool`, `fetchStatements(CodCarrierProfile $p, Carbon $from, Carbon $to): iterable`. Implemented only where a real API exists (§6). |

### 5.3 Jobs — `backend/app/Jobs/`

| Job | Trigger | Notes |
|---|---|---|
| `ParseCodStatementJob(CodStatementImport $import)` | after upload | Streams the file (never `file()` the whole thing — 50k-row statements are normal). Writes `cod_remittance_lines` in chunks of 500. On success dispatches `ReconcileCodRemittanceJob`. `tries = 3`, `backoff = [30,120,600]`. |
| `ReconcileCodRemittanceJob(CodRemittance $r)` | after parse, or manual re-run | Runs §4.4 in a DB transaction with `lockForUpdate`. `WithoutOverlapping($r->id)`. |
| `RematchUnmatchedCodLinesJob` | nightly 02:00 | Re-scores `unmatched` lines younger than 30 days against newly arrived transactions. |
| `AgeCodTransactionsJob` | nightly 03:00 | Recomputes overdue flags, fires `CodOverdueDetected` at 7/30/60-day thresholds (once each, tracked in `metadata.overdue_alerts`). |
| `RecalculateCodRiskJob(?int $organizationId)` | nightly 04:00, and on `RtoRecorded` | Chunked rollup. |
| `PostCodFeesJob(CodTransaction $t)` | on `CodRemittanceReconciled` | Wraps `CodFeePostingService`. |
| `SyncCarrierCodStatementsJob(CodCarrierProfile $p)` | hourly, only where `api_enabled` | Pulls statements from carriers that actually expose an API. |
| `BackfillCodTransactionsJob(int $storeId)` | one-off, §12.2 | Creates transactions for historical COD orders. |

`routes/console.php` additions (matching the existing `Schedule::job(...)` style):

```php
Schedule::job(new RematchUnmatchedCodLinesJob)->dailyAt('02:00');
Schedule::job(new AgeCodTransactionsJob)->dailyAt('03:00');
Schedule::job(new RecalculateCodRiskJob)->dailyAt('04:00');
Schedule::job(new SyncCarrierCodStatementsJob)->hourly();
```

### 5.4 Events — `backend/app/Events/Cod/`

`CodTransactionCreated`, `CodCashCollected`, `CodRtoRecorded`, `CodRemittanceImported`, `CodRemittanceReconciled`, `CodVarianceDetected`, `CodOverdueDetected`, `CodRiskBandChanged`.

Listeners:
- `CreateCodNotification` — writes a row to the existing `notifications` table (`organization_id`, `title`, `message`, `type`) exactly as `SyncOrdersJob` does today. Types: `warning` for variance/overdue, `success` for a clean reconcile, `error` for a failed import.
- `NotifyAutomationRules` — hands the event to the Automation Rules Engine so merchants can wire their own reactions (e.g. `CodRiskBandChanged → blocked` sends a WhatsApp COD confirmation).
- `PostCodFees` — dispatches `PostCodFeesJob`.

### 5.5 API endpoints

All under `Route::middleware('auth:sanctum')->group(... ->middleware('org.member') ...)` in `routes/api.php`, so every request carries `Authorization: Bearer <sanctum token>` and `X-Organization-Id`. Grouped as `Route::prefix('cod')->group(...)` with `App\Http\Controllers\Cod\*` controllers.

Standard error envelope follows Laravel's default: `422` `{ "message": ..., "errors": { field: [msg] } }`; `403` `{ "message": "..." }`.

#### Transactions

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/cod/transactions` | List / filter |
| `GET` | `/api/cod/transactions/{id}` | Detail with order, lines, RTO events |
| `PATCH` | `/api/cod/transactions/{id}` | Manual correction |
| `POST` | `/api/cod/transactions/{id}/dispute` | Open a dispute |
| `POST` | `/api/cod/transactions/{id}/write-off` | Absorb a shortfall |
| `GET` | `/api/cod/transactions/export` | CSV stream (mirrors `OrderController::export`) |

`GET /api/cod/transactions` query params:

```
status        string|array   in the §4.1 enum
carrier_code  string
store_id      int
overdue       boolean
aging_bucket  current|1_7|8_14|15_30|31_60|60_plus
from, to      date (Y-m-d), applied to collected_at (or dispatched_at when status=in_transit)
search        string   → matches awb_number, order.external_id, customer_name, customer_phone
min_variance  numeric  → |variance_amount| ≥ n
sort          due_at|collected_at|expected_amount|variance_amount  (prefix '-' for desc)
per_page      int (default 25, max 200)
```

Response `200` — Laravel paginator envelope, matching `OrderController::index`:

```json
{
  "data": [{
    "id": 8811,
    "order": { "id": 4412, "external_id": "#1043", "customer_name": "Sara A.", "customer_phone": "+9665...", "total": "349.00" },
    "carrier_code": "aramex",
    "awb_number": "12345678901",
    "currency": "SAR",
    "expected_amount": "349.00",
    "collected_amount": "347.50",
    "remitted_amount": null,
    "carrier_cod_fee": "12.00",
    "variance_amount": "-1.50",
    "status": "short_paid",
    "is_overdue": true,
    "aging_bucket": "8_14",
    "days_outstanding": 11,
    "match_type": "auto_awb",
    "match_confidence": "100.00",
    "collected_at": "2026-07-08T00:00:00Z",
    "due_at": "2026-07-22T00:00:00Z",
    "remittance": { "id": 91, "reference": "ARX-STMT-2026-27" }
  }],
  "current_page": 1, "last_page": 12, "per_page": 25, "total": 288
}
```

`PATCH /api/cod/transactions/{id}` — validation:

```php
'status'          => ['sometimes','string', Rule::in(CodTransaction::STATUSES)],
'collected_amount'=> ['sometimes','numeric','min:0','max:99999999999.99'],
'collected_at'    => ['sometimes','date','before_or_equal:now'],
'carrier_cod_fee' => ['sometimes','numeric','min:0'],
'awb_number'      => ['sometimes','string','max:64'],
'dispute_note'    => ['sometimes','string','max:2000'],
```
A `status` change goes through the state machine; an illegal transition returns `422` with `{"errors":{"status":["Cannot move from reconciled to pending."]}}`.

#### Remittances

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/cod/remittances` | List; filters `carrier_code`, `status`, `from`, `to` |
| `POST` | `/api/cod/remittances` | Create a manual (no-file) remittance |
| `GET` | `/api/cod/remittances/{id}` | Header + counters + variance summary |
| `GET` | `/api/cod/remittances/{id}/lines` | Paginated lines; filter `match_status` |
| `POST` | `/api/cod/remittances/{id}/reconcile` | Re-run the engine → `202 {"job_queued":true}` |
| `POST` | `/api/cod/remittances/{id}/settle` | Record the bank receipt |
| `POST` | `/api/cod/remittances/{id}/close` | Accounting cut-off |
| `DELETE` | `/api/cod/remittances/{id}` | Only while `status ∈ {draft, imported, failed}`; else `409` |
| `POST` | `/api/cod/remittances/{id}/lines/{lineId}/match` | Manual match |
| `POST` | `/api/cod/remittances/{id}/lines/{lineId}/unmatch` | Detach |
| `POST` | `/api/cod/remittances/{id}/lines/{lineId}/resolve` | Set `resolution` + note |
| `GET` | `/api/cod/remittances/{id}/lines/{lineId}/candidates` | Top-5 ranked candidates for the ambiguous queue |

`POST /api/cod/remittances/{id}/settle`:

```json
{ "received_amount": 41230.75, "received_at": "2026-07-20", "bank_reference": "TRF-99182" }
```
Validation: `received_amount` `required|numeric|min:0`; `received_at` `required|date|before_or_equal:today`; `bank_reference` `nullable|string|max:120`.
Response `200` with the updated remittance including `variance_amount` and a computed `variance_within_tolerance` boolean. If the variance exceeds tolerance the remittance becomes `discrepancy` and `CodVarianceDetected` fires.

`POST .../lines/{lineId}/match`:
```json
{ "cod_transaction_id": 8811, "note": "Carrier used the old AWB" }
```
`422` if the target transaction already has a `remittance_id` — with a message naming the conflicting remittance. Never silently reassign settled money.

#### Statement import

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/api/cod/statements/import` | `multipart/form-data` |
| `GET` | `/api/cod/statements/imports` | History |
| `GET` | `/api/cod/statements/imports/{id}` | Status + errors (poll while parsing) |
| `POST` | `/api/cod/statements/preview` | Parse the first 20 rows without persisting — the mapping-confirmation step |
| `GET` | `/api/cod/statements/mappers` | Available mappers + their required headers |

`POST /api/cod/statements/import` validation:

```php
'file'            => ['required','file','mimes:csv,txt,xlsx,xls','max:20480'],   // 20 MB
'carrier_code'    => ['required','string','max:40','exists:cod_carrier_profiles,carrier_code'],
'mapper_key'      => ['nullable','string','max:40'],       // omit to auto-detect
'reference'       => ['nullable','string','max:120'],      // omit to derive from filename/content
'statement_date'  => ['nullable','date'],
'period_start'    => ['nullable','date'],
'period_end'      => ['nullable','date','after_or_equal:period_start'],
'currency'        => ['nullable','string','size:3'],
'column_map'      => ['nullable','array'],                 // generic mapper override
```

Responses: `202` `{"import_id":41,"remittance_id":91,"status":"parsing"}` · `409` `{"message":"This file was already imported on 2026-07-14 as ARX-STMT-2026-27.","import_id":38}` · `422` on unmappable headers, listing which required canonical fields could not be resolved.

The file is stored on a **private** disk (`storage/app/cod-statements/{org}/{uuid}.{ext}`), never public, and purged after 90 days by a scheduled prune.

#### Carrier profiles

`GET /api/cod/carriers` · `POST /api/cod/carriers` · `PUT /api/cod/carriers/{id}` · `DELETE /api/cod/carriers/{id}` · `POST /api/cod/carriers/{id}/test-connection`.
`api_credentials` is write-only: accepted on write, never returned; the response exposes `has_credentials: true|false` only.

#### Analytics & risk

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/api/cod/summary` | Cash-flow tiles (§4.5) |
| `GET` | `/api/cod/aging` | Buckets, optionally by carrier |
| `GET` | `/api/cod/rto/analytics` | `dimension=sku\|city\|carrier\|reason\|region`, `from`, `to`, `limit` |
| `GET` | `/api/cod/rto/events` | Raw event feed |
| `GET` | `/api/cod/risk/customers` | `band`, `min_score`, `search`, sorted by score desc |
| `GET` | `/api/cod/risk/customers/{customerKey}` | Detail + order history |
| `POST` | `/api/cod/risk/customers/{customerKey}/override` | `{band, reason, until}` |
| `DELETE` | `/api/cod/risk/customers/{customerKey}/override` | Clear |

`GET /api/cod/summary` response:

```json
{
  "currency": "SAR",
  "cash_in_transit": "128430.00",
  "cash_collected":  "64110.50",
  "cash_remitted":   "312990.00",
  "cash_overdue":    "18720.00",
  "cash_at_risk":    "9450.00",
  "shortfall_open":  "1204.25",
  "cod_dso_days": 16.4,
  "cod_order_share": 0.61,
  "rto_rate": 0.184,
  "by_carrier": [
    {"carrier_code":"aramex","in_transit":"81200.00","collected":"40100.00","overdue":"12400.00","rto_rate":0.21}
  ],
  "as_of": "2026-07-22T09:14:00Z"
}
```

`{customerKey}` is a phone number and must be URL-encoded; the route uses `->where('customerKey', '.*')` like the existing `/customers/{email}` route, and the controller re-normalises before lookup. **Never** log the raw key at info level.

### 5.6 Inbound webhooks

Carrier delivery events extend the existing `POST /api/webhooks/{platform}` route rather than inventing a parallel mechanism. `WebhookController` gains a `carrier` branch — but carrier webhooks are properly the Shipping & Labels spec's territory, so this spec defines only what COD needs from them:

- `delivered` + `cod_collected` → `CodTransactionService::markCollected($awb, $amount, $at)`
- `delivery_failed` / `refused` / `unreachable` → `recordRto()` creating a `cod_rto_events` row with `event_type = failed_attempt` and incrementing `attempt_count`
- `rto_initiated` / `rto_received` → transaction → `rto` / `rto_closed`

`VerifyWebhookSignature` is extended with per-carrier HMAC entries in the same `match` expression; carriers with no signing secret configured log a warning and pass, mirroring current behaviour. Every carrier webhook body is written to `webhook_logs` (`platform = "carrier:{code}"`) before processing, so an unrecognised payload is recoverable.

---

## 6. Per-carrier notes — and an honest statement about what is verified

> **Read this section as "design for the unknown", not "these are the columns".** Aramex publishes public API documentation for *shipping* (rates, label creation, tracking); **none of Aramex, SMSA, Naqel, J&T Express or Torod publishes a public, stable specification for COD remittance/settlement statements.** Those artefacts are per-merchant-contract Excel/CSV files emailed or downloaded from a merchant portal, and their columns differ between merchants and change without notice. **Every column name below is unverified and must be confirmed against a real statement from a design-partner merchant before a mapper ships.** The architecture is built on that assumption: mappers are data (`column_map`), not code, wherever we can manage it, and the generic mapper plus a mapping UI is the *primary* path — the named mappers are convenience presets.

### 6.1 Canonical line shape (what every mapper must produce)

```php
[
  'awb_number'        => ?string,   // normalised by the service, not the mapper
  'carrier_order_ref' => ?string,
  'external_order_id' => ?string,
  'customer_phone'    => ?string,
  'collected_amount'  => ?string,   // decimal string, never float
  'cod_fee'           => string,    // '0.00' default
  'shipping_fee'      => string,
  'rto_fee'           => string,
  'net_amount'        => ?string,
  'collected_at'      => ?string,   // Y-m-d
  'delivery_status'   => ?string,   // carrier wording, preserved
  'delivery_city'     => ?string,
  'currency'          => ?string,
]
```

Required to be resolvable for a mapper to be usable: **one of** {`awb_number`, `carrier_order_ref`, `external_order_id`} **and** `collected_amount`. Everything else is optional and improves match confidence.

### 6.2 Aramex (`aramex`)

- Shipping/tracking APIs are public and SOAP/REST-based; a `Tracking` call can supply delivery status and, on some contracts, COD collection status. **COD settlement statements are not part of the public API surface.**
- Statements observed in the market are Excel with an English header row, sometimes preceded by 3–8 metadata rows (account, period, logo). The mapper must therefore **locate the header row** rather than assume row 1 — implemented as "first row where ≥3 required headers resolve".
- Likely header aliases: `AWB` / `Airway Bill` / `Shipment Number` → `awb_number`; `Reference 1` / `Shipper Reference` → `external_order_id`; `COD Amount` / `Cash Amount` → `collected_amount`; `COD Fee` / `COD Charges` → `cod_fee`; `Delivery Date` → `collected_at`.
- COD fee model in the region is commonly percent-of-value with a floor. Default profile: `percent`, `1.000%`, `min 15.00`. **Unverified — merchant must confirm.**

### 6.3 SMSA Express (`smsa`)

- Saudi domestic; public APIs cover shipment creation and tracking. **No public settlement API.**
- Statements are typically CSV. Arabic column headers appear on some accounts; the mapper's alias table must include Arabic strings (`رقم البوليصة` → AWB, `المبلغ المحصل` → collected amount) and the profile's `file_encoding` may need `Windows-1256`.
- Alias guesses: `AWB No` / `Waybill` → `awb_number`; `Customer Ref` → `external_order_id`; `COD Value` → `collected_amount`; `Net Payable` → `net_amount`.

### 6.4 Naqel Express (`naqel`)

- Saudi/GCC. Public docs cover shipping; settlement statements are portal downloads. **Unverified.**
- Alias guesses: `Waybill No` → `awb_number`; `Client Reference` → `external_order_id`; `COD Amount` → `collected_amount`; `Deduction` → aggregate fee (needs splitting; if only an aggregate deduction is available, map it entirely to `cod_fee` and note it in the profile so the merchant knows the split is approximate).

### 6.5 J&T Express (`jt`) and Torod (`torod`)

- **J&T** operates across KSA/UAE/Egypt with an API that varies by country entity. Statement format almost certainly differs per country. Treat `jt` as a family: `mapper_key` may need `jt_sa`, `jt_eg`, `jt_ae`. The profile's `carrier_code` stays `jt` while `mapper_key` disambiguates.
- **Torod** is a Saudi shipping *aggregator* — it fronts multiple carriers. Its statement therefore carries a `carrier` column per row, meaning one Torod remittance can span Aramex, SMSA and others. The engine already handles this because matching is keyed on AWB, not carrier; but the candidate query must relax the `carrier_code` filter when `profile.metadata.is_aggregator = true`. **This is a real architectural requirement, and it is the reason the carrier filter in §4.4 is written as "or IS NULL".**

### 6.6 Normalised RTO reason codes

Carrier reason strings are free text and multilingual. `ReasonCodeNormaliser` maps them to a fixed vocabulary via a per-carrier keyword table (Arabic + English), defaulting to `other`:

`customer_refused` · `customer_unreachable` · `address_incomplete` · `address_wrong` · `customer_postponed` · `out_of_coverage` · `damaged` · `duplicate_order` · `price_dispute` · `delivery_window_missed` · `carrier_failure` · `other`

The raw string is always kept in `reason_text`. Unmapped strings are counted per carrier and surfaced in an ops dashboard so the keyword table can be grown from real data.

### 6.7 Detecting COD from each of the 7 integrations

Extraction rules for `orders.payment_method` / `is_cod`, applied in `SyncOrdersJob::mapOrderData()` and in the backfill. All are **assumptions to verify against live payloads**:

| Platform | Field in `raw_data` | COD when |
|---|---|---|
| Shopify | `gateway`, `payment_gateway_names[]`, `financial_status` | gateway matches `/cash.?on.?delivery|cod/i`, or `financial_status = 'pending'` with a COD gateway |
| Salla | `payment_method` | `= 'cod'` (Salla uses this literal) |
| Zid | `payment_method` / `payment.method` | contains `cod` or `cash` |
| WooCommerce | `payment_method` | `= 'cod'` (WooCommerce core gateway id) |
| Amazon | `PaymentMethod` / `PaymentMethodDetails[]` | `PaymentMethod = 'COD'` — available in some MENA marketplaces only |
| Noon | `payment_method` / `payment.type` | contains `cod`/`cash` |
| Trendyol | — | Trendyol MENA is largely prepaid; **assume no COD unless a payload proves otherwise**, default `is_cod = false` |

A COD order that we fail to detect simply never gets a `cod_transaction`, which is a silent under-count. Mitigation: a "COD detection coverage" ops metric per store — share of orders where `payment_method IS NULL` — alerting when it exceeds 5%.

---

## 7. Dashboard (Next.js 16 App Router, Tailwind v4, RTL)

### 7.1 Routes

```
src/app/(dashboard)/cod/page.tsx                     → COD overview (cash-flow + aging)
src/app/(dashboard)/cod/transactions/page.tsx        → transaction ledger
src/app/(dashboard)/cod/transactions/[id]/page.tsx   → transaction detail
src/app/(dashboard)/cod/remittances/page.tsx         → remittance list
src/app/(dashboard)/cod/remittances/[id]/page.tsx    → reconciliation workspace (the core screen)
src/app/(dashboard)/cod/remittances/import/page.tsx  → 3-step import wizard
src/app/(dashboard)/cod/rto/page.tsx                 → RTO analytics
src/app/(dashboard)/cod/risk/page.tsx                → customer risk list
src/app/(dashboard)/cod/settings/page.tsx            → carrier profiles
```

Nav entry added to `src/components/layout` sidebar between Orders and Analytics, gated on the `cod` feature flag.

### 7.2 Components — `src/components/cod/`

| Component | Notes |
|---|---|
| `CashFlowTiles.tsx` | 5 tiles: in transit / collected / remitted / overdue / at risk. Each tile is a link into a pre-filtered ledger. |
| `AgingChart.tsx` | Stacked bars per bucket, per carrier. Uses the existing `components/charts` conventions. |
| `CodTransactionTable.tsx` | Virtualised; columns Order · Carrier · AWB · Expected · Collected · Variance · Status · Age. Variance cells colour-coded (red shortfall, amber over, neutral zero). |
| `StatusBadge.tsx` | One badge component for the whole §4.1 vocabulary; colours defined once. |
| `RemittanceImportWizard.tsx` | Step 1 upload + carrier, Step 2 **column mapping preview** (auto-detected mapping shown, editable, with 5 sample rows), Step 3 confirm. |
| `ReconciliationWorkspace.tsx` | The money screen. Left: statement lines grouped by `match_status` in tabs (`Matched n` · `Needs review n` · `Unmatched n` · `Ignored n`). Right: for a selected line, ranked candidate transactions with the score breakdown made visible ("phone matches, amount within 1.50, city matches") and a one-click Match. Keyboard-first: `j`/`k` to move, `Enter` to match, `i` to ignore. |
| `VarianceSummaryCard.tsx` | Statement net vs bank received vs expected, with the delta called out in words, not just a number. |
| `SettleRemittanceDialog.tsx` | Bank receipt form. |
| `RtoAnalyticsPanel.tsx` | Tabbed by dimension; low-confidence rows visually de-emphasised with a tooltip explaining why. |
| `RiskCustomerTable.tsx` | Score, band, RTO count, last RTO, override control. |
| `CarrierProfileForm.tsx` | Fee model, cycle days, tolerances, credentials (write-only). |

### 7.3 States every screen must handle

`loading` (skeleton) · `empty — no COD orders yet` (explains what COD tracking does, links to carrier setup) · `empty — no remittances yet` (CTA to import) · `parsing` (poll `imports/{id}`, progress by `parsed_count / row_count`) · `import failed` (row-level error list, downloadable) · `all clean` (positive confirmation, not a blank screen) · `discrepancy` (amount + count, with the primary action being "review N lines") · `permission denied` · `network error with retry`.

### 7.4 i18n — `src/i18n/dicts/cod.ts`

Registered in `src/i18n/dictionary.ts` as `cod: cod.en` / `cod: cod.ar`, following the existing pattern exactly.

```ts
export const cod = {
  en: {
    title: 'COD & Cash',
    subtitle: 'Track cash on delivery from dispatch to bank.',
    nav: { overview: 'Overview', transactions: 'Transactions', remittances: 'Remittances', rto: 'RTO', risk: 'Customer Risk', settings: 'Carriers' },
    tiles: {
      inTransit: 'Cash in transit', inTransitHint: 'Shipped, not yet delivered.',
      collected: 'Collected by carrier', collectedHint: 'Cash the carrier holds.',
      remitted: 'Remitted to you', overdue: 'Overdue', overdueHint: 'Past the agreed remittance cycle.',
      atRisk: 'At risk', dso: 'Avg. days to cash', rtoRate: 'RTO rate', codShare: 'COD share of orders',
    },
    status: {
      pending: 'Pending', in_transit: 'In transit', collected: 'Collected', remitted: 'Remitted',
      reconciled: 'Reconciled', short_paid: 'Short paid', over_paid: 'Over paid', rto: 'RTO',
      rto_closed: 'RTO closed', disputed: 'Disputed', written_off: 'Written off', cancelled: 'Cancelled',
    },
    aging: { current: 'Current', b1_7: '1–7 days', b8_14: '8–14 days', b15_30: '15–30 days', b31_60: '31–60 days', b60_plus: '60+ days' },
    columns: { order: 'Order', carrier: 'Carrier', awb: 'AWB', expected: 'Expected', collected: 'Collected', variance: 'Variance', age: 'Age', dueAt: 'Due', status: 'Status' },
    remittance: {
      title: 'Remittances', reference: 'Reference', period: 'Period', statementNet: 'Statement net',
      received: 'Received in bank', variance: 'Variance', lines: 'Lines', settle: 'Record bank receipt',
      close: 'Close remittance', reconcile: 'Re-run matching', closeWarning: 'Closing locks this remittance. Unresolved lines must be resolved first.',
    },
    match: {
      matched: 'Matched', needsReview: 'Needs review', unmatched: 'Unmatched', ignored: 'Ignored',
      ambiguous: 'Multiple possible orders', candidates: 'Possible orders', matchAction: 'Match',
      unmatchAction: 'Unmatch', ignoreAction: 'Ignore row', confidence: 'Confidence',
      why: 'Why this match', whyPhone: 'Phone matches', whyAmount: 'Amount within tolerance', whyCity: 'City matches', whyAwb: 'AWB matches exactly',
    },
    resolution: { accepted: 'Accept as-is', written_off: 'Write off', disputed: 'Dispute with carrier', ignored: 'Ignore', deferred: 'Decide later' },
    import: {
      title: 'Import carrier statement', step1: 'Upload', step2: 'Map columns', step3: 'Confirm',
      dropzone: 'Drop a CSV or Excel statement here', carrier: 'Carrier', detected: 'Detected format',
      preview: 'Preview', mappingHint: 'Check each column is mapped correctly before importing.',
      duplicate: 'This file has already been imported.', parsing: 'Reading your statement…',
      parsedCount: '{parsed} of {total} rows read', errorsTitle: 'Rows we could not read',
    },
    rto: {
      title: 'Returns to origin', rate: 'RTO rate', cost: 'RTO cost', byDimension: 'Break down by',
      bySku: 'Product', byCity: 'City', byCarrier: 'Carrier', byReason: 'Reason',
      lowConfidence: 'Too few orders to be reliable', reasons: {
        customer_refused: 'Customer refused', customer_unreachable: 'Customer unreachable',
        address_incomplete: 'Incomplete address', address_wrong: 'Wrong address',
        customer_postponed: 'Customer postponed', out_of_coverage: 'Outside coverage',
        damaged: 'Damaged', duplicate_order: 'Duplicate order', price_dispute: 'Price dispute',
        delivery_window_missed: 'Missed delivery window', carrier_failure: 'Carrier failure', other: 'Other',
      },
    },
    risk: {
      title: 'Customer COD risk', score: 'Risk score', band: 'Risk band',
      bands: { unknown: 'New', low: 'Low', medium: 'Medium', high: 'High', blocked: 'Blocked' },
      rtoCount: 'RTOs', lastRto: 'Last RTO', override: 'Override', overrideReason: 'Reason',
      overrideUntil: 'Until', clearOverride: 'Clear override',
      explain: 'Based on this customer’s COD history with your store only.',
    },
    settings: {
      title: 'Carrier settings', feeModel: 'COD fee model', flat: 'Flat', percent: 'Percentage',
      tiered: 'Tiered', carrierReported: 'Use carrier statement', cycleDays: 'Remittance cycle (days)',
      toleranceAbsolute: 'Tolerance (amount)', tolerancePercent: 'Tolerance (%)',
      autoMatch: 'Auto-match statements', credentials: 'API credentials', credentialsSet: 'Credentials saved',
      testConnection: 'Test connection',
    },
    empty: {
      noCod: 'No cash-on-delivery orders yet.',
      noCodHint: 'Once you receive a COD order, its cash will be tracked here from dispatch to bank.',
      noRemittances: 'No carrier statements imported yet.',
      allClean: 'Everything matches. Nothing to review.',
    },
    toast: {
      imported: 'Statement imported.', matched: 'Line matched.', unmatched: 'Line unmatched.',
      settled: 'Bank receipt recorded.', closed: 'Remittance closed.',
      importFailed: 'We could not read that statement.',
      alreadySettled: 'That order is already settled on another remittance.',
    },
  },
  ar: {
    title: 'الدفع عند الاستلام',
    subtitle: 'تتبع النقد من الشحن حتى وصوله إلى حسابك البنكي.',
    nav: { overview: 'نظرة عامة', transactions: 'المعاملات', remittances: 'التحويلات', rto: 'المرتجعات', risk: 'مخاطر العملاء', settings: 'شركات الشحن' },
    tiles: {
      inTransit: 'نقد قيد الشحن', inTransitHint: 'تم الشحن ولم يُسلَّم بعد.',
      collected: 'محصَّل لدى الناقل', collectedHint: 'مبالغ في عهدة شركة الشحن.',
      remitted: 'محوَّل إليك', overdue: 'متأخر', overdueHint: 'تجاوز دورة التحويل المتفق عليها.',
      atRisk: 'معرّض للخطر', dso: 'متوسط أيام التحصيل', rtoRate: 'نسبة المرتجعات', codShare: 'نسبة الطلبات بالدفع عند الاستلام',
    },
    status: {
      pending: 'قيد الانتظار', in_transit: 'قيد الشحن', collected: 'تم التحصيل', remitted: 'تم التحويل',
      reconciled: 'مطابَق', short_paid: 'تحصيل ناقص', over_paid: 'تحصيل زائد', rto: 'مرتجع',
      rto_closed: 'مرتجع مغلق', disputed: 'قيد الاعتراض', written_off: 'مشطوب', cancelled: 'ملغي',
    },
    aging: { current: 'ضمن المدة', b1_7: '١–٧ أيام', b8_14: '٨–١٤ يوم', b15_30: '١٥–٣٠ يوم', b31_60: '٣١–٦٠ يوم', b60_plus: 'أكثر من ٦٠ يوم' },
    columns: { order: 'الطلب', carrier: 'الناقل', awb: 'رقم البوليصة', expected: 'المتوقع', collected: 'المحصَّل', variance: 'الفرق', age: 'العمر', dueAt: 'تاريخ الاستحقاق', status: 'الحالة' },
    remittance: {
      title: 'التحويلات', reference: 'المرجع', period: 'الفترة', statementNet: 'صافي الكشف',
      received: 'المستلم بنكياً', variance: 'الفرق', lines: 'السطور', settle: 'تسجيل الاستلام البنكي',
      close: 'إغلاق التحويل', reconcile: 'إعادة المطابقة',
      closeWarning: 'الإغلاق يقفل هذا التحويل. يجب معالجة كل السطور غير المطابقة أولاً.',
    },
    match: {
      matched: 'مطابَق', needsReview: 'يحتاج مراجعة', unmatched: 'غير مطابَق', ignored: 'متجاهَل',
      ambiguous: 'أكثر من طلب محتمل', candidates: 'الطلبات المحتملة', matchAction: 'مطابقة',
      unmatchAction: 'إلغاء المطابقة', ignoreAction: 'تجاهل السطر', confidence: 'درجة الثقة',
      why: 'سبب المطابقة', whyPhone: 'رقم الجوال مطابق', whyAmount: 'المبلغ ضمن حد التسامح', whyCity: 'المدينة مطابقة', whyAwb: 'رقم البوليصة مطابق تماماً',
    },
    resolution: { accepted: 'قبول كما هو', written_off: 'شطب', disputed: 'اعتراض لدى الناقل', ignored: 'تجاهل', deferred: 'تأجيل القرار' },
    import: {
      title: 'استيراد كشف الناقل', step1: 'الرفع', step2: 'مطابقة الأعمدة', step3: 'التأكيد',
      dropzone: 'أفلت ملف CSV أو Excel هنا', carrier: 'الناقل', detected: 'الصيغة المكتشفة',
      preview: 'معاينة', mappingHint: 'تأكد من صحة مطابقة كل عمود قبل الاستيراد.',
      duplicate: 'تم استيراد هذا الملف من قبل.', parsing: 'جارٍ قراءة الكشف…',
      parsedCount: 'تمت قراءة {parsed} من {total} سطر', errorsTitle: 'سطور تعذّرت قراءتها',
    },
    rto: {
      title: 'المرتجعات', rate: 'نسبة المرتجعات', cost: 'تكلفة المرتجعات', byDimension: 'التصنيف حسب',
      bySku: 'المنتج', byCity: 'المدينة', byCarrier: 'الناقل', byReason: 'السبب',
      lowConfidence: 'عدد الطلبات أقل من أن يُعتمد عليه', reasons: {
        customer_refused: 'رفض العميل', customer_unreachable: 'تعذّر الوصول للعميل',
        address_incomplete: 'عنوان ناقص', address_wrong: 'عنوان خاطئ',
        customer_postponed: 'تأجيل من العميل', out_of_coverage: 'خارج نطاق التغطية',
        damaged: 'تالف', duplicate_order: 'طلب مكرر', price_dispute: 'خلاف على السعر',
        delivery_window_missed: 'فوات موعد التسليم', carrier_failure: 'خطأ من الناقل', other: 'أخرى',
      },
    },
    risk: {
      title: 'مخاطر العملاء', score: 'درجة الخطورة', band: 'التصنيف',
      bands: { unknown: 'جديد', low: 'منخفض', medium: 'متوسط', high: 'مرتفع', blocked: 'محظور' },
      rtoCount: 'عدد المرتجعات', lastRto: 'آخر مرتجع', override: 'تجاوز يدوي', overrideReason: 'السبب',
      overrideUntil: 'حتى تاريخ', clearOverride: 'إلغاء التجاوز',
      explain: 'محسوبة من سجل هذا العميل مع متجرك فقط.',
    },
    settings: {
      title: 'إعدادات الناقلين', feeModel: 'نموذج رسوم التحصيل', flat: 'ثابت', percent: 'نسبة مئوية',
      tiered: 'شرائح', carrierReported: 'حسب كشف الناقل', cycleDays: 'دورة التحويل (أيام)',
      toleranceAbsolute: 'حد التسامح (مبلغ)', tolerancePercent: 'حد التسامح (٪)',
      autoMatch: 'المطابقة التلقائية للكشوف', credentials: 'بيانات الربط', credentialsSet: 'تم حفظ البيانات',
      testConnection: 'اختبار الاتصال',
    },
    empty: {
      noCod: 'لا توجد طلبات بالدفع عند الاستلام بعد.',
      noCodHint: 'عند وصول أول طلب، سنتتبع نقده من الشحن حتى حسابك البنكي.',
      noRemittances: 'لم يتم استيراد أي كشف بعد.',
      allClean: 'كل شيء مطابق. لا يوجد ما يحتاج مراجعة.',
    },
    toast: {
      imported: 'تم استيراد الكشف.', matched: 'تمت مطابقة السطر.', unmatched: 'تم إلغاء المطابقة.',
      settled: 'تم تسجيل الاستلام البنكي.', closed: 'تم إغلاق التحويل.',
      importFailed: 'تعذّرت قراءة هذا الكشف.',
      alreadySettled: 'هذا الطلب مُسوّى بالفعل ضمن تحويل آخر.',
    },
  },
} as const;
```

**RTL specifics.** Money columns stay LTR-numeric inside an RTL table (`dir="ltr"` on the numeric cell, `text-align` flipped by logical properties). Aging bars mirror. AWB numbers are always `dir="ltr"`. Negative variance in Arabic must render as `−١٢٫٥٠` with the minus *leading* the number visually — force `dir="ltr"` on the cell rather than relying on bidi resolution, which produces a trailing minus in some browsers and would be read as a completely different number.

---

## 8. Mobile (Flutter)

Mobile is for a merchant checking cash on their phone, not for reconciliation work. Reconciliation is a desk task and forcing it onto a 6-inch screen would produce mistakes with money.

New feature folder `mobile/lib/features/cod/`:

| Screen | Content |
|---|---|
| `cod_overview_page.dart` | The five cash tiles + aging bar. Pull-to-refresh. Primary surface. |
| `cod_transactions_page.dart` | Filterable ledger, read-only, infinite scroll. Filter chips: overdue / short paid / RTO. |
| `cod_transaction_detail_page.dart` | Timeline: dispatched → collected → remitted → reconciled, with amounts and the carrier. Deep-links to the existing order detail page. |
| `cod_remittances_page.dart` | List with status chips; tapping opens a **read-only** summary (counts + variance) and a "review on desktop" note. |

Added to `mobile/lib/features/more/` navigation and `app_router.dart`. Strings go in `mobile/lib/l10n/` alongside existing ARB/dict files, mirroring the `en`/`ar` keys above.

**Not on mobile:** statement import, manual matching, write-offs, close, carrier credentials. Push notifications (via the existing notifications feature) for: remittance overdue, discrepancy detected, statement imported.

---

## 9. Permissions & multi-tenancy

### Tenancy

Every query is scoped by `organization_id`, sourced from the `X-Organization-Id` header and validated by `EnsureOrganizationMember` (already aliased as `org.member`). No COD controller reads `organization_id` from the request *body*, ever.

Additionally:
- `CodTransaction`, `CodRemittance`, `CodRemittanceLine` and `CodRtoEvent` all carry `organization_id` directly, so no COD list query needs `whereHas`.
- Route-model binding is **not** used for COD resources. Controllers resolve via `Model::forOrganization($orgId)->findOrFail($id)` so a cross-tenant id returns `404`, not `403` — we do not confirm the existence of another tenant's remittance.
- A `CodTenancyTest` asserts every endpoint returns 404/403 for a foreign id. This is non-negotiable: the objects here are money.

### Roles

The `organization_user` pivot has a `role` column (`owner` seen in tests). COD introduces an explicit capability map, enforced by a `CodPolicy`:

| Capability | owner | admin | member | viewer |
|---|---|---|---|---|
| View COD dashboards / transactions | ✔ | ✔ | ✔ | ✔ |
| Import statements | ✔ | ✔ | ✔ | ✘ |
| Manually match / unmatch lines | ✔ | ✔ | ✔ | ✘ |
| Record bank receipt (settle) | ✔ | ✔ | ✘ | ✘ |
| Write off / dispute | ✔ | ✔ | ✘ | ✘ |
| Close a remittance | ✔ | ✔ | ✘ | ✘ |
| Edit carrier profiles / credentials | ✔ | ✔ | ✘ | ✘ |
| Override customer risk band | ✔ | ✔ | ✘ | ✘ |

**Assumption:** roles beyond `owner` are not yet fully defined in the repo (`OrganizationController::updateMemberRole` exists but the vocabulary is not visible in the code read). If the role set is only `owner`/`member`, collapse the table to owner-only for the money-moving rows and revisit.

### Audit

Every money-affecting action (`match`, `unmatch`, `settle`, `write-off`, `close`, `override`) writes an audit row. **Assumption:** no audit table exists yet. v1 writes to `sync_logs`-style rows is wrong (that table is store-scoped); instead this spec adds `cod_audit_events` (`organization_id`, `user_id`, `subject_type`, `subject_id`, `action`, `before` json, `after` json, `ip`, `created_at`) in migration `2026_07_22_000010_create_cod_audit_events_table.php`. If a platform-wide audit table lands later, this collapses into it.

### Data protection

- `api_credentials` encrypted at rest, `$hidden`, never logged, never returned.
- Uploaded statements contain customer phone numbers → private disk, 90-day retention, and the retention window is configurable per org for merchants under stricter policy.
- `customer_key` (phone) is never written to application logs above `debug`; log the `cod_customer_risk.id` instead.

---

## 10. Edge cases & failure modes

| # | Case | Handling |
|---|---|---|
| 1 | Same statement uploaded twice | `unique(organization_id, file_hash)` → `409` naming the original import. |
| 2 | Same statement re-sent by the carrier with one corrected row | Different hash, so it imports. The duplicate guard (§4.4) flags every already-settled AWB as `duplicate`; the merchant sees "48 of 50 rows already settled" and reviews the 2 real changes. |
| 3 | Statement has a multi-row preamble before the header | Header-row detection scans the first 15 rows for ≥3 resolvable required headers. Fails loudly with the sniffed rows shown, never guesses. |
| 4 | Arabic headers / `Windows-1256` encoding | Profile-level `file_encoding`; mapper alias tables include Arabic. Import preview shows decoded sample rows so mojibake is caught before commit. |
| 5 | Amounts formatted `1,234.56` or `1.234,56` or with `SAR` suffix or Arabic-Indic digits `١٢٣٤` | `MoneyParser` normalises: strip currency symbols/letters, convert Arabic-Indic and Extended Arabic-Indic digits, apply `decimal_separator` from the profile, reject anything still non-numeric with a row error. |
| 6 | Excel serial dates (`45678`) instead of date strings | Date parser tries: profile format → ISO → common regional formats → Excel serial. Unparseable dates leave `collected_at` null and drop T3 confidence, rather than inventing a date. |
| 7 | Float rounding | All money is `decimal(15,2)` in the DB and handled as strings / `bcmath` in PHP. **No `float` arithmetic on money anywhere in this feature.** A lint rule / code-review checklist item enforces it. |
| 8 | 50,000-row statement | Streaming parse, chunked inserts (500), reconciliation batched with a bounded candidate query. Target: 50k rows parsed + matched in < 5 minutes on the standard queue worker. |
| 9 | Carrier remits a batch spanning two currencies | Rejected at import with a clear message; merchant splits the file. Silent FX would corrupt the ledger. |
| 10 | Order deleted after COD transaction exists | `cascadeOnDelete` removes the transaction. But if it was already on a **closed** remittance, that is data loss. Mitigation: orders on closed remittances are protected by a `deleting` observer that throws; the merchant must reopen or write off first. |
| 11 | Two admins reconcile the same remittance simultaneously | `WithoutOverlapping` on the job + `lockForUpdate` on candidates + optimistic check on `remittance.status`. Second actor gets `409 Reconciliation already running`. |
| 12 | Merchant changes `remittance_cycle_days` retroactively | `due_at` is recalculated only for transactions not yet `remitted`. Historical aging is not rewritten — the past is what it was. |
| 13 | Carrier collects more than expected (customer paid for an add-on at the door) | `over_paid`. Auto-accept is **off** by default; the merchant accepts it, which posts a positive `cod_overage` fee. |
| 14 | COD order that was actually prepaid (customer paid online after ordering) | Merchant marks the transaction `cancelled` with reason `converted_to_prepaid`; the order's payment method is corrected; no cash expectation remains. |
| 15 | Aggregator (Torod) statement mixing carriers | Candidate query relaxes the carrier filter when `metadata.is_aggregator`; `carrier_code` on the line comes from the row, not the profile. |
| 16 | AWB reused by the carrier across years | Candidate query is windowed to `dispatched_at ∈ [period_start − 90d, period_end + 7d]`, which makes reuse across years harmless. |
| 17 | Queue worker dies mid-parse | `CodStatementImport.status` stuck at `parsing`. A sweeper marks imports `parsing` for > 30 min as `failed` with a retry action; partial lines are deleted with the remittance in the same transaction, so there is never a half-imported batch. |
| 18 | Reconciliation posts fees, then the merchant unmatches the line | `CodFeePostingService::reverse()` removes/zeroes the `order_fees` rows for that `source_ref` and clears `fees_posted_at`. Profit must never keep a fee for a match that no longer exists. |
| 19 | Risk score computed on a customer with no phone | `customer_key` falls back to `email:<lowercased>`. If both are missing (guest COD with a phone-only carrier record), no risk row is created — we do not invent identity. |
| 20 | Carrier never sends a statement at all | The whole feature still works from webhooks/manual entry: a merchant can mark a transaction collected and create a manual remittance. Statement import is an accelerant, not a dependency. |

---

## 11. Testing

Following existing conventions (`RefreshDatabase`, `makeOrganization($user)`, Sanctum token + `X-Organization-Id` header, `tests/Feature` and `tests/Unit`).

### Unit — `tests/Unit/Cod/`

| Test | Asserts |
|---|---|
| `MoneyParserTest` | `"1,234.56"`, `"1.234,56"` (with `,` separator profile), `"SAR 349.00"`, `"٣٤٩٫٠٠"`, `"(120.00)"` → correct decimal strings; garbage throws. |
| `AwbNormaliserTest` | `"aramex-1234 5678"`, `"0012345678"`, `"12345678"` all normalise to the same key. |
| `CodMatchScorerTest` | Each T3 component contributes exactly its weight; a phone+amount+city hit scores 85; amount-only scores 30 → `unmatched`. |
| `CodToleranceTest` | `max(absolute, percent)` boundary: expected 300, tolerance `max(1.00, 0.5%) = 1.50`; variance −1.50 is `tolerance`, −1.51 is `partial`. |
| `CodStateMachineTest` | Every legal transition passes; a representative set of illegal ones (`reconciled → pending`, `cancelled → collected`) throws. |
| `CodFeeCalculatorTest` | flat / percent-with-min-and-max / tiered boundaries (exactly at `upto`), and `carrier_reported` returning 0. |
| `CodRiskScoringTest` | New customer → ~14 / `unknown`→`low`; 3 RTOs of 4 orders → `blocked` via the hard rule; a 1-order-1-RTO customer scores below `high` because of the confidence term; override wins and expires. |
| `AgingBucketTest` | Boundary days 0/1/7/8/14/15/30/31/60/61. |
| `ReasonCodeNormaliserTest` | Arabic and English carrier strings map to the right codes; unknown → `other` with `reason_text` preserved. |
| Mapper tests (one per carrier) | A fixture CSV/XLSX per carrier in `tests/Fixtures/cod/` → canonical rows. Fixtures are **synthetic** until real statements are obtained; each fixture file carries a header comment saying so. |

### Feature — `tests/Feature/Cod/`

| Test | Asserts |
|---|---|
| `CodTransactionCreationTest` | A synced COD order creates exactly one transaction with the right `organization_id`, currency and `expected_amount`; a prepaid order creates none; re-syncing the same order does not duplicate. |
| `StatementImportTest` | Upload → `202`, import parses, remittance + lines created, counters correct, file stored on the private disk. |
| `StatementDuplicateImportTest` | Same bytes twice → `409` with the original import id. |
| `StatementBadFileTest` | Wrong mime → `422`; unmappable headers → `422` listing missing canonical fields; 21 MB → `422`. |
| `ReconciliationAutoMatchTest` | 10 lines, 10 transactions, AWB-exact → all `matched`, remittance `imported → reconciling → discrepancy`(no bank receipt yet), transactions `remitted`. |
| `ReconciliationToleranceTest` | Variance 1.20 within tolerance → `tolerance`, transaction `remitted` not `short_paid`. |
| `ReconciliationShortfallTest` | Variance −25.00 → line `partial`, transaction `short_paid`, `CodVarianceDetected` fired, a `notifications` row created. |
| `ReconciliationAmbiguousTest` | Two candidates at equal score → `ambiguous`, no auto-assignment, `/candidates` returns both ranked. |
| `ReconciliationDuplicateGuardTest` | An AWB already settled on remittance A appears in remittance B → `duplicate`, transaction untouched, no double `remitted_amount`. |
| `ReconciliationIdempotencyTest` | Running `reconcile` three times yields identical DB state; human `resolution`s survive. |
| `ManualMatchTest` | Match/unmatch round-trip; matching to an already-settled transaction → `422`. |
| `SettleRemittanceTest` | Bank receipt within tolerance → `reconciled`; outside → `discrepancy` + event. |
| `CloseRemittanceTest` | Close with unresolved lines → `422`; after resolving → `closed`; closed remittance rejects further mutation with `409`. |
| `CodFeePostingTest` | Reconcile posts `cod_fee` into `order_fees` once; re-reconcile does not duplicate; unmatch reverses. |
| `RtoFlowTest` | Failed attempt → attempt_count 1; second failure → RTO; `rto_received` + restock creates an `inventory_logs` row and sets `restocked`. |
| `RtoAnalyticsTest` | Rates per SKU/city/carrier; a 3-order SKU returns `low_confidence: true`. |
| `CodSummaryTest` | The six cash aggregates against a hand-built fixture set; totals reconcile (`in_transit + collected + remitted == Σ expected` for non-RTO, non-cancelled). |
| `CodAgingTest` | Bucket counts and sums. |
| `CodRiskEndpointTest` | List/filter/override/clear; override expiry respected. |
| `CodTenancyTest` | **For every COD endpoint**: a second organization's id returns 404/403 and never leaks a field. Parameterised over the full route list so a new endpoint added without tenancy fails the suite. |
| `CodPermissionTest` | Each role × each money-moving endpoint. |
| `CodLargeStatementTest` | 5,000-row generated statement parses and reconciles within the job; asserts chunking (query count bounded, not O(rows)). |

### Non-functional targets

- `GET /api/cod/summary` p95 < 250 ms at 250k transactions in one org (indexed aggregates, 60 s cache keyed by org + filters).
- `GET /api/cod/transactions` p95 < 300 ms at 250k rows.
- Reconciliation of a 10k-line statement < 90 s.
- Zero `float` in money paths — asserted by a static check in CI (grep for `(float)` / `floatval` under `app/Services/Cod`).

---

## 12. Rollout

### 12.1 Feature flags

- `cod` — master flag, per organization. Stored in the existing plan/feature mechanism (`plans` has a `features` column, added by `2026_05_06_082834_add_features_to_plans_table.php`). COD is positioned as a **paid, MENA-tier feature**.
- `cod.auto_match` — per org, defaults on; killable if the engine misbehaves for a specific merchant.
- `cod.carrier_api` — per carrier profile (`api_enabled`), default off everywhere until a real API is verified.
- `cod.risk_scoring` — off by default in v1 until there is enough history to score anything meaningfully.

Dashboard nav and mobile tab are hidden entirely when `cod` is off. API returns `403 {"message":"COD is not enabled for this organization."}`.

### 12.2 Migration plan

**Phase 0 — schema (no behaviour).** Ship migrations `000001`–`000008` + `000010`. Nothing reads them. Safe to deploy any time; all additive, no column drops, no rewrites of `orders` beyond nullable adds.

**Phase 1 — detection & backfill.**
- Extend `SyncOrdersJob::mapOrderData()` with `payment_method` / `is_cod` / `customer_phone` / `shipping_city` per §6.7.
- `php artisan cod:backfill-orders {--store=} {--since=} {--dry-run}` re-reads `orders.raw_data` and populates the new columns in chunks of 1,000. Idempotent, resumable, `--dry-run` prints a detection-coverage report first.
- `php artisan cod:backfill-transactions {--store=} {--since=}` creates `cod_transactions` for historical COD orders in status `pending`/`in_transit` (or `collected` if the order status implies delivery). **Default lookback: 90 days.** Older history has no reliable carrier data and would create noise the merchant cannot reconcile.

**Phase 2 — internal only.** Enable `cod` for Hubby's own test org + 2 design-partner merchants. Import real statements. **This is the phase where §6's unverified column names become verified.** Do not build more mappers before this phase produces real files.

**Phase 3 — manual reconciliation GA.** Import + match + settle + close, RTO capture, cash-flow view. Risk scoring still off.

**Phase 4 — risk + automation.** Turn on `cod.risk_scoring` once ≥ 60 days of RTO history exists per merchant; expose conditions to the Automation Rules Engine.

**Phase 5 — carrier APIs.** Only for carriers where a real settlement API has been confirmed in writing.

**Rollback.** Phases 1–4 are behaviour-flag rollbacks (flip the flag; data stays). Schema rollback is a last resort — each migration has a working `down()`, but `down()` on `cod_transactions` destroys reconciliation history and must never be run in production without an export first. The `down()` methods carry a comment saying exactly that.

### 12.3 Credentials & sandbox

- Carrier API credentials live **per organization** in `cod_carrier_profiles.api_credentials` (encrypted), not in `.env` — different merchants have different carrier accounts. This differs from the platform integrations, whose OAuth app credentials are global in `config/services.php`; that difference is intentional and should be stated in the code comment.
- A `config/cod.php` file holds only non-secret defaults: mapper registry, default tolerances, retention days, low-confidence threshold, max upload size.
- Sandbox: none of these carriers offers a usable public COD sandbox (**unverified/assumed**). Development uses fixture statements in `tests/Fixtures/cod/` plus a `cod:generate-statement` artisan command that emits a synthetic statement for a given set of real orders — which also doubles as the demo path for sales.
- `POST /api/cod/carriers/{id}/test-connection` returns a structured result (`reachable`, `authenticated`, `statements_supported`) so a merchant can tell "wrong password" from "this carrier has no API".

---

## 13. Acceptance criteria

**Data & lifecycle**
- [ ] A COD order synced from any of the 7 platforms produces exactly one `cod_transaction` with correct `organization_id`, `currency` and `expected_amount`.
- [ ] A prepaid order produces none.
- [ ] The §4.1 state machine rejects every illegal transition with a 422 and a human-readable reason.
- [ ] `due_at` is set on entry to `collected` using the carrier profile's cycle.
- [ ] Overdue is derived, never stored as a status.

**Import**
- [ ] CSV and XLSX both import; header row is detected even with a preamble.
- [ ] Arabic headers and `Windows-1256` files import correctly.
- [ ] Arabic-Indic digits and `1.234,56` formats parse to correct decimals.
- [ ] Re-uploading identical bytes returns 409 naming the original import.
- [ ] An unmappable file returns 422 listing exactly which canonical fields could not be resolved.
- [ ] A 20 MB / 50k-row statement parses and reconciles without timing out or exhausting memory.
- [ ] The mapping preview shows 5 real sample rows before anything is persisted.

**Matching**
- [ ] AWB-exact matches auto-assign at confidence 100.
- [ ] Amounts within `max(absolute, percent)` tolerance are `matched`/`tolerance`, outside are `partial`/`over`.
- [ ] Two equally-scored candidates produce `ambiguous`, never a guess.
- [ ] An already-settled transaction can never be claimed by a second line; the attempt is visible as `duplicate`.
- [ ] Re-running reconciliation is idempotent and preserves human resolutions.
- [ ] Every auto-match records `match_type` and `match_confidence`, and the UI can explain *why* it matched.

**Money correctness**
- [ ] No `float` arithmetic anywhere in the COD money path (CI check).
- [ ] `cod_fee` / `rto_fee` / `cod_shortfall` post to `order_fees` exactly once per transaction and reverse cleanly on unmatch.
- [ ] Carrier-reported fees override predicted fees.
- [ ] Bank receipt outside tolerance moves the remittance to `discrepancy` and raises a notification.
- [ ] Closing a remittance requires every non-clean line to carry a resolution.
- [ ] A closed remittance rejects mutation with 409.

**RTO & risk**
- [ ] Failed attempts increment `attempt_count`; RTO creates a `cod_rto_events` row with a normalised reason.
- [ ] Restocking links to an `inventory_logs` row.
- [ ] RTO analytics by SKU / city / carrier / reason return rates and costs, with `low_confidence` on slices under 20 orders.
- [ ] Risk scores are computed with the smoothing + confidence terms; a 1-order customer is never `blocked` by the score alone.
- [ ] Risk conditions are consumable by the Automation Rules Engine.
- [ ] Risk data never crosses organizations.

**Tenancy, permissions, UX**
- [ ] Every COD endpoint is covered by `CodTenancyTest`; a foreign id returns 404/403 with no field leakage.
- [ ] Role matrix in §9 is enforced by policy and tested.
- [ ] Carrier API credentials are encrypted, hidden, never returned, never logged.
- [ ] Every screen handles loading / empty / parsing / error / discrepancy / all-clean.
- [ ] Full `en` + `ar` coverage with no untranslated keys; RTL layout correct; money and AWB render LTR inside RTL.
- [ ] Mobile shows the cash overview, ledger and read-only remittances; no money-moving action is possible from mobile.

**Performance**
- [ ] `/api/cod/summary` p95 < 250 ms and `/api/cod/transactions` p95 < 300 ms at 250k transactions.
- [ ] 10k-line reconciliation completes in < 90 s.

---

## 14. Effort estimate + dependencies

Estimates assume one senior backend engineer and one senior frontend engineer working in parallel, and **exclude** the time to obtain real carrier statements — which is calendar risk, not engineering risk.

| Workstream | Effort |
|---|---|
| Migrations + models + tenancy trait + policy | 3 d |
| COD detection across 7 platforms + backfill commands | 4 d |
| Transaction service + state machine + events + notifications | 4 d |
| Statement import: storage, hashing, streaming parse, XLSX, preview, generic mapper + mapping UI contract | 6 d |
| Per-carrier mappers (5) — *after* real statements exist | 5 d |
| Reconciliation engine + scorer + duplicate guard + idempotency + locking | 7 d |
| Fee posting into `order_fees` (+ reversal) | 2 d |
| Cash-flow + aging + DSO services and endpoints | 3 d |
| RTO capture, events, analytics service | 4 d |
| Risk scoring + rollup job + endpoints + rules-engine conditions | 4 d |
| Carrier webhook branch (COD-relevant events only) | 2 d |
| Backend tests (unit + feature + tenancy + perf) | 7 d |
| **Backend subtotal** | **~51 d** |
| Dashboard: overview, ledger, detail | 5 d |
| Dashboard: import wizard incl. column-mapping step | 5 d |
| Dashboard: reconciliation workspace (the hard one — keyboard nav, candidate ranking, explainability) | 8 d |
| Dashboard: RTO analytics, risk, carrier settings | 5 d |
| i18n en/ar + RTL/number-direction pass | 3 d |
| Frontend tests | 4 d |
| **Frontend subtotal** | **~30 d** |
| Mobile (4 read-mostly screens + l10n + push) | 6 d |
| Docs, ops runbook, demo statement generator | 3 d |
| **Total** | **≈ 90 engineer-days (~8–9 calendar weeks with 2 engineers + partial mobile)** |

### Hard dependencies

1. **Shipping & Labels spec** — `shipments`, `carriers`, AWB capture and carrier delivery webhooks. COD can ship *without* it (AWB entered manually or read from `orders.raw_data`), but the experience is materially worse. Sequence Shipping first if possible.
2. **Profit & Cost Engine** — `order_fees` must exist, and must expose an idempotency key (`source_ref` or equivalent). **Blocking for fee posting.**
3. **Real carrier statements** from at least 3 design-partner merchants. **Blocking for §6.** Start this procurement on day 1; it has the longest lead time in the whole project.
4. **Automation Rules Engine** — only for Phase 4 risk conditions. Not blocking for GA.
5. `orders.customer_phone` — needed by both this spec and Spec 07. Whichever ships first owns the migration; the other must not duplicate it.

### Soft dependencies

- A role/permission vocabulary beyond `owner` (§9 assumption).
- XLSX reading: adds a dependency (`phpoffice/phpspreadsheet` or `openspout`). **Recommend `openspout`** — it streams, which matters at 50k rows, where PhpSpreadsheet's memory profile is a genuine operational risk.

---

## 15. Open questions

1. **Split shipments per order.** v1 assumes one `cod_transaction` per order (enforced by `unique(order_id)`). If merchants routinely split a COD order across two shipments with two cash collections, that unique index is wrong and the model needs `cod_transactions` keyed on `(order_id, shipment_id)` with an order-level rollup. *Needs a design-partner answer before Phase 1 ships, because changing it later is a data migration on money.*
2. **Do any of Aramex / SMSA / Naqel / J&T / Torod actually expose a settlement or COD-statement API?** Everything in §6 assumes not. If one does, `api_enabled` becomes the primary path for that carrier and the import UI becomes a fallback.
3. **Is the merchant's bank receipt worth automating?** Manual entry is fine at 2–4 remittances per carrier per month. If merchants use 5 carriers with weekly cycles, that is 20 manual entries a month and a bank-feed integration starts to pay for itself.
4. **Where do org-level COD settings live** that are not carrier-specific (handling cost per RTO, default currency, retention days)? Options: a new `organization_settings` table, a `settings` json column on `organizations`, or `cod_carrier_profiles.metadata` (wrong home, but zero new schema). Recommend a proper `organization_settings` table, but that is a platform decision beyond this spec.
5. **Should COD risk be shareable across organizations?** Commercially attractive (a network effect no competitor could match), legally serious (PII sharing across data controllers, PDPL in KSA). v1 says no. Any future yes needs legal review and explicit merchant consent, not a product decision.
6. **RTO cost when COGS is unknown.** We report a partial cost and flag it. Is a partial number more useful than no number, or does it undermine trust? Needs a call with design partners.
7. **Statement retention.** 90 days is a guess. Merchants under audit may need 7 years; that is an object-storage cost decision plus a PII retention decision.
8. **What happens to an unreconciled remittance at fiscal year end?** There is no period-close concept in the platform. If accountants need one, `closed_at` is the hook, but the semantics need defining.
9. **Currency of the risk model.** `cod_value_lost` sums across currencies today. For a single-country merchant this is fine; for a GCC merchant selling in SAR and AED it is meaningless. Either store per-currency or convert at a stored rate.
10. **Does Trendyol have any COD flow in its MENA operation?** §6.7 assumes no. If it does, the detection table is incomplete.
