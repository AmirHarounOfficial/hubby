# Spec 02 — Automation / Rules Engine

**Status:** Draft, implementation-ready
**Owner:** Backend / Platform
**Target release:** v1.next
**Repo base:** `D:\work\HubbyGlobal`
**Related:** `docs/COMPETITIVE_STRATEGY.md` §1.1, §5 item 1, §7 item 1

---

## 0. Assumptions & verified facts

Everything below was read from the repo before writing. Anything marked **ASSUMPTION** is *not* verified in code and must be confirmed before/while building.

### Verified (read from source)

| Fact | Source |
| --- | --- |
| `orders` = `id, store_id (FK cascade), external_id, status, total decimal(15,2), currency char(3) def 'USD', customer_name, customer_email, raw_data json, timestamps`, `unique(store_id, external_id)` | `database/migrations/2026_05_05_202920_create_orders_table.php` |
| `orders` has **no** `organization_id`, no country/city, no payment method, no weight, no tags, no carrier, no hold state | same |
| `order_items` = `id, order_id, sku, quantity, price, name, timestamps` | `2026_05_05_202921_create_order_items_table.php` |
| Org membership roles live on the `organization_user` pivot: `owner`, `admin`, `viewer` | `2026_05_05_202907_...`, `OrganizationController::ROLES` |
| Tenancy is enforced by `X-Organization-Id` header + `org.member` middleware alias | `bootstrap/app.php`, `app/Http/Middleware/EnsureOrganizationMember.php` |
| Controllers scope orders with `Order::whereHas('store', fn($q) => $q->where('organization_id', $orgId))` | `app/Http/Controllers/OrderController.php` |
| Jobs use `Dispatchable, InteractsWithQueue, Queueable, SerializesModels` + `implements ShouldQueue` | `app/Jobs/SyncOrdersJob.php` |
| Webhooks: `POST /webhooks/{platform}` → `VerifyWebhookSignature` (HMAC, skipped when no secret configured) → `WebhookController::handle` | `routes/api.php:111`, `app/Http/Middleware/VerifyWebhookSignature.php` |
| Webhook handlers only re-dispatch `SyncOrdersJob` / `SyncInventoryJob`; no per-order processing | `WebhookController.php:52-117` |
| `SyncOrdersJob::mapOrderData()` only has real mappings for `shopify`, `salla`, `trendyol`; all others fall through to `return $data;` | `SyncOrdersJob.php:111-168` |
| In-app notifications = `Notification::create(['organization_id','title','message','type'])` | `SyncOrdersJob.php:87`, `app/Models/Notification.php` |
| Stack: Laravel 12, PHP ^8.2 (target 8.3), Horizon 5, Sanctum 4, predis 3, spatie/laravel-permission 7, PHPUnit 11 | `backend/composer.json` |
| Scheduler exists in `routes/console.php` (`Schedule::job(...)`) | `routes/console.php` |
| Frontend i18n: one file per feature in `src/i18n/dicts/*.ts` exporting `{ en, ar }`, registered in `src/i18n/dictionary.ts`, consumed via `useT()` dot-paths | `src/i18n/*` |
| Sidebar nav is a static array of `{ icon, key, href }` where `key` resolves to `common.nav.<key>` | `src/components/layout/Sidebar.tsx:26-34` |
| API client injects `Authorization` + `X-Organization-Id` automatically | `src/lib/api.ts` |
| Mobile is Flutter with `lib/features/<feature>/` folders (has `notifications`, `orders`, `more`) | `mobile/lib/features` |

### Pre-existing defects this spec depends on (must be fixed first — they are blockers, not part of this feature)

1. **`WebhookController` dispatches `SyncOrdersJob::dispatch($externalId, $platform)`** (`WebhookController.php:55,65,72,89,99,114`) but the constructor is `__construct(Store $store = null)`. Every webhook-driven sync is currently broken/no-op. Rules that must fire "on webhook" cannot work until this is corrected to resolve a `Store` (and ideally a targeted single-order fetch).
2. **`SyncOrdersJob` writes `OrderItem` columns that don't exist**: `external_id` and `product_name` (`SyncOrdersJob.php:70-81`) — the table has `sku, quantity, price, name`. Item-level conditions (SKU/category) depend on `order_items` being correct.
3. **`Order::updateOrCreate(['external_id' => ...])`** matches on `external_id` only, ignoring `store_id`, while the unique index is `(store_id, external_id)`. Two stores with the same external order id will collide/overwrite. Rule idempotency keys off `order.id`, so this must be `['store_id' => ..., 'external_id' => ...]`.

Ticket these as **AUT-0 prerequisites**.

### ASSUMPTIONS (verify before building)

- **A1** — Destination country/city, payment method, weight, and shipping speed are only obtainable from `orders.raw_data`. The per-platform extraction paths in §6 are written from platform API knowledge, **not** from captured payloads in this repo. Capture one real payload per platform and unit-test each resolver.
- **A2** — There is no `locations` / `warehouses` table. `route_location` therefore writes a **string code** to `orders.fulfillment_location`; when a real Locations feature lands, add an FK and backfill.
- **A3** — There is no `carriers` table and no shipping-label integration. `assign_carrier` writes `carrier_code` + `shipping_service` strings and validates against a config allowlist (`config/automation.php`), not a DB table.
- **A4** — There is no WhatsApp provider wired (`app/Mail` contains only `VerifyEmailMail`). The `notify` action ships with `in_app` (works today) and `email` (works today, Laravel Mailer); `whatsapp` is implemented behind a `NotificationChannelInterface` and disabled by config until a provider (Meta Cloud API / Twilio) is chosen.
- **A5** — `products`/`product_variants` have a `stock` integer but **no** low-stock threshold column. `stock.below_threshold` requires adding one (migration 006 below).
- **A6** — "Customer type (new vs repeat)" is derived from `orders.customer_email` count within the org. There is no `customers` table (`CustomerController` derives customers from orders).

---

## 1. Why this exists (competitive rationale)

Linnworks' rules engine is the single feature customers name when asked why they cannot leave. It is **unlimited and included in every plan** — the one genuinely generous thing about their pricing. `docs/COMPETITIVE_STRATEGY.md` scores us `❌` against their `✅ ungated` on this row, and lists it as **gap #1** on the build order.

Three concrete reasons it matters for Hubby specifically:

1. **It converts multi-channel from a viewer into an operator.** Today Hubby *shows* you orders from 7 channels. Linnworks *acts* on them. A merchant with 400 orders/day across Salla + Amazon.sa + Noon does not want a nicer table; they want COD orders over 1,500 SAR auto-held for a confirmation call, and Riyadh-destined orders auto-routed to the Riyadh DC.
2. **COD is the MENA wedge Linnworks structurally cannot serve.** COD is 40–60% of GCC e-commerce volume and drives a distinct operational playbook (confirmation calls, risk holds, cash reconciliation, higher RTO). Linnworks' condition set has no notion of payment method as a first-class MENA concern; ours does, with `order.is_cod` as a top-level field and COD templates shipped in the rule library. This is a feature they'd have to want the region to build.
3. **Retention economics.** A merchant with 25 live rules encoding their fulfilment policy does not churn. Rules are the highest-stickiness artifact we can let a customer create, and they cost us nothing per-tenant to store.

**Pricing commitment (non-negotiable):** ungated in every plan, including free/trial. Limits exist only for platform safety (§12), never for monetisation. `plans.features` gets `"automation_rules": true` on **every** plan row.

---

## 2. Scope — in / out

### In scope

- `automation_rules` + `automation_runs` + `automation_rule_applications` tables and models.
- Triggers: `order.created`, `order.updated`, `order.status_changed`, `stock.below_threshold`, `sync.failed`.
- Composable AND/OR condition groups (nesting depth ≤ 3) over a typed field catalogue with a fixed operator set.
- Actions: `add_tag`, `remove_tag`, `route_location`, `assign_carrier`, `split_order`, `hold_order`, `release_hold`, `set_status`, `assign_folder`, `notify`, `call_webhook`, `add_note`, `stop_processing`.
- Evaluation on order ingest (sync job) **and** on webhook, with priority ordering, multi-rule chaining, idempotency, loop prevention, dry-run mode, and full audit trail.
- Rule tester: simulate a saved or unsaved rule against the last *N* orders, with per-order match explanation and would-be actions.
- Dashboard: rule list, visual condition/action builder, run history, simulation view. Full `en` + `ar` (RTL).
- REST API under `/automation/*` following existing route/controller conventions.
- Manual re-evaluation of selected orders + a rate-limited historical backfill.

### Out of scope (this spec)

