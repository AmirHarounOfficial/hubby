# Spec 03 — Returns / RMA

**Status:** Draft, implementation-ready
**Owner:** Backend / Order lifecycle
**Depends on:** Spec 04 (Shipping & Labels) for return labels; Profit & Cost Engine spec for `product_costs` / `order_fees`
**Repo baseline verified:** `backend/app/Models/{Order,OrderItem,InventoryLog,Store,Integration}.php`, `backend/app/Http/Controllers/{OrderController,InventoryController,WebhookController}.php`, `backend/app/Services/Integrations/*`, `backend/app/Jobs/SyncOrdersJob.php`, `backend/routes/api.php`, `backend/database/migrations/*`, `frontend/src/i18n/{dictionary.ts,dicts/orders.ts}`, `mobile/lib/l10n/strings.dart`

---

## 0. Baseline facts and explicit assumptions

Everything in this section was read from the repo. Where I state an assumption, it is marked **ASSUMPTION** and must be confirmed before coding.

**Verified today:**

| Fact | Source |
| --- | --- |
| `orders` has `id, store_id, external_id, status, total, currency, customer_name, customer_email, raw_data, timestamps`; `unique(store_id, external_id)` | `2026_05_05_202920_create_orders_table.php` |
| `orders` has **no** `organization_id`. Tenancy is derived: `Order::whereHas('store', fn($q) => $q->where('organization_id', $orgId))` | `OrderController::applyFilters()` |
| `order_items` has `id, order_id, sku (nullable), quantity (int), price decimal(15,2), name, timestamps`. **No** `external_id`, **no** `product_id`/`product_variant_id`, **no** tax/discount columns | `2026_05_05_202921_create_order_items_table.php`, `App\Models\OrderItem` |
| `inventory_logs` has `id, product_id (nullable FK), product_variant_id (nullable FK), change (int), source (string), reason (string, nullable), timestamps` | `2026_05_05_202913_...` + `2026_06_25_000002_add_product_id_to_inventory_logs.php` |
| Stock lives on `product_variants.stock` (int, default 0) and `products.stock` (added later); adjustments are `increment('stock', $change)` inside `DB::transaction` + one `InventoryLog::create` | `InventoryController::adjust()` |
| Tenancy gate is the `org.member` middleware (`EnsureOrganizationMember`) reading the `X-Organization-Id` header; roles are `owner`, `admin`, `viewer` on the `organization_user` pivot | `EnsureOrganizationMember.php`, `OrganizationController::ROLES` |
| Integration services all implement `IntegrationServiceInterface` (`getAuthUrl`, `exchangeCode`, `refreshToken`, `fetchOrders`, `fetchProducts`, `fetchInventory`, `updateInventory`, `updateOrderStatus`, `cancelOrder`) and are constructed by `IntegrationFactory::make($platform)` | `IntegrationServiceInterface.php`, `IntegrationFactory.php` |
| Jobs use `Dispatchable, InteractsWithQueue, Queueable, SerializesModels`, write a `SyncLog` row, and create a `Notification` on success/failure | `SyncOrdersJob.php` |
| Webhooks land on `POST /api/webhooks/{platform}` behind `VerifyWebhookSignature`, then fan out per platform in `WebhookController` | `routes/api.php`, `WebhookController.php` |

**Pre-existing defect this spec depends on being fixed:**

`SyncOrdersJob::handle()` writes `order_items` with keys `external_id` and `product_name`:

```php
OrderItem::updateOrCreate(
    ['order_id' => $order->id, 'external_id' => $itemData['external_id'] ?? null],
    ['product_name' => $itemData['name'], 'sku' => ..., 'quantity' => ..., 'price' => ...]
);
```

Neither `external_id` nor `product_name` exists in the `order_items` migration or in `OrderItem::$fillable`. So today order-item sync silently drops those fields (mass-assignment guard) or throws on the `external_id` where-clause depending on driver. Returns **cannot** map a marketplace return line to our line without `order_items.external_id`, so migration `2026_07_23_000006` below fixes it. This is a dependency, not an optional cleanup.

**ASSUMPTIONS (confirm before coding):**

1. `products.stock` and `product_variants.stock` are the single source of truth for on-hand quantity; there is no location/bin dimension. Returns therefore restock to a single implicit location.
2. Order money is stored gross with no per-line tax or discount breakdown. Refund math must therefore **allocate** order-level discount/tax proportionally from `orders.raw_data` when present, and fall back to `unit_price × qty` when not. Column `order_items.tax_amount` / `discount_amount` are added here (migration `...000006`) so the allocation is computed once at sync time rather than at refund time.
3. `orders.currency` is the currency of the refund. No FX conversion in v1.
4. Order **shipping address** is not persisted today (it only lives inside `raw_data`). Spec 04 introduces `order_addresses`; Returns reads from it for RTO and pickup. Until Spec 04 lands, `ReturnService` falls back to `orders.raw_data`.
5. `product_costs` and `order_fees` are defined by the Profit & Cost Engine spec. This spec **writes rows into `order_fees`** and **reads `product_costs`** but does not define either table.

---

## 1. Why this exists (competitive rationale)

Every serious competitor already ships this and we ship none of it:

- **Linnworks** has full RMA: returns, refunds, exchanges, resends, per-channel return sync, and books the cost back into order profitability.
- **Sellerboard** does not manage returns operationally but *does* net Amazon refunds and reimbursements into profit — so a Hubby user comparing profit numbers sees us over-report profit until returns exist.
- **Rithum / ChannelAdvisor** mirrors marketplace-managed returns across channels and treats "return rate by SKU" as a merchandising signal.

Two things make returns strategically bigger for Hubby than for any of them:

1. **COD is the dominant payment method in MENA.** In Saudi, UAE, Egypt and Turkey a large share of orders are cash on delivery, and the failure mode is not "customer returns a product" — it is **RTO: the parcel never gets delivered and comes back**. RTO is a pure loss (two shipping legs + handling, zero revenue) and it is invisible in every Western-built tool because they model returns as *post-delivery refunds*. Hubby modelling RTO as a first-class return type, auto-detected from carrier tracking, is a wedge no incumbent has.
2. **Multi-channel returns are unreconciled today.** A merchant selling on Salla + Amazon + Noon has three return inboxes, three refund policies, and no unified return rate. Because Hubby already unifies orders and inventory across 7 platforms, returns is the cheapest high-value thing to unify next: one RMA queue, one restock decision, one inventory ledger.

Without this feature: inventory drifts (returned goods never come back into stock), profit is overstated (refunds and RTO shipping never subtract), and the "one dashboard for all channels" promise breaks at exactly the moment a merchant loses money.

---

## 2. Scope — in / out

### In scope (v1)

- RMA record with a strict state machine, per-line (partial) returns.
- Reason-code taxonomy: global seeded set + per-organization custom reasons, bilingual EN/AR.
- Receive + inspect flow with per-line disposition (restock / scrap / quarantine / return-to-vendor / repair) and its inventory effect written to `inventory_logs`.
- Refunds: full and partial; merchant-issued (we call the platform refund API) and marketplace-issued (we mirror only). Restocking fee and return-shipping deduction.
- Exchanges and resends via a linked replacement order.
- **RTO for COD orders**: auto-detection from carrier tracking (Spec 04) or platform status, a reduced RTO lifecycle, and cost posting.
- Return shipping labels via the carrier abstraction in Spec 04 (`createReturnShipment`).
- Customer-facing return portal (token-based, no login), **feature-flagged, optional per organization**.
- Returns analytics: return rate by SKU / channel / reason / period, RTO rate, refund value, restock ratio.
- Per-platform mirroring for all 7 integrations, honest about which are merchant-controlled and which are marketplace-controlled.
- Dashboard screens (EN/AR, RTL) and mobile read + approve surfaces.

### Out of scope (v1) — explicitly deferred

- Warehouse locations / bins for returned stock (single implicit location only).
- Return-to-vendor (RTV) purchase-order flow to suppliers — the `return_to_vendor` disposition is recorded but no supplier document is generated.
- Automated fraud scoring on serial returners (we expose the data; no model).
- Store credit ledger / gift cards (a `store_credit` refund method is recorded as an amount but no balance is tracked).
- Warranty/repair tracking beyond the `repair` disposition flag.
- Photo/video evidence upload from the customer portal (the `requires_photo` flag exists on reason codes; upload is v1.1).
- FX conversion when refund currency ≠ organization reporting currency.
- Multi-package return consolidation (one return = at most one inbound shipment in v1).

---

## 3. Data model

Convention notes: migration files follow the repo pattern `YYYY_MM_DD_NNNNNN_verb_noun_table.php` returning `new class extends Migration`. Money is `decimal(15,2)`, currency is `string(3)`. Unlike `orders`, the new tables carry a **denormalized `organization_id`** — `orders` scopes through `stores` via `whereHas`, which produces a dependent subquery on every list query. Returns lists are filtered and sorted heavily, so a direct indexed `organization_id` is worth the denormalization. This is a deliberate deviation from the `orders` convention and is documented in the migration docblock.

### 3.1 `return_reasons`

Migration: `2026_07_23_000001_create_return_reasons_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | PK |
| `organization_id` | `foreignId` → `organizations.id` `cascadeOnDelete` | **yes** | `null` | `null` = global seeded reason, visible to all orgs |
| `code` | `string(48)` | no | — | stable machine key, e.g. `wrong_size` |
| `group` | `enum('customer','product','logistics','fraud','other')` | no | `'other'` | drives analytics buckets |
| `label_en` | `string(120)` | no | — | |
| `label_ar` | `string(120)` | no | — | |
| `description_en` | `string(255)` | yes | `null` | shown in customer portal |
| `description_ar` | `string(255)` | yes | `null` | |
| `requires_note` | `boolean` | no | `false` | free-text mandatory |
| `requires_photo` | `boolean` | no | `false` | reserved for v1.1 upload |
| `is_defect` | `boolean` | no | `false` | true ⇒ merchant fault ⇒ refund shipping by default |
| `is_customer_fault` | `boolean` | no | `false` | true ⇒ restocking fee applies by default |
| `default_disposition` | `enum('restock','scrap','quarantine','return_to_vendor','repair')` | no | `'restock'` | pre-fills inspection |
| `visible_in_portal` | `boolean` | no | `true` | |
| `is_active` | `boolean` | no | `true` | |
| `sort_order` | `unsignedSmallInteger` | no | `0` | |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `unique(['organization_id','code'], 'return_reasons_org_code_unique')`, `index(['organization_id','is_active'])`, `index('group')`.

> **MySQL caveat, be explicit:** a composite unique with a nullable column treats `NULL`s as distinct, so two global reasons could share a `code`. Global uniqueness is enforced in the seeder and in `StoreReturnReasonRequest` (`Rule::unique('return_reasons','code')->whereNull('organization_id')`). Do not rely on the DB for it.

**Seeded global taxonomy** (`database/seeders/ReturnReasonSeeder.php`):

| group | code | label_en | label_ar | is_defect | is_customer_fault | default_disposition |
| --- | --- | --- | --- | --- | --- | --- |
| customer | `changed_mind` | Changed mind | غيّر رأيه | no | yes | restock |
| customer | `ordered_by_mistake` | Ordered by mistake | طلب بالخطأ | no | yes | restock |
| customer | `found_better_price` | Found a better price | وجد سعرًا أفضل | no | yes | restock |
| customer | `no_longer_needed` | No longer needed | لم يعد بحاجة إليه | no | yes | restock |
| customer | `arrived_late` | Arrived too late | وصل متأخرًا | no | no | restock |
| product | `damaged_in_transit` | Damaged in transit | تالف أثناء الشحن | yes | no | scrap |
| product | `defective` | Defective / not working | معيب أو لا يعمل | yes | no | quarantine |
| product | `not_as_described` | Not as described | غير مطابق للوصف | yes | no | restock |
| product | `wrong_item_sent` | Wrong item sent | تم إرسال منتج خاطئ | yes | no | restock |
| product | `wrong_size` | Wrong size | مقاس غير مناسب | no | yes | restock |
| product | `wrong_color` | Wrong colour | لون غير مناسب | no | yes | restock |
| product | `missing_parts` | Missing parts | أجزاء ناقصة | yes | no | quarantine |
| product | `quality_below_expectation` | Quality below expectation | الجودة أقل من المتوقع | no | no | restock |
| product | `expired` | Expired / near expiry | منتهي الصلاحية | yes | no | scrap |
| logistics | `delivery_failed` | Delivery failed | فشل التسليم | no | no | restock |
| logistics | `address_incorrect` | Incorrect address | العنوان غير صحيح | no | yes | restock |
| logistics | `customer_unreachable` | Customer unreachable | تعذّر الوصول للعميل | no | yes | restock |
| logistics | `customer_refused` | Customer refused parcel | العميل رفض استلام الشحنة | no | yes | restock |
| logistics | `cod_payment_refused` | COD payment refused | رفض دفع المبلغ عند الاستلام | no | yes | restock |
| logistics | `out_of_delivery_zone` | Outside delivery zone | خارج نطاق التوصيل | no | no | restock |
| fraud | `suspected_fraud` | Suspected fraud | اشتباه احتيال | no | yes | quarantine |
| fraud | `chargeback` | Chargeback | استرداد قسري من البنك | no | no | quarantine |
| other | `other` | Other | أخرى | no | no | restock |

The five `logistics` codes are the RTO mapping targets — carriers report exactly these failure shapes.

### 3.2 `return_requests`

Migration: `2026_07_23_000002_create_return_requests_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations.id` `cascadeOnDelete` | no | — | denormalized, see note above |
| `store_id` | `foreignId` → `stores.id` `cascadeOnDelete` | no | — | |
| `order_id` | `foreignId` → `orders.id` `cascadeOnDelete` | no | — | |
| `rma_number` | `string(32)` | no | — | human key, e.g. `RMA-2026-000431` |
| `external_id` | `string(120)` | yes | `null` | marketplace return/claim id |
| `type` | `enum('customer_return','rto','damage_claim','exchange')` | no | `'customer_return'` | |
| `origin` | `enum('dashboard','portal','platform','carrier','api','mobile')` | no | `'dashboard'` | who created it |
| `status` | `string(32)` | no | `'requested'` | see §4.1 |
| `resolution` | `enum('refund','exchange','store_credit','repair','reject','none')` | no | `'none'` | chosen at approval |
| `reason_code` | `string(48)` | yes | `null` | header-level reason (majority line reason) |
| `reason_note` | `text` | yes | `null` | |
| `is_marketplace_managed` | `boolean` | no | `false` | true ⇒ read-only mirror, no approve/reject |
| `refund_responsibility` | `enum('merchant','marketplace','none')` | no | `'merchant'` | who pays the buyer |
| `currency` | `string(3)` | no | `'USD'` | copied from `orders.currency` |
| `items_subtotal` | `decimal(15,2)` | no | `0` | Σ line refundable value |
| `tax_refund` | `decimal(15,2)` | no | `0` | |
| `shipping_refund` | `decimal(15,2)` | no | `0` | original outbound shipping we give back |
| `restocking_fee` | `decimal(15,2)` | no | `0` | deducted |
| `return_shipping_cost` | `decimal(15,2)` | no | `0` | our cost of the inbound leg |
| `return_shipping_paid_by` | `enum('merchant','customer','marketplace')` | no | `'merchant'` | if `customer`, deducted from refund |
| `total_refund` | `decimal(15,2)` | no | `0` | computed, see §4.4 |
| `refunded_amount` | `decimal(15,2)` | no | `0` | Σ succeeded `refunds.amount` |
| `customer_name` | `string(255)` | yes | `null` | snapshot |
| `customer_email` | `string(255)` | yes | `null` | |
| `customer_phone` | `string(32)` | yes | `null` | primary MENA identifier |
| `pickup_address` | `json` | yes | `null` | snapshot for reverse pickup |
| `carrier_code` | `string(32)` | yes | `null` | e.g. `aramex`, `smsa` |
| `tracking_number` | `string(64)` | yes | `null` | inbound AWB |
| `return_shipment_id` | `unsignedBigInteger` | yes | `null` | FK to `shipments` **added in a follow-up migration once Spec 04 lands** |
| `outbound_shipment_id` | `unsignedBigInteger` | yes | `null` | the delivery that failed (RTO) |
| `replacement_order_id` | `foreignId` → `orders.id` `nullOnDelete` | yes | `null` | exchange / resend |
| `portal_token` | `string(64)` | yes | `null` | customer portal access |
| `created_by_user_id` | `foreignId` → `users.id` `nullOnDelete` | yes | `null` | null for portal/system |
| `approved_by_user_id` | `foreignId` → `users.id` `nullOnDelete` | yes | `null` | |
| `requested_at` | `timestamp` | yes | `null` | |
| `approved_at` | `timestamp` | yes | `null` | |
| `rejected_at` | `timestamp` | yes | `null` | |
| `shipped_at` | `timestamp` | yes | `null` | customer handed over |
| `received_at` | `timestamp` | yes | `null` | arrived at warehouse |
| `inspected_at` | `timestamp` | yes | `null` | |
| `refunded_at` | `timestamp` | yes | `null` | |
| `closed_at` | `timestamp` | yes | `null` | |
| `sla_due_at` | `timestamp` | yes | `null` | approval SLA, drives the "overdue" filter |
| `rejected_reason` | `text` | yes | `null` | |
| `raw_data` | `json` | yes | `null` | platform payload, mirrors `orders.raw_data` |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes:
- `unique(['organization_id','rma_number'])`
- `unique(['store_id','external_id'])` — mirrors `orders`' `unique(store_id, external_id)`; `external_id` nullable so merchant-created RMAs don't collide (same NULL caveat as above: enforce in app for the non-null case, which `updateOrCreate` already satisfies)
- `unique('portal_token')`
- `index(['organization_id','status'])`
- `index(['organization_id','type','created_at'])`
- `index(['store_id','status'])`
- `index('order_id')`
- `index('reason_code')`
- `index('tracking_number')`
- `index('sla_due_at')`

### 3.3 `return_items`

Migration: `2026_07_23_000003_create_return_items_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `return_request_id` | `foreignId` → `return_requests.id` `cascadeOnDelete` | no | — | |
| `order_item_id` | `foreignId` → `order_items.id` `nullOnDelete` | yes | `null` | nullable: RTO of a whole parcel may predate line mapping |
| `product_id` | `foreignId` → `products.id` `nullOnDelete` | yes | `null` | resolved at creation |
| `product_variant_id` | `foreignId` → `product_variants.id` `nullOnDelete` | yes | `null` | |
| `sku` | `string(255)` | yes | `null` | snapshot; matches `order_items.sku` nullability |
| `name` | `string(255)` | no | — | snapshot |
| `quantity_requested` | `unsignedInteger` | no | — | ≥ 1 |
| `quantity_approved` | `unsignedInteger` | no | `0` | may be < requested |
| `quantity_received` | `unsignedInteger` | no | `0` | |
| `quantity_restocked` | `unsignedInteger` | no | `0` | |
| `quantity_scrapped` | `unsignedInteger` | no | `0` | |
| `unit_price` | `decimal(15,2)` | no | `0` | from `order_items.price` |
| `tax_amount` | `decimal(15,2)` | no | `0` | allocated |
| `discount_amount` | `decimal(15,2)` | no | `0` | allocated |
| `refund_amount` | `decimal(15,2)` | no | `0` | computed |
| `unit_cost` | `decimal(15,2)` | yes | `null` | snapshot from `product_costs` at receipt, for COGS reversal |
| `reason_code` | `string(48)` | yes | `null` | per-line reason |
| `reason_note` | `text` | yes | `null` | |
| `condition` | `enum('new','opened','used','damaged','defective','wrong_item','missing_parts','unknown')` | no | `'unknown'` | set at inspection |
| `disposition` | `enum('restock','scrap','quarantine','return_to_vendor','repair','pending')` | no | `'pending'` | |
| `inspection_note` | `text` | yes | `null` | |
| `exchange_variant_id` | `foreignId` → `product_variants.id` `nullOnDelete` | yes | `null` | what we send back instead |
| `inventory_log_id` | `foreignId` → `inventory_logs.id` `nullOnDelete` | yes | `null` | restock traceability |
| `scrap_inventory_log_id` | `foreignId` → `inventory_logs.id` `nullOnDelete` | yes | `null` | scrap traceability |
| `received_at` | `timestamp` | yes | `null` | |
| `inspected_at` | `timestamp` | yes | `null` | |
| `restocked_at` | `timestamp` | yes | `null` | |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `index('return_request_id')`, `index('sku')`, `index('product_variant_id')`, `index(['disposition','inspected_at'])`, `unique(['return_request_id','order_item_id'])` (one RMA line per order line; a second return of the same order line becomes a **new** RMA).

Invariants enforced in `ReturnItemObserver` / service layer (not DB, because MySQL 5.7 compatibility is unknown):
- `quantity_approved ≤ quantity_requested`
- `quantity_received ≤ quantity_approved`
- `quantity_restocked + quantity_scrapped ≤ quantity_received`
- `quantity_requested ≤ order_items.quantity − Σ(quantity_approved on other non-cancelled RMAs for the same order_item)`

### 3.4 `return_events`

Migration: `2026_07_23_000004_create_return_events_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `return_request_id` | `foreignId` → `return_requests.id` `cascadeOnDelete` | no | — | |
| `from_status` | `string(32)` | yes | `null` | null on creation |
| `to_status` | `string(32)` | no | — | |
| `actor_type` | `enum('user','system','platform','carrier','customer')` | no | `'system'` | |
| `actor_id` | `unsignedBigInteger` | yes | `null` | `users.id` when `actor_type='user'` |
| `actor_label` | `string(120)` | yes | `null` | e.g. `aramex`, `amazon`, customer email |
| `note` | `text` | yes | `null` | |
| `payload` | `json` | yes | `null` | raw webhook / API response |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `index(['return_request_id','created_at'])`, `index('to_status')`.

This table is append-only. It is the audit trail: every status change, every refund attempt, every restock, every carrier tracking update that moved the RMA.

### 3.5 `refunds`

Migration: `2026_07_23_000005_create_refunds_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations.id` `cascadeOnDelete` | no | — | |
| `store_id` | `foreignId` → `stores.id` `cascadeOnDelete` | no | — | |
| `order_id` | `foreignId` → `orders.id` `cascadeOnDelete` | no | — | |
| `return_request_id` | `foreignId` → `return_requests.id` `nullOnDelete` | yes | `null` | a refund can exist without an RMA (goodwill) |
| `external_id` | `string(120)` | yes | `null` | platform refund id |
| `issuer` | `enum('merchant','marketplace','psp')` | no | `'merchant'` | who actually moved the money |
| `method` | `enum('original_payment','store_credit','bank_transfer','cash','wallet','cod_not_collected')` | no | `'original_payment'` | `cod_not_collected` = COD/RTO, no money ever changed hands |
| `status` | `enum('pending','processing','succeeded','failed','cancelled')` | no | `'pending'` | |
| `amount` | `decimal(15,2)` | no | `0` | total moved |
| `items_amount` | `decimal(15,2)` | no | `0` | |
| `shipping_amount` | `decimal(15,2)` | no | `0` | |
| `tax_amount` | `decimal(15,2)` | no | `0` | |
| `fee_amount` | `decimal(15,2)` | no | `0` | gateway/marketplace fee **not** returned to us |
| `currency` | `string(3)` | no | `'USD'` | |
| `gateway` | `string(48)` | yes | `null` | `shopify`, `woocommerce`, `edfapay`, `manual`, … |
| `reason` | `string(255)` | yes | `null` | |
| `failure_reason` | `text` | yes | `null` | |
| `attempts` | `unsignedTinyInteger` | no | `0` | |
| `idempotency_key` | `string(64)` | no | — | `sha1(return_id|line-hash|amount)` |
| `processed_at` | `timestamp` | yes | `null` | |
| `created_by_user_id` | `foreignId` → `users.id` `nullOnDelete` | yes | `null` | |
| `raw_data` | `json` | yes | `null` | |
| `created_at` / `updated_at` | `timestamps` | yes | `null` | |

Indexes: `unique('idempotency_key')`, `unique(['store_id','external_id'])`, `index(['organization_id','status'])`, `index('order_id')`, `index('return_request_id')`, `index(['organization_id','processed_at'])`.

### 3.6 Changes to existing tables

Migration: `2026_07_23_000006_add_line_identity_to_order_items_table.php`

```
order_items:
  + external_id       string(120) nullable  after 'order_id'   index
  + product_id        foreignId nullable -> products.id nullOnDelete
  + product_variant_id foreignId nullable -> product_variants.id nullOnDelete
  + tax_amount        decimal(15,2) not null default 0
  + discount_amount   decimal(15,2) not null default 0
  + returned_quantity unsignedInteger not null default 0
  unique(['order_id','external_id'])   // guarded: only added when no duplicate NULL-pair rows exist
```

Guarded with `Schema::hasColumn()` exactly like `2026_05_06_090717_fix_orders_table_columns.php`, so `migrate:fresh` and legacy DBs both work. `OrderItem::$fillable` gains the new keys, and `SyncOrdersJob::mapOrderData()` keeps writing `external_id`; the stray `product_name` key is renamed to `name` in the same PR.

Migration: `2026_07_23_000007_add_return_summary_to_orders_table.php`

```
orders:
  + return_status     string(24) nullable   // null | 'partial' | 'full' | 'rto'
  + returned_total    decimal(15,2) not null default 0
  + refunded_total    decimal(15,2) not null default 0
  + open_returns_count unsignedSmallInteger not null default 0
  index(['store_id','return_status'])
```

These are **derived** columns maintained by `ReturnRequestObserver` so the orders list can show a returns badge and filter without a join. They are rebuildable: `php artisan hubby:rebuild-order-return-summary`.

### 3.7 ER summary

```
organizations 1─* stores 1─* orders 1─* order_items
                                  │            │
                                  │            └─1 return_items *─1 return_requests
                                  └─* return_requests 1─* return_events
                                             │
                                             ├─* refunds
                                             ├─0..1 shipments (Spec 04, inbound leg)
                                             └─0..1 orders (replacement_order_id)
return_reasons ──(code)── return_requests.reason_code / return_items.reason_code
return_items ──0..2 inventory_logs (restock log, scrap log)
return_requests ──* order_fees (Profit spec: return_shipping, restocking, rto_fee)
```

---

## 4. Domain logic

### 4.1 Return request state machine

Statuses (stored in `return_requests.status`, `string(32)`):

| Status | Meaning |
| --- | --- |
| `requested` | Created by customer/merchant/platform. Nothing committed. |
| `approved` | Merchant accepted. Quantities approved per line. |
| `rejected` | Merchant refused. **Terminal.** |
| `cancelled` | Withdrawn by customer/merchant before receipt. **Terminal.** |
| `awaiting_shipment` | Approved and a return label/AWB exists; waiting for the customer to hand the parcel over. |
| `in_transit` | Carrier scanned the inbound parcel. |
| `received` | Parcel arrived at the merchant's location. |
| `inspecting` | Warehouse is grading lines. |
| `inspected` | All lines have a `condition` and a non-`pending` `disposition`. |
| `refund_pending` | Refund created, gateway/platform call in flight. |
| `refunded` | Refund(s) succeeded and `refunded_amount ≥ total_refund`. |
| `exchange_pending` | Replacement order created, not yet fulfilled. |
| `exchanged` | Replacement order fulfilled. **Terminal.** |
| `closed` | No monetary movement needed (e.g. RTO, or rejected-after-receipt). **Terminal.** |
| `failed` | Unrecoverable: parcel lost in transit, or refund permanently failed after max attempts. **Terminal until a user reopens.** |

Transition table (`App\Services\Returns\ReturnStateMachine::TRANSITIONS`):

| From | Allowed to | Trigger | Guard |
| --- | --- | --- | --- |
| `requested` | `approved` | `POST /returns/{id}/approve` | actor role ∈ {owner, admin}; `!is_marketplace_managed`; ≥1 line with `quantity_approved > 0` |
| `requested` | `rejected` | `POST /returns/{id}/reject` | role; `rejected_reason` present |
| `requested` | `cancelled` | customer portal or merchant | — |
| `requested` | `in_transit` | platform/carrier mirror | `is_marketplace_managed` (marketplaces auto-approve) |
| `approved` | `awaiting_shipment` | label issued | `return_shipment_id` set |
| `approved` | `in_transit` | carrier scan / manual | — |
| `approved` | `cancelled` | merchant/customer | no receipt yet |
| `awaiting_shipment` | `in_transit` | carrier `picked_up` webhook | — |
| `awaiting_shipment` | `cancelled` | merchant, or SLA expiry job | `now() > sla_due_at + grace` |
| `in_transit` | `received` | `POST /returns/{id}/receive` or carrier `delivered` at merchant address | — |
| `in_transit` | `failed` | carrier `lost` | — |
| `received` | `inspecting` | first line graded | — |
| `received` | `rejected` | merchant rejects after inspection-on-arrival | goods returned to customer; sets `closed_at` too |
| `inspecting` | `inspected` | all lines graded | every line `disposition != 'pending'` |
| `inspected` | `refund_pending` | `POST /returns/{id}/refund` | `resolution = 'refund'` or `'store_credit'`; `total_refund > 0`; `refund_responsibility = 'merchant'` |
| `inspected` | `exchange_pending` | `POST /returns/{id}/exchange` | `resolution = 'exchange'`; replacement order created |
| `inspected` | `closed` | auto | `total_refund = 0` or `refund_responsibility = 'marketplace'` or `type = 'rto'` |
| `refund_pending` | `refunded` | refund succeeded | `refunded_amount ≥ total_refund − 0.01` |
| `refund_pending` | `failed` | `attempts ≥ 3` and last status `failed` | |
| `exchange_pending` | `exchanged` | replacement order fulfilled | |
| `failed` | `requested` | `POST /returns/{id}/reopen` | role owner/admin only |

Anything not in the table throws `App\Exceptions\InvalidReturnTransition` → HTTP `422` with `{"message": "...", "code": "INVALID_RETURN_TRANSITION", "from": "...", "to": "..."}`.

Every accepted transition writes a `return_events` row inside the same DB transaction as the status write. No exceptions — the audit trail must never lag the state.

### 4.2 RTO state machine (`type = 'rto'`)

RTO is a return of a parcel that was **never delivered**, almost always COD. It is created by the system, not by a customer, and it never produces a refund because no money was collected.

```
(carrier reports final failure)
        │
        ▼
   requested ──auto──► approved ──► in_transit ──► received ──► inspecting ──► inspected ──► closed
                                        │
                                        └── failed (parcel lost on the way back)