- `return.requested` and `payment.captured` triggers — **deliberately deferred**: neither a returns/RMA model nor a payments-webhook ingestion path exists in the repo (`COMPETITIVE_STRATEGY.md` lists returns as "don't have"). The trigger string enum and `AutomationTrigger` enum reserve both values so no migration is needed later; the registry simply does not expose them. Ship them with the Returns and Payments specs.
- Warehouse/location master data, bins, picking (that's the WMS spec).
- Carrier/label purchase, rate shopping, tracking ingestion.
- AI rule recommendation ("Spotlight AI" equivalent) — a later layer that reads `automation_runs`.
- Rule sharing/marketplace across orgs.
- Visual flow-chart / branching canvas UI. v1 is a linear condition-group + ordered-action-list builder, which covers 100% of Linnworks' documented capability.
- Scheduled/time-based triggers ("every day at 9am"). Reserved as `schedule.tick` but not built.

---

## 3. Data model

Migration file naming follows the repo convention (`YYYY_MM_DD_NNNNNN_verb_noun.php`, e.g. `2026_07_02_000004_add_trendyol_to_stores_platform.php`).

### 3.1 `automation_rules`

**File:** `database/migrations/2026_07_22_000001_create_automation_rules_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | PK |
| `organization_id` | `foreignId` | no | — | FK → `organizations.id`, `onDelete('cascade')` |
| `name` | `string(120)` | no | — | |
| `description` | `text` | yes | `null` | |
| `trigger` | `string(60)` | no | — | `order.created` \| `order.updated` \| `order.status_changed` \| `stock.below_threshold` \| `sync.failed` (string, not DB enum — see §12.1) |
| `conditions` | `json` | no | `'{"match":"all","rules":[]}'` | §3.5. Empty `rules` = "always match" |
| `actions` | `json` | no | `'[]'` | §3.6 |
| `priority` | `unsignedSmallInteger` | no | `100` | **lower runs first**; ties broken by `id` asc |
| `enabled` | `boolean` | no | `false` | **safe default: new rules are off** (§12.3) |
| `run_mode` | `string(10)` | no | `'dry_run'` | `live` \| `dry_run`. Dry-run evaluates + records runs but applies nothing |
| `stop_processing` | `boolean` | no | `false` | when this rule matches, no lower-priority rule runs for the subject |
| `version` | `unsignedInteger` | no | `1` | incremented on every `conditions`/`actions` change; part of the idempotency fingerprint |
| `last_run_at` | `timestamp` | yes | `null` | |
| `matched_count` | `unsignedBigInteger` | no | `0` | denormalised counter for the list UI |
| `applied_count` | `unsignedBigInteger` | no | `0` | |
| `failed_count` | `unsignedBigInteger` | no | `0` | |
| `created_by` | `foreignId` | yes | `null` | FK → `users.id`, `nullOnDelete()` |
| `updated_by` | `foreignId` | yes | `null` | FK → `users.id`, `nullOnDelete()` |
| `created_at` / `updated_at` | `timestamps` | yes | — | |
| `deleted_at` | `softDeletes` | yes | `null` | preserves audit integrity of `automation_runs` |

**Indexes**

```php
$table->index(['organization_id', 'trigger', 'enabled', 'priority'], 'automation_rules_dispatch_idx');
$table->index(['organization_id', 'enabled']);
$table->index(['organization_id', 'deleted_at']);
```

`automation_rules_dispatch_idx` is the one that matters — it is the exact shape of the hot query in `RuleRepository::forTrigger()`.

### 3.2 `automation_runs`

**File:** `database/migrations/2026_07_22_000002_create_automation_runs_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` | no | — | FK → `organizations.id`, cascade |
| `automation_rule_id` | `foreignId` | yes | `null` | FK → `automation_rules.id`, `nullOnDelete()` — audit survives hard delete |
| `rule_name` | `string(120)` | no | — | denormalised snapshot; the rule may be renamed or deleted |
| `rule_version` | `unsignedInteger` | no | `1` | which version of the rule produced this run |
| `trigger` | `string(60)` | no | — | |
| `subject_type` | `string(40)` | no | — | `order` \| `product_variant` \| `store` \| `sync_log` |
| `subject_id` | `unsignedBigInteger` | no | — | |
| `subject_label` | `string(120)` | yes | `null` | e.g. order external id — so the audit view needs no join |
| `correlation_id` | `uuid` | no | — | groups every rule run in one evaluation pass over one subject |
| `source` | `string(20)` | no | — | `sync` \| `webhook` \| `manual` \| `backfill` \| `simulation` \| `api` |
| `outcome` | `string(16)` | no | — | `matched` \| `skipped` \| `partial` \| `failed` \| `simulated` \| `deduped` |
| `matched` | `boolean` | no | `false` | |
| `dry_run` | `boolean` | no | `false` | |
| `chain_depth` | `unsignedTinyInteger` | no | `0` | 0 = original trigger; >0 = re-entrant (§4.6) |
| `facts` | `json` | yes | `null` | the normalised subject facts used — makes every decision explainable |
| `condition_trace` | `json` | yes | `null` | per-leaf `{path, field, operator, value, actual, result}` |
| `actions_applied` | `json` | yes | `null` | array of `{action_id, type, status, result, error, duration_ms}` |
| `error` | `text` | yes | `null` | first fatal error |
| `duration_ms` | `unsignedInteger` | no | `0` | |
| `created_at` / `updated_at` | `timestamps` | yes | — | rows are immutable; `updated_at` unused |

**Indexes**

```php
$table->index(['organization_id', 'created_at']);
$table->index(['automation_rule_id', 'created_at']);
$table->index(['subject_type', 'subject_id']);
$table->index(['correlation_id']);
$table->index(['organization_id', 'outcome', 'created_at']);
```

**Retention:** 90 days, pruned nightly (§12.4). `skipped` (non-match) runs are only persisted when `config('automation.log_non_matches')` is true or the pass came from `simulation`/`manual` — otherwise a non-matching rule × every order would 10× the table for zero user value. The counters on `automation_rules` still increment.

### 3.3 `automation_rule_applications` (idempotency ledger)

**File:** `database/migrations/2026_07_22_000003_create_automation_rule_applications_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` | no | — | cascade |
| `automation_rule_id` | `foreignId` | no | — | FK → `automation_rules.id`, cascade |
| `subject_type` | `string(40)` | no | — | |
| `subject_id` | `unsignedBigInteger` | no | — | |
| `fingerprint` | `char(64)` | no | — | sha256, §4.5 |
| `automation_run_id` | `unsignedBigInteger` | yes | `null` | no FK (runs are pruned) |
| `applied_at` | `timestamp` | no | `useCurrent()` | |

**Indexes**

```php
$table->unique(
  ['automation_rule_id', 'subject_type', 'subject_id', 'fingerprint'],
  'automation_apps_unique'
);
$table->index(['organization_id', 'applied_at']);
$table->index(['subject_type', 'subject_id']);
```

The unique index **is** the idempotency mechanism: `insert` throws `QueryException` (23000) → the rule was already applied → outcome `deduped`. No read-then-write race.

### 3.4 Order-side columns

**File:** `database/migrations/2026_07_22_000004_add_automation_fields_to_orders_table.php`

All guarded with `Schema::hasColumn` per the house pattern in `2026_05_06_090717_fix_orders_table_columns.php`.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `tags` | `json` | yes | `null` | array of lowercase strings; cast `array` |
| `fulfillment_location` | `string(100)` | yes | `null` | ASSUMPTION A2 — string code, future FK |
| `carrier_code` | `string(60)` | yes | `null` | ASSUMPTION A3 |
| `shipping_service` | `string(60)` | yes | `null` | |
| `folder` | `string(60)` | yes | `null` | queue/folder name (Linnworks parity) |
| `is_held` | `boolean` | no | `false` | parked for review |
| `hold_reason` | `string(255)` | yes | `null` | |
| `held_at` | `timestamp` | yes | `null` | |
| `parent_order_id` | `unsignedBigInteger` | yes | `null` | set on children produced by `split_order`; self-FK `nullOnDelete()` |
| `split_index` | `unsignedSmallInteger` | yes | `null` | 1-based |
| `automation_state` | `json` | yes | `null` | `{last_evaluated_at, last_facts_hash, chain_depth, applied_rule_ids[]}` |
| `internal_notes` | `json` | yes | `null` | append-only `[{at, by, source, text}]` for `add_note` |

Indexes: `index('is_held')`, `index('parent_order_id')`, `index(['store_id','folder'])`.

**File:** `database/migrations/2026_07_22_000005_add_organization_id_to_orders_table.php` — **recommended, not strictly required.**

Adds `organization_id` (`unsignedBigInteger`, nullable initially, FK cascade, `index(['organization_id','created_at'])`) and backfills from `stores`. Rationale: the rule tester ("last 200 orders for this org"), the runs audit view, and the hot dispatch query all currently need `whereHas('store', ...)`, a correlated subquery on every evaluation. With ~100k+ orders per org this is the difference between a 4 ms and a 300 ms preview.

Rollout: add nullable → backfill in chunks (`Order::whereNull('organization_id')->chunkById(1000, ...)`) → a second migration flips it to `NOT NULL` after verification. Until then all automation queries use a helper that prefers `organization_id` and falls back to `whereHas`. If the team rejects the denormalisation, everything still works; the tester just gets a lower default `N`.

**File:** `database/migrations/2026_07_22_000006_add_low_stock_threshold_to_stock_tables.php` — ASSUMPTION A5.

Adds `low_stock_threshold` (`unsignedInteger`, nullable, default `null`) to both `products` and `product_variants`, plus `automation_low_stock_notified_at` (`timestamp`, nullable) on `product_variants` for hysteresis (§10.7). Org-level fallback threshold lives in `config('automation.default_low_stock_threshold')` (default `5`).

### 3.5 JSON schema — `conditions`

Recursive group structure. `match` is `all` (AND) or `any` (OR). A group's `rules` may contain leaves and nested groups.

```jsonc
{
  "match": "all",
  "rules": [
    { "field": "order.channel",           "operator": "in",      "value": ["salla", "zid"] },
    { "field": "order.is_cod",            "operator": "eq",      "value": true },
    { "field": "order.total",             "operator": "gte",     "value": 1500 },
    {
      "match": "any",
      "rules": [
        { "field": "order.shipping_country", "operator": "eq",      "value": "SA" },
        { "field": "order.shipping_city",    "operator": "in",      "value": ["Riyadh", "الرياض"] },
        { "field": "order.skus",             "operator": "any_of",  "value": ["FRAGILE-*"] }
      ]
    },
    { "field": "order.customer_type",     "operator": "eq",      "value": "new" },
    { "field": "order.weight_grams",      "operator": "between", "value": [0, 30000] }
  ]
}
```

Leaf shape:

```jsonc
{
  "field":    "order.total",     // must exist in the field catalogue for this trigger
  "operator": "gte",             // must be legal for the field's type
  "value":    1500,              // type must match; array for in/not_in/between/any_of/...
  "negate":   false,             // optional, default false — wraps the leaf in NOT
  "case_sensitive": false        // optional, string operators only, default false
}
```

**Constraints (enforced by `AutomationConditionRule` validator):** nesting depth ≤ 3 · total leaves ≤ 25 · `value` array length ≤ 100 · `matches` (regex) patterns ≤ 200 chars, compiled with a 50 ms `preg` backtrack limit and rejected if they contain nested quantifiers (ReDoS guard).

#### Field catalogue

Served by `GET /automation/schema` so the builder never hardcodes fields. `label_key` is the i18n dot-path.

**`order.*` — available on `order.created`, `order.updated`, `order.status_changed`**

| Field | Type | Source | Notes |
| --- | --- | --- | --- |
| `order.channel` | `enum` | `stores.platform` | 7 platform values |
| `order.store_id` | `enum<int>` | `orders.store_id` | options = org's stores |
| `order.status` | `string` | `orders.status` | free string (platform-dependent) |
| `order.previous_status` | `string` | event payload | `order.status_changed` only; `null` otherwise |
| `order.total` | `decimal` | `orders.total` | |
| `order.total_base` | `decimal` | computed | converted to org base currency; `null` if no FX rate — see §15 Q4 |
| `order.currency` | `enum` | `orders.currency` | |
| `order.item_count` | `int` | `count(order_items)` | distinct lines |
| `order.total_quantity` | `int` | `sum(order_items.quantity)` | |
| `order.weight_grams` | `int` | `raw_data` (§6) | `null` when the platform doesn't send it |
| `order.skus` | `array<string>` | `order_items.sku` | supports `*` glob in values |
| `order.product_names` | `array<string>` | `order_items.name` | |
| `order.category_ids` | `array<int>` | `order_items.sku` → `products.category_id` | joined via SKU; misses if the SKU isn't a Hubby product |
| `order.tags` | `array<string>` | `orders.tags` | rule-written tags are visible to later rules |
| `order.payment_method` | `string` | `raw_data` (§6) | normalised: `cod`, `card`, `wallet`, `bank_transfer`, `bnpl`, `marketplace`, `unknown` |
| `order.is_cod` | `bool` | derived | convenience alias for `payment_method == 'cod'` |
| `order.shipping_country` | `enum` | `raw_data` (§6) | **ISO-3166-1 alpha-2, uppercase** |
| `order.shipping_city` | `string` | `raw_data` (§6) | raw platform string; matching is case- and whitespace-insensitive |
| `order.shipping_speed` | `enum` | `raw_data` (§6) | `standard`, `express`, `same_day`, `scheduled`, `unknown` |
| `order.customer_email` | `string` | `orders.customer_email` | |
| `order.customer_type` | `enum` | derived (A6) | `new` \| `repeat` |
| `order.customer_order_count` | `int` | derived (A6) | count of prior orders with the same email in the org |
| `order.is_held` | `bool` | `orders.is_held` | |
| `order.folder` | `string` | `orders.folder` | |
| `order.fulfillment_location` | `string` | `orders.fulfillment_location` | |
| `order.carrier_code` | `string` | `orders.carrier_code` | |
| `order.created_hour` | `int` | `orders.created_at` | 0–23, org timezone |
| `order.age_minutes` | `int` | computed | minutes since `orders.created_at` |
| `order.min_stock_of_items` | `int` | joined | lowest `product_variants.stock` across the order's SKUs; `null` if unmatched |

**`stock.*` — `stock.below_threshold`**

`stock.sku` (string), `stock.available` (int), `stock.threshold` (int), `stock.product_id` (int), `stock.variant_id` (int), `stock.category_id` (enum<int>), `stock.product_name` (string), `stock.deficit` (int, `threshold - available`).

**`sync.*` — `sync.failed`**

`sync.platform` (enum), `sync.store_id` (enum<int>), `sync.type` (enum: `orders`|`products`|`inventory`), `sync.error_message` (string), `sync.consecutive_failures` (int).

#### Operator set

| Operator | Valid field types | `value` shape | Semantics |
| --- | --- | --- | --- |
| `eq` / `neq` | all scalars | scalar | strict after type coercion |
| `gt` / `gte` / `lt` / `lte` | int, decimal, date | scalar | numeric/temporal compare |
| `between` | int, decimal, date | `[min, max]` | inclusive both ends |
| `in` / `not_in` | scalars | array | membership |
| `contains` / `not_contains` | string | string | substring |
| `starts_with` / `ends_with` | string | string | |
| `matches` | string | string | PCRE, guarded (see constraints) |
| `is_empty` / `is_not_empty` | all | *(omitted)* | `null`, `''`, or `[]` |
| `any_of` | array | array | intersection non-empty; values may glob with `*` |
| `all_of` | array | array | every value present |
| `none_of` | array | array | intersection empty |
| `is_true` / `is_false` | bool | *(omitted)* | |

**Coercion & null rules (must be tested):**
- Strings compare case-insensitively and whitespace-trimmed unless `case_sensitive: true`.
- Numeric fields cast via `(float)`; a non-numeric actual value makes the leaf `false`, never an exception.
- **`null` actual → the leaf is `false` for every operator except `is_empty`, `none_of`, and `not_in`** (a missing weight must never accidentally satisfy `weight < 5000`). This is the single most important semantic to get right and is called out in the UI as "unknown values never match".
- `order.shipping_country` is upper-cased on both sides; `order.shipping_city` is lower-cased + inner-whitespace-collapsed on both sides.
- An unknown `field` (removed from the catalogue) makes the leaf `false` and marks the run `skipped` with `error: "unknown_field:<name>"` — never a hard failure.

### 3.6 JSON schema — `actions`

An **ordered array**. Each element:

```jsonc
{
  "id": "a3f1c0d2-...",     // uuid, generated by the UI, STABLE across edits — part of the fingerprint
  "type": "hold_order",
  "params": { "reason": "COD > 1500 SAR — call to confirm" },
  "on_error": "stop"        // "stop" (default) | "continue"
}
```

Full example (the flagship COD rule):

```jsonc
[
  { "id": "…1", "type": "add_tag",       "params": { "tags": ["cod-high-value", "needs-call"] } },
  { "id": "…2", "type": "hold_order",    "params": { "reason": "COD over 1500 SAR" } },
  { "id": "…3", "type": "assign_folder", "params": { "folder": "COD Verification" } },
  { "id": "…4", "type": "notify",        "params": {
      "channels": ["in_app", "whatsapp"],
      "recipients": { "roles": ["owner", "admin"], "users": [], "emails": [], "phones": [] },
      "title": "COD order needs confirmation",
      "message": "Order {{order.external_id}} ({{order.total}} {{order.currency}}) from {{order.customer_name}} is on hold.",
      "type": "warning"
  }},
  { "id": "…5", "type": "call_webhook",  "params": {
      "url": "https://ops.example.com/hooks/cod",
      "method": "POST",
      "payload_template": "order",
      "headers": { "X-Source": "hubby" }
  }, "on_error": "continue" }
]
```

#### Action catalogue

| `type` | `params` | Deferred? | Mutates facts? | Notes |
| --- | --- | --- | --- | --- |
| `add_tag` | `tags: string[1..10]` | no | yes | lowercased, slug-safe, deduped; order tag cap 50 |
| `remove_tag` | `tags: string[1..10]` | no | yes | |
| `route_location` | `location: string(≤100)` | no | yes | A2 |
| `assign_carrier` | `carrier_code: string`, `service_code?: string` | no | yes | validated against `config('automation.carriers')` allowlist (A3) |
| `split_order` | `strategy: by_sku\|by_location\|by_stock_availability`, `groups?: [{name, skus[]}]` | no | **terminates chain** | §4.7 |
| `hold_order` | `reason: string(≤255)` | no | yes | sets `is_held`, `hold_reason`, `held_at` |
| `release_hold` | — | no | yes | |
| `set_status` | `status: string`, `push_to_platform: bool = false` | partly | yes | local write is immediate; platform push is a deferred job using `IntegrationServiceInterface::updateOrderStatus()` |
| `assign_folder` | `folder: string(≤60)` | no | yes | |
| `add_note` | `text: string(≤1000)` (templated) | no | no | appends to `orders.internal_notes` |
| `notify` | `channels: (in_app\|email\|whatsapp)[]`, `recipients: {roles[], users[], emails[], phones[]}`, `title`, `message`, `type: info\|success\|warning\|error` | yes | no | `in_app` writes `Notification` (works today); `email`/`whatsapp` are queued (A4) |
| `call_webhook` | `url: https`, `method: POST\|PUT`, `headers?: object(≤10)`, `payload_template: order\|minimal\|custom`, `custom_payload?: object` | yes | no | HMAC-signed, SSRF-guarded, retried 3× |
| `stop_processing` | — | no | **terminates chain** | equivalent to rule-level `stop_processing` but expressible mid-list |

**Templating.** `{{ order.<field> }}` and `{{ stock.<field> }}` only, resolved against the same facts array used for conditions. Rendered with a whitelist resolver — **not** Blade, **not** `eval`. Unknown placeholders render as empty string and add a warning to `actions_applied`. Values are escaped per channel (HTML-escaped for email, plain for in-app/WhatsApp).

**Deferred actions** (`notify`, `call_webhook`, and the platform push half of `set_status`) implement `isDeferred(): true`. They are **not** executed inline — the `ActionDispatcher` queues `ApplyAutomationActionJob` with `->afterCommit()` and records status `queued` in `actions_applied`. The job later PATCHes the run row with the terminal status. This keeps DB transactions short and stops a slow third party from stalling order ingest.

---

## 4. Domain logic

### 4.1 Components

```
AutomationDispatcher   entry point: given (trigger, subject, source, depth) → run the pass
  ├─ FactResolver      OrderFactResolver | StockFactResolver | SyncFactResolver → array of facts
  ├─ RuleRepository    cached, org+trigger scoped, priority-ordered rule fetch
  ├─ RuleEvaluator     conditions JSON × facts → MatchResult{matched, trace}
  │    └─ ConditionEvaluator → OperatorRegistry
  └─ ActionDispatcher  actions JSON × context → ActionResult[]
       └─ Actions\*    one class per action type
```

### 4.2 Evaluation algorithm (pseudocode)

```php
// App\Services\Automation\AutomationDispatcher

function run(string $trigger, Subject $subject, string $source, int $depth = 0, ?string $correlationId = null): PassResult
{
    $correlationId ??= (string) Str::uuid();

    // ── guard 0: kill switch + plan/feature flag
    if (! config('automation.enabled')) return PassResult::disabled();

    // ── guard 1: chain depth (loop prevention, §4.6)
    if ($depth > config('automation.max_chain_depth')) {          // default 3
        Log::warning('automation.chain_depth_exceeded', compact('trigger','correlationId') + ['subject' => $subject->key()]);
        AutomationRun::record(outcome: 'skipped', error: 'chain_depth_exceeded', ...);
        return PassResult::halted('chain_depth_exceeded');
    }

    // ── guard 2: per-subject burst cap (loop prevention, §4.6)
    $burstKey = "automation:burst:{$subject->key()}";
    if (Redis::incr($burstKey) === 1) Redis::expire($burstKey, 60);
    if (Redis::get($burstKey) > config('automation.max_passes_per_minute')) {   // default 20
        return PassResult::halted('burst_cap');
    }

    // ── guard 3: serialise passes over the same subject
    $lock = Cache::lock("automation:subject:{$subject->key()}", 30);
    if (! $lock->block(5, fn() => null)) {                         // waits up to 5s
        EvaluateAutomationRulesJob::dispatch($trigger, $subject, $source, $depth, $correlationId)
            ->delay(now()->addSeconds(10));                        // requeue rather than drop
        return PassResult::requeued();
    }

    try {
        $facts = FactResolver::for($subject)->resolve($subject, $trigger);   // ONE query batch
        $rules = RuleRepository::forTrigger($subject->organizationId(), $trigger);  // cached 60s

        $results = [];

        foreach ($rules as $rule) {
            $t0 = hrtime(true);

            // ── evaluate
            $match = RuleEvaluator::evaluate($rule->conditions, $facts);

            if (! $match->matched) {
                $rule->incrementQuietly('evaluated_count');
                if (config('automation.log_non_matches') || $source === 'simulation' || $source === 'manual') {
                    $results[] = AutomationRun::record($rule, $subject, 'skipped', matched: false,
                        facts: $facts, trace: $match->trace, correlationId: $correlationId,
                        source: $source, depth: $depth, durationMs: ms($t0));
                }
                continue;
            }

            // ── idempotency (§4.5)
            $fingerprint = Fingerprint::for($rule, $subject, $facts);
            if ($source !== 'simulation' && ! Idempotency::claim($rule, $subject, $fingerprint)) {
                $results[] = AutomationRun::record($rule, $subject, 'deduped', matched: true, ...);
                if ($rule->stop_processing) break;
                continue;
            }

            // ── apply
            if ($rule->run_mode === 'dry_run' || $source === 'simulation') {
                $applied = ActionDispatcher::preview($rule->actions, $facts, $subject);
                $outcome = 'simulated';
            } else {
                [$applied, $outcome] = DB::transaction(function () use ($rule, $subject, $facts, $correlationId, $depth) {
                    return ActionDispatcher::apply($rule->actions, new AutomationContext(
                        subject: $subject, facts: $facts, rule: $rule,
                        correlationId: $correlationId, depth: $depth,
                    ));
                });
                // outcome ∈ matched | partial | failed
            }

            $results[] = AutomationRun::record($rule, $subject, $outcome, matched: true,
                facts: $facts, trace: $match->trace, applied: $applied,
                correlationId: $correlationId, source: $source, depth: $depth, durationMs: ms($t0));

            if ($outcome === 'failed') {
                Idempotency::release($rule, $subject, $fingerprint);   // let a retry re-apply
            }

            // ── chaining: refresh facts if anything mutated the subject
            if (ActionDispatcher::mutatedSubject($applied)) {
                $subject->refresh();
                $facts = FactResolver::for($subject)->resolve($subject, $trigger);
            }

            if (ActionDispatcher::terminated($applied) || $rule->stop_processing) break;
        }

        $subject->markAutomationEvaluated($correlationId, $depth, Fingerprint::factsHash($facts));

        return PassResult::completed($results, $correlationId);
    } finally {
        $lock->release();
    }
}
```

`EvaluateAutomationRulesJob` is a thin `ShouldQueue` wrapper around `AutomationDispatcher::run()`, on the `automation` queue.

### 4.3 `RuleEvaluator` (recursive group evaluation)

```php
function evaluate(array $node, array $facts, string $path = '$'): MatchResult
{
    if (isset($node['match'])) {                      // group
        $children = $node['rules'] ?? [];
        if ($children === []) return MatchResult::pass($path, 'empty_group');   // empty = always match

        $trace = [];
        $results = [];
        foreach ($children as $i => $child) {
            $r = evaluate($child, $facts, "{$path}.rules[{$i}]");
            $trace = array_merge($trace, $r->trace);
            $results[] = $r->matched;

            // short-circuit but keep the trace honest: remaining leaves marked 'not_evaluated'
            if ($node['match'] === 'all' && ! $r->matched) break;
            if ($node['match'] === 'any' &&   $r->matched) break;
        }
        $matched = $node['match'] === 'all' ? ! in_array(false, $results, true)
                                            :   in_array(true,  $results, true);
        return new MatchResult($matched, $trace);
    }

    // leaf
    $field  = $node['field'];
    $spec   = FieldCatalogue::get($field);
    if (! $spec) return MatchResult::fail($path, "unknown_field:{$field}");

    $actual = Arr::get($facts, $field);                       // null-safe
    $result = OperatorRegistry::get($node['operator'])->matches($actual, $node['value'] ?? null, $spec, $node);
    if ($node['negate'] ?? false) $result = ! $result;

    return new MatchResult($result, [[
        'path' => $path, 'field' => $field, 'operator' => $node['operator'],
        'value' => $node['value'] ?? null, 'actual' => $actual, 'result' => $result,
    ]]);
}
```

The `trace` is what powers "why did/didn't this match?" in the audit and simulation UI. It is the reason merchants will trust the engine.

### 4.4 Priority & chaining semantics

- Rules for one `(organization_id, trigger)` run in `priority ASC, id ASC`. `priority` is `0..65535`; the UI presents it as drag-to-reorder and writes back a normalised sequence in steps of 10 (leaving room for manual insertion).
- **All matching rules run** (Linnworks parity) unless one sets `stop_processing` or emits a `stop_processing` / `split_order` action.
- After a rule mutates the subject, facts are **re-resolved** before the next rule evaluates. Rule 2 therefore sees the tag Rule 1 added. This is the documented, intended behaviour and is what makes rule libraries composable.
- A rule can only run **once per pass** — it is never revisited within the same `correlation_id`.
- Mutations made by rules do **not** synchronously re-trigger `order.updated`. Re-entrancy is explicit: only `set_status` re-dispatches, as `order.status_changed` at `depth + 1` (§4.6).

### 4.5 Idempotency

The problem: `SyncOrdersJob` runs every few minutes and `updateOrCreate`s the same orders. Without protection, "add tag + notify ops" would fire on every sync forever.

**Fingerprint:**

```php
Fingerprint::for($rule, $subject, $facts) = hash('sha256', json_encode([
    'rule'          => $rule->id,
    'rule_version'  => $rule->version,
    'action_ids'    => collect($rule->actions)->pluck('id')->sort()->values(),
    'subject'       => $subject->key(),                  // "order:1234"
    'material'      => Arr::only($facts, $rule->materialFields()),  // ONLY fields the conditions read
], JSON_THROW_ON_ERROR));
```

`materialFields()` = the set of `field` values appearing in the rule's `conditions`, cached on the model.

Consequences, all intended:

| Situation | Fingerprint | Behaviour |
| --- | --- | --- |
| Same order re-synced, nothing changed | same | `deduped` — no re-apply |
| Order total corrected 500 → 2,000 (rule reads `order.total`) | different | re-applies (correctly — it's a materially different order) |
| Order's `customer_name` corrected (rule doesn't read it) | same | `deduped` |
| Merchant edits the rule's conditions/actions | different (`version`++) | re-applies to matching orders on their next trigger |
| Merchant renames the rule | same | no re-apply |

Claim is a plain `INSERT` against the unique index; a `23000` violation = already applied. On rule delete, the ledger cascades away; on rule *edit*, old rows stay harmless (different fingerprint).

Actions are additionally **individually idempotent where cheap**: `add_tag` is a set-union, `assign_folder`/`route_location`/`assign_carrier` are last-write-wins, `hold_order` is a no-op when `is_held` is already true. `notify`, `call_webhook`, and `split_order` are *not* naturally idempotent — they rely entirely on the ledger, so `call_webhook` additionally sends an `Idempotency-Key: <fingerprint>:<action_id>` header so the receiver can dedupe too.

### 4.6 Loop prevention (four independent guards)

1. **Suppression during application.** `ActionDispatcher` sets `Automation::$applying = true` for the duration. `Order` model observers and `OrderController::update` check `Automation::applying()` and skip re-dispatch. Rule-caused writes never *implicitly* re-trigger.
2. **Explicit, depth-tagged re-entrancy.** Only `set_status` re-dispatches, deliberately, as `order.status_changed` with `depth + 1`. Any rule at depth ≥ `max_chain_depth` (3) is refused and logged.
3. **Per-subject burst cap.** Redis counter `automation:burst:order:{id}`, 60 s TTL, max 20 passes. Trips → pass halted + one `warning` notification to org admins ("Automation loop protection triggered for order X").
4. **Static cycle detection at save time.** `RuleGraphAnalyzer` builds a directed graph over the org's rules: an edge `A → B` exists when A's `set_status` value could satisfy B's `order.status`/`order.previous_status` conditions. A cycle produces a **warning** on save (not a hard block — legitimate ping-pong across statuses exists) surfaced as `warnings[]` in the create/update response and shown in the builder.

### 4.7 `split_order` semantics

Splitting is the only structurally destructive action, so it is deliberately constrained:

- Creates *N* child `orders` rows with `parent_order_id = original.id`, `split_index = 1..N`, `external_id = "{original.external_id}-S{n}"` (satisfies `unique(store_id, external_id)`), same `store_id`, `status`, `currency`, customer fields; `total` = sum of that group's item prices × quantities; `raw_data` = `{"_split_of": <parent_id>, "_split_strategy": "..."}` (the original payload stays on the parent).
- Moves `order_items` to children by group. The **parent is retained** as `is_held = true`, `folder = 'Split — parent'`, `status` unchanged, and is excluded from analytics by a `parent_of_split` tag. It is never deleted (audit + platform reconciliation).
- **Terminates the chain** for the parent. Children are dispatched as fresh `order.created` passes at `depth + 1`, `source = 'automation'`. Rules must therefore be written to be split-aware; the UI warns when `split_order` is not the last action.
- A split is refused (and the action fails with `error: 'already_split'`) when the order already has children — the ledger normally prevents this, but the check is belt-and-braces.
- **Splits are local-only.** Nothing is pushed to the channel; marketplaces mostly don't allow it. This is documented in the UI help text.

### 4.8 Transactionality

- Each **rule** is one DB transaction. A mid-chain failure never leaves one rule half-applied; earlier rules in the same pass stay committed (they succeeded, and re-running them would be wrong).
- Only local DB mutations happen inside the transaction. Every external call (platform API, webhook, email, WhatsApp) is a deferred job dispatched with `->afterCommit()`.
- The `automation_runs` row is written **outside/after** the rule transaction (`DB::afterCommit`) so a rollback never erases the audit of *why* it rolled back; the row records `outcome: 'failed'` with the exception message and class.
- `automation_rule_applications` claim happens **inside** the rule transaction, so a rollback releases the claim automatically.

---

## 5. Backend

### 5.1 Models

`app/Models/AutomationRule.php`

```php
protected $fillable = ['organization_id','name','description','trigger','conditions','actions',
                       'priority','enabled','run_mode','stop_processing','created_by','updated_by'];
protected $casts = ['conditions'=>'array','actions'=>'array','enabled'=>'boolean',
                    'stop_processing'=>'boolean','last_run_at'=>'datetime'];
use SoftDeletes;

public function organization(): BelongsTo
public function runs(): HasMany                 // AutomationRun
public function creator(): BelongsTo            // User, 'created_by'
public function scopeForOrganization($q, $orgId)
public function scopeEnabled($q)
public function scopeForTrigger($q, string $trigger)
public function materialFields(): array         // cached extraction of condition field names
```

Booted: on `saving`, if `conditions` or `actions` is dirty → `$model->version++`. On `saved`/`deleted` → `RuleRepository::flush($organizationId)` (busts the Redis cache).

`app/Models/AutomationRun.php` — casts `facts`, `condition_trace`, `actions_applied` to `array`; `$guarded = []`; relations `rule()`, `organization()`; `scopeForOrganization`, `scopeForSubject($type,$id)`. Static factory `AutomationRun::record(...)`.

`app/Models/AutomationRuleApplication.php` — `$timestamps = false`, `applied_at` cast to datetime.

`app/Models/Order.php` — add to `$fillable`: `tags, fulfillment_location, carrier_code, shipping_service, folder, is_held, hold_reason, held_at, parent_order_id, split_index, automation_state, internal_notes, organization_id`; add casts `tags=>array, internal_notes=>array, automation_state=>array, is_held=>boolean, held_at=>datetime, total=>decimal:2`; add relations `parent()`, `children()`, `automationRuns()`, `organization()`.

### 5.2 Services (`app/Services/Automation/`)

| Class | Responsibility |
| --- | --- |
| `AutomationDispatcher` | pass orchestration (§4.2); the only public entry point |
| `RuleRepository` | `forTrigger(int $orgId, string $trigger): Collection` — Redis-cached 60 s under `automation:rules:{org}:{trigger}`; `flush(int $orgId)` |
| `RuleEvaluator` | recursive group evaluation → `MatchResult` |
| `ConditionEvaluator` | single-leaf evaluation + type coercion |
| `OperatorRegistry` | `get(string $op): OperatorInterface`; one small class per operator |
| `FieldCatalogue` | field specs (type, trigger applicability, option source, i18n label key); powers `GET /automation/schema` and validation |
| `ActionDispatcher` | `apply()` / `preview()`; runs actions in order, aggregates `ActionResult[]`, honours `on_error` |
| `ActionRegistry` | `type` → action class map |
| `Actions\*` | `AddTagAction`, `RemoveTagAction`, `RouteLocationAction`, `AssignCarrierAction`, `SplitOrderAction`, `HoldOrderAction`, `ReleaseHoldAction`, `SetStatusAction`, `AssignFolderAction`, `AddNoteAction`, `NotifyAction`, `CallWebhookAction`, `StopProcessingAction` — all implement `AutomationActionInterface` |
| `Facts\OrderFactResolver` | §6 per-platform normalisation; one batched query set per order |
| `Facts\StockFactResolver` | |
| `Facts\SyncFactResolver` | |
| `Fingerprint` | idempotency hashing |
| `Idempotency` | `claim()` / `release()` against the ledger |
| `Templating\FactTemplateRenderer` | `{{ order.x }}` whitelist rendering |
| `RuleGraphAnalyzer` | static cycle detection (§4.6.4) |
| `RuleSimulator` | preview a rule (saved or draft) against the last *N* orders |
| `Notifications\NotificationChannelInterface` + `InAppChannel`, `EmailChannel`, `WhatsAppChannel` | A4 |
| `Webhook\OutboundWebhookSigner` | HMAC-SHA256 over the raw body with a per-org secret; header `X-Hubby-Signature: sha256=<hex>` + `X-Hubby-Timestamp`, mirroring the inbound convention in `VerifyWebhookSignature` |
| `Webhook\UrlGuard` | https-only; rejects private/loopback/link-local/metadata IPs (`127/8`, `10/8`, `172.16/12`, `192.168/16`, `169.254/16`, `::1`, `fc00::/7`) after DNS resolution, re-checked at request time (DNS-rebinding guard); no redirects followed |

Interfaces:

```php
interface AutomationActionInterface {
    public function type(): string;
    public function rules(): array;                                  // Laravel validation for params
    public function isDeferred(): bool;
    public function mutatesSubject(): bool;
    public function terminatesChain(): bool;
    public function apply(AutomationContext $ctx, array $params): ActionResult;
    public function preview(AutomationContext $ctx, array $params): ActionResult;  // describes, never writes
}
```

`ActionResult` = `{status: applied|queued|skipped|failed|previewed, summary: string, data: array, error: ?string, duration_ms: int}`.

### 5.3 Jobs (`app/Jobs/`)

Following the existing style (`implements ShouldQueue` + the four traits).

| Job | Purpose | Queue / retries |
| --- | --- | --- |
| `EvaluateAutomationRulesJob(string $trigger, string $subjectType, int $subjectId, string $source, int $depth = 0, ?string $correlationId = null)` | one evaluation pass | `automation`, `$tries = 3`, `backoff = [10,60,300]`, `$uniqueFor = 60` via `ShouldBeUnique` on `"{$subjectType}:{$subjectId}:{$trigger}"` |
| `ApplyAutomationActionJob(int $runId, string $actionId, string $type, array $params, array $facts, int $subjectId)` | deferred external actions | `automation-actions`, `$tries = 3`, exponential backoff |
| `BackfillAutomationRulesJob(int $ruleId, array $orderIds, string $correlationId)` | chunked historical application | `automation-backfill` (low priority), chunk 200 |
| `PruneAutomationRunsJob` | nightly retention | scheduled |

`routes/console.php` additions:

```php
Schedule::job(new PruneAutomationRunsJob)->dailyAt('03:15');
```

**Horizon** (`config/horizon.php`): add `automation` (high-ish), `automation-actions`, `automation-backfill` (`maxProcesses` low) to the supervisor queue list so backfills never starve order sync.

### 5.4 Events & listeners

New (`app/Events/`): `OrderIngested(Order $order, bool $wasCreated, string $source)`, `OrderStatusChanged(Order $order, ?string $previousStatus, string $source)`, `StockDroppedBelowThreshold(ProductVariant $variant, int $threshold, string $source)`, `SyncFailed(SyncLog $log, string $message)`.

Listener (`app/Listeners/DispatchAutomationEvaluation.php`, `ShouldQueue`) maps event → trigger → `EvaluateAutomationRulesJob::dispatch(...)`, and short-circuits when `Automation::applying()` is true.

The repo has no `app/Events` or `EventServiceProvider` today; Laravel 12 auto-discovers listeners in `app/Listeners`, so no provider registration is required — but register explicitly in `AppServiceProvider::boot()` if auto-discovery is disabled.

### 5.5 API endpoints

All inside the existing `Route::middleware('auth:sanctum')->group(fn() => Route::middleware('org.member')->group(...))` block in `routes/api.php`, placed after the Categories block:

```php
    // Automation (rules engine) — ungated: available on every plan.
    Route::get   ('/automation/schema',            [AutomationController::class, 'schema']);
    Route::get   ('/automation/rules',             [AutomationController::class, 'index']);
    Route::post  ('/automation/rules',             [AutomationController::class, 'store']);
    Route::get   ('/automation/rules/{id}',        [AutomationController::class, 'show']);
    Route::put   ('/automation/rules/{id}',        [AutomationController::class, 'update']);
    Route::delete('/automation/rules/{id}',        [AutomationController::class, 'destroy']);
    Route::post  ('/automation/rules/{id}/toggle', [AutomationController::class, 'toggle']);
    Route::post  ('/automation/rules/reorder',     [AutomationController::class, 'reorder']);
    Route::post  ('/automation/rules/{id}/simulate', [AutomationController::class, 'simulateSaved']);
    Route::post  ('/automation/simulate',          [AutomationController::class, 'simulateDraft']);
    Route::post  ('/automation/rules/{id}/apply',  [AutomationController::class, 'applyToOrders']);
    Route::get   ('/automation/runs',              [AutomationController::class, 'runs']);
    Route::get   ('/automation/runs/{id}',         [AutomationController::class, 'runShow']);
    Route::get   ('/automation/templates',         [AutomationController::class, 'templates']);
```

Every handler resolves `$organizationId = $request->header('X-Organization-Id')` and scopes with `->where('organization_id', $organizationId)` before `findOrFail`, matching `OrderController`/`NotificationController`.

---

**`GET /automation/schema`** — everything the builder needs.

Response `200`:
```jsonc
{
  "triggers": [{ "value": "order.created", "label_key": "automation.triggers.orderCreated", "subject": "order" }, ...],
  "fields": [{
      "value": "order.total", "type": "decimal", "label_key": "automation.fields.orderTotal",
      "triggers": ["order.created","order.updated","order.status_changed"],
      "operators": ["eq","neq","gt","gte","lt","lte","between","is_empty","is_not_empty"],
      "options": null, "unit": "currency", "nullable": false
  }, {
      "value": "order.channel", "type": "enum", "label_key": "automation.fields.orderChannel",
      "operators": ["eq","neq","in","not_in"],
      "options": [{ "value": "shopify", "label": "Shopify" }, ...]
  }, ...],
  "operators": [{ "value": "gte", "label_key": "automation.operators.gte", "arity": 1 }, ...],
  "actions": [{
      "value": "hold_order", "label_key": "automation.actions.holdOrder",
      "params_schema": { "reason": { "type": "string", "max": 255, "required": true } },
      "deferred": false, "terminates_chain": false, "available": true
  }, ...],
  "limits": { "max_rules": 200, "max_actions_per_rule": 20, "max_condition_leaves": 25, "max_depth": 3 },
  "stores": [{ "id": 12, "name": "Main Salla", "platform": "salla" }],
  "categories": [{ "id": 3, "name": "Fragile" }]
}
```

Cached 5 min per org (`automation:schema:{org}`), busted on store/category writes.

---

**`GET /automation/rules`** — query `?trigger=&enabled=&search=&per_page=25`. Returns a paginator (same shape as `OrderController::index`) of:

```jsonc
{ "id": 7, "name": "COD > 1500 SAR → hold + call", "trigger": "order.created",
  "priority": 20, "enabled": true, "run_mode": "live", "stop_processing": false,
  "condition_summary": "Channel is Salla or Zid AND COD AND Total ≥ 1500",
  "action_summary": ["Add tag", "Hold for review", "Notify"],
  "matched_count": 412, "applied_count": 409, "failed_count": 3,
  "last_run_at": "2026-07-21T18:04:11Z", "created_by": { "id": 3, "name": "Amir" },
  "updated_at": "2026-07-19T09:00:00Z" }
```

---

**`POST /automation/rules`**

Request:
```jsonc
{ "name": "COD > 1500 SAR → hold + call",
  "description": "…",
  "trigger": "order.created",
  "conditions": { "match": "all", "rules": [...] },
  "actions": [ { "id": "uuid", "type": "hold_order", "params": { "reason": "…" }, "on_error": "stop" } ],
  "priority": 20, "enabled": false, "run_mode": "dry_run", "stop_processing": false }
```

Validation (`app/Http/Requests/StoreAutomationRuleRequest.php`):
```php
'name'            => ['required','string','max:120'],
'description'     => ['nullable','string','max:1000'],
'trigger'         => ['required', Rule::in(AutomationTrigger::available())],
'conditions'      => ['required','array', new ValidConditionTree($this->input('trigger'))],
'actions'         => ['required','array','min:1','max:20', new ValidActionList($this->input('trigger'))],
'actions.*.id'    => ['required','uuid','distinct'],
'actions.*.type'  => ['required', Rule::in(ActionRegistry::availableTypes())],
'actions.*.params'=> ['required','array'],
'actions.*.on_error' => ['nullable', Rule::in(['stop','continue'])],
'priority'        => ['nullable','integer','min:0','max:65535'],
'enabled'         => ['nullable','boolean'],
'run_mode'        => ['nullable', Rule::in(['live','dry_run'])],
'stop_processing' => ['nullable','boolean'],
```
Plus, in `withValidator`: org rule-count cap (`200`), role check (owner/admin — §9), per-action `params` validated against that action's own `rules()`.

Responses: `201` with the full rule object + `"warnings": ["automation.warnings.possibleLoop"]` from `RuleGraphAnalyzer` · `403` wrong role · `422` validation (Laravel's standard `{message, errors}`) · `429` rule cap reached (custom `{message, code: "rule_limit_reached"}`).

---

**`PUT /automation/rules/{id}`** — same body/validation, all fields optional (`sometimes`). Bumps `version` when `conditions`/`actions` change. `200` with the rule + warnings. `404` if the rule belongs to another org (never `403` — don't leak existence).

**`DELETE /automation/rules/{id}`** — soft delete. `200 {"message":"Rule deleted."}`. Runs are preserved with `automation_rule_id` intact.

**`POST /automation/rules/{id}/toggle`** — body `{ "enabled": true }`. Owner/admin only. Returns `{id, enabled}`. Emits an in-app `Notification` when a rule is switched to `live` for the first time.

**`POST /automation/rules/reorder`** — body `{ "order": [ {"id":7,"priority":10}, {"id":9,"priority":20} ] }`, all ids must belong to the org and share a trigger. Single transaction. `200 {"message":"Order updated."}`.

---

**`POST /automation/rules/{id}/simulate`** and **`POST /automation/simulate`** — the rule tester.

Request (draft variant carries the unsaved rule inline):
```jsonc
{ "sample": { "type": "recent_orders", "limit": 50, "store_id": null, "since": null },
  "rule": { "trigger": "order.created", "conditions": {...}, "actions": [...] }   // draft only
}
```
Validation: `sample.limit` `integer|min:1|max:200`; `sample.type` in `recent_orders|order_ids|held_orders`; `sample.order_ids` `array|max:200`.

Response `200`:
```jsonc
{
  "evaluated": 50, "matched": 7, "duration_ms": 118,
  "results": [{
    "order": { "id": 8812, "external_id": "#1042", "platform": "salla", "total": "1850.00",
               "currency": "SAR", "customer_name": "…", "created_at": "…" },
    "matched": true,
    "trace": [
      { "path": "$.rules[0]", "field": "order.channel", "operator": "in",
        "value": ["salla","zid"], "actual": "salla", "result": true },
      { "path": "$.rules[1]", "field": "order.is_cod", "operator": "eq",
        "value": true, "actual": true, "result": true },
      { "path": "$.rules[2]", "field": "order.total", "operator": "gte",
        "value": 1500, "actual": 1850, "result": true }
    ],
    "would_apply": [
      { "action_id": "…1", "type": "add_tag",    "summary": "Add tags: cod-high-value, needs-call", "status": "previewed" },
      { "action_id": "…2", "type": "hold_order", "summary": "Hold — \"COD over 1500 SAR\"",         "status": "previewed" },
      { "action_id": "…4", "type": "notify",     "summary": "Notify owner, admin via in-app, WhatsApp", "status": "previewed" }
    ],
    "already_applied": false
  }, ... ]
}
```

**Simulation writes nothing** — no order mutation, no ledger claim, no deferred jobs, no `automation_runs` rows unless `config('automation.persist_simulations')` (default `false`). It is rate-limited to 30/min/org (`throttle:30,1` + a per-org limiter).

---

**`POST /automation/rules/{id}/apply`** — deliberate re-application to selected orders.

Request: `{ "order_ids": [8812, 8813], "confirm": true }` (max 500) or `{ "filter": { "since": "2026-07-01", "store_id": 3, "status": "pending" }, "confirm": true }` (capped at 5,000, dispatched via `BackfillAutomationRulesJob` in chunks of 200).

Owner/admin only. `confirm` must be `true` (`accepted`). Response `202`:
`{ "message": "Queued.", "queued": 1240, "correlation_id": "…", "batch_id": "…" }`.

Backfills go on the `automation-backfill` queue at 200 orders/chunk with a 2 s inter-chunk delay, and are hard-capped at `config('automation.backfill_max_per_day')` (default 20,000/org/day).

---

**`GET /automation/runs`** — query `?rule_id=&subject_type=&subject_id=&outcome=&source=&from=&to=&per_page=25`. Paginated, `orderByDesc('created_at')`, org-scoped.

**`GET /automation/runs/{id}`** — full row including `facts`, `condition_trace`, `actions_applied`.

**`GET /automation/templates`** — the starter library (§12.3), returned as ready-to-clone rule bodies with `label_key`/`description_key` for i18n.

---

## 6. Integration touchpoints

### 6.1 The two universal hook points

**(a) Order ingest — `app/Jobs/SyncOrdersJob.php`.** This is the primary hook and covers **all 7 platforms at once**, because every platform's orders land through this one loop. Inside `foreach ($orders as $orderData)`, after the `OrderItem` sync (currently line 82):

```php
$wasCreated  = $order->wasRecentlyCreated;
$statusMoved = ! $wasCreated && $order->wasChanged('status');
$previous    = $order->getOriginal('status');

$trigger = match (true) {
    $wasCreated  => 'order.created',
    $statusMoved => 'order.status_changed',
    $order->wasChanged() => 'order.updated',
    default => null,             // nothing changed → do not even enqueue
};

if ($trigger && ! Automation::applying()) {
    EvaluateAutomationRulesJob::dispatch($trigger, 'order', $order->id, 'sync')
        ->afterCommit()
        ->onQueue('automation');
}
```

`SyncOrdersJob` currently runs no transaction, so `afterCommit()` is a no-op today and remains correct if one is added later.

**(b) Webhooks — `app/Http/Controllers/WebhookController.php`.** Today every handler just re-dispatches `SyncOrdersJob` (and is broken per AUT-0 #1). Once `SyncOrdersJob` resolves a `Store` correctly, **webhook-driven rules ride the same hook (a)** — no per-platform automation code is needed, and behaviour is identical between sync and webhook by construction. That is the design goal: one ingest path, one hook.

The only webhook-specific addition is provenance: pass the source down so the audit distinguishes them.

```php
// SyncOrdersJob::__construct(?Store $store = null, ?string $externalId = null, string $source = 'sync')
SyncOrdersJob::dispatch($store, $externalId, 'webhook');
```
…and `$this->source` is forwarded to `EvaluateAutomationRulesJob`.

### 6.2 Per-platform fact extraction (`OrderFactResolver`) — **all ASSUMPTION A1**

Only `shopify`, `salla`, `trendyol` have real mappings in `mapOrderData()` today; the rest fall through to `return $data;`. `OrderFactResolver` reads `orders.raw_data` (which stores the *original* payload verbatim — good) with one small extractor class per platform under `Services/Automation/Facts/Platforms/`, each implementing:

```php
interface PlatformOrderFactExtractor {
    public function country(array $raw): ?string;        // ISO-3166 alpha-2, UPPER
    public function city(array $raw): ?string;
    public function paymentMethod(array $raw): string;   // normalised vocabulary
    public function weightGrams(array $raw): ?int;
    public function shippingSpeed(array $raw): string;
    public function carrier(array $raw): ?string;
}
```

| Platform | Country | City | Payment method → normalised | Weight | Shipping speed |
| --- | --- | --- | --- | --- | --- |
| **Shopify** | `shipping_address.country_code` | `shipping_address.city` | `payment_gateway_names[0]`; `cash_on_delivery`/`cod`/`Cash on Delivery (COD)` → `cod`; `manual`→`bank_transfer`; else `card` | `total_weight` (already grams) | `shipping_lines[0].code`/`.title` matched against config regex map |
| **Salla** | `shipping.address.country_code` ?? `ship_to.country_code` | `shipping.address.city` ?? `ship_to.city` | `payment_method`; Salla emits the literal `cod` → `cod`; `credit_card`/`mada`→`card`; `apple_pay`/`stc_pay`→`wallet`; `tabby`/`tamara`→`bnpl` | `weight.value` × unit factor (`kg`→1000, `g`→1) | `shipping.company` / `shipping.option` |
| **Zid** | `shipping.address.country.code` ?? `customer.country_code` | `shipping.address.city.name` | `payment_method`; `cash_on_delivery` → `cod` | `order_total_weight` (kg) × 1000 | `shipping.delivery_option` |
| **Amazon (SP-API)** | `ShippingAddress.CountryCode` | `ShippingAddress.City` | `PaymentMethod` (`COD` → `cod`, `CBA`/`Other` → `marketplace`) or `PaymentMethodDetails[]` | not provided → `null` | `ShipmentServiceLevelCategory`: `NextDay`/`SameDay`→`same_day`, `Expedited`/`SecondDay`→`express`, `Standard`→`standard`, `Scheduled`→`scheduled` |
| **Noon** | `shipping_address.country_code` ?? `address.country` | `shipping_address.city` | `payment_type` / `is_cod` flag → `cod`; `credit_card`→`card` | `total_weight` (grams, verify) | `delivery_type` / `sla_type` |
| **WooCommerce** | `shipping.country` (falls back to `billing.country`) | `shipping.city` ?? `billing.city` | `payment_method` — Woo's built-in COD gateway id is literally `cod` → `cod`; `bacs`→`bank_transfer`; `cheque`→`bank_transfer`; else `card` | sum of `line_items[].meta` weight, or `null` (Woo often omits it) | `shipping_lines[0].method_id` |
| **Trendyol** | `shipmentAddress.countryCode` ?? `invoiceAddress.countryCode` (default `TR`) | `shipmentAddress.city` | Trendyol collects payment; always `marketplace` (**no COD**) | `lines[].productSize`/weight rarely present → `null` | `fastDeliveryType` present → `express`, `deliveryType` `SAME_DAY` → `same_day`, else `standard` |

Rules of the road for the resolver:
- Any missing value is `null` (or `'unknown'` for the enum-typed `payment_method`/`shipping_speed`) — **never** a guess, never a default that could accidentally satisfy a condition. §3.5's null semantics then make the condition safely false.
- Country codes are normalised: full names ("Saudi Arabia", "السعودية") are mapped via a lookup table before comparison; unmapped → `null` + a `facts_warning` recorded on the run.
- Each extractor gets a golden-payload unit test with a real captured payload committed to `tests/Fixtures/orders/{platform}.json`.
- The resolver is a **pure function of `raw_data` + DB joins** — no network calls. This is what makes simulation fast and safe.

### 6.3 Other hook points

| Where | File:line (current) | Change |
| --- | --- | --- |
| Manual status change | `OrderController::update()` — after `$order->update(['status' => …])` (line 76) | `event(new OrderStatusChanged($order, $oldStatus, 'api'))` — `$oldStatus` is already captured on line 75 |
| Sync failure | `SyncOrdersJob` catch block (line 93–103) and the equivalent in `SyncProductsJob`/`SyncInventoryJob` | after `$log->update(['status'=>'failed', ...])`, `event(new SyncFailed($log, $e->getMessage()))` |
| Stock threshold | `SyncInventoryJob` + `InventoryController::adjust()` + `PushInventoryJob` | after any write to `product_variants.stock` / `products.stock`, if new value `<= low_stock_threshold` **and** `automation_low_stock_notified_at` is null-or-older-than-24 h → `event(new StockDroppedBelowThreshold(...))` (hysteresis, §10.7) |
| Order model observer | new `app/Observers/OrderObserver.php` | safety net: `updated` → dispatch `order.updated` **only** when `! Automation::applying()` and the change didn't originate from `SyncOrdersJob` (which already dispatches). Guarded by `config('automation.observer_enabled')`, default `false` for v1 to avoid double-firing; enable after ingest paths are proven |

---

## 7. Dashboard

### 7.1 Routes & files

```
src/app/(dashboard)/automation/page.tsx                  Rule list
src/app/(dashboard)/automation/new/page.tsx              Create (builder)
src/app/(dashboard)/automation/[ruleId]/edit/page.tsx    Edit (builder)
src/app/(dashboard)/automation/runs/page.tsx             Run history / audit
src/app/(dashboard)/automation/runs/[runId]/page.tsx     Run detail
```

Components under `src/components/automation/`:
`RuleList.tsx` · `RuleCard.tsx` · `RuleBuilder.tsx` · `TriggerPicker.tsx` · `ConditionGroup.tsx` (recursive) · `ConditionLeaf.tsx` · `FieldPicker.tsx` · `OperatorPicker.tsx` · `ValueInput.tsx` (polymorphic by field type: number, currency, multiselect, country picker, SKU tag input, boolean toggle) · `ActionList.tsx` · `ActionEditor.tsx` (one params form per action type) · `RuleSimulator.tsx` · `SimulationResultRow.tsx` (with the trace table) · `RunHistoryTable.tsx` · `RunDetailDrawer.tsx` · `RuleTemplateGallery.tsx` · `PriorityDragList.tsx`.

Data fetching via `api` from `src/lib/api` (org header is automatic). Reuse `Card`, `Button`, `Input`, `Modal`, `Toast` from `src/components/ui`.

Nav: add `{ icon: Workflow, key: 'automation', href: '/automation' }` to `NAV` in `src/components/layout/Sidebar.tsx` (between `inventory` and `stores`; `Workflow` from `lucide-react`).

### 7.2 Rule list

Table/cards, grouped by trigger, drag-to-reorder within a trigger group (writes `POST /automation/rules/reorder`). Per row: name · plain-language condition summary · action chips · enabled toggle · `LIVE`/`DRY RUN` badge · matched/applied counters · last run · overflow menu (Edit, Duplicate, Simulate, View runs, Delete).

Empty state uses the existing `connect`-style empty pattern and offers the **template gallery** (§12.3) as the primary CTA — a merchant should get a working rule in under 60 seconds.

### 7.3 Rule builder

Three stacked sections, no canvas:

1. **When** — trigger radio cards with descriptions. Changing the trigger re-filters the field catalogue and warns if existing conditions become invalid.
2. **If** — recursive `ConditionGroup`. Each group has an `ALL`/`ANY` segmented toggle and `+ Condition` / `+ Group` buttons (the latter hidden at depth 3). Each leaf: field select (grouped by `order.*` / `stock.*` with i18n labels) → operator select (filtered by field type) → value input (typed). Live plain-language preview under the group ("Channel is Salla or Zid **and** payment is COD **and** total ≥ 1,500 SAR") with correct Arabic ordering.
3. **Then** — ordered, drag-sortable action list. Each action expands to its own params form. Actions that terminate the chain (`split_order`, `stop_processing`) are visually marked and warn if not last. Deferred actions show a "runs in background" hint.

Sticky footer: `Simulate` (opens the tester in a drawer without saving) · `Save as draft (dry run)` · `Save & enable`. `Save & enable` shows a confirm modal listing the actions that will start running for real.

Client-side mirrors of the server limits, but the server is authoritative; `422` errors are mapped back onto the offending condition/action by the `path` in the error key (`conditions.rules.2.value`).

### 7.4 Simulation view

Runs `POST /automation/simulate` against the last *N* orders (default 50, selector 10/50/100/200). Header stat row: evaluated · matched · match rate · duration. Then a list of orders, matched ones first, each expandable to:
- **Why** — the trace table: field · operator · expected · actual · ✓/✗, with failing leaves highlighted. This is the trust-builder.
- **What would happen** — the ordered `would_apply` summaries, plus an `Already applied` badge when the ledger already has a matching fingerprint.

A prominent, permanent banner: "Simulation only — nothing was changed." (`automation.simulate.noChangesBanner`).

### 7.5 Run history / audit

Filters: rule · outcome · source · date range · order id. Columns: time · rule · subject (links to the order) · outcome badge · actions applied (chips) · duration · source. Row click opens `RunDetailDrawer` with the trace, the facts snapshot (collapsible JSON), and per-action status/error. A `Retry action` button on failed deferred actions (owner/admin only) re-dispatches `ApplyAutomationActionJob`.

Also: an **Automation** tab on the order detail page showing `GET /automation/runs?subject_type=order&subject_id={id}` — the per-order "why is this order tagged/held?" answer.

### 7.6 i18n

New file `src/i18n/dicts/automation.ts` exporting `{ en, ar }`, registered in `src/i18n/dictionary.ts` (`automation: automation.en` / `automation.ar`), plus `nav.automation` in `common.ts`.

```ts
export const automation = {
  en: {
    title: 'Automation',
    subtitle: 'Rules that act on your orders automatically — unlimited, on every plan.',
    newRule: 'New rule',
    fromTemplate: 'Start from a template',
    empty: { title: 'No automation rules yet',
             body: 'Create a rule to tag, route, hold or notify automatically as orders arrive.',
             cta: 'Create your first rule' },
    badges: { live: 'Live', dryRun: 'Dry run', disabled: 'Disabled', stopsChain: 'Stops other rules' },
    list: { columns: { name: 'Rule', trigger: 'When', conditions: 'If', actions: 'Then',
                       matched: 'Matched', lastRun: 'Last run', enabled: 'Enabled' },
            reorderHint: 'Drag to change the order rules run in. Rules run top to bottom.' },
    builder: {
      when: 'When', if: 'If', then: 'Then',
      addCondition: 'Add condition', addGroup: 'Add group',
      addAction: 'Add action', matchAll: 'ALL of these', matchAny: 'ANY of these',
      namePlaceholder: 'e.g. COD over 1,500 SAR → hold for confirmation',
      previewPrefix: 'This rule runs when', unknownNeverMatches: 'Unknown values never match a condition.',
      terminatesWarning: 'This action stops any further rules for this order.',
      saveDraft: 'Save as draft (dry run)', saveEnable: 'Save & enable',
      enableConfirm: { title: 'Enable this rule?',
                       body: 'From now on it will act on matching orders for real. You can switch it back to dry run at any time.' },
    },
    triggers: { orderCreated: 'An order is created', orderUpdated: 'An order is updated',
                orderStatusChanged: 'An order status changes', stockBelowThreshold: 'Stock drops below its threshold',
                syncFailed: 'A sync fails' },
    fields: { orderChannel: 'Sales channel', orderStore: 'Store', orderTotal: 'Order total',
              orderCurrency: 'Currency', orderItemCount: 'Number of items', orderTotalQuantity: 'Total quantity',
              orderWeight: 'Order weight (g)', orderSkus: 'SKU', orderCategories: 'Category',
              orderTags: 'Tags', orderPaymentMethod: 'Payment method', orderIsCod: 'Cash on delivery (COD)',
              orderShippingCountry: 'Destination country', orderShippingCity: 'Destination city',
              orderShippingSpeed: 'Delivery speed', orderCustomerType: 'Customer type',
              orderCustomerOrderCount: 'Previous orders', orderStatus: 'Order status',
              orderPreviousStatus: 'Previous status', orderMinStock: 'Lowest stock in order',
              stockSku: 'SKU', stockAvailable: 'Available stock', stockThreshold: 'Threshold',
              syncPlatform: 'Platform', syncType: 'Sync type', syncError: 'Error message' },
    operators: { eq: 'is', neq: 'is not', gt: 'is greater than', gte: 'is at least',
                 lt: 'is less than', lte: 'is at most', between: 'is between',
                 in: 'is one of', not_in: 'is none of', contains: 'contains', not_contains: 'does not contain',
                 starts_with: 'starts with', ends_with: 'ends with', matches: 'matches pattern',
                 is_empty: 'is empty', is_not_empty: 'is not empty',
                 any_of: 'includes any of', all_of: 'includes all of', none_of: 'includes none of',
                 is_true: 'is yes', is_false: 'is no' },
    actions: { addTag: 'Add tag', removeTag: 'Remove tag', routeLocation: 'Route to location',
               assignCarrier: 'Assign carrier', splitOrder: 'Split order', holdOrder: 'Hold for review',
               releaseHold: 'Release hold', setStatus: 'Set status', assignFolder: 'Move to folder',
               addNote: 'Add internal note', notify: 'Send notification', callWebhook: 'Call webhook',
               stopProcessing: 'Stop processing further rules' },
    customerType: { new: 'New customer', repeat: 'Repeat customer' },
    paymentMethod: { cod: 'Cash on delivery', card: 'Card', wallet: 'Wallet',
                     bank_transfer: 'Bank transfer', bnpl: 'Buy now, pay later',
                     marketplace: 'Collected by marketplace', unknown: 'Unknown' },
    shippingSpeed: { standard: 'Standard', express: 'Express', same_day: 'Same day',
                     scheduled: 'Scheduled', unknown: 'Unknown' },
    simulate: { title: 'Test this rule', run: 'Run test', sampleSize: 'Test against the last',
                orders: 'orders', evaluated: 'Evaluated', matched: 'Matched', matchRate: 'Match rate',
                noChangesBanner: 'Simulation only — no orders were changed.',
                why: 'Why it matched', whyNot: 'Why it did not match',
                wouldApply: 'What would happen', alreadyApplied: 'Already applied',
                expected: 'Expected', actual: 'Actual', noMatches: 'No orders matched this rule.' },
    runs: { title: 'Run history', subtitle: 'Every decision the automation engine made.',
            columns: { time: 'Time', rule: 'Rule', subject: 'Order', outcome: 'Outcome',
                       actions: 'Actions', duration: 'Duration', source: 'Source' },
            outcome: { matched: 'Applied', skipped: 'No match', partial: 'Partly applied',
                       failed: 'Failed', simulated: 'Dry run', deduped: 'Already applied' },
            source: { sync: 'Sync', webhook: 'Webhook', manual: 'Manual', backfill: 'Backfill',
                      simulation: 'Simulation', api: 'API' },
            retryAction: 'Retry this action', empty: 'No runs recorded yet.' },
    apply: { title: 'Apply to existing orders', body: 'Run this rule against orders you already have.',
             confirm: 'Apply now', queued: 'Queued — you will be notified when it finishes.' },
    warnings: { possibleLoop: 'These rules may trigger each other in a loop. Review the order they run in.',
                terminatingNotLast: 'An action that stops processing is not the last action.',
                whatsappUnavailable: 'WhatsApp notifications are not configured yet.',
                noConditions: 'This rule has no conditions — it will match every order.' },
    toast: { created: 'Rule created.', updated: 'Rule updated.', deleted: 'Rule deleted.',
             enabled: 'Rule enabled.', disabled: 'Rule disabled.', reordered: 'Order updated.',
             saveFailed: 'Could not save the rule.', limitReached: 'You have reached the rule limit.' },
  },
  ar: {
    title: 'الأتمتة',
    subtitle: 'قواعد تتعامل مع طلباتك تلقائياً — غير محدودة، وفي كل الباقات.',
    newRule: 'قاعدة جديدة',
    fromTemplate: 'ابدأ من قالب جاهز',
    empty: { title: 'لا توجد قواعد أتمتة بعد',
             body: 'أنشئ قاعدة لإضافة وسم أو التوجيه أو التعليق أو الإشعار تلقائياً عند وصول الطلبات.',
             cta: 'أنشئ أول قاعدة' },
    badges: { live: 'مُفعّلة', dryRun: 'تجريبية', disabled: 'موقوفة', stopsChain: 'توقف باقي القواعد' },
    list: { columns: { name: 'القاعدة', trigger: 'متى', conditions: 'إذا', actions: 'إذاً',
                       matched: 'المطابقات', lastRun: 'آخر تشغيل', enabled: 'مُفعّلة' },
            reorderHint: 'اسحب لتغيير ترتيب تنفيذ القواعد. تُنفَّذ من الأعلى إلى الأسفل.' },
    builder: {
      when: 'متى', if: 'إذا', then: 'إذاً',
      addCondition: 'أضف شرطاً', addGroup: 'أضف مجموعة',
      addAction: 'أضف إجراءً', matchAll: 'كل هذه الشروط', matchAny: 'أي من هذه الشروط',
      namePlaceholder: 'مثال: الدفع عند الاستلام فوق ١٥٠٠ ر.س ← تعليق للتأكيد',
      previewPrefix: 'تعمل هذه القاعدة عندما', unknownNeverMatches: 'القيم غير المعروفة لا تطابق أي شرط.',
      terminatesWarning: 'هذا الإجراء يوقف تنفيذ باقي القواعد على هذا الطلب.',
      saveDraft: 'حفظ كمسودة (وضع تجريبي)', saveEnable: 'حفظ وتفعيل',
      enableConfirm: { title: 'تفعيل هذه القاعدة؟',
                       body: 'ستبدأ بالتنفيذ فعلياً على الطلبات المطابقة. يمكنك إعادتها للوضع التجريبي في أي وقت.' },
    },
    triggers: { orderCreated: 'عند إنشاء طلب', orderUpdated: 'عند تحديث طلب',
                orderStatusChanged: 'عند تغيّر حالة الطلب', stockBelowThreshold: 'عند انخفاض المخزون عن الحد',
                syncFailed: 'عند فشل المزامنة' },
    fields: { orderChannel: 'قناة البيع', orderStore: 'المتجر', orderTotal: 'إجمالي الطلب',
              orderCurrency: 'العملة', orderItemCount: 'عدد الأصناف', orderTotalQuantity: 'إجمالي الكمية',
              orderWeight: 'وزن الطلب (جم)', orderSkus: 'رمز المنتج (SKU)', orderCategories: 'الفئة',
              orderTags: 'الوسوم', orderPaymentMethod: 'طريقة الدفع', orderIsCod: 'الدفع عند الاستلام',
              orderShippingCountry: 'دولة الشحن', orderShippingCity: 'مدينة الشحن',
              orderShippingSpeed: 'سرعة التوصيل', orderCustomerType: 'نوع العميل',
              orderCustomerOrderCount: 'الطلبات السابقة', orderStatus: 'حالة الطلب',
              orderPreviousStatus: 'الحالة السابقة', orderMinStock: 'أقل مخزون في الطلب',
              stockSku: 'رمز المنتج', stockAvailable: 'المخزون المتاح', stockThreshold: 'الحد الأدنى',
              syncPlatform: 'المنصة', syncType: 'نوع المزامنة', syncError: 'رسالة الخطأ' },
    operators: { eq: 'يساوي', neq: 'لا يساوي', gt: 'أكبر من', gte: 'لا يقل عن',
                 lt: 'أقل من', lte: 'لا يزيد عن', between: 'بين',
                 in: 'أحد', not_in: 'ليس من', contains: 'يحتوي على', not_contains: 'لا يحتوي على',
                 starts_with: 'يبدأ بـ', ends_with: 'ينتهي بـ', matches: 'يطابق النمط',
                 is_empty: 'فارغ', is_not_empty: 'غير فارغ',
                 any_of: 'يتضمن أياً من', all_of: 'يتضمن كل', none_of: 'لا يتضمن أياً من',
                 is_true: 'نعم', is_false: 'لا' },
    actions: { addTag: 'إضافة وسم', removeTag: 'إزالة وسم', routeLocation: 'التوجيه إلى موقع',
               assignCarrier: 'تعيين شركة شحن', splitOrder: 'تقسيم الطلب', holdOrder: 'تعليق للمراجعة',
               releaseHold: 'إلغاء التعليق', setStatus: 'تغيير الحالة', assignFolder: 'نقل إلى مجلد',
               addNote: 'إضافة ملاحظة داخلية', notify: 'إرسال إشعار', callWebhook: 'استدعاء Webhook',
               stopProcessing: 'إيقاف تنفيذ باقي القواعد' },
    customerType: { new: 'عميل جديد', repeat: 'عميل متكرر' },
    paymentMethod: { cod: 'الدفع عند الاستلام', card: 'بطاقة', wallet: 'محفظة',
                     bank_transfer: 'تحويل بنكي', bnpl: 'اشترِ الآن وادفع لاحقاً',
                     marketplace: 'محصّل من المنصة', unknown: 'غير معروف' },
    shippingSpeed: { standard: 'عادي', express: 'سريع', same_day: 'نفس اليوم',
                     scheduled: 'مجدول', unknown: 'غير معروف' },
    simulate: { title: 'اختبر هذه القاعدة', run: 'تشغيل الاختبار', sampleSize: 'اختبر على آخر',
                orders: 'طلب', evaluated: 'تم فحصها', matched: 'مطابقة', matchRate: 'نسبة المطابقة',
                noChangesBanner: 'اختبار فقط — لم يتم تعديل أي طلب.',
                why: 'سبب المطابقة', whyNot: 'سبب عدم المطابقة',
                wouldApply: 'ما الذي سيحدث', alreadyApplied: 'مُطبّقة مسبقاً',
                expected: 'المتوقع', actual: 'الفعلي', noMatches: 'لا توجد طلبات مطابقة لهذه القاعدة.' },
    runs: { title: 'سجل التشغيل', subtitle: 'كل قرار اتخذه محرك الأتمتة.',
            columns: { time: 'الوقت', rule: 'القاعدة', subject: 'الطلب', outcome: 'النتيجة',
                       actions: 'الإجراءات', duration: 'المدة', source: 'المصدر' },
            outcome: { matched: 'تم التطبيق', skipped: 'لا تطابق', partial: 'تطبيق جزئي',
                       failed: 'فشل', simulated: 'وضع تجريبي', deduped: 'مُطبّقة مسبقاً' },
            source: { sync: 'مزامنة', webhook: 'Webhook', manual: 'يدوي', backfill: 'تطبيق رجعي',
                      simulation: 'اختبار', api: 'API' },
            retryAction: 'إعادة محاولة هذا الإجراء', empty: 'لا توجد عمليات تشغيل بعد.' },
    apply: { title: 'التطبيق على الطلبات الحالية', body: 'شغّل هذه القاعدة على الطلبات الموجودة لديك.',
             confirm: 'طبّق الآن', queued: 'تمت الجدولة — سنُشعرك عند الانتهاء.' },
    warnings: { possibleLoop: 'قد تُشغّل هذه القواعد بعضها في حلقة مفرغة. راجع ترتيب تنفيذها.',
                terminatingNotLast: 'يوجد إجراء يوقف التنفيذ وليس آخر إجراء في القائمة.',
                whatsappUnavailable: 'إشعارات واتساب غير مهيأة بعد.',
                noConditions: 'هذه القاعدة بلا شروط — ستطابق كل الطلبات.' },
    toast: { created: 'تم إنشاء القاعدة.', updated: 'تم تحديث القاعدة.', deleted: 'تم حذف القاعدة.',
             enabled: 'تم تفعيل القاعدة.', disabled: 'تم إيقاف القاعدة.', reordered: 'تم تحديث الترتيب.',
             saveFailed: 'تعذّر حفظ القاعدة.', limitReached: 'وصلت إلى الحد الأقصى لعدد القواعد.' },
  },
} as const;
```

`common.ts` additions: `nav.automation: 'Automation'` / `'الأتمتة'`.

**RTL notes:** the condition builder is a horizontal `field → operator → value` row; under `dir="rtl"` it must read right-to-left. Use logical properties (`ms-*`, `me-*`, `ps-*`, `pe-*`) throughout and never `ml-*`/`pl-*`. Numbers and SKUs stay LTR inside RTL text — wrap them in `<span dir="ltr" className="inline-block">`. The drag-reorder handle sits on the inline-start side in both directions.

---

## 8. Mobile (Flutter)

Deliberately **read-and-react only**. Building a rule builder on a phone is not where the value is; reacting to what rules did is.

New `mobile/lib/features/automation/`:

1. **Held-orders queue** — the highest-value surface. A list driven by `GET /orders?is_held=1` (requires a small filter addition to `OrderController::applyFilters`) showing hold reason, order value, COD badge, customer phone. Actions: **Release hold** and **Cancel**, both hitting existing endpoints. This is a warehouse supervisor's morning triage — exactly the "mobile warehouse ops" advantage §5.8 of the strategy doc calls out.
2. **Order detail → Automation section** — the runs list for that order (`GET /automation/runs?subject_type=order&subject_id=`) answering "why is this held/tagged?", plus tags, folder, location, carrier chips.
3. **Notifications** — rule `notify` actions already land in the existing `Notification` table, so `features/notifications` shows them with **no mobile work** beyond a `type`-based icon and a deep link to the order.
4. **Rule enable/disable toggle** (read-only list under `features/more`) — an ops lead needs to be able to kill a misbehaving rule from anywhere. `GET /automation/rules` + `POST /automation/rules/{id}/toggle`, owner/admin only, with a confirm dialog.

**Not on mobile:** rule creation, editing, condition building, simulation, backfill.
Arabic strings go in the existing `mobile/lib/l10n` ARB files, mirroring §7.6 keys.

---

## 9. Permissions & multi-tenancy

### Tenancy

- Every automation table carries `organization_id` and every query filters on the `X-Organization-Id` header, validated by `org.member` (which already proves the caller is a member).
- `findOrFail` is **always** preceded by `->where('organization_id', $organizationId)` — a cross-org id returns `404`, not `403` (no existence leak). Same pattern as `NotificationController`.
- `RuleRepository` cache keys are org-scoped (`automation:rules:{org}:{trigger}`); the Redis subject lock is order-scoped (globally unique ids).
- Actions may only reference org-owned entities: `store_id` values in conditions, `category_id` values, and `notify.recipients.users[]` are all validated to belong to the org at save time **and** re-validated at apply time (a user may have been removed since).
- Simulation may only read orders belonging to the org (`whereHas('store', …)` / `organization_id`).
- `automation_runs.facts` contains customer email/city — it is org-scoped PII and must never appear in a cross-org response or in application logs at `info` level. Log only `rule_id`, `subject_id`, `outcome`, `correlation_id`.

### Roles (`organization_user.role`: `owner` | `admin` | `viewer`)

| Capability | owner | admin | viewer |
| --- | :-: | :-: | :-: |
| View rules, run history, schema | ✅ | ✅ | ✅ |
| Simulate (saved or draft) | ✅ | ✅ | ✅ |
| Create / edit / delete rules | ✅ | ✅ | ❌ |
| Enable / disable, change `run_mode` | ✅ | ✅ | ❌ |
| Reorder | ✅ | ✅ | ❌ |
| Apply to existing orders (backfill) | ✅ | ❌ | ❌ |
| Retry a failed deferred action | ✅ | ✅ | ❌ |
| Configure the outbound-webhook secret | ✅ | ❌ | ❌ |

Backfill and the webhook secret are owner-only because they are the two irreversible/security-sensitive operations.

Enforced by `app/Policies/AutomationRulePolicy.php` (`viewAny`, `view`, `create`, `update`, `delete`, `toggle`, `backfill`) registered in `AppServiceProvider`, resolving the actor's role via the `organization_user` pivot exactly as `OrganizationController::roleOf()` does. **Note:** `spatie/laravel-permission` is installed and `User` uses `HasRoles`, but real authorisation in this codebase lives on the pivot — the policy must use the pivot, not Spatie roles, or it will silently authorise nobody.

Denials return `403 {"message": "You do not have permission to change automation rules."}`, matching `OrganizationController`'s wording style.

---

## 10. Edge cases & failure modes

| # | Case | Behaviour |
| --- | --- | --- |
| 1 | **Action fails mid-chain** | The rule's transaction rolls back all *local* mutations from that rule. `on_error: "continue"` lets the dispatcher proceed to the next action, marking that one `failed` and the run `partial`; `on_error: "stop"` aborts the rule and marks it `failed`. Earlier rules in the pass stay committed. The idempotency claim is **released** on `failed` so a retry can re-apply. An org notification fires on the 3rd consecutive failure of the same rule (not the 1st — avoid alert fatigue). |
| 2 | **Deferred action fails after commit** (webhook 500, mail down) | The local mutations already committed; only the deferred job retries (3× exponential backoff). Final failure PATCHes `actions_applied[i].status = 'failed'` and downgrades the run to `partial`. A rule is never rolled back because a webhook was down. |
| 3 | **Carrier unavailable / not in allowlist** | `assign_carrier` validates at save time against `config('automation.carriers')`; if the carrier is later removed from config, the action fails with `carrier_unavailable`, the run is `partial`, and the rule is **not** auto-disabled — but the list UI shows a red "action misconfigured" badge. Since v1 only writes a string (A3), this is metadata-only; nothing is booked. |
| 4 | **Two rules set conflicting values** (both `set_status`, or two `route_location`) | Last-write-wins by priority — the **higher-priority (lower number) rule runs first, the later one overwrites it**. This is Linnworks-equivalent and is documented in the builder. `RuleGraphAnalyzer` raises a save-time warning when two enabled rules with overlapping conditions write the same field. `stop_processing` is the escape hatch. |
| 5 | **Rule loops** (A sets `shipped`, B on `shipped` sets `processing`) | Four independent guards, §4.6. Worst case: 3 chained passes then a hard stop + one warning notification. Never unbounded. |
| 6 | **Historical backfill on 100k orders** | Only via `POST /automation/rules/{id}/apply`, owner-only, `confirm: true`, capped at 5,000 per call and 20,000/org/day, chunked 200 with a 2 s delay on a dedicated low-priority queue. **Deferred actions are disabled during backfill by default** (`notify`/`call_webhook` are skipped with status `skipped_backfill`) so a backfill never emails 5,000 notifications — overridable by an explicit `"include_notifications": true` flag with a scary confirm. |
| 7 | **Stock threshold flapping** (stock oscillates around the threshold on every 5-minute `SyncInventoryJob`) | Hysteresis: the event fires only if `automation_low_stock_notified_at` is null or > 24 h old, **and** re-arms only once stock rises above `threshold × 1.2`. |
| 8 | **Order re-synced with no material change** | Fingerprint identical → `deduped`, zero side effects. This is the single most important correctness property (§11 has a dedicated test). |
| 9 | **Rule edited while a job is in flight** | The job holds the rule model it loaded; it completes against that version. The bumped `version` changes the fingerprint, so the next trigger re-applies. No mid-pass version mixing. |
| 10 | **Missing facts** (Trendyol sends no weight, Woo sends no country) | Fact is `null`; §3.5 null semantics make the condition false. The run records a `facts_warning`, and the simulator shows "Unknown" in the trace's *actual* column so the merchant sees exactly why nothing matched. |
| 11 | **Order deleted / store disconnected between dispatch and execution** | `EvaluateAutomationRulesJob` re-fetches the subject; a missing subject → job returns silently (no `ModelNotFoundException` retry storm). |
| 12 | **Concurrent passes on the same order** (sync + webhook race) | Redis lock `automation:subject:order:{id}`, 5 s block then re-queue with a 10 s delay. Serialised, never dropped. |
| 13 | **`split_order` on an already-split order** | Blocked by both the ledger and an explicit `children()->exists()` check → `already_split`. |
| 14 | **`set_status` to a status the platform rejects** | Local status is written; the platform push is deferred and may fail → run becomes `partial`, and the order gets an auto-note `"Status pushed to {platform} failed: …"`. Local and remote can diverge; the next sync reconciles, which may re-trigger `order.status_changed` — depth guard caps the ping-pong at 3. |
| 15 | **Webhook action pointed at an internal address** | `UrlGuard` rejects at save time **and** at request time (DNS rebinding). `422 {"errors":{"actions.0.params.url":["…private address…"]}}`. |
| 16 | **Org exceeds the rule cap mid-import** | `POST` returns `429` with `code: rule_limit_reached`; existing rules keep running. |
| 17 | **A rule with empty conditions** | Legal (matches everything) but produces a save-time warning `automation.warnings.noConditions`, and the enable-confirm modal states how many of the last 100 orders would have matched. |
| 18 | **Notification recipient removed from the org** | Re-validated at apply time; unknown recipients are dropped and noted in `actions_applied`; the action still succeeds if ≥1 valid recipient remains, else `failed`. |
| 19 | **Multi-currency thresholds** (`total >= 1500` across SAR/AED/USD stores) | `order.total` is raw store currency. The builder **requires** a `order.currency` condition alongside any `order.total` condition, or shows a warning. `order.total_base` exists for the converted comparison but is `null` without an FX source (§15 Q4). |
| 20 | **Queue backlog** | Evaluation is idempotent and order-independent, so a backlog only delays actions. Horizon alerting on the `automation` queue wait time; SLO in §13. |

---

## 11. Testing

Per the project memory: run PHPUnit in Docker forcing sqlite (`-e DB_CONNECTION=sqlite`). **Consequence:** all automation queries must be sqlite-compatible — no MySQL-only JSON functions. Use PHP-side filtering on decoded `array` casts rather than `whereJsonContains` where behaviour differs, and add a `@group mysql-only` guard on anything that can't be.

### Unit — `tests/Unit/Automation/`

`ConditionEvaluatorTest`
- `test_eq_operator_is_case_insensitive_by_default`
- `test_null_actual_never_matches_comparison_operators` (the critical one: `null < 5000` is **false**)
- `test_null_actual_matches_is_empty_and_none_of`
- `test_numeric_string_actual_is_coerced` (`"1850.00" >= 1500`)
- `test_non_numeric_actual_on_numeric_field_returns_false_not_exception`
- `test_between_is_inclusive`
- `test_any_of_supports_glob_in_values` (`FRAGILE-*` matches `FRAGILE-01`)
- `test_country_codes_are_normalised_before_compare` (`sa` vs `SA`)
- `test_city_match_ignores_case_and_extra_whitespace`
- `test_unknown_field_yields_false_and_records_error`
- `test_regex_operator_rejects_catastrophic_pattern`

`RuleEvaluatorTest`
- `test_all_group_requires_every_child`
- `test_any_group_requires_one_child`
- `test_nested_groups_three_levels_deep`
- `test_empty_rules_array_matches_everything`
- `test_negate_inverts_a_leaf`
- `test_trace_records_every_evaluated_leaf_with_actual_values`
- `test_short_circuit_marks_remaining_leaves_not_evaluated`

`OrderFactResolverTest` — one per platform, against `tests/Fixtures/orders/{platform}.json`
- `test_shopify_extracts_country_city_cod_and_weight`
- `test_salla_cod_payment_method_is_normalised`
- `test_woocommerce_cod_gateway_id_maps_to_cod`
- `test_amazon_shipment_service_level_maps_to_shipping_speed`
- `test_trendyol_has_no_cod_and_defaults_to_marketplace`
- `test_noon_and_zid_country_fallbacks`
- `test_missing_weight_resolves_to_null_not_zero`
- `test_customer_type_is_repeat_when_email_has_prior_orders`
- `test_category_ids_resolve_through_sku_to_product`

`FingerprintTest`
- `test_same_material_facts_produce_same_fingerprint`
- `test_immaterial_field_change_does_not_change_fingerprint` (customer name edit)
- `test_material_field_change_changes_fingerprint` (total 500 → 2000)
- `test_rule_version_bump_changes_fingerprint`
- `test_rule_rename_does_not_change_fingerprint`

`FactTemplateRendererTest`
- `test_renders_whitelisted_placeholders`
- `test_unknown_placeholder_renders_empty_and_warns`
- `test_does_not_evaluate_php_or_blade`

`RuleGraphAnalyzerTest`
- `test_detects_two_rule_status_cycle`
- `test_does_not_flag_a_linear_status_progression`

### Feature — `tests/Feature/`

`AutomationRuleApiTest`
- `test_admin_can_create_a_rule`
- `test_viewer_cannot_create_a_rule` (403)
- `test_viewer_can_simulate`
- `test_rule_from_another_org_returns_404`
- `test_missing_org_header_returns_400` (existing middleware)
- `test_invalid_condition_tree_returns_422_with_field_paths`
- `test_condition_nesting_deeper_than_three_is_rejected`
- `test_more_than_twenty_actions_is_rejected`
- `test_rule_cap_returns_429`
- `test_new_rules_default_to_disabled_and_dry_run`
- `test_editing_conditions_bumps_version`
- `test_renaming_does_not_bump_version`
- `test_reorder_updates_priorities_in_one_transaction`
- `test_webhook_action_rejects_private_ip_url`
- `test_only_owner_can_backfill`

`AutomationEvaluationTest`
- `test_order_created_via_sync_triggers_matching_rule`
- `test_rules_run_in_priority_order`
- `test_second_rule_sees_tag_added_by_first_rule` (chaining + fact refresh)
- `test_stop_processing_halts_the_chain`
- `test_disabled_rule_never_runs`
- `test_dry_run_rule_records_a_run_but_mutates_nothing`
- `test_rule_from_another_org_never_evaluates_this_orgs_order`
- `test_cod_over_threshold_holds_order_and_creates_notification` (the flagship end-to-end)
- `test_sync_failure_triggers_sync_failed_rule`
- `test_stock_below_threshold_triggers_once_then_respects_hysteresis`
- `test_status_change_via_order_controller_triggers_status_changed_rule`

`AutomationIdempotencyTest`
- `test_resyncing_an_unchanged_order_does_not_reapply` (**the headline test**: run sync twice, assert exactly one `Notification`, one `matched` run, one `deduped` run)
- `test_material_change_reapplies`
- `test_failed_rule_releases_the_claim_and_retry_reapplies`
- `test_concurrent_evaluations_are_serialised_by_the_lock`

`AutomationLoopPreventionTest`
- `test_chain_depth_is_capped_at_three`
- `test_burst_cap_halts_runaway_passes_and_notifies_admins`
- `test_actions_applied_by_automation_do_not_retrigger_via_observer`

`AutomationActionsTest`
- `test_add_tag_is_a_set_union_and_deduplicates`
- `test_hold_order_is_a_noop_when_already_held`
- `test_split_order_creates_children_with_unique_external_ids_and_retains_parent`
- `test_split_order_terminates_the_chain`
- `test_set_status_queues_platform_push_only_when_flagged`
- `test_notify_writes_an_in_app_notification_scoped_to_the_org`
- `test_call_webhook_is_signed_and_carries_an_idempotency_key` (`Http::fake` + assert headers)
- `test_deferred_actions_are_queued_after_commit_not_inline` (`Queue::fake`, assert `Notification` count is 0 inside the transaction)
- `test_action_failure_with_on_error_stop_rolls_back_the_rule`
- `test_action_failure_with_on_error_continue_marks_run_partial`

`AutomationSimulationTest`
- `test_simulation_returns_trace_and_would_apply`
- `test_simulation_mutates_nothing` (snapshot all order columns before/after)
- `test_simulation_creates_no_ledger_rows_and_no_queued_jobs`
- `test_simulation_of_an_unsaved_draft_rule`
- `test_simulation_flags_already_applied_orders`
- `test_simulation_is_rate_limited`

`AutomationAuditTest`
- `test_run_records_facts_trace_and_actions`
- `test_runs_are_org_scoped`
- `test_non_matching_runs_are_not_persisted_by_default`
- `test_deleting_a_rule_preserves_its_runs`
- `test_prune_job_removes_runs_older_than_retention`

### Frontend

`frontend/src/test/` (existing dir) — Vitest/RTL: `ConditionGroup` add/remove/nest, operator list filters by field type, plain-language preview renders correctly in `en` **and** `ar`, and a dictionary-parity test asserting every `automation.*` key present in `en` also exists in `ar` (extend to a generic parity test across all dicts — cheap and catches every future drift).

### Performance targets (asserted in a `@group perf` test)

- 20 rules × 1 order evaluation (facts already resolved): **< 15 ms**.
- Fact resolution for one order: **≤ 4 queries** (order+items eager, variant stock batch, category batch, prior-order count) — asserted with a query counter.
- Simulation over 200 orders: **< 2 s** wall clock.

---

## 12. Rollout

### 12.1 Migration plan

Order: `000001` rules → `000002` runs → `000003` applications → `000004` order columns → `000005` orders.organization_id (nullable + chunked backfill) → `000006` low-stock thresholds. All additive, all `Schema::hasColumn`-guarded, all reversible. Zero downtime — no column is renamed, no type is narrowed, nothing existing is dropped. `orders.organization_id` follows expand-and-contract: nullable + backfill now, `NOT NULL` in a follow-up migration one release later, once a reconciliation query confirms `SELECT COUNT(*) FROM orders WHERE organization_id IS NULL = 0`.

`trigger`, `outcome`, `source`, `subject_type` are **`string`, not DB `enum`** — the repo already has an enum-migration tax (`2026_06_25_000003_add_amazon_noon_to_stores_platform.php`, `2026_07_02_000004_add_trendyol_to_stores_platform.php` both exist only to widen `stores.platform`). Adding `return.requested` later must not require a migration. Validation is enforced in PHP via backed enums (`app/Enums/AutomationTrigger.php` etc.).

### 12.2 Feature flag

`config/automation.php`:

```php
return [
    'enabled'                      => env('AUTOMATION_ENABLED', false),   // global kill switch
    'observer_enabled'             => env('AUTOMATION_OBSERVER_ENABLED', false),
    'max_rules_per_org'            => env('AUTOMATION_MAX_RULES', 200),
    'max_actions_per_rule'         => 20,
    'max_condition_leaves'         => 25,
    'max_condition_depth'          => 3,
    'max_chain_depth'              => 3,
    'max_passes_per_minute'        => 20,
    'log_non_matches'              => env('AUTOMATION_LOG_NON_MATCHES', false),
    'persist_simulations'          => false,
    'run_retention_days'           => 90,
    'backfill_max_per_call'        => 5000,
    'backfill_max_per_day'         => 20000,
    'default_low_stock_threshold'  => 5,
    'webhook' => ['timeout' => 5, 'connect_timeout' => 2, 'max_per_minute_per_org' => 60,
                  'user_agent' => 'Hubby-Automation/1.0'],
    'channels' => ['in_app' => true, 'email' => true,
                   'whatsapp' => env('AUTOMATION_WHATSAPP_ENABLED', false)],
    'carriers' => [ /* code => label */ ],
];
```

Plus a **per-org** override row so the flag can be flipped for pilot tenants only: `organizations.settings` JSON key `automation.enabled` (add `settings` json column if absent), checked as `orgSetting ?? config('automation.enabled')`.

**Rollout stages:**
1. Ship migrations + backend + API with `AUTOMATION_ENABLED=false`. Nothing evaluates. Dashboard route hidden.
2. Enable for 3 internal/design-partner orgs. All their rules forced to `dry_run` for 1 week. Watch `automation_runs` for false positives.
3. Allow `live` for pilot orgs. Monitor queue wait, failure rate, `deduped` ratio (should be high and stable — a low ratio means the fingerprint is over-sensitive).
4. Global `AUTOMATION_ENABLED=true`. Announce as **ungated on every plan**.

### 12.3 Safe defaults

- New rules: `enabled = false`, `run_mode = 'dry_run'`. A merchant must consciously enable and then consciously go live (two separate confirmations). Nothing acts on real orders by accident.
- Enabling never applies retroactively — only future triggers. Historical application is the explicit, owner-only `apply` endpoint.
- Backfills skip `notify` and `call_webhook` by default (§10.6).
- `plans.features` gets `"automation_rules": true` on **every** plan row via a seeder update; `CheckSubscription` middleware is **not** applied to `/automation/*`. This must be explicitly tested (`test_free_plan_org_can_create_rules`) so nobody later "optimises" it into a paywall.
- Global limits are safety rails, never upsells. If a merchant hits 200 rules, the answer is to raise the limit after a look at their setup — not to sell them a tier. The `429` message says so.

### 12.4 Starter template library (`GET /automation/templates`)

Ships with the feature; the empty state leads here. Each is a full, ready-to-clone rule body saved as `dry_run`:

1. **COD over 1,500 SAR → hold + notify** (the MENA flagship)
2. **New customer + COD → tag `verify-phone`**
3. **Destination Riyadh → route to `RUH-DC1`**
4. **Order value > 5,000 → tag `high-value` + notify owner**
5. **Express delivery → assign express carrier + folder `Priority`**
6. **Any item with stock < 3 → hold + tag `stock-risk`**
7. **Sync failed 3× → notify admins**
8. **International (country ≠ SA) → folder `Export docs`**
9. **Amazon order → set status `processing`**
10. **Weight > 30 kg → route to `Bulk` + assign freight carrier**

---

## 13. Acceptance criteria

**Data & migrations**
- [ ] All six migrations run clean on `migrate:fresh` **and** on a copy of production; each `down()` reverses cleanly.
- [ ] `orders.organization_id` backfill reconciles to zero nulls before the `NOT NULL` follow-up.
- [ ] Unique index `automation_apps_unique` exists and a duplicate insert raises `23000`.

**Evaluation**
- [ ] All 5 in-scope triggers fire from their documented hook points, verified end-to-end for at least Shopify, Salla, and Amazon with real captured payloads.
- [ ] Rules run in `priority ASC, id ASC`; a second rule observes the first rule's mutations.
- [ ] `stop_processing` (rule flag and action) halts the chain.
- [ ] Re-running `SyncOrdersJob` on unchanged orders produces **zero** additional side effects (assert notification count, tag array, and run outcomes).
- [ ] A rule that sets a status cannot cause more than 3 chained passes.
- [ ] A pass with 20 rules over one order completes in < 15 ms excluding fact resolution.

**Actions**
- [ ] Every action in §3.6 is implemented, has params validation, a preview implementation, and a passing feature test.
- [ ] Deferred actions are queued after commit — asserted with `Queue::fake()`.
- [ ] Outbound webhooks are HTTPS-only, HMAC-signed, SSRF-guarded, carry an idempotency key, and are rate-limited.
- [ ] `split_order` retains the parent, creates valid unique child `external_id`s, and terminates the chain.

**API & permissions**
- [ ] All 14 endpoints exist at the documented paths inside the `auth:sanctum` + `org.member` group.
- [ ] Cross-org access returns `404` for every endpoint (rules and runs).
- [ ] The role matrix in §9 is enforced and tested for each role.
- [ ] `/automation/*` is reachable on the free plan (no `CheckSubscription`) — explicitly tested.

**Dashboard**
- [ ] Rule builder creates every condition type and every action type without hand-writing JSON.
- [ ] Plain-language preview is correct in `en` and `ar`.
- [ ] Simulation shows a per-leaf trace with actual values and mutates nothing (verified by DB snapshot).
- [ ] Run history filters by rule, outcome, source, and date; run detail shows facts + trace + per-action status.
- [ ] Order detail has an Automation tab answering "why is this order held/tagged?".
- [ ] Every `automation.*` key exists in both `en` and `ar`; a parity test enforces it.
- [ ] Full RTL pass: no `ml-*`/`pl-*`/`left-*`, condition rows read right-to-left, numbers/SKUs stay LTR.

**Mobile**
- [ ] Held-orders queue lists held orders with reason and allows release.
- [ ] Order detail shows automation history.
- [ ] Rule enable/disable toggle works for owner/admin, hidden for viewer.

**Ops**
- [ ] `AUTOMATION_ENABLED=false` makes the engine inert with no errors and hides the nav item.
- [ ] Horizon shows the three automation queues; backfill cannot starve `default`.
- [ ] Runs prune after 90 days.
- [ ] Structured logs carry `correlation_id`, `rule_id`, `subject`, `outcome` and **no** customer PII.
- [ ] SLO dashboard: p95 trigger→action-applied < 30 s; rule failure rate < 1%; automation queue wait p95 < 60 s.

---

## 14. Effort estimate + dependencies

Assumes one senior backend + one senior frontend, working in parallel after the schema lands.

| Workstream | Days |
| --- | --- |
| **AUT-0 prerequisites** (fix the 3 defects in §0: webhook→job signature, `OrderItem` columns, `updateOrCreate` store scoping) | 1.5 |
| Migrations + models + enums + config | 1.5 |
| `FieldCatalogue`, `OperatorRegistry`, `ConditionEvaluator`, `RuleEvaluator` + unit tests | 3 |
| `OrderFactResolver` + 7 platform extractors + golden fixtures + tests (**highest-risk item — A1**) | 4 |
| `Stock`/`Sync` fact resolvers | 1 |
| `ActionDispatcher` + 13 actions + tests (`split_order` and `call_webhook` are ~2 of these days) | 5 |
| `AutomationDispatcher`: locking, idempotency ledger, chaining, loop guards, transactionality | 3 |
| Jobs, events, listeners, observer, Horizon + scheduler wiring | 1.5 |
| Integration hook points across sync/webhook/controller/inventory paths | 1.5 |
| `AutomationController` + form requests + policy + `schema` + `templates` endpoints | 3 |
| `RuleSimulator` + `RuleGraphAnalyzer` | 2 |
| Backend tests to the §11 bar | 4 |
| **Backend subtotal** | **~31 days** |
| Rule list + reorder + toggle + template gallery | 3 |
| Rule builder (recursive condition group, typed value inputs, action editors) — the big one | 7 |
| Simulation view (trace table, would-apply) | 3 |
| Run history + run detail + order Automation tab | 3 |
| i18n `en`/`ar` + full RTL pass | 2 |
| Frontend tests | 2 |
| **Frontend subtotal** | **~20 days** |
| Flutter: held-orders queue, automation history, rule toggle, l10n | 4 |
| Docs (merchant help + rule cookbook, `en`/`ar`), staged rollout, monitoring dashboards | 3 |
| **Total** | **~58 person-days ≈ 6 calendar weeks** with 2 devs in parallel + 1 week mobile |

### Dependencies

**Hard blockers**
1. AUT-0 defects fixed (§0) — webhook-triggered rules literally cannot work otherwise.
2. Real captured order payloads for all 7 platforms (A1). Without these the fact resolvers are guesswork and the whole engine is untrustworthy. **Start collecting on day 1.**
3. Redis available in every environment (locks + rule cache). Already a dependency (`predis`, Horizon) — confirm it's provisioned in prod per `DEPLOYMENT.md`.
4. Queue workers running for the new queues.

**Soft dependencies**
5. WhatsApp provider decision (A4) — `whatsapp` ships disabled until then; nothing else blocks.
6. Locations/warehouses model (A2) — `route_location` is a string until the WMS spec lands.
7. Carrier list (A3) — config allowlist for v1.
8. Email templates for the `email` notify channel (only `VerifyEmailMail` exists today) — 0.5 day.
9. FX rates for `order.total_base` (§15 Q4) — field ships as `null` until a source exists.

---

## 15. Open questions

1. **`order.updated` firing rate.** Every 5-minute sync re-`updateOrCreate`s orders. Even with idempotency deduping the *actions*, we still enqueue an evaluation job per changed order. At 100k orders/org this could be a lot of jobs. Should `order.updated` be restricted to a whitelist of materially-changed columns (`status`, `total`, `raw_data` diff) rather than any change? **Proposed:** yes — only enqueue when a watched column changed, and skip entirely if no org rule uses the `order.updated` trigger (a cheap cached count check). Needs a decision before load testing.
2. **Should `automation_runs` store `facts` for every run?** It's the best debugging asset we have and the reason merchants will trust the engine — but it's PII-bearing and roughly 2–4 KB/row. At 90-day retention and 5k orders/day × 3 matching rules that's ~4 GB/org/quarter. Options: store facts only for `matched`/`failed`/`simulated` runs (proposed), or truncate to the `materialFields` subset. Confirm with whoever owns the DB budget.
3. **Do we expose "rule folders/queues" as real entities?** `assign_folder` writes a free string today. Linnworks has first-class folders with their own views. Do we want a `folders` table + a folder-filtered order list in v1, or is a string + an order-list filter enough? **Proposed:** string in v1, promote to a table when the WMS spec lands.
4. **Multi-currency thresholds.** `order.total >= 1500` means different things in SAR vs AED vs USD. Do we (a) require a currency condition alongside every value condition (proposed for v1, enforced by a builder warning), (b) add an org base currency + daily FX rates and expose `order.total_base`, or (c) let rules declare their own currency and skip non-matching orders? (b) is the right long-term answer and needs an FX source decision.
5. **`return.requested` / `payment.captured` timing.** Both are out of scope here purely because the underlying models don't exist. Should the Returns spec own its trigger, or should this engine ship a generic "custom event" trigger that any future feature can emit into? A generic `custom.<name>` trigger is cheap now and would let the public-API/webhooks work (strategy item 14) fire rules without touching this engine again. Worth deciding before we hardcode the trigger enum.
6. **Rule-level notification throttling.** If a rule matches 300 orders in a batch and notifies on each, we generate 300 in-app notifications. Do we add per-rule notification aggregation ("42 orders held by rule X in the last hour") as a v1 requirement or a fast-follow? **Proposed:** fast-follow, but reserve a `notify.aggregate` param now so no schema change is needed.
7. **Who can see `automation_runs.facts`?** It contains customer email and city. Currently any org member (including `viewer`) can read it via the run detail. Should facts be redacted for `viewer`? Depends on whether `viewer` is intended as "warehouse staff" or "accountant". Needs a product answer.
8. **Ordering guarantee across triggers.** If an order is created and updated within the same second (Salla does this), two passes may be queued near-simultaneously. The subject lock serialises them but does **not** guarantee `created` is processed before `updated`. Is best-effort ordering acceptable, or do we need a per-subject FIFO queue? **Proposed:** best-effort for v1 — the fact resolver always reads current state, so the final state converges correctly; only the audit trail's ordering can look odd.