```

Rules:
- Created by `DetectRtoJob` when the outbound shipment reaches a normalized status of `returned_to_origin` / `rto_in_transit` (Spec 04 §4), **or** when a platform order status maps to the org's configured RTO status (see §6).
- `origin = 'carrier'` (or `'platform'`), `reason_code` mapped from the carrier failure reason (`delivery_failed`, `customer_unreachable`, `customer_refused`, `cod_payment_refused`, `address_incorrect`, `out_of_delivery_zone`).
- `refund_responsibility = 'none'`; a `refunds` row **is** created with `method = 'cod_not_collected'`, `amount = 0`, `status = 'succeeded'` so the reconciliation report can prove no money was owed. (Alternative — creating no refund row at all — was rejected because analytics then can't distinguish "not refunded yet" from "nothing to refund".)
- All lines default to `disposition = 'restock'` and `condition = 'new'` — the goods never left the box — but the warehouse can override.
- Financial effect: reverse the recognized revenue for the order, and post the sunk costs as `order_fees` rows (see §4.6). This is the number MENA merchants care most about and nobody else shows them.
- `orders.return_status` is set to `'rto'` and `orders.status` is set to `'rto'` (a new status value the dashboard renders in red).

### 4.3 Partial returns

A partial return is the default, not a special case:

- A `return_request` contains 1..n `return_items`, each pointing at a distinct `order_items` row with `quantity_requested ≤ remaining returnable quantity`.
- Remaining returnable quantity for an order line =
  `order_items.quantity − order_items.returned_quantity` where `returned_quantity` counts lines on RMAs whose status ∉ {`rejected`, `cancelled`}.
- `order_items.returned_quantity` is incremented on approval and decremented if the RMA is later cancelled/rejected, inside a `DB::transaction` with `lockForUpdate()` on the order-item row to prevent two concurrent RMAs over-returning.
- A second return of the same order line (e.g. customer returns 1 of 3, then another 1) creates a **new** RMA. The `unique(return_request_id, order_item_id)` index makes this explicit.
- `orders.return_status` = `'partial'` if Σ approved return quantities < Σ order line quantities, `'full'` if equal, `'rto'` if any RTO RMA exists.

### 4.4 Refund calculation

Deterministic, computed by `App\Services\Returns\RefundCalculator`, always re-derived — never trusted from the client.

```
per line i (approved for refund):
  gross_i     = unit_price_i * qty_refundable_i
  discount_i  = discount_amount_i * (qty_refundable_i / quantity_i_on_order)
  tax_i       = tax_amount_i     * (qty_refundable_i / quantity_i_on_order)
  refund_i    = round(gross_i - discount_i + tax_i, 2)

items_subtotal = Σ refund_i                       (excluding tax)
tax_refund     = Σ tax_i
shipping_refund  = policy(§4.4.1)
restocking_fee   = policy(§4.4.2)
return_shipping_deduction = (return_shipping_paid_by === 'customer') ? return_shipping_cost : 0

total_refund = max(0,
    items_subtotal + tax_refund + shipping_refund
    - restocking_fee - return_shipping_deduction)
```

`qty_refundable_i` = `quantity_received_i` when the RMA has been received, else `quantity_approved_i` (used to *preview* the refund at approval time).

**Rounding:** each line rounds half-up to 2 dp. The residual `total_refund − Σ round(refund_i)` is added to the highest-value line so `Σ line refunds == header total` exactly. Refund totals are asserted to be ≤ `orders.total − orders.refunded_total`; violation → `422 REFUND_EXCEEDS_ORDER`.

**When line tax/discount are unknown** (legacy rows synced before migration `...000006`): `discount_i = tax_i = 0` and the calculator sets `refunds.raw_data.allocation = 'degraded'` so the merchant sees a "tax not itemised" hint in the UI rather than a silently wrong number.

#### 4.4.1 Shipping refund policy

Per-organization setting `returns.shipping_refund_policy` (stored in `organizations.settings` JSON, or a `settings` row — **ASSUMPTION**: `organizations` has a JSON settings column; if not, add `2026_07_23_000008_add_returns_settings_to_organizations_table.php` with `returns_settings json nullable`).

| Policy | Behaviour |
| --- | --- |
| `never` (default) | `shipping_refund = 0` |
| `on_defect` | full outbound shipping refunded iff every approved line's reason has `is_defect = true` |
| `on_full_return` | refunded iff the whole order is being returned |
| `always` | always refunded |

#### 4.4.2 Restocking fee policy

Setting `returns.restocking_fee`: `{ mode: 'none'|'percent'|'fixed', value: number, apply_when: 'customer_fault'|'always' }`.
- `percent` ⇒ `round(items_subtotal * value / 100, 2)`
- `fixed` ⇒ `min(value, items_subtotal)`
- `apply_when = 'customer_fault'` ⇒ only if **all** approved lines' reasons have `is_customer_fault = true`.

Never applied to `type = 'rto'` or `damage_claim`.

### 4.5 Restock vs scrap — and the inventory ledger

**Decision rule** at inspection (pre-filled from `return_reasons.default_disposition`, always overridable by the operator):

| condition | default disposition | rationale |
| --- | --- | --- |
| `new` | `restock` | sellable |
| `opened` | `restock` (org setting `returns.restock_opened`, default true) | sellable in most MENA categories |
| `used` | `scrap` | not sellable |
| `damaged` | `scrap` | not sellable; if reason is `damaged_in_transit`, flag for carrier claim |
| `defective` | `quarantine` → later `return_to_vendor` or `scrap` | supplier chargeback candidate |
| `wrong_item` | `quarantine` | it isn't our SKU; needs human triage |
| `missing_parts` | `quarantine` | |

**Inventory effect — this is the important part.**

Sold units already left stock when the order was created/synced, so a return must put them back. We use a **two-movement ledger** so `inventory_logs` alone can answer both "how much came back?" and "how much did we write off?":

| Disposition | `inventory_logs` rows written | Net stock change |
| --- | --- | --- |
| `restock` | 1 row: `change = +qty`, `source = 'return_restock'`, `reason = "RMA {rma_number} · {reason_code}"` | `+qty` |
| `scrap` | 2 rows: `change = +qty`, `source = 'return_received'`; then `change = -qty`, `source = 'return_scrap'`, `reason = "RMA {rma} · {condition}"` | `0` |
| `quarantine` | 2 rows: `+qty` `source='return_received'`, `-qty` `source='return_quarantine'` | `0` |
| `return_to_vendor` | 2 rows: `+qty` `return_received`, `-qty` `return_to_vendor` | `0` |
| `repair` | 2 rows: `+qty` `return_received`, `-qty` `return_repair` | `0` |

Why two movements rather than "do nothing for scrap": shrinkage and write-off value become a straight `SUM(change) WHERE source LIKE 'return_%' AND change < 0` query on a table that already exists, with no new shrinkage table and no ambiguity about whether a returned-but-unsellable unit was ever "in stock". The net is still zero so on-hand stays correct.

Implementation (`App\Services\Returns\RestockService::apply(ReturnItem $item)`), mirroring `InventoryController::adjust()` exactly:

```php
DB::transaction(function () use ($item) {
    $target = $item->product_variant_id
        ? ProductVariant::lockForUpdate()->find($item->product_variant_id)
        : Product::lockForUpdate()->find($item->product_id);
    // ... increment('stock', +qty) then, for non-restock dispositions, decrement back
    // ... InventoryLog::create([...]) per movement, ids stored on the return_item
});
```

Then dispatch `PushInventoryJob` (existing job) for the affected product so all connected stores get the new quantity — this is the step `InventoryController::adjust()` still has as a `// TODO`, and returns is the feature that makes it non-optional.

Guards:
- Restock is **idempotent per line**: if `return_items.inventory_log_id` is already set, the call is a no-op and logs a warning. Prevents double-restock on job retry.
- Restock is only permitted from status `received`, `inspecting`, or `inspected`.
- If `product_variant_id` and `product_id` are both null (unmapped SKU), the line is marked `disposition = 'quarantine'` and a `Notification` of type `warning` is raised: *"Return line SKU {sku} is not mapped to a Hubby product — stock was not adjusted."* Never silently drop.

### 4.6 Cost posting into profit (references the Profit & Cost Engine spec)

Returns write to `order_fees` (defined in the Profit spec, **not** here). This spec only commits to the fee `type` values and when they are written:

| `order_fees.type` | Written when | Amount |
| --- | --- | --- |
| `return_shipping` | inbound label purchased or manual cost entered | `return_requests.return_shipping_cost` |
| `restocking_credit` | refund succeeds with a restocking fee | `−restocking_fee` (income, reduces cost) |
| `refund` | refund succeeds | `refunds.amount` |
| `refund_fee_retained` | refund succeeds and gateway/marketplace keeps its fee | `refunds.fee_amount` |
| `rto_shipping` | RTO closed | outbound + inbound leg cost from the shipments |
| `write_off` | line disposition ∈ {scrap, repair} | `unit_cost × quantity_scrapped` from `product_costs` |

COGS reversal: on `restock`, the units' `unit_cost` returns to inventory value, so the Profit engine must **not** double-count. `return_items.unit_cost` is snapshotted at receipt for this reason. Exact posting semantics belong to the Profit spec; this spec guarantees the data is there.

### 4.7 Exchanges and resends

Two distinct flows, one mechanism:

- **Exchange** (`resolution = 'exchange'`): customer returns item A, receives item B. `return_items.exchange_variant_id` names B. On `POST /returns/{id}/exchange`, we create a **new local order** (`orders` row) with `external_id = "EXCH-{rma_number}"`, `total = 0`, `status = 'pending'`, `store_id` = original store, and link it via `replacement_order_id`. Price difference: if `Σ(exchange variant price × qty) > Σ(returned line value)`, the delta is surfaced to the merchant as "collect {amount} from customer" — v1 does **not** create a payment link (out of scope).
- **Resend** (`resolution = 'exchange'` with `exchange_variant_id` equal to the original variant): same mechanism, used for lost/damaged parcels. Reason code is usually `damaged_in_transit`.

The replacement order is a **local** order and is not pushed to the source platform in v1 (Shopify/Salla/Woo all have draft-order APIs that would let us; marketplaces do not). This is stated in the UI: *"Replacement order created in Hubby only — fulfil and ship it from here."*

### 4.8 RMA number generation

`RMA-{YYYY}-{6-digit org sequence}`, e.g. `RMA-2026-000431`. Generated in a transaction with `SELECT ... FOR UPDATE` on an `organizations`-scoped counter (reuse the same approach as any existing sequence; if none exists, `MAX(rma_number)` within a `lockForUpdate` on the `return_requests` table filtered by org + year). Never derived from `id` (leaks volume across tenants).

### 4.9 Edge-case rules (see also §10)

- **Return window:** org setting `returns.window_days` (default 14). `POST /returns` beyond the window is rejected with `422 RETURN_WINDOW_EXPIRED` unless the actor is owner/admin (merchants can always override; customers can't).
- **Non-returnable SKUs:** org setting `returns.non_returnable_skus` (array) plus a per-product flag (v1.1). Portal blocks them; dashboard warns but allows.
- **Marketplace-managed RMAs are read-only:** approve/reject/refund endpoints return `409 MARKETPLACE_MANAGED` with a link to the marketplace console. We can still record receipt, inspection, and restock — because the physical goods land in *our* warehouse regardless of who decided the refund.

---

## 5. Backend

### 5.1 Models — `backend/app/Models/`

```
ReturnRequest.php   ReturnItem.php   ReturnReason.php   ReturnEvent.php   Refund.php
```

Each follows the existing minimal style (no traits beyond what is needed, `$fillable`, `$casts`, typed relation methods returning `BelongsTo`/`HasMany`).

`ReturnRequest`:
- `$casts`: `raw_data => 'array'`, `pickup_address => 'array'`, all `*_at => 'datetime'`, money → `'decimal:2'`.
- Relations: `organization()`, `store()`, `order()`, `items()`, `events()`, `refunds()`, `replacementOrder()`, `createdBy()`, `approvedBy()`.
- Scopes: `scopeForOrganization($q, $orgId)`, `scopeOpen($q)` (status ∉ terminal set), `scopeOverdue($q)`.
- Accessors: `getIsTerminalAttribute()`, `getOutstandingRefundAttribute()`.

Additions to existing models:
- `Order`: `returns(): HasMany`, `refunds(): HasMany`; extend `$fillable` with `return_status`, `returned_total`, `refunded_total`, `open_returns_count`.
- `OrderItem`: extend `$fillable` with `external_id`, `product_id`, `product_variant_id`, `tax_amount`, `discount_amount`, `returned_quantity`; add `returnItems(): HasMany`, `variant(): BelongsTo`.
- `InventoryLog`: no change (already generic via `source`/`reason`).

### 5.2 Services — `backend/app/Services/Returns/`

| Class | Responsibility |
| --- | --- |
| `ReturnService` | Orchestrator. `createFromOrder()`, `approve()`, `reject()`, `cancel()`, `markShipped()`, `receive()`, `inspect()`, `close()`, `reopen()`. Every method opens a `DB::transaction`, calls the state machine, writes a `ReturnEvent`, fires a domain event. |
| `ReturnStateMachine` | `canTransition(string $from, string $to): bool`, `assert(ReturnRequest $r, string $to): void`. Holds the `TRANSITIONS` const from §4.1. Pure, no DB. |
| `RefundCalculator` | `preview(ReturnRequest $r): RefundBreakdown`, `finalize(ReturnRequest $r): RefundBreakdown`. Pure; policy read from org settings. |
| `RefundService` | `issue(ReturnRequest $r, array $opts): Refund`. Creates the `refunds` row with an idempotency key, dispatches `IssueRefundJob`, records success/failure. Decides merchant-vs-marketplace from `refund_responsibility`. |
| `RestockService` | §4.5. `apply(ReturnItem $item): void`, `applyAll(ReturnRequest $r): void`. |
| `RtoDetector` | `fromShipmentStatus(Shipment $s): ?ReturnRequest`, `fromOrderStatus(Order $o, string $newStatus): ?ReturnRequest`. Maps carrier/platform statuses to `logistics` reason codes. |
| `ReturnPortalService` | Token issue/verify (`hash_equals`, 30-day TTL), rate-limited lookup, portal DTO with **no** PII beyond what the requester already proved they know. |
| `ReturnNumberGenerator` | §4.8. |
| `PlatformReturnMirror` | Given a platform payload, upsert a `ReturnRequest` + items. One `map{Platform}()` method per platform, mirroring `SyncOrdersJob::mapOrderData()`'s shape. |

### 5.3 Integration interface extension

**Do not widen `IntegrationServiceInterface`** — all 7 services implement it and only some can do returns. Add a capability interface next to it:

`backend/app/Services/Integrations/SupportsReturnsInterface.php`

```php
interface SupportsReturnsInterface
{
    /** @return array<int,array<string,mixed>> raw platform return objects */
    public function fetchReturns(Store $store, array $params = []): array;

    public function approveReturn(Store $store, string $externalReturnId, array $payload = []): bool;

    public function rejectReturn(Store $store, string $externalReturnId, string $reason): bool;

    /** @return array{id:string,status:string,amount:float}|null */
    public function refundOrder(Store $store, string $externalOrderId, array $payload): ?array;

    /** @return array{tracking_number:string,label_url:?string}|null */
    public function createReturnLabel(Store $store, string $externalReturnId, array $payload = []): ?array;

    /** Capability probe: 'fetch','approve','reject','refund','label' */
    public function supportsReturnCapability(string $capability): bool;
}
```

Call sites always guard:

```php
$service = IntegrationFactory::make($store->platform);
if (! $service instanceof SupportsReturnsInterface) {
    // local-only RMA; nothing to push
}
```

`IntegrationFactory` is unchanged. A companion `ReturnCapabilityMatrix` (plain PHP array in `config/returns.php`) drives the dashboard so the UI can grey out "Approve on platform" without a round trip.

### 5.4 Jobs — `backend/app/Jobs/`

All follow the existing pattern: `implements ShouldQueue`, `use Dispatchable, InteractsWithQueue, Queueable, SerializesModels`, `SyncLog` row where it is a sync, `Notification` on terminal failure.

| Job | Trigger | Behaviour |
| --- | --- | --- |
| `SyncReturnsJob(Store $store = null)` | scheduler every 30 min + webhook | Mirrors the `SyncOrdersJob` shape exactly (null store ⇒ fan out to all stores). Calls `fetchReturns()` when supported, upserts via `PlatformReturnMirror`. Writes `SyncLog(type: 'returns')`. |
| `PushReturnDecisionJob(ReturnRequest $r, string $decision)` | after approve/reject | Calls `approveReturn`/`rejectReturn`. `tries = 3`, `backoff = [60, 300, 900]`. On final failure sets `return_events` entry + `Notification(type: 'error')`; **does not** roll back the local decision. |
| `IssueRefundJob(Refund $refund)` | after `RefundService::issue()` | Calls `refundOrder()`; on `200` marks `succeeded` + `processed_at`; on failure increments `attempts`, records `failure_reason`. `tries = 3`, exponential backoff. Idempotent via `refunds.idempotency_key` — re-running never double-refunds because we check `status = 'succeeded'` first. |
| `RestockReturnItemsJob(ReturnRequest $r)` | after inspection completes | `RestockService::applyAll()` then `PushInventoryJob` per affected product. |
| `DetectRtoJob(Shipment $shipment)` | Spec 04 tracking webhook | `RtoDetector::fromShipmentStatus()`. Idempotent: no-op if an RTO RMA already exists for the order. |
| `ExpireReturnLabelsJob` | daily scheduler | `awaiting_shipment` older than `sla_due_at + 7d` ⇒ `cancelled`, void label via carrier. |
| `CloseSettledReturnsJob` | daily | `refunded`/`exchanged` older than 30 d ⇒ `closed`. |

Scheduler entries in `bootstrap/app.php` / `routes/console.php` (match wherever `GenerateDailyAnalyticsJob` is scheduled today).

### 5.5 Events & listeners — `backend/app/Events/Returns/`

`ReturnRequested`, `ReturnApproved`, `ReturnRejected`, `ReturnReceived`, `ReturnInspected`, `ReturnRestocked`, `ReturnRefunded`, `ReturnExchanged`, `RtoDetected`, `ReturnFailed`.

Listeners:
- `NotifyOrganizationOnReturnEvent` → creates a `Notification` row (the repo's existing in-app notification mechanism; `SyncOrdersJob` writes these inline, so keep both patterns consistent by routing all *new* notifications through the listener).
- `UpdateOrderReturnSummary` → maintains `orders.return_status` / `returned_total` / `refunded_total` / `open_returns_count`.
- `PostReturnFeesToProfit` → writes `order_fees` rows (§4.6). Registered only when the Profit engine feature flag is on.
- `EmitReturnWebhook` (v1.1 placeholder — outbound webhooks are out of scope).

### 5.6 API endpoints

All merchant endpoints go inside the existing `Route::middleware('auth:sanctum')->group(... Route::middleware('org.member')->group(...))` block in `backend/routes/api.php`, in the same flat style as the Orders block. Every handler scopes by `$request->header('X-Organization-Id')`.

```php
// Returns
Route::get('/returns', [ReturnController::class, 'index']);
Route::get('/returns/export', [ReturnController::class, 'export']);
Route::get('/returns/reasons', [ReturnReasonController::class, 'index']);
Route::post('/returns/reasons', [ReturnReasonController::class, 'store']);
Route::put('/returns/reasons/{id}', [ReturnReasonController::class, 'update']);
Route::delete('/returns/reasons/{id}', [ReturnReasonController::class, 'destroy']);
Route::post('/returns', [ReturnController::class, 'store']);
Route::get('/returns/{id}', [ReturnController::class, 'show']);
Route::put('/returns/{id}', [ReturnController::class, 'update']);
Route::post('/returns/{id}/approve', [ReturnActionController::class, 'approve']);
Route::post('/returns/{id}/reject', [ReturnActionController::class, 'reject']);
Route::post('/returns/{id}/cancel', [ReturnActionController::class, 'cancel']);
Route::post('/returns/{id}/label', [ReturnActionController::class, 'label']);
Route::post('/returns/{id}/receive', [ReturnActionController::class, 'receive']);
Route::post('/returns/{id}/inspect', [ReturnActionController::class, 'inspect']);
Route::post('/returns/{id}/restock', [ReturnActionController::class, 'restock']);
Route::post('/returns/{id}/refund', [ReturnActionController::class, 'refund']);
Route::post('/returns/{id}/exchange', [ReturnActionController::class, 'exchange']);
Route::post('/returns/{id}/reopen', [ReturnActionController::class, 'reopen']);
Route::get('/returns/{id}/events', [ReturnController::class, 'events']);
Route::post('/orders/{id}/returns', [ReturnController::class, 'storeForOrder']);
Route::post('/returns/sync', [ReturnController::class, 'sync']);

// Refunds
Route::get('/refunds', [RefundController::class, 'index']);
Route::get('/refunds/{id}', [RefundController::class, 'show']);
Route::post('/refunds/{id}/retry', [RefundController::class, 'retry']);

// Returns analytics
Route::get('/analytics/returns', [ReturnAnalyticsController::class, 'summary']);
Route::get('/analytics/returns/by-reason', [ReturnAnalyticsController::class, 'byReason']);
Route::get('/analytics/returns/by-sku', [ReturnAnalyticsController::class, 'bySku']);
Route::get('/analytics/returns/by-platform', [ReturnAnalyticsController::class, 'byPlatform']);
Route::get('/analytics/returns/rto', [ReturnAnalyticsController::class, 'rto']);
```

Customer portal (public, **outside** the sanctum group, near the existing public routes):

```php
Route::prefix('portal')->middleware('throttle:10,1')->group(function () {
    Route::post('/returns/lookup', [PortalReturnController::class, 'lookup']);
    Route::post('/returns', [PortalReturnController::class, 'store']);
    Route::get('/returns/{token}', [PortalReturnController::class, 'show']);
    Route::post('/returns/{token}/cancel', [PortalReturnController::class, 'cancel']);
});
```

#### Endpoint contracts

**`GET /api/returns`** — auth: `auth:sanctum` + `org.member`, role any.
Query: `status` (string | `All`), `type`, `platform` (mirrors `OrderController` semantics incl. the `All` sentinel), `store_id`, `reason_code`, `search` (rma_number / order external_id / customer name / email / phone / tracking_number), `date_from`, `date_to` (`Y-m-d`), `overdue` (bool), `sort` (`created_at|total_refund|sla_due_at`), `direction`, `per_page` (default 15, max 100).
Response: Laravel paginator, same envelope as `GET /orders`:

```json
{
  "current_page": 1,
  "data": [{
    "id": 431, "rma_number": "RMA-2026-000431", "status": "inspected",
    "type": "customer_return", "resolution": "refund",
    "reason_code": "wrong_size", "is_marketplace_managed": false,
    "currency": "SAR", "total_refund": "249.00", "refunded_amount": "0.00",
    "customer_name": "Sara A.", "tracking_number": "4712398812",
    "sla_due_at": "2026-07-25T09:00:00Z", "created_at": "...",
    "order": { "id": 9912, "external_id": "1002345", "total": "612.00" },
    "store": { "id": 3, "name": "Salla Store", "platform": "salla" },
    "items_count": 2
  }],
  "per_page": 15, "total": 87
}
```

**`POST /api/returns`** — role `owner|admin`.
Validation:
```php
'order_id'                 => ['required','integer','exists:orders,id'],
'type'                     => ['nullable', Rule::in(['customer_return','rto','damage_claim','exchange'])],
'reason_code'              => ['required','string','max:48'],
'reason_note'              => ['nullable','string','max:2000'],
'resolution'               => ['nullable', Rule::in(['refund','exchange','store_credit','repair','none'])],
'return_shipping_paid_by'  => ['nullable', Rule::in(['merchant','customer','marketplace'])],
'items'                    => ['required','array','min:1'],
'items.*.order_item_id'    => ['required','integer','exists:order_items,id'],
'items.*.quantity'         => ['required','integer','min:1'],
'items.*.reason_code'      => ['nullable','string','max:48'],
'items.*.reason_note'      => ['nullable','string','max:2000'],
'items.*.exchange_variant_id' => ['nullable','integer','exists:product_variants,id'],
```
Extra rules enforced in `StoreReturnRequest::withValidator()`: the order belongs to the org (via its store); every `order_item_id` belongs to that order; `quantity ≤ remaining returnable`; the order is inside `returns.window_days` unless the actor is owner/admin.
Responses: `201` with the full RMA resource; `422` with Laravel's validation envelope; `403` on role; `404` when the order isn't in the org.

**`POST /api/returns/{id}/approve`** — role `owner|admin`.
```php
'items'                  => ['required','array','min:1'],
'items.*.id'             => ['required','integer','exists:return_items,id'],
'items.*.quantity_approved' => ['required','integer','min:0'],
'resolution'             => ['required', Rule::in(['refund','exchange','store_credit','repair'])],
'restocking_fee'         => ['nullable','numeric','min:0'],
'shipping_refund'        => ['nullable','numeric','min:0'],
'note'                   => ['nullable','string','max:2000'],
'create_return_label'    => ['nullable','boolean'],
'carrier_code'           => ['nullable','string','max:32','required_if:create_return_label,true'],
```
`200` → RMA with a `refund_preview` block (`RefundCalculator::preview()`); `409 MARKETPLACE_MANAGED`; `422 INVALID_RETURN_TRANSITION`.

**`POST /api/returns/{id}/receive`** — role `owner|admin` (warehouse staff are `admin` in v1).
```php
'items'                    => ['required','array','min:1'],
'items.*.id'               => ['required','integer','exists:return_items,id'],
'items.*.quantity_received'=> ['required','integer','min:0'],
'received_at'              => ['nullable','date'],
'note'                     => ['nullable','string','max:2000'],
```

**`POST /api/returns/{id}/inspect`** — role `owner|admin`.
```php
'items'                 => ['required','array','min:1'],
'items.*.id'            => ['required','integer','exists:return_items,id'],
'items.*.condition'     => ['required', Rule::in(['new','opened','used','damaged','defective','wrong_item','missing_parts'])],
'items.*.disposition'   => ['required', Rule::in(['restock','scrap','quarantine','return_to_vendor','repair'])],
'items.*.quantity_restocked' => ['nullable','integer','min:0'],
'items.*.quantity_scrapped'  => ['nullable','integer','min:0'],
'items.*.inspection_note'    => ['nullable','string','max:2000'],
'auto_restock'          => ['nullable','boolean'],   // default true
```
On success dispatches `RestockReturnItemsJob`. `200` → RMA + `inventory_preview`.

**`POST /api/returns/{id}/refund`** — role `owner` only by default (org setting `returns.refund_min_role`, default `owner`).
```php
'amount'          => ['nullable','numeric','min:0'],   // omit ⇒ calculated total
'method'          => ['nullable', Rule::in(['original_payment','store_credit','bank_transfer','cash','wallet'])],
'notify_customer' => ['nullable','boolean'],
'reason'          => ['nullable','string','max:255'],
```
`202 Accepted` with the `refunds` row in `pending` (the actual money movement is async). `409 REFUND_ALREADY_ISSUED` when a `succeeded`/`processing` refund with the same idempotency key exists. `422 REFUND_EXCEEDS_ORDER`.

**`POST /api/returns/{id}/label`** — role `owner|admin`. Delegates to Spec 04's `ShippingService::createReturnShipment()`. `202` + `{ "shipment_id": 88, "tracking_number": "...", "label_url": "..." }`. `503 CARRIER_UNAVAILABLE` on carrier timeout, with the RMA left in `approved`.

**`GET /api/analytics/returns`** — query `date_from`, `date_to`, `store_id`, `platform`. Response:
```json
{
  "orders_count": 4210, "returns_count": 218, "return_rate": 0.0518,
  "rto_count": 141, "rto_rate": 0.0335,
  "units_returned": 260, "units_restocked": 197, "restock_rate": 0.7577,
  "refund_value": "48210.00", "return_shipping_cost": "6350.00",
  "write_off_value": "3120.00", "currency": "SAR",
  "avg_days_to_close": 6.4
}
```

**Portal `POST /api/portal/returns/lookup`** — no auth, `throttle:10,1`, additionally throttled per order key.
```php
'order_reference' => ['required','string','max:120'],   // orders.external_id
'contact'         => ['required','string','max:255'],   // email or phone
```
Returns `200` with a minimal order DTO (order reference, placed date, returnable lines with name/sku/qty, and a one-time `lookup_token`) or a deliberately generic `404 { "message": "We couldn't find that order." }` — never distinguishing "no such order" from "contact doesn't match", to avoid an enumeration oracle.

### 5.7 Error codes

`INVALID_RETURN_TRANSITION` (422), `RETURN_WINDOW_EXPIRED` (422), `QUANTITY_EXCEEDS_ORDER_LINE` (422), `REFUND_EXCEEDS_ORDER` (422), `REFUND_ALREADY_ISSUED` (409), `MARKETPLACE_MANAGED` (409), `RETURN_ALREADY_RESTOCKED` (409), `SKU_NOT_MAPPED` (409), `CARRIER_UNAVAILABLE` (503), `PLATFORM_UNAVAILABLE` (503). Every response carries `{"message","code"}` at minimum; validation errors keep Laravel's `{"message","errors"}` shape so the existing frontend error handling keeps working.

---

## 6. Per-platform notes

Honest capability matrix first. **Verified** = confirmed against the code in this repo or well-established public API surface. **Unverified** = plausible from public knowledge but *must* be confirmed against live sandbox docs before implementation; do not ship against it blind.

| Platform | Native returns object | We can create | We can approve/reject | We can refund | Return label | v1 mode |
| --- | --- | --- | --- | --- | --- | --- |
| Shopify | Yes (Returns API) | Yes | Yes | Yes | Yes (reverse delivery) | Two-way |
| Salla | Unverified | No | No | Unverified | No | Local + status mirror |
| Zid | Unverified | No | No | Unverified | No | Local + status mirror |
| WooCommerce | No (refunds only) | n/a | n/a | Yes | No | Local RMA, push refund |
| Amazon | Yes, seller-controlled only for some | No | Limited | Marketplace issues | Amazon issues | Mirror-only |
| Noon | Unverified | No | No | Marketplace issues | Marketplace issues | Mirror-only |
| Trendyol | Yes (claims) | No | Yes (unverified path) | Marketplace issues | Marketplace issues | Mirror + approve |

### 6.1 Shopify

Current code (`ShopifyService`) uses **REST Admin `2024-01`** with an `X-Shopify-Access-Token` header and offline tokens, and its scopes are `read_orders,write_orders,read_products,write_products,read_inventory,write_inventory`.

- **Refunds — verified pattern, REST:** `POST /admin/api/2024-01/orders/{order_id}/refunds/calculate.json` to get the suggested refund (line items, shipping, tax) followed by `POST /admin/api/2024-01/orders/{order_id}/refunds.json` to commit it. This is a natural fit for `RefundCalculator` — we call `calculate` and compare against our own number, logging a `return_events` entry when they disagree by more than 0.01 (that disagreement is a real signal about tax config).
- **Returns object — GraphQL only, UNVERIFIED against our app:** Shopify's merchant-managed returns (`returnCreate`, `returnRequestApprove`, `returnRequestDeclare`, `returnProcess`, `reverseDeliveryCreateWithShipping`) live in the **GraphQL Admin API**, not REST. Our codebase has no GraphQL client. Implementation cost: add a small `ShopifyGraphQLClient` (a single `POST /admin/api/{version}/graphql.json`). Mutation names, argument shapes, and the exact `returns` scope requirement **must be re-verified against the current Shopify Admin API docs** — I am not asserting them from memory.
- **Additional scopes required:** `read_returns`, `write_returns` (name unverified). Because scopes are baked into `ShopifyService::getAuthUrl()`, adding them forces a **re-authorisation of every connected Shopify store**. Plan for a re-consent banner; this is the single biggest rollout cost of Spec A.
- **Webhooks:** `refunds/create` is a long-standing verified topic. `returns/request`, `returns/approve`, `returns/decline`, `returns/close` are plausible but **unverified**; `WebhookController::handleShopify()` gets a `str_contains($event, 'returns/')` and a `refunds/` branch that dispatch `SyncReturnsJob`. Fall back to a 30-minute poll if the topics don't exist.
- **Restocking:** Shopify's refund payload has a `restock_type` per line (`no_restock`, `cancel`, `return`, `legacy_restock`). We must send `no_restock` and manage stock ourselves, otherwise Shopify restocks *and* Hubby restocks and stock doubles. This is a real, easy-to-hit double-count bug — call it out in code comments and cover it with a test.

### 6.2 Salla

Current code uses OAuth2 against `accounts.salla.sa` and the Admin API at `https://api.salla.dev/admin/v2`, with `POST /orders/{id}/status` for status pushes.

- **Returns/refunds endpoints: UNVERIFIED.** I did not find, and am not asserting, a Salla returns API surface. Salla's order model does carry a returned/refunded status, and Salla has a merchant-facing returns feature in its own dashboard.
- **v1 approach — status mirror.** Hubby owns the RMA record. `SyncReturnsJob` for Salla does not call a returns endpoint; instead `SyncOrdersJob` gains a hook: when a Salla order's status name maps to the org's configured "returned"/"refunded" status (config `returns.platform_status_map.salla`, seeded with `مسترجع`, `returned`, `refunded`, `مرتجع`), `PlatformReturnMirror` creates a mirrored RMA with `origin = 'platform'`, `is_marketplace_managed = false`.
- **Push:** on approve we push a status change via the existing `updateOrderStatus()` using the org-configured status slug. On refund we mark `refunds.issuer = 'merchant'`, `gateway = 'manual'` unless a Salla refund endpoint is confirmed — the merchant refunds in Salla and Hubby records it.
- **Action item before build:** confirm against Salla's current Merchant API docs whether `GET /orders/{id}/refunds` or an order-refund endpoint exists. If yes, upgrade Salla to `SupportsReturnsInterface` with `refund` capability only.

### 6.3 Zid

`ZidService` exists in the repo and implements the base interface.

- **Returns/refunds endpoints: UNVERIFIED.** Same posture as Salla — I will not invent an endpoint.
- **v1 approach:** identical status-mirror path (`returns.platform_status_map.zid`), local RMA, manual refund recording. Zid's order status vocabulary must be read from a live account during implementation.
- Zid and Salla together are ~most of the Saudi SMB long tail, so "local RMA that still restocks inventory and posts cost to profit" is genuinely valuable even with zero platform API — the merchant currently has *nothing*.

### 6.4 WooCommerce

- **Refunds — verified pattern:** WooCommerce REST API v3, `POST /wp-json/wc/v3/orders/{order_id}/refunds` with `{ amount, reason, api_refund, line_items: [{id, quantity, refund_total, refund_tax}] }`, authenticated with consumer key/secret (Basic auth over HTTPS). `GET .../refunds` lists them. Confirm the auth mode actually used by `WooCommerceService` before wiring.
- **`api_refund` flag:** when `true`, Woo asks the payment gateway to refund. Many MENA Woo stores use COD or manual bank transfer where the gateway can't refund — so default `api_refund = false` and let the org opt in per store (`stores.settings.woo_api_refund`). Getting this wrong produces a refund that looks successful in Hubby and never reaches the customer.
- **`restock_items`:** Woo's refund endpoint can restock. Send `false` (or omit) — Hubby owns stock, same double-count hazard as Shopify.
- **No native RMA.** Returns in Woo-land are plugin territory (WooCommerce Returns/RMA, YITH). We do **not** integrate with plugins in v1. Hubby is the RMA system; Woo receives only the refund.
- **Webhooks:** Woo delivers `order.updated` with topic header `X-WC-Webhook-Topic`, already handled. A refund created in Woo shows up as an order update; `SyncOrdersJob` should detect `refunds[]` growth in the payload and mirror it.

### 6.5 Amazon

`AmazonService` uses LWA (`api.amazon.com/auth/o2/token`) + SP-API regional hosts (`sellingpartnerapi-{na,eu,fe}.amazon.com`) with `x-amz-access-token`.

**Be blunt: Amazon returns are Amazon's.** For FBA, the customer initiates, Amazon authorises, Amazon refunds, the goods land in an Amazon fulfilment centre. For MFN (seller-fulfilled) with prepaid returns enabled, most returns are auto-authorised too. A seller tool that pretends it can "approve" an Amazon return is lying.

- **What we can do — mirror.** Returns data comes from the **Reports API**, not a returns REST resource: request a report of type `GET_XML_RETURNS_DATA_BY_RETURN_DATE` (MFN) or `GET_FBA_FULFILLMENT_CUSTOMER_RETURNS_DATA` (FBA), poll `getReportDocument`, decrypt/decompress, parse. **Report type identifiers and availability per marketplace are UNVERIFIED here and must be checked against the current SP-API report-type reference** — Amazon renames and deprecates these regularly.
- **Refund mirroring:** financial events (`GET /finances/v0/financialEvents`) carry `RefundEventList`, which is the authoritative record of what Amazon actually refunded, including retained fees. That's the row we write into `refunds` with `issuer = 'marketplace'`, `refund_responsibility = 'marketplace'`. Endpoint path is well established but treat the response shape as needing verification.
- **MFN return authorisation:** SP-API's Merchant Fulfillment / Solicitations surface does not give general approve/deny. Amazon has historically exposed return authorisation only through Seller Central. **Unverified whether any current SP-API operation allows programmatic approve/deny; assume not.**
- **v1 behaviour:** `is_marketplace_managed = true`, `refund_responsibility = 'marketplace'`, approve/reject endpoints return `409 MARKETPLACE_MANAGED` with a deep link to Seller Central. Receive/inspect/restock **are** enabled for MFN because the physical goods come to the merchant's own warehouse.
- **Rate limits:** SP-API Reports is heavily throttled (report creation is on the order of a request per minute). `SyncReturnsJob` for Amazon must run on a slow cadence (default every 6 h, configurable) and use `Bus::chain` with delays, never a tight loop.

### 6.6 Noon

`NoonService` targets `api.noon.partners` / `accounts.noon.partners` (both config-overridable), OAuth2 client-credentials.

- **Returns API: UNVERIFIED — publicly undocumented.** Noon's partner APIs are behind a seller agreement. I am not going to assert endpoint paths.
- **What is structurally certain:** Noon is a marketplace. Noon owns the customer relationship, authorises the return, and issues the refund. Noon also has a very high RTO/failed-delivery share in the region. So the v1 posture is identical to Amazon: `is_marketplace_managed = true`, `refund_responsibility = 'marketplace'`, mirror-only.
- **v1 mechanism — order status mapping, no new endpoint:** `returns.platform_status_map.noon` maps Noon order/item statuses (`returned`, `return_in_transit`, `rto`, `failed_delivery`, `cancelled_by_customer` — **values unverified, must be read from a live seller account**) into RMA creation with the right `type` and `reason_code`. This works today with the polling we already do, and needs zero new Noon API surface.
- **Implementation gate:** before writing `NoonService::fetchReturns()`, get a sandbox/seller account and capture 20 real order payloads. Encode the observed status vocabulary into `config/returns.php`, not into code.

### 6.7 Trendyol

`TrendyolService` uses HTTP Basic auth (`apiKey`/`apiSecret`) with a `User-Agent: {supplierId} - SelfIntegration` header against `https://api.trendyol.com` (`/sapigw/suppliers/{supplierId}/...`) — this is verified in our code.

- **Trendyol does have a Claims (iade / returns) API** under the supplier namespace, e.g. `GET /sapigw/suppliers/{supplierId}/claims` with filters, plus item-level approve and "create claim issue" operations. **The exact paths, verbs and body shapes are UNVERIFIED here** and must be confirmed against Trendyol's current integration docs (they migrated hosts and versioned paths in recent years — the base host itself is configurable in our service for exactly this reason).
- **Refunds are Trendyol's.** The buyer is refunded by Trendyol; the seller's lever is approving or contesting the claim. So: `refund_responsibility = 'marketplace'`, but **approve/reject IS available** — Trendyol is the one marketplace of the three where our approve button can be real. Capability matrix: `fetch = true`, `approve = true`, `reject = true` (as "create claim issue"/contest), `refund = false`, `label = false`.
- **Goods flow to the seller's warehouse**, so receive/inspect/restock are fully ours.
- Webhook: `WebhookController::handleTrendyol()` already matches `claim`-less strings; extend the condition to `stripos($event, 'claim') !== false` and dispatch `SyncReturnsJob`.

### 6.8 Platform status map (config)

`backend/config/returns.php`:

```php
'platform_status_map' => [
    'salla'   => ['returned' => ['returned','refunded','مسترجع','مرتجع'], 'rto' => ['delivery_failed','لم يتم التسليم']],
    'zid'     => ['returned' => ['returned','refunded'], 'rto' => []],
    'noon'    => ['returned' => ['returned'], 'rto' => ['rto','failed_delivery','undelivered']],
    'amazon'  => ['returned' => ['Returned'], 'rto' => ['Undeliverable']],
    'trendyol'=> ['returned' => ['Returned','İade'], 'rto' => ['UnDelivered','Teslim Edilemedi']],
    // shopify / woocommerce use their real return + refund objects
],
```

Every value here is a **guess until verified against a live account** and is deliberately data, not code, so a support engineer can fix it without a deploy.

---

## 7. Dashboard

Next.js 16 App Router, Tailwind v4, under `frontend/src/app/(dashboard)/returns/`.

### 7.1 Routes & screens

| Route | Screen | Notes |
| --- | --- | --- |
| `/returns` | Returns queue | Default view. Tabs: `All`, `Needs action` (status `requested`), `In transit`, `Awaiting inspection` (`received`/`inspecting`), `RTO`, `Closed`. Filter bar mirrors `/orders` (platform, status, store, date range, search) plus reason and "overdue". |
| `/returns/[id]` | RMA detail | Header (RMA number, status pill, SLA countdown, order link, platform badge, marketplace-managed lock icon). Sections: Items, Timeline (from `return_events`), Refund breakdown, Shipping/AWB, Notes. Action bar is state-driven. |
| `/returns/new` | Create RMA | Step 1 order search → Step 2 line + qty + reason → Step 3 resolution & policy preview. |
| `/returns/[id]/inspect` | Inspection view | Optimised for a warehouse tablet: big touch targets, one card per line, condition + disposition segmented controls, running restock/scrap tally. |
| `/orders/[id]` (existing) | + "Returns" panel | Shows related RMAs and a "Create return" button. Non-invasive addition. |
| `/analytics` (existing) | + "Returns" tab | Return rate over time, top return reasons (bar), top returned SKUs (table), RTO rate by platform and by city, refund value vs revenue. |
| `/settings/returns` | Policy settings | Return window, shipping refund policy, restocking fee, auto-restock toggles, reason-code manager (CRUD, EN + AR labels, drag sort), portal enable + branding, per-platform capability display (read-only matrix). |
| `/track` / portal | Customer return portal | Separate route group `frontend/src/app/(portal)/returns/` — no dashboard chrome, org-branded, RTL-first. Screens: lookup → select items + reason → confirmation with RMA number and (if issued) the return label. |

### 7.2 Components — `frontend/src/components/returns/`

`ReturnStatusBadge` (colour map: requested amber, approved blue, in_transit indigo, received violet, inspected teal, refunded green, rejected/cancelled slate, rto red, failed rose), `ReturnTable`, `ReturnFilters`, `ReturnTimeline`, `ReturnItemRow`, `DispositionSelect`, `ConditionSelect`, `RefundBreakdownCard`, `ReturnActionBar`, `CreateReturnWizard`, `ReasonCodeManager`, `RtoAlertBanner`, `MarketplaceManagedNotice`.

### 7.3 States (every list and detail view)

- **loading** — skeleton rows (match the existing orders page treatment).
- **empty (no returns ever)** — illustration + "Returns will appear here once a customer requests one, or create one manually."
- **empty (filters)** — "No returns match your filters." + clear-filters button.
- **error** — inline retry.
- **partial/degraded** — banner when a platform sync last failed: "Amazon returns last synced 6 h ago — some returns may be missing."
- **locked** — `MarketplaceManagedNotice` replaces the approve/reject buttons with a link to the marketplace console.
- **optimistic-then-reconciled** — approve/reject flips the badge immediately, reverts with a toast on API failure (same pattern as the existing cancel-order flow).

### 7.4 i18n — `frontend/src/i18n/dicts/returns.ts`

Registered in `frontend/src/i18n/dictionary.ts` as `returns: returns.en` / `returns: returns.ar`, exactly like `orders`.

```ts
export const returns = {
  en: {
    title: 'Returns',
    subtitle: 'Track return requests, restock inventory and issue refunds across all your stores.',
    connectDescription: 'Connect a store to start managing returns here.',
    searchPlaceholder: 'Search by RMA, order, customer or tracking number...',
    emptyState: 'No returns found matching your criteria.',
    emptyFirstTime: 'No returns yet. They will appear here automatically, or you can create one.',
    createReturn: 'Create return',
    exportCsv: 'Export CSV',
    tabs: { all: 'All', needsAction: 'Needs action', inTransit: 'In transit', awaitingInspection: 'Awaiting inspection', rto: 'RTO', closed: 'Closed' },
    filters: { platform: 'Platform:', status: 'Status:', reason: 'Reason:', type: 'Type:', store: 'Store:', overdue: 'Overdue only', all: 'All' },
    columns: { rma: 'RMA', order: 'Order', customer: 'Customer', platform: 'Platform', reason: 'Reason', items: 'Items', refund: 'Refund', status: 'Status', age: 'Age', actions: 'Actions' },
    status: {
      requested: 'Requested', approved: 'Approved', rejected: 'Rejected', cancelled: 'Cancelled',
      awaiting_shipment: 'Awaiting shipment', in_transit: 'In transit', received: 'Received',
      inspecting: 'Inspecting', inspected: 'Inspected', refund_pending: 'Refund pending',
      refunded: 'Refunded', exchange_pending: 'Exchange pending', exchanged: 'Exchanged',
      closed: 'Closed', failed: 'Failed',
    },
    type: { customer_return: 'Customer return', rto: 'Return to origin', damage_claim: 'Damage claim', exchange: 'Exchange' },
    resolution: { refund: 'Refund', exchange: 'Exchange', store_credit: 'Store credit', repair: 'Repair', reject: 'Reject', none: 'Not decided' },
    condition: { new: 'New', opened: 'Opened', used: 'Used', damaged: 'Damaged', defective: 'Defective', wrong_item: 'Wrong item', missing_parts: 'Missing parts', unknown: 'Not inspected' },
    disposition: { restock: 'Restock', scrap: 'Scrap', quarantine: 'Quarantine', return_to_vendor: 'Return to supplier', repair: 'Repair', pending: 'Pending' },
    actions: { approve: 'Approve', reject: 'Reject', cancel: 'Cancel return', createLabel: 'Create return label', markReceived: 'Mark received', inspect: 'Inspect', restock: 'Restock', refund: 'Issue refund', exchange: 'Create exchange', reopen: 'Reopen', viewOrder: 'View order', viewOnPlatform: 'View on platform', printLabel: 'Print label' },
    detail: {
      returnOf: 'Return of order', requestedOn: 'Requested on', notFound: 'Return not found', backToReturns: 'Back to returns',
      timeline: 'Timeline', items: 'Items', refundBreakdown: 'Refund breakdown', shipping: 'Return shipping', notes: 'Notes',
      slaDue: 'Approve by', overdue: 'Overdue', reasonLabel: 'Reason', customerNote: 'Customer note', inspectionNote: 'Inspection note',
    },
    refund: {
      itemsSubtotal: 'Items subtotal', tax: 'Tax', shippingRefund: 'Shipping refunded', restockingFee: 'Restocking fee',
      returnShipping: 'Return shipping deducted', total: 'Total refund', alreadyRefunded: 'Already refunded', outstanding: 'Outstanding',
      issuedBy: 'Issued by', issuerMerchant: 'You', issuerMarketplace: 'Marketplace', method: 'Method',
      degradedNotice: 'Tax is not itemised for this order, so the refund excludes tax allocation.',
    },
    rto: {
      title: 'Return to origin', banner: 'This COD order was never delivered and is coming back to you.',
      noRefund: 'No refund is due — payment was never collected.', costImpact: 'Cost impact',
      rate: 'RTO rate', byCity: 'RTO by city', topReasons: 'Top RTO reasons',
    },
    marketplace: {
      lockedTitle: 'Managed by {platform}',
      lockedBody: 'This return is authorised and refunded by {platform}. You can still receive, inspect and restock the goods here.',
      openConsole: 'Open {platform} console',
    },
    inspect: { title: 'Inspect return', lineOf: 'Item {index} of {total}', received: 'Received', restocked: 'Restock', scrapped: 'Scrap', finish: 'Finish inspection', restockSummary: '{restocked} restocked, {scrapped} scrapped' },
    analytics: { title: 'Returns analytics', returnRate: 'Return rate', rtoRate: 'RTO rate', unitsReturned: 'Units returned', restockRate: 'Restock rate', refundValue: 'Refund value', writeOffValue: 'Write-off value', byReason: 'By reason', bySku: 'By SKU', byPlatform: 'By channel', avgDaysToClose: 'Avg. days to close' },
    settings: { title: 'Returns policy', window: 'Return window (days)', shippingPolicy: 'Refund original shipping', restockingFee: 'Restocking fee', autoRestock: 'Restock automatically after inspection', portalEnabled: 'Enable customer return portal', portalUrl: 'Portal link', reasons: 'Reason codes', addReason: 'Add reason', capabilityMatrix: 'What each platform supports' },
    portal: {
      title: 'Request a return', lookupTitle: 'Find your order', orderRef: 'Order number', contact: 'Email or mobile number',
      find: 'Find order', notFound: "We couldn't find that order.", selectItems: 'Select the items you want to return',
      selectReason: 'Why are you returning this?', submit: 'Submit request', submitted: 'Your return request was received',
      rmaIs: 'Your return number is', nextSteps: 'What happens next', downloadLabel: 'Download return label',
      windowExpired: 'The return window for this order has closed.',
    },
    confirm: { approve: 'Approve this return?', reject: 'Reject this return? The customer will be notified.', refund: 'Issue a refund of {amount}?', restock: 'Restock {qty} unit(s) back into inventory?' },
    toast: {
      approved: 'Return approved.', rejected: 'Return rejected.', received: 'Return marked as received.',
      inspected: 'Inspection saved.', restocked: 'Inventory updated.', refundQueued: 'Refund queued.',
      labelCreated: 'Return label created.', failed: 'Something went wrong. Please try again.',
      skuNotMapped: 'This SKU is not linked to a Hubby product — stock was not adjusted.',
    },
  },
  ar: {
    title: 'المرتجعات',
    subtitle: 'تابع طلبات الإرجاع، وأعد المخزون، وأصدر المبالغ المستردة لجميع متاجرك.',
    connectDescription: 'اربط متجرًا لتبدأ إدارة المرتجعات من هنا.',
    searchPlaceholder: 'ابحث برقم الإرجاع أو الطلب أو العميل أو رقم التتبع...',
    emptyState: 'لا توجد مرتجعات مطابقة لبحثك.',
    emptyFirstTime: 'لا توجد مرتجعات بعد. ستظهر هنا تلقائيًا، أو يمكنك إنشاء واحد.',
    createReturn: 'إنشاء مرتجع',
    exportCsv: 'تصدير CSV',
    tabs: { all: 'الكل', needsAction: 'بحاجة إلى إجراء', inTransit: 'قيد الشحن', awaitingInspection: 'بانتظار الفحص', rto: 'مرتجع للمصدر', closed: 'مغلق' },
    filters: { platform: 'المنصة:', status: 'الحالة:', reason: 'السبب:', type: 'النوع:', store: 'المتجر:', overdue: 'المتأخرة فقط', all: 'الكل' },
    columns: { rma: 'رقم الإرجاع', order: 'الطلب', customer: 'العميل', platform: 'المنصة', reason: 'السبب', items: 'الأصناف', refund: 'المبلغ المسترد', status: 'الحالة', age: 'المدة', actions: 'الإجراءات' },
    status: {
      requested: 'مطلوب', approved: 'تمت الموافقة', rejected: 'مرفوض', cancelled: 'ملغي',
      awaiting_shipment: 'بانتظار الشحن', in_transit: 'قيد الشحن', received: 'تم الاستلام',
      inspecting: 'قيد الفحص', inspected: 'تم الفحص', refund_pending: 'الاسترداد قيد التنفيذ',
      refunded: 'تم الاسترداد', exchange_pending: 'الاستبدال قيد التنفيذ', exchanged: 'تم الاستبدال',
      closed: 'مغلق', failed: 'فشل',
    },
    type: { customer_return: 'إرجاع عميل', rto: 'مرتجع للمصدر', damage_claim: 'مطالبة تلف', exchange: 'استبدال' },
    resolution: { refund: 'استرداد', exchange: 'استبدال', store_credit: 'رصيد بالمتجر', repair: 'إصلاح', reject: 'رفض', none: 'لم يتحدد' },
    condition: { new: 'جديد', opened: 'مفتوح', used: 'مستعمل', damaged: 'تالف', defective: 'معيب', wrong_item: 'منتج خاطئ', missing_parts: 'أجزاء ناقصة', unknown: 'لم يُفحص' },
    disposition: { restock: 'إعادة للمخزون', scrap: 'إتلاف', quarantine: 'حجر', return_to_vendor: 'إرجاع للمورد', repair: 'إصلاح', pending: 'قيد الانتظار' },
    actions: { approve: 'موافقة', reject: 'رفض', cancel: 'إلغاء الإرجاع', createLabel: 'إنشاء بوليصة إرجاع', markReceived: 'تأكيد الاستلام', inspect: 'فحص', restock: 'إعادة للمخزون', refund: 'إصدار استرداد', exchange: 'إنشاء استبدال', reopen: 'إعادة فتح', viewOrder: 'عرض الطلب', viewOnPlatform: 'عرض في المنصة', printLabel: 'طباعة البوليصة' },
    detail: {
      returnOf: 'إرجاع للطلب', requestedOn: 'تاريخ الطلب', notFound: 'الإرجاع غير موجود', backToReturns: 'العودة إلى المرتجعات',
      timeline: 'السجل الزمني', items: 'الأصناف', refundBreakdown: 'تفاصيل الاسترداد', shipping: 'شحن الإرجاع', notes: 'ملاحظات',
      slaDue: 'الموافقة قبل', overdue: 'متأخر', reasonLabel: 'السبب', customerNote: 'ملاحظة العميل', inspectionNote: 'ملاحظة الفحص',
    },
    refund: {
      itemsSubtotal: 'إجمالي الأصناف', tax: 'الضريبة', shippingRefund: 'الشحن المسترد', restockingFee: 'رسوم إعادة التخزين',
      returnShipping: 'خصم شحن الإرجاع', total: 'إجمالي المسترد', alreadyRefunded: 'تم استرداده', outstanding: 'المتبقي',
      issuedBy: 'صادر من', issuerMerchant: 'أنت', issuerMarketplace: 'المنصة', method: 'الطريقة',
      degradedNotice: 'الضريبة غير مفصّلة لهذا الطلب، لذلك لا يشمل المبلغ توزيع الضريبة.',
    },
    rto: {
      title: 'مرتجع للمصدر', banner: 'هذا الطلب بالدفع عند الاستلام لم يُسلَّم وهو في طريقه إليك.',
      noRefund: 'لا يوجد مبلغ مستحق للاسترداد — لم يتم تحصيل أي مبلغ.', costImpact: 'الأثر على التكلفة',
      rate: 'نسبة المرتجع للمصدر', byCity: 'المرتجع حسب المدينة', topReasons: 'أبرز أسباب الإرجاع',
    },
    marketplace: {
      lockedTitle: 'تُدار بواسطة {platform}',
      lockedBody: 'يتم اعتماد هذا الإرجاع واسترداد مبلغه من {platform}. لا يزال بإمكانك استلام البضاعة وفحصها وإعادتها للمخزون هنا.',
      openConsole: 'فتح لوحة {platform}',
    },
    inspect: { title: 'فحص المرتجع', lineOf: 'الصنف {index} من {total}', received: 'المستلم', restocked: 'إعادة للمخزون', scrapped: 'إتلاف', finish: 'إنهاء الفحص', restockSummary: 'تمت إعادة {restocked} وإتلاف {scrapped}' },
    analytics: { title: 'تحليلات المرتجعات', returnRate: 'نسبة الإرجاع', rtoRate: 'نسبة المرتجع للمصدر', unitsReturned: 'الوحدات المرتجعة', restockRate: 'نسبة إعادة التخزين', refundValue: 'قيمة المبالغ المستردة', writeOffValue: 'قيمة الإتلاف', byReason: 'حسب السبب', bySku: 'حسب المنتج', byPlatform: 'حسب القناة', avgDaysToClose: 'متوسط أيام الإغلاق' },
    settings: { title: 'سياسة الإرجاع', window: 'مدة الإرجاع (أيام)', shippingPolicy: 'استرداد رسوم الشحن الأصلية', restockingFee: 'رسوم إعادة التخزين', autoRestock: 'إعادة للمخزون تلقائيًا بعد الفحص', portalEnabled: 'تفعيل بوابة الإرجاع للعملاء', portalUrl: 'رابط البوابة', reasons: 'رموز الأسباب', addReason: 'إضافة سبب', capabilityMatrix: 'ما تدعمه كل منصة' },
    portal: {
      title: 'طلب إرجاع', lookupTitle: 'ابحث عن طلبك', orderRef: 'رقم الطلب', contact: 'البريد الإلكتروني أو رقم الجوال',
      find: 'بحث', notFound: 'لم نتمكن من العثور على هذا الطلب.', selectItems: 'اختر الأصناف التي تريد إرجاعها',
      selectReason: 'ما سبب الإرجاع؟', submit: 'إرسال الطلب', submitted: 'تم استلام طلب الإرجاع',
      rmaIs: 'رقم الإرجاع الخاص بك', nextSteps: 'الخطوات التالية', downloadLabel: 'تحميل بوليصة الإرجاع',
      windowExpired: 'انتهت مدة الإرجاع لهذا الطلب.',
    },
    confirm: { approve: 'هل تريد الموافقة على هذا الإرجاع؟', reject: 'هل تريد رفض هذا الإرجاع؟ سيتم إشعار العميل.', refund: 'هل تريد استرداد مبلغ {amount}؟', restock: 'إعادة {qty} وحدة إلى المخزون؟' },
    toast: {
      approved: 'تمت الموافقة على الإرجاع.', rejected: 'تم رفض الإرجاع.', received: 'تم تسجيل استلام الإرجاع.',
      inspected: 'تم حفظ الفحص.', restocked: 'تم تحديث المخزون.', refundQueued: 'تم جدولة الاسترداد.',
      labelCreated: 'تم إنشاء بوليصة الإرجاع.', failed: 'حدث خطأ ما. حاول مرة أخرى.',
      skuNotMapped: 'هذا الرمز غير مرتبط بمنتج في Hubby — لم يتم تعديل المخزون.',
    },
  },
} as const;
```

Also add `nav.returns` to `common.ts` (`'Returns'` / `'المرتجعات'`).

**RTL notes:** status pills and the timeline must mirror; the SLA countdown and money values stay LTR-numeric inside an RTL paragraph (`dir="auto"` on the value span); the inspect screen's segmented controls reverse order under `dir="rtl"`.

---

## 8. Mobile

Flutter, `mobile/lib/features/returns/`. The mobile app is for the owner-on-the-go and the warehouse, not for full RMA administration.

**Surfaces:**

| Screen | File | Purpose |
| --- | --- | --- |
| Returns list | `returns_page.dart` | Filterable by status; default filter = "Needs action". Pull to refresh. |
| Return detail | `return_detail_page.dart` | Read the RMA, timeline, refund preview; **approve / reject** with a confirm sheet. |
| Receive & inspect | `return_inspect_page.dart` | Warehouse flow: scan or type the AWB/RMA → line list → per-line received qty + condition + disposition → submit. Barcode scan via the same scanner used elsewhere in the app (**ASSUMPTION**: a scanner package is already present; if not, this screen degrades to manual entry in v1). |
| RTO alerts | inside `notifications` | Push + in-app notification when an RTO is detected, deep-linking to the RMA. |
| Orders detail (existing) | `order_detail_page.dart` | Add a "Returns" section and a "Create return" action. |
| Dashboard (existing) | `dashboard` | Add two tiles: open returns count, RTO rate this month. |

**Not on mobile in v1:** refund issuance (money movement stays on desktop), reason-code management, portal settings, exchanges, analytics deep dives.

**Strings** — `mobile/lib/l10n/strings.dart`, flat dotted keys matching the existing map:

```dart
// en
'nav.returns': 'Returns',
'returns.title': 'Returns',
'returns.needsAction': 'Needs action',
'returns.empty': 'No returns yet.',
'returns.rma': 'RMA',
'returns.status.requested': 'Requested',
'returns.status.approved': 'Approved',
'returns.status.inTransit': 'In transit',
'returns.status.received': 'Received',
'returns.status.inspected': 'Inspected',
'returns.status.refunded': 'Refunded',
'returns.status.rto': 'Return to origin',
'returns.approve': 'Approve',
'returns.reject': 'Reject',
'returns.markReceived': 'Mark received',
'returns.inspect': 'Inspect',
'returns.scanAwb': 'Scan or enter AWB',
'returns.condition': 'Condition',
'returns.disposition': 'Disposition',
'returns.restock': 'Restock',
'returns.scrap': 'Scrap',
'returns.saved': 'Saved',
'returns.rtoAlert': 'A COD order is coming back to you',
'returns.noPermission': 'You do not have permission to do this',

// ar
'nav.returns': 'المرتجعات',
'returns.title': 'المرتجعات',
'returns.needsAction': 'بحاجة إلى إجراء',
'returns.empty': 'لا توجد مرتجعات بعد.',
'returns.rma': 'رقم الإرجاع',
'returns.status.requested': 'مطلوب',
'returns.status.approved': 'تمت الموافقة',
'returns.status.inTransit': 'قيد الشحن',
'returns.status.received': 'تم الاستلام',
'returns.status.inspected': 'تم الفحص',
'returns.status.refunded': 'تم الاسترداد',
'returns.status.rto': 'مرتجع للمصدر',
'returns.approve': 'موافقة',
'returns.reject': 'رفض',
'returns.markReceived': 'تأكيد الاستلام',
'returns.inspect': 'فحص',
'returns.scanAwb': 'امسح أو أدخل رقم البوليصة',
'returns.condition': 'الحالة',
'returns.disposition': 'الإجراء',
'returns.restock': 'إعادة للمخزون',
'returns.scrap': 'إتلاف',
'returns.saved': 'تم الحفظ',
'returns.rtoAlert': 'طلب بالدفع عند الاستلام في طريقه للعودة إليك',
'returns.noPermission': 'ليس لديك صلاحية لتنفيذ هذا الإجراء',
```

---

## 9. Permissions & multi-tenancy

**Tenancy.** Every query is scoped by `organization_id`. Two layers:

1. `org.member` middleware already validates that the authenticated user belongs to the `X-Organization-Id` org.
2. A global scope is *not* used (the repo doesn't use them); instead every controller starts from `ReturnRequest::forOrganization($orgId)` and every write path re-verifies ownership of nested resources (`order_id` → its store → org; `return_items.id` → its RMA → org). A `ReturnRequest` fetched by id alone is a bug — code review must catch it.
3. Cross-tenant fixture test is mandatory (§11).

**Roles.** Existing roles are `owner`, `admin`, `viewer` (`OrganizationController::ROLES`). The repo has no policy layer yet, so introduce `app/Policies/ReturnRequestPolicy.php` + `RefundPolicy.php` registered in `AppServiceProvider`, deriving the role exactly the way `OrganizationController::roleOf()` does (pivot lookup):

| Ability | owner | admin | viewer |
| --- | --- | --- | --- |
| `viewAny` / `view` | ✔ | ✔ | ✔ |
| `create` | ✔ | ✔ | ✖ |
| `approve` / `reject` / `cancel` | ✔ | ✔ | ✖ |
| `receive` / `inspect` / `restock` | ✔ | ✔ | ✖ |
| `refund` | ✔ | configurable (`returns.refund_min_role`, default `owner`) | ✖ |
| `exchange` | ✔ | ✔ | ✖ |
| `reopen` | ✔ | ✖ | ✖ |
| manage reason codes / policy settings | ✔ | ✔ | ✖ |

**Portal security.**
- `portal_token`: 32 random bytes, hex, stored plain (it *is* the credential) but only ever emailed/linked to the customer; never listed in any authenticated API response body except the RMA detail for org members.
- Lookup is a two-factor-ish proof: order reference **plus** a matching email or phone. Response is uniform on failure (no enumeration).
- Throttle: `throttle:10,1` globally on the prefix, plus a per-`order_reference` rate limiter (5 attempts / hour) to stop targeted brute force.
- Portal never exposes: full address, other orders, order-level costs, internal notes, `raw_data`.
- Portal is **off by default**; enabling it is an explicit per-org setting.

**Data protection.** `return_requests.customer_phone` and `pickup_address` are PII. They are excluded from `GET /returns` list responses (detail only), excluded from CSV export unless the actor is `owner`, and never logged. `return_events.payload` may contain platform PII — truncate to 8 KB and redact keys matching `/(phone|mobile|email|address|token|authorization)/i` before persisting.

---

## 10. Edge cases & failure modes

| # | Case | Handling |
| --- | --- | --- |
| 1 | Two RMAs opened for the same order line concurrently | `lockForUpdate()` on `order_items` inside the create transaction + `unique(return_request_id, order_item_id)`; second request fails `422 QUANTITY_EXCEEDS_ORDER_LINE`. |
| 2 | Customer returns more units than ordered | Same guard; the receive endpoint additionally caps `quantity_received ≤ quantity_approved` and surfaces "extra units received" as a note, not stock. |
| 3 | Return of an order that was already fully refunded | `RefundCalculator` clamps to `orders.total − orders.refunded_total`; if that is 0 the RMA is allowed but `resolution` is forced to `none`/`exchange`. |
| 4 | Refund succeeds on the platform but our request times out | `refunds.idempotency_key` + a reconcile step: `IssueRefundJob` first calls the platform's list-refunds endpoint filtered by order; if a refund matching our key/amount exists we mark `succeeded` instead of re-issuing. Never blind-retry a money call. |
| 5 | Platform refunds and restocks (Shopify `restock_type`, Woo `restock_items`) | Always send the no-restock variant. Guarded by a test that asserts the outgoing payload. |
| 6 | RMA restock job retried after partial success | Per-line idempotency via `return_items.inventory_log_id`; already-restocked lines are skipped and logged. |
| 7 | Returned SKU is not mapped to any Hubby product | Line forced to `quarantine`, no stock movement, `Notification(type: 'warning')`, dashboard shows an "unmapped SKU" chip with a "link product" action. |
| 8 | Product/variant deleted between order and return | FKs are `nullOnDelete`; the line keeps its `sku`/`name` snapshot; restock is blocked with `SKU_NOT_MAPPED`. |
| 9 | Store disconnected while an RMA is open | RMA remains fully usable locally; platform push jobs short-circuit and write a `return_events` entry "platform unavailable (store disconnected)". |
| 10 | Marketplace refunds a buyer we never approved (Amazon/Noon) | Mirror creates the RMA already in `refunded`, `is_marketplace_managed = true`, `refund_responsibility = 'marketplace'`. Status machine allows the mirror path to jump straight to a terminal state (mirror writes bypass `assert()` via `ReturnStateMachine::forceMirror()`, which still writes the event). |
| 11 | RTO detected for an order already marked delivered | `RtoDetector` trusts the latest carrier event by timestamp, not by arrival order; out-of-order webhooks are ignored if `event_time` is older than the shipment's current status time (see Spec 04 ordering rules). |
| 12 | RTO detected twice (carrier + platform) | `DetectRtoJob` is idempotent on `(order_id, type='rto')`; second detection appends a `return_events` row instead of creating an RMA. |
| 13 | COD order that was actually delivered and paid, then returned | Normal `customer_return` with a real refund; the `cod_not_collected` method is only used when `type = 'rto'`. |
| 14 | Return parcel lost in transit | Carrier `lost` → status `failed`, `Notification(type: 'error')`, and a "claim with carrier" prompt. No restock, no refund unless a human overrides via `reopen`. |
| 15 | Customer ships back a different item | Inspection sets `condition = 'wrong_item'`, `disposition = 'quarantine'`; `RefundCalculator` zeroes that line and the merchant is prompted to reduce the refund. |
| 16 | Partial receipt (2 of 3 units arrive) | `quantity_received = 2`; refund is computed on received quantity; the RMA stays `received` with a "short receipt" flag until the merchant closes it. |
| 17 | Currency mismatch between order and org reporting currency | Refund is always in `orders.currency`; analytics sums are grouped by currency in v1 (no FX). The UI shows per-currency subtotals rather than a wrong single total. |
| 18 | Negative/zero refund after fees exceed value | `total_refund` is clamped at 0; the excess is shown as "fees exceed refund" and posted as an `order_fees` row, never as a negative refund. |
| 19 | Reason code deleted while RMAs reference it | Reason codes are soft-deactivated (`is_active = false`), never hard-deleted when referenced; `DELETE /returns/reasons/{id}` deactivates instead and returns `200` with `{"deactivated": true}`. |
| 20 | Webhook replay / duplicate platform return payload | `unique(store_id, external_id)` + `updateOrCreate` in `PlatformReturnMirror`; `webhook_logs` keeps the raw payload for forensics. |
| 21 | Very large RMA (100+ lines from a B2B order) | Inspect endpoint accepts batched line updates; the mobile inspect screen paginates; `return_items` writes are chunked at 100 per transaction. |
| 22 | Clock skew on `sla_due_at` | All timestamps UTC; SLA computed server-side from `approved_at`/`requested_at`, never from a client-supplied date. |
| 23 | Org deleted | `cascadeOnDelete` on `organization_id` removes RMAs, items, events, refunds. Verify with a test — cascading through `stores` and `orders` too means the delete must not deadlock; run it in a chunked job for large orgs. |

**Failure-mode budgets:** every platform call from a job uses a 15 s connect / 30 s total timeout, `tries = 3` with `backoff = [60, 300, 900]`, and never blocks the HTTP request that triggered it (all pushes are queued). A platform being down degrades Hubby to "local RMA only" — it never blocks receiving or restocking, because the goods are physically present regardless.

---

## 11. Testing

`backend/tests/Feature/` follows the existing style: `RefreshDatabase`, a `setUp()` that builds user + org + store + Sanctum token, and requests sent with `Authorization: Bearer` + `X-Organization-Id` headers (see `OrderTest.php`).

### Feature tests — `ReturnTest.php`
1. `test_user_can_list_returns` — paginated envelope, only own org's rows.
2. `test_returns_are_scoped_to_organization` — an RMA in org B is invisible and `GET /returns/{id}` returns 404 for org A. **Cross-tenant test is non-negotiable.**
3. `test_create_return_from_order` — 201, RMA number format, `requested` status, one `return_events` row.
4. `test_create_return_rejects_quantity_over_order_line` — 422 `QUANTITY_EXCEEDS_ORDER_LINE`.
5. `test_create_return_outside_window_is_rejected_for_viewer_but_allowed_for_owner`.
6. `test_partial_return_leaves_order_return_status_partial`.
7. `test_second_return_on_same_line_uses_remaining_quantity`.
8. `test_viewer_cannot_approve_return` — 403.
9. `test_approve_creates_event_and_sets_approved_at`.
10. `test_marketplace_managed_return_cannot_be_approved` — 409 `MARKETPLACE_MANAGED`.

### `ReturnStateMachineTest.php` (Unit)
11. Table-driven: every legal transition returns true, a representative set of illegal ones throws. Assert the terminal set can only be left via `reopen`.

### `ReturnRestockTest.php`
12. `test_restock_increments_variant_stock_and_writes_inventory_log` — asserts `product_variants.stock` +2 and exactly one `inventory_logs` row with `source = 'return_restock'`.
13. `test_scrap_writes_two_inventory_logs_with_zero_net` — `+2` then `-2`, net stock unchanged, both rows present.
14. `test_restock_is_idempotent` — running the job twice produces one log and one increment.
15. `test_unmapped_sku_quarantines_and_does_not_touch_stock` — plus a `Notification` row.
16. `test_restock_dispatches_push_inventory_job` — `Bus::fake()`.

### `RefundCalculatorTest.php` (Unit)
17. Full return, no fees → `total_refund == order total`.
18. Partial return with percentage restocking fee → exact expected number.
19. Line rounding residual lands on the largest line and header total equals the sum of lines.
20. `on_defect` shipping policy refunds shipping only when every reason `is_defect`.
21. Refund clamped by `orders.total − refunded_total` → `REFUND_EXCEEDS_ORDER`.
22. Degraded allocation when tax/discount are null.

### `RefundIssueTest.php`
23. `test_refund_is_queued_and_idempotent` — two identical `POST /returns/{id}/refund` calls produce one `refunds` row (409 on the second).
24. `test_refund_job_marks_succeeded_on_platform_200` — `Http::fake()`.
25. `test_refund_job_retries_and_fails_after_three_attempts` and leaves the RMA in `refund_pending` → `failed`.
26. `test_shopify_refund_payload_sends_no_restock` — asserts the outgoing body's `restock_type`.
27. `test_woocommerce_refund_payload_respects_api_refund_setting`.
28. `test_marketplace_refund_is_recorded_not_issued` — no outbound HTTP call, `issuer = 'marketplace'`.

### `RtoTest.php`
29. `test_rto_is_created_from_carrier_returned_to_origin_status` with the right `reason_code`.
30. `test_rto_creates_zero_refund_with_cod_not_collected_method`.
31. `test_rto_detection_is_idempotent`.
32. `test_rto_sets_order_return_status_rto`.
33. `test_rto_posts_shipping_cost_to_order_fees` (skipped when the profit flag is off).

### `ReturnPortalTest.php`
34. `test_lookup_requires_matching_contact` — wrong email → generic 404.
35. `test_lookup_is_rate_limited` — 11th request in a minute → 429.
36. `test_portal_can_create_return_and_returns_token`.
37. `test_portal_token_cannot_read_another_return`.
38. `test_portal_is_disabled_by_default` — 404 when the org flag is off.

### `PlatformReturnMirrorTest.php`
39. One test per platform with a **captured fixture payload** in `tests/Fixtures/returns/{platform}.json`: asserts the mapped RMA fields. Where the payload shape is unverified (Salla, Zid, Noon, Trendyol), the fixture is marked `@group unverified-fixture` and the test asserts the *mapper contract*, not real-world truth — so it fails loudly when we replace the fixture with a real capture.
40. `test_duplicate_platform_payload_updates_not_duplicates` — `unique(store_id, external_id)`.

### `ReturnAnalyticsTest.php`
41. Return rate math with a known dataset (orders, returns, RTOs) — exact expected floats.
42. Grouping by reason / SKU / platform respects the date window and org scope.

### Frontend
43. Component test: `ReturnStatusBadge` renders every status in EN and AR.
44. i18n parity test (extend any existing dictionary test): every key present in `returns.en` exists in `returns.ar` and vice versa. If no such test exists, add one — it's ~15 lines and catches the most common bilingual regression.
45. Playwright/RTL smoke: `/returns` renders under `dir="rtl"` without horizontal overflow.

### Mobile
46. Widget test: returns list renders empty/loading/error states.
47. Widget test: approve button hidden for `viewer` role.

---

## 12. Rollout

### 12.1 Migration plan (expand → backfill → contract)

**Phase 1 — expand (no behaviour change).**
- Ship migrations `...000001` … `...000007`. All new tables; the two `ALTER TABLE`s are additive-only with `Schema::hasColumn` guards, matching `2026_05_06_090717_fix_orders_table_columns.php`.
- Deploy the `order_items` fillable fix + `SyncOrdersJob` key rename in the **same release** as `...000006`, because that migration is what makes the existing (broken) writes work.
- Seed `return_reasons` globals via `ReturnReasonSeeder` (idempotent `updateOrCreate` on `code` where `organization_id IS NULL`).

**Phase 2 — backfill.**
- `php artisan hubby:backfill-order-item-external-ids` — re-reads `orders.raw_data` and populates `order_items.external_id`, `product_id`, `product_variant_id`, `tax_amount`, `discount_amount`. Chunked at 500 orders, resumable via a cursor, safe to re-run. Without this, historical orders can't be returned line-accurately.
- `php artisan hubby:rebuild-order-return-summary` — no-op initially (there are no returns), but ship it now so it exists for reconciliation later.
- Reconciliation check: count of `order_items` with a non-null `external_id` vs total, reported per platform. Target ≥ 95 % for Shopify/Salla/Trendyol (their payloads carry line ids); Amazon/Noon may be lower and that's expected — record the actual number, don't assume.

**Phase 3 — enable.** Feature flags on, org by org.

**Phase 4 — contract.** After 30 days: drop the temporary `hubby:backfill-*` commands, and add the `return_shipment_id` FK constraint to `shipments` once Spec 04 is live (`2026_08_xx_add_shipment_fk_to_return_requests_table.php`).

**Rollback:** all four migrations have working `down()`. The RMA tables are additive so a rollback loses returns data only — no existing feature depends on them. The `order_items` additions must **not** be rolled back once backfilled (dropping `external_id` re-breaks order sync); document this as a one-way door in the migration docblock.

### 12.2 Feature flags

Flags live where the existing plan features live (`plans.features` JSON, per `2026_05_06_082834_add_features_to_plans_table.php`) plus a per-org override:

| Flag | Default | Gates |
| --- | --- | --- |
| `returns` | off | The whole feature: nav item, routes, jobs. `CheckSubscription`-style middleware guard on the route group. |
| `returns.portal` | off | Public portal routes + settings section. |
| `returns.auto_restock` | on (when `returns` on) | Auto-restock after inspection. |
| `returns.rto_detection` | off until Spec 04 ships | `DetectRtoJob` registration. |
| `returns.platform_push` | off | `PushReturnDecisionJob` + `IssueRefundJob` outbound calls. **Ship with this off**: run in mirror-only mode for 2 weeks so we can compare Hubby's computed refunds against what merchants actually issued manually, before we let Hubby move money. |

Rollout order: internal org → 3 design-partner orgs (1 Salla, 1 Shopify, 1 Noon/Amazon seller) → 10 % → all. Watch: refund amount mismatch rate vs platform-calculated refunds, restock double-count incidents, RTO false-positive rate.

### 12.3 Sandbox & credentials

- No new secrets for returns itself; it reuses the existing per-store `integrations.access_token`.
- **Shopify requires re-consent** for `read_returns`/`write_returns` (scope names unverified). Build the re-consent flow first: a banner on `/stores` → `GET /oauth/shopify/redirect` with the new scope set → the existing `OAuthController::callback`. Until a store re-consents, its returns capability is reported as `false` and the UI says "Reconnect this store to enable returns".
- Sandbox coverage by platform: Shopify dev store (free, full returns + refunds), WooCommerce local site (free), Salla partner sandbox (available, **verify returns endpoints there first**), Zid partner account (needs a request), Amazon SP-API sandbox (available; **returns reports are typically not meaningfully populated in sandbox** — plan for a real seller account under NDA), Noon (**no public sandbox; blocked on a partner account** — this is a hard dependency, flag it early), Trendyol (has a test/stage environment; needs supplier credentials).
- Store fixture payloads captured from each sandbox under `backend/tests/Fixtures/returns/`. **A platform's `SupportsReturnsInterface` implementation does not merge without a real captured fixture** — this is the rule that keeps unverified guesses out of production.
- `.env` additions: none required for v1 beyond existing per-platform keys. `RETURNS_PORTAL_URL` (public base for portal links) and `RETURNS_DEFAULT_WINDOW_DAYS` go in `config/returns.php`.

---

## 13. Acceptance criteria

- [ ] `return_requests`, `return_items`, `return_reasons`, `return_events`, `refunds` exist with every column, index and FK in §3, and `migrate:fresh` plus an upgrade from the current production schema both succeed.
- [ ] `order_items` has `external_id`, `product_id`, `product_variant_id`, `tax_amount`, `discount_amount`, `returned_quantity`; `SyncOrdersJob` writes them without silent drops.
- [ ] 23 global reason codes are seeded with EN **and** AR labels; an org can add, edit, reorder and deactivate its own.
- [ ] An RMA can be created from the dashboard, from the mobile app, from the customer portal, and by platform mirroring — with `origin` set correctly in each case.
- [ ] Partial returns work: 1 of 3 units returns, the order shows `return_status = 'partial'`, and a second RMA can return 1 more but not 3 more.
- [ ] Every state transition in §4.1 is enforced; an illegal transition returns `422 INVALID_RETURN_TRANSITION` and changes nothing.
- [ ] Every transition writes exactly one `return_events` row, in the same transaction.
- [ ] Restock increments `product_variants.stock` (or `products.stock`) and writes an `inventory_logs` row with `source = 'return_restock'`; scrap writes the `+qty`/`−qty` pair with net zero.
- [ ] Restock is idempotent under job retry (verified by test).
- [ ] After restock, `PushInventoryJob` is dispatched for the affected product.
- [ ] `RefundCalculator` output matches the §4.4 formula to the cent, including rounding residual placement, and never exceeds `orders.total − refunded_total`.
- [ ] Refunds are idempotent: a duplicate issue attempt returns `409` and creates no second refund.
- [ ] Shopify and WooCommerce refund payloads assert no platform-side restocking.
- [ ] Marketplace-managed RMAs (Amazon, Noon, Trendyol) are read-only for approve/reject/refund but fully usable for receive/inspect/restock.
- [ ] An RTO is auto-created from a carrier `returned_to_origin` status, has `refund_responsibility = 'none'`, a `cod_not_collected` zero-value refund record, sets `orders.return_status = 'rto'`, and posts shipping costs to `order_fees`.
- [ ] RTO detection is idempotent across carrier + platform double-signals.
- [ ] A return label can be created via Spec 04 and appears on the RMA with a printable URL.
- [ ] `/returns`, `/returns/[id]`, `/returns/new`, `/returns/[id]/inspect`, `/settings/returns` render in EN and AR, with correct RTL layout and no missing keys.
- [ ] Mobile shows the returns list, detail, approve/reject, and the receive-and-inspect flow; refunds are not exposed on mobile.
- [ ] `viewer` role cannot mutate any return; `admin` cannot refund when `returns.refund_min_role = owner`.
- [ ] Cross-tenant access returns 404, verified by an automated test.
- [ ] The customer portal is off by default; when on, lookup is rate-limited, failure responses are uniform, and no PII beyond the requester's own order is exposed.
- [ ] Returns analytics returns correct return rate, RTO rate, restock rate, refund value and write-off value for a known fixture dataset.
- [ ] Every unverified platform assumption in §6 is either confirmed against a live sandbox and the doc updated, or the platform ships in mirror-only mode with the capability matrix reporting `false`.
- [ ] All tests in §11 pass; no test is skipped without an inline reason.

---

## 14. Effort estimate + dependencies

Estimates are for one senior full-stack engineer, in ideal engineering days.

| Workstream | Days |
| --- | --- |
| Migrations, models, seeder, `order_items` fix + backfill command | 3 |
| State machine, `ReturnService`, events, observers, policies | 4 |
| `RefundCalculator` + `RefundService` + `IssueRefundJob` (incl. idempotency/reconcile) | 3 |
| `RestockService` + inventory ledger + `PushInventoryJob` wiring | 2 |
| RTO detection + cost posting | 2 |
| API layer: 25 endpoints, form requests, resources, error codes | 4 |
| Shopify two-way (GraphQL client + returns + refunds + webhooks + re-consent flow) | 5 |
| WooCommerce refunds | 1.5 |
| Salla + Zid status-mirror path | 2 |
| Amazon mirror (Reports API + financial events) | 4 |
| Trendyol claims mirror + approve | 3 |
| Noon mirror (status map only; blocked on account) | 1.5 |
| Dashboard: queue, detail, wizard, inspect, settings, analytics tab | 8 |
| i18n EN/AR + RTL polish | 1.5 |
| Customer portal (backend + `(portal)` route group) | 3 |
| Mobile: list, detail, approve/reject, inspect | 4 |
| Tests (47 listed) | 5 |
| Rollout, flags, docs, sandbox capture | 2 |
| **Total** | **≈ 58.5 days** |

Phased delivery:
- **M1 — Local RMA (≈ 20 d):** tables, state machine, restock, dashboard queue + detail + inspect, no platform push. Already valuable: unified returns + correct inventory.
- **M2 — Money (≈ 12 d):** refunds, Shopify + Woo push, profit posting.
- **M3 — RTO (≈ 8 d):** depends on Spec 04 tracking.
- **M4 — Marketplaces (≈ 12 d):** Amazon, Trendyol, Noon, Salla, Zid mirrors.
- **M5 — Portal + mobile (≈ 7 d).**

**Hard dependencies:**
1. **Spec 04 (Shipping & Labels)** — required for return labels (M1 optional) and for RTO detection (M3 blocking). RTO cannot ship without normalized carrier tracking.
2. **Profit & Cost Engine spec** — `order_fees` (write target) and `product_costs` (read source for write-off value). Returns degrades gracefully without it (cost columns show as "—").
3. **`order_items.external_id` migration + backfill** — blocking for accurate line mapping on every platform.
4. **Shopify scope re-consent** — blocking for Shopify two-way; needs a product decision about how aggressively to prompt.
5. **Noon partner account** — blocking for anything beyond a guessed status map. Start the request now; it has the longest lead time.
6. **Trendyol supplier test credentials** — blocking for claims verification.
7. **A GraphQL client for Shopify** — small, but new infrastructure in this codebase.
8. **`PushInventoryJob` correctness** — returns is the first feature that makes inventory push mandatory; whatever is incomplete there surfaces here.

---

## 15. Open questions

1. **Does `organizations` have a JSON settings column?** All the policy settings (return window, restocking fee, portal toggle) assume one. If not, we add `returns_settings json nullable` — needs a decision on whether to introduce a general `organization_settings` table instead, since Spec 04 will want the same thing.
2. **Warehouse locations.** Returned stock going back to a single implicit location is fine for one-warehouse merchants and wrong for anyone with two. Do we accept that for v1, or is a minimal `locations` table a prerequisite? (It affects the `inventory_logs` shape, so deciding late is expensive.)
3. **Who is "warehouse staff"?** Today the only roles are owner/admin/viewer. Receiving and inspecting is a natural fourth role (`operator`: can receive/inspect/restock, cannot refund or see money). Do we add it now or overload `admin`?
4. **Store credit.** We record `store_credit` as a refund method but track no balance. Is a credit ledger in scope for the next quarter, or do we drop the method until it is real?
5. **Shopify: REST refunds + GraphQL returns, or migrate wholesale to GraphQL?** REST Admin API is on a deprecation trajectory. Doing returns is the natural moment to introduce a GraphQL client — but a half-REST/half-GraphQL `ShopifyService` is a maintenance smell. Decide before M2.
6. **Amazon FBA returns.** FBA returned goods go to Amazon's warehouse, not ours, so "restock" means Amazon's inventory, not ours. Do we mirror FBA returns at all in v1, or restrict Amazon returns to MFN and label FBA as "visible in analytics only"?
7. **RTO reason attribution.** Carriers report a failure code; merchants want to know *whose fault* it was (bad address vs customer refusal vs carrier failure) because it drives whether they blacklist a customer or switch carriers. Do we need a `fault_party` column (`customer`/`carrier`/`merchant`) in v1, or is the reason code enough?
8. **Serial-returner detection.** We will have the data (`customer_email`/`phone` × return count). Do we surface a simple "this customer has returned 7 of 9 orders" chip in v1, given COD abuse is a real MENA problem? It's cheap, but it's also a policy/ethics decision the product owner should make, not the backend.
9. **Refund notification to the customer.** `notify_customer` is in the API contract but there is no transactional email/SMS infrastructure described in the repo for customer-facing (as opposed to user-facing) messages. Which channel, and who owns the templates in AR?
10. **Return window per product category.** A 3-day window on perishables and 14 days on electronics is normal in the region. Org-wide only in v1 — is that acceptable to the design partners?
11. **Do we ever accept a return without an order?** ("Walk-in with a receipt.") Currently `order_id` is `NOT NULL`. Making it nullable later is a migration; deciding now is free.
