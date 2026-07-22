# Spec 08 — Mobile Warehouse Operations (camera barcode scanning)

**Status:** Draft, implementation-ready
**Owner:** Mobile + Backend
**Last updated:** 2026-07-22
**Related specs (referenced, not redefined here):** Shipping & Labels, Returns/RMA, Automation Rules Engine

> **Verification note.** Everything in §"Current repo facts" below was read from the repo at
> `D:\work\HubbyGlobal` before writing. Anything I could not verify is explicitly marked
> **[ASSUMPTION]**. Package version numbers are the latest known at authoring time and must be
> re-pinned by `flutter pub outdated` / `composer outdated` at implementation.

### Current repo facts this spec is built on

| Area | Verified fact | File |
| --- | --- | --- |
| Mobile shell | 5-tab `NavigationBar` (Dashboard/Orders/Products/Inventory/More), `IndexedStack` | `mobile/lib/app/app_shell.dart` |
| Mobile routing | `go_router`, `buildRouter(AuthBloc)`, flat `GoRoute` list, auth redirect | `mobile/lib/core/router/app_router.dart` |
| Mobile HTTP | `ApiClient` wraps `Dio`; injects `Authorization: Bearer` + `X-Organization-Id`; `ApiClient.messageFrom(e)` | `mobile/lib/core/network/api_client.dart` |
| Mobile state | `Cubit` + `Equatable` state class with `copyWith` (see `StoresCubit`); providers wired in `main.dart` | `mobile/lib/features/stores/cubit/stores_cubit.dart`, `mobile/lib/main.dart` |
| Mobile i18n | Flat `Map<String, Map<String,String>>` with `'en'` and `'ar'` maps, accessed via `context.t('key')` | `mobile/lib/l10n/strings.dart` (356 lines) |
| Mobile theme | `AppPalette` (primary `#0B5A5C`, secondary `#4FD34A`), spacing `s4..s32`, radii `rSm..rPill`, `shadowCard` | `mobile/lib/core/theme/app_palette.dart` |
| Mobile shared widgets | `AppCard`, `EmptyState`, `ErrorView`, `Skeleton`, `showToast(context, msg, ToastKind)`, `AsyncView<T>`, `PageHeader` | `mobile/lib/shared/widgets/` |
| Mobile storage | `SessionStore` over `shared_preferences` (token, user, active org, locale). **No local database exists yet.** | `mobile/lib/core/storage/session_store.dart` |
| Mobile deps | `dio 5.9.2`, `flutter_bloc 9.1.1`, `go_router 17.2.3`, `shared_preferences`, `image_picker`, `share_plus`, `path_provider`, `fl_chart`, `lucide_icons`, `flutter_svg`, `intl`. **No camera/scanner/db/connectivity package.** | `mobile/pubspec.yaml` |
| Android manifest | **No `android.permission.CAMERA` declared today**; `minSdk = flutter.minSdkVersion` | `mobile/android/app/src/main/AndroidManifest.xml`, `android/app/build.gradle.kts` |
| iOS | `ios/` folder exists but `flutter_launcher_icons`/`flutter_native_splash` are configured `ios: false` — iOS is not a shipped target yet | `mobile/pubspec.yaml` |
| Backend routes | Flat routes inside `Route::middleware('auth:sanctum')->group(...)->middleware('org.member')`; org read from `X-Organization-Id` header | `backend/routes/api.php` |
| Org scoping | `EnsureOrganizationMember` checks header org against `user->organizations()`; **controllers re-read the header themselves** | `backend/app/Http/Middleware/EnsureOrganizationMember.php`, `InventoryController` |
| Roles | `private const ROLES = ['owner', 'admin', 'viewer']` on `organization_user.role` (string, default `member`) | `backend/app/Http/Controllers/OrganizationController.php`, migration `2026_05_05_202907` |
| Inventory write path | `POST /inventory/adjust` → `DB::transaction` → `increment('stock')` on variant **or** product → `InventoryLog::create([...'source' => 'Manual Adjustment'])`; **has a `// TODO: Dispatch job to push new inventory` — push is not wired** | `backend/app/Http/Controllers/InventoryController.php:52` |
| `inventory_logs` | `id, product_id (nullable FK), product_variant_id (nullable FK), change (int), source (string), reason (string, nullable), timestamps` — **no org id, no user id, no idempotency key** | migrations `2026_05_05_202913`, `2026_06_25_000002` |
| `products` | `id, organization_id, name, sku (nullable, indexed), price, stock, description, image_url, status` | migrations `2026_05_05_202909`, `..._090840`, `..._140000` |
| `product_variants` | `id, product_id, sku (**globally unique**), price, stock` | migration `2026_05_05_202910` |
| `order_items` | `id, order_id, sku (nullable), quantity, price, name` — **no product/variant FK, no barcode** | migration `2026_05_05_202921` |
| Orders → org | `orders.store_id → stores.organization_id` (orders have no direct `organization_id`) | `backend/app/Models/Order.php` |
| Jobs | `PushInventoryJob(ProductVariant $variant, ?Store $sourceStore)` pushes `updateInventory($store, $sku, $stock)` to every connected store | `backend/app/Jobs/PushInventoryJob.php` |
| Dashboard | Next.js App Router, `src/app/(dashboard)/<feature>/page.tsx`, `@/lib/api`, `useT()` from `@/i18n`, dicts at `src/i18n/dicts/<feature>.ts` shaped `{ en: {...}, ar: {...} }` registered in `src/i18n/dictionary.ts` | `frontend/src/` |
| Tests | Backend `tests/Feature/*Test.php` (incl. `InventorySyncTest`); mobile `test/*_test.dart` (3 files), `bloc_test` already a dev dep | `backend/tests`, `mobile/test` |

---

## 1. Why this exists

Hubby's competitive analysis (`docs/COMPETITIVE_STRATEGY.md`) identified mobile warehouse operations
as strike #8 and a top-4 priority. The rationale, verbatim from the competitor teardown:

- **Linnworks** — *"Mobile is the biggest hole — confirmed by their own docs: no dedicated mobile OS
  app, camera-as-scanner explicitly unsupported, 'mobile app in BETA for a long time'; a third-party
  ecosystem exists purely to fill it."* Their WMS add-on (pick-path optimization, wave picking,
  bins/zones, barcode receiving/packing, QC) is **paid separately** and assumes dedicated hardware
  scanners. A warehouse that wants barcode ops on Linnworks must buy the WMS add-on **and** buy
  hardware **and** in many cases buy a third-party mobile bridge.
- **Rithum** — *"No mobile app mentioned anywhere."* Enterprise-only, sales-gated, desktop.
- **Sellerboard** — has reseller/arbitrage mobile scanning, but it is an Amazon-analytics tool, not
  multi-channel warehouse ops.

Hubby already ships a Flutter app with EN/AR + RTL (`docs/COMPETITIVE_STRATEGY.md` capability table:
Mobile app — Linnworks ❌ / Sellerboard ✅ / Rithum ❌ / **Hubby ✅**). The strategic move is cheap
because the asset exists: adding camera scanning converts our largest existing asset into the exact
capability two of three competitors cannot ship without a platform rewrite.

**Positioning claim this unlocks:** *"Your phone is the scanner. No hardware, no add-on, no extra
tier — pick, pack, receive and count from the device already in your pocket, in Arabic, offline."*

**Product wedge (why a phone beats a $900 Zebra for our ICP):** our target Gulf/MENA seller runs a
5–50 person operation. A dedicated WMS + rugged scanner deployment is a 6-figure, 3-month project.
A phone app is a 10-minute onboarding. We do not need to beat Zebra ergonomics — we need to beat
*a clipboard and a spreadsheet*, which is what our ICP actually uses today.

**Non-goal:** we are not building a full WMS (pick-path optimization, wave planning, 3D bin
topology, QC workflows). We are building the 80% that a mid-market seller does daily, on hardware
they already own.

---

## 2. Scope — in / out

### In scope (this spec)

1. **Camera barcode/QR scanning** in the Flutter app: continuous and single-shot, torch, symbology
   filtering, scan-window, haptic + audio feedback.
2. **Five workflows:** Pick, Pack, Receive, Stock/Cycle Count, Lookup.
3. **Offline-first scan capture** — local SQLite outbox, optimistic UI, sync-on-reconnect,
   idempotency keys, conflict resolution.
4. **Barcode ↔ SKU mapping model** (`product_barcodes`) — many barcodes per item, provenance per
   channel/store.
5. **Auditable session model** — every scan is an immutable `scan_events` row tied to a session.
6. **Stock writes** flow through one service into `inventory_logs` and dispatch the existing
   `PushInventoryJob`.
7. **Minimum location model** — `warehouses` + `stock_locations`, advisory only (see §3.9).
8. **Supervisor dashboard views** — pick list creation, count approval, variance review, barcode
   management.
9. **External Bluetooth (HID keyboard-wedge) scanner** support as an input alternative.
10. **`warehouse_operator` role** and org scoping.
11. EN + AR (RTL) throughout.

### Out of scope (deferred / owned elsewhere)

| Item | Where it lives |
| --- | --- |
| Carrier rates, label PDF generation, manifests, tracking | **Shipping & Labels spec.** Pack hands off a `pack_session` and receives a label reference. |
| Return/RMA inspection & disposition | **Returns/RMA spec.** Receive supports a `receipt.type = 'return'` hook only. |
| Auto-triggering actions on scan events (e.g. "on short-pick, notify Slack") | **Automation Rules Engine spec.** We emit domain events; the engine subscribes. |
| Per-location quantity ledger, multi-warehouse stock allocation, transfers, pick-path optimization, wave/zone picking | **Phase 2 "Multi-warehouse & bins".** Flagged in §3.9. |
| Purchase Order object with supplier catalogue, costs, landed cost | Phase 2. `receipts.reference` holds a free-text PO number for now. |
| Batch/lot/serial/expiry tracking | Phase 3. Schema leaves `meta` JSON room. |
| Kits/bundles explosion during pick | Phase 3. |
| Dedicated Android rugged-device build (DataWedge intents) | Phase 3; HID mode covers most devices meanwhile. |
| Voice picking, pick-to-light, RFID | Not planned. |
| iOS release | **[ASSUMPTION]** iOS is not currently a shipped target (`ios: false` in launcher/splash config). The spec includes all iOS wiring so it is ready, but the pilot ships Android-only. |

---

## 3. Data model

### Conventions

- Migration filenames follow the repo pattern `YYYY_MM_DD_NNNNNN_verb_object_table.php` (e.g.
  `2026_07_02_000004_add_trendyol_to_stores_platform.php`). All new files use the `2026_07_22_0000NN`
  sequence.
- Every new tenant table carries an explicit `organization_id` FK. This deliberately **breaks with
  `inventory_logs`**, which scopes via `whereHas('product')` — that pattern does not survive the
  query volume scanning produces (see §3.8).
- All FKs are `foreignId(...)->constrained()`; delete behaviour is stated per column.
- Money uses `decimal(15,2)` to match `products.price` / `product_variants.price`.
- Timestamps captured on the device are `client_*_at`; server clock is `*_at`. Never trust device
  clocks for ordering across devices (see §4.6).

---

### 3.1 `warehouses`

`database/migrations/2026_07_22_000001_create_warehouses_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigIncrements | no | — | |
| `organization_id` | foreignId → `organizations.id` | no | — | `onDelete('cascade')` |
| `name` | string(120) | no | — | "Riyadh Main" |
| `code` | string(24) | no | — | Short code used in barcodes/labels, uppercased on save |
| `address` | text | yes | null | |
| `timezone` | string(64) | yes | null | Used for cycle-count day boundaries |
| `is_default` | boolean | no | `false` | Exactly one per org enforced in `WarehouseService` |
| `is_active` | boolean | no | `true` | |
| `meta` | json | yes | null | |
| `created_at` / `updated_at` | timestamps | no | — | |

Indexes: `unique(['organization_id','code'])`, `index(['organization_id','is_active'])`.

Seeding: on first use of any warehouse feature, `WarehouseService::ensureDefault($orgId)` creates
`{code: 'MAIN', name: <org name>, is_default: true}` so single-warehouse orgs never see the concept.

---

### 3.2 `stock_locations`

`database/migrations/2026_07_22_000002_create_stock_locations_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigIncrements | no | — | |
| `organization_id` | foreignId → `organizations.id` | no | — | cascade; denormalised for index-only tenant filters |
| `warehouse_id` | foreignId → `warehouses.id` | no | — | cascade |
| `code` | string(32) | no | — | `A-01-3`. This is what gets printed on the shelf label |
| `name` | string(120) | yes | null | |
| `type` | string(24) | no | `'bin'` | `bin` \| `shelf` \| `staging` \| `receiving` \| `packing` \| `quarantine` |
| `barcode` | string(64) | yes | null | Location label payload if different from `code` |
| `sequence` | unsignedInteger | no | `0` | Walk order — the seed of future pick-path optimization |
| `is_active` | boolean | no | `true` | |
| `created_at` / `updated_at` | timestamps | no | — | |

Indexes: `unique(['organization_id','warehouse_id','code'])`,
`unique(['organization_id','barcode'])` (nullable — MySQL allows multiple NULLs, which is what we
want), `index(['warehouse_id','sequence'])`.

---

### 3.3 `product_barcodes`

`database/migrations/2026_07_22_000003_create_product_barcodes_table.php`

The heart of the feature. A product/variant may carry many barcodes (manufacturer EAN, our own
Code128, an Amazon FNSKU, a Noon channel code). A barcode string resolves to **exactly one** sellable
item within an organization.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigIncrements | no | — | |
| `organization_id` | foreignId → `organizations.id` | no | — | cascade |
| `product_id` | foreignId → `products.id` | yes | null | cascade. Null only if variant-only |
| `product_variant_id` | foreignId → `product_variants.id` | yes | null | cascade |
| `barcode` | string(128) | no | — | Stored **normalised** (see below) |
| `barcode_raw` | string(160) | yes | null | Exact payload as scanned/imported, for forensics |
| `symbology` | string(24) | no | `'unknown'` | `ean13` \| `ean8` \| `upca` \| `upce` \| `code128` \| `code39` \| `itf` \| `qr` \| `datamatrix` \| `unknown` |
| `store_id` | foreignId → `stores.id` | yes | null | `nullOnDelete`. Provenance: "this is the Amazon barcode". Informational only — **not** part of uniqueness |
| `is_primary` | boolean | no | `false` | The one printed on our own labels |
| `pack_size` | unsignedInteger | no | `1` | A case barcode = 12 units. Scanning it adds `pack_size` units |
| `source` | string(24) | no | `'manual'` | `manual` \| `import` \| `sync` \| `scan_learned` |
| `created_by_user_id` | foreignId → `users.id` | yes | null | `nullOnDelete` |
| `created_at` / `updated_at` | timestamps | no | — | |

Indexes:
- `unique(['organization_id','barcode'])` — **the resolution index.** A barcode is unambiguous inside
  a tenant. Cross-tenant collisions are fine and expected (two orgs both sell the same EAN).
- `index(['product_id'])`, `index(['product_variant_id'])`.
- Partial-uniqueness for "one primary per item" is enforced in `BarcodeService`, not in SQL
  (MySQL has no filtered indexes).

**Normalisation rules** (`App\Support\BarcodeNormalizer::normalize(string $raw): string`):
1. Trim, strip control chars, strip zero-width chars, collapse internal whitespace.
2. Uppercase (Code128/Code39 alphanumerics; digits unaffected).
3. Strip a leading `]` AIM identifier prefix if present (`]E0`, `]C1`, …) — some HID scanners emit it.
4. If the payload is 12 digits and a valid UPC-A, left-pad to 13 to match its EAN-13 form; store
   **both** the 12- and 13-digit rows pointing at the same item (a real-world necessity — US catalogues
   carry UPC, EU carry EAN).
5. If the payload is 8 digits and a valid UPC-E, also store the expanded UPC-A/EAN-13 form.
6. Check-digit validation is **advisory**: a failing check digit is logged in
   `scan_events.reject_reason` as a warning but does not block resolution if the string matches a
   stored row. (Warehouse reality: hand-typed barcodes and reprinted labels are often wrong.)

**Constraint:** exactly one of `product_id` / `product_variant_id` must be non-null (validated in
`BarcodeService`; if `product_variant_id` is set, `product_id` is backfilled from the variant for
faster joins).

---

### 3.4 `pick_lists`, `pick_list_orders`, `pick_list_items`

`2026_07_22_000004_create_pick_lists_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigIncrements | no | — | |
| `organization_id` | foreignId → `organizations.id` | no | — | cascade |
| `warehouse_id` | foreignId → `warehouses.id` | yes | null | `nullOnDelete` |
| `code` | string(24) | no | — | Human handle `PL-2607-0042`, generated by `SequenceService` |
| `type` | string(16) | no | `'order'` | `order` (1 order) \| `batch` (n orders, aggregated by SKU) \| `wave` (reserved) |
| `status` | string(24) | no | `'draft'` | See §4.1 state machine |
| `assigned_user_id` | foreignId → `users.id` | yes | null | `nullOnDelete` |
| `created_by_user_id` | foreignId → `users.id` | yes | null | `nullOnDelete` |
| `priority` | unsignedTinyInteger | no | `5` | 1 = highest |
| `item_count` | unsignedInteger | no | `0` | Denormalised for list screens |
| `picked_count` | unsignedInteger | no | `0` | Denormalised |
| `started_at` / `completed_at` / `cancelled_at` | timestamp | yes | null | |
| `notes` | text | yes | null | |
| `meta` | json | yes | null | |
| `created_at` / `updated_at` | timestamps | no | — | |

Indexes: `unique(['organization_id','code'])`,
`index(['organization_id','status','priority'])`, `index(['assigned_user_id','status'])`.

`2026_07_22_000005_create_pick_list_orders_table.php` — pivot for batch picks.

| Column | Type | Null | Default |
| --- | --- | --- | --- |
| `id` | bigIncrements | no | — |
| `pick_list_id` | foreignId → `pick_lists.id` (cascade) | no | — |
| `order_id` | foreignId → `orders.id` (cascade) | no | — |
| `created_at` / `updated_at` | timestamps | no | — |

Index: `unique(['pick_list_id','order_id'])`, `index(['order_id'])`.

`2026_07_22_000006_create_pick_list_items_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigIncrements | no | — | |
| `pick_list_id` | foreignId → `pick_lists.id` | no | — | cascade |
| `order_item_id` | foreignId → `order_items.id` | yes | null | `nullOnDelete`. Null on aggregated batch lines |
| `order_id` | foreignId → `orders.id` | yes | null | `nullOnDelete`. Which order this line serves (single-order lines) |
| `product_id` | foreignId → `products.id` | yes | null | `nullOnDelete` |
| `product_variant_id` | foreignId → `product_variants.id` | yes | null | `nullOnDelete` |
| `sku` | string(120) | yes | null | Snapshot — survives catalogue edits |
| `name` | string(255) | yes | null | Snapshot |
| `image_url` | string(512) | yes | null | Snapshot — operators recognise pictures faster than SKUs |
| `stock_location_id` | foreignId → `stock_locations.id` | yes | null | `nullOnDelete`. Suggested pick face |
| `qty_required` | unsignedInteger | no | — | |
| `qty_picked` | unsignedInteger | no | `0` | |
| `qty_short` | unsignedInteger | no | `0` | |
| `status` | string(16) | no | `'pending'` | See §4.1 |
| `short_reason` | string(32) | yes | null | `not_found` \| `damaged` \| `insufficient` \| `wrong_location` \| `other` |
| `substituted_variant_id` | foreignId → `product_variants.id` | yes | null | `nullOnDelete`. Phase 2 hook |
| `sequence` | unsignedInteger | no | `0` | Pick order = `stock_locations.sequence` then SKU |
| `picked_by_user_id` | foreignId → `users.id` | yes | null | `nullOnDelete` |
| `picked_at` | timestamp | yes | null | Server time of the accepted final scan |
| `created_at` / `updated_at` | timestamps | no | — | |

Indexes: `index(['pick_list_id','sequence'])`, `index(['pick_list_id','status'])`,
`index(['product_variant_id'])`, `index(['sku'])`.

---

### 3.5 `pack_sessions`, `pack_session_items`

`2026_07_22_000007_create_pack_sessions_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigIncrements | no | — | |
| `organization_id` | foreignId → `organizations.id` | no | — | cascade |
| `order_id` | foreignId → `orders.id` | no | — | cascade |
| `pick_list_id` | foreignId → `pick_lists.id` | yes | null | `nullOnDelete` |
| `warehouse_id` | foreignId → `warehouses.id` | yes | null | `nullOnDelete` |
| `code` | string(24) | no | — | `PK-2607-0091` |
| `status` | string(24) | no | `'open'` | See §4.2 |
| `package_index` | unsignedTinyInteger | no | `1` | Multi-box orders → several `pack_sessions` for one order |
| `package_count` | unsignedTinyInteger | no | `1` | |
| `weight_grams` | unsignedInteger | yes | null | |
| `length_mm` / `width_mm` / `height_mm` | unsignedInteger | yes | null | |
| `packaging_type` | string(32) | yes | null | `box_s` \| `box_m` \| `polybag` \| `custom` |
| `shipment_ref` | string(64) | yes | null | **Owned by Shipping spec** — opaque handle |
| `label_url` | string(512) | yes | null | **Owned by Shipping spec** |
| `packed_by_user_id` | foreignId → `users.id` | yes | null | `nullOnDelete` |
| `verified_at` / `completed_at` / `voided_at` | timestamp | yes | null | |
| `meta` | json | yes | null | |
| `created_at` / `updated_at` | timestamps | no | — | |

Indexes: `unique(['organization_id','code'])`, `index(['order_id','package_index'])`,
`index(['organization_id','status'])`.

`2026_07_22_000008_create_pack_session_items_table.php`

| Column | Type | Null | Default |
| --- | --- | --- | --- |
| `id` | bigIncrements | no | — |
| `pack_session_id` | foreignId → `pack_sessions.id` (cascade) | no | — |
| `order_item_id` | foreignId → `order_items.id` (nullOnDelete) | yes | null |
| `product_variant_id` | foreignId → `product_variants.id` (nullOnDelete) | yes | null |
| `sku` | string(120) | yes | null |
| `name` | string(255) | yes | null |
| `qty_required` | unsignedInteger | no | — |
| `qty_packed` | unsignedInteger | no | `0` |
| `created_at` / `updated_at` | timestamps | no | — |

Index: `index(['pack_session_id'])`, `unique(['pack_session_id','order_item_id'])`.

---

### 3.6 `receipts`, `receipt_items`

`2026_07_22_000009_create_receipts_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigIncrements | no | — | |
| `organization_id` | foreignId → `organizations.id` | no | — | cascade |
| `warehouse_id` | foreignId → `warehouses.id` | yes | null | `nullOnDelete` |
| `code` | string(24) | no | — | `RC-2607-0013` |
| `type` | string(16) | no | `'inbound'` | `inbound` \| `return` (hook for Returns/RMA spec) \| `transfer` (Phase 2) |
| `status` | string(24) | no | `'draft'` | See §4.3 |
| `supplier_name` | string(180) | yes | null | Free text until the PO module exists |
| `reference` | string(64) | yes | null | PO number / ASN / carrier tracking |
| `expected_lines` | json | yes | null | `[{sku, qty}]` pasted or CSV-imported; drives informed receiving |
| `created_by_user_id` / `received_by_user_id` | foreignId → `users.id` | yes | null | `nullOnDelete` |
| `started_at` / `completed_at` / `cancelled_at` | timestamp | yes | null | |
| `notes` | text | yes | null | |
| `created_at` / `updated_at` | timestamps | no | — | |

Indexes: `unique(['organization_id','code'])`, `index(['organization_id','status'])`.

`2026_07_22_000010_create_receipt_items_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigIncrements | no | — | |
| `receipt_id` | foreignId → `receipts.id` | no | — | cascade |
| `product_id` / `product_variant_id` | foreignId (nullOnDelete) | yes | null | |
| `sku` | string(120) | yes | null | Snapshot |
| `name` | string(255) | yes | null | Snapshot |
| `stock_location_id` | foreignId → `stock_locations.id` | yes | null | `nullOnDelete` — put-away face |
| `qty_expected` | unsignedInteger | yes | null | Null = unexpected line |
| `qty_received` | unsignedInteger | no | `0` | |
| `qty_damaged` | unsignedInteger | no | `0` | Received but not sellable — **does not** raise stock |
| `discrepancy` | integer | no | `0` | `qty_received - qty_expected`, computed on completion |
| `discrepancy_reason` | string(32) | yes | null | `over` \| `short` \| `damaged` \| `unexpected_sku` \| `other` |
| `unit_cost` | decimal(15,2) | yes | null | Captured for future landed-cost work |
| `created_at` / `updated_at` | timestamps | no | — | |

Indexes: `index(['receipt_id'])`, `index(['product_variant_id'])`.

---

### 3.7 `count_sessions`, `count_entries`

`2026_07_22_000011_create_count_sessions_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigIncrements | no | — | |
| `organization_id` | foreignId → `organizations.id` | no | — | cascade |
| `warehouse_id` | foreignId → `warehouses.id` | yes | null | `nullOnDelete` |
| `code` | string(24) | no | — | `CC-2607-0007` |
| `mode` | string(12) | no | `'blind'` | `blind` (expected qty hidden) \| `informed` (expected shown) |
| `scope_type` | string(16) | no | `'sku_list'` | `full` \| `location` \| `category` \| `sku_list` |
| `scope_ref` | json | yes | null | `{location_ids:[]}` / `{category_ids:[]}` / `{skus:[]}` |
| `status` | string(24) | no | `'draft'` | See §4.4 |
| `assigned_user_id` / `created_by_user_id` / `approved_by_user_id` | foreignId → `users.id` | yes | null | `nullOnDelete` |
| `expected_snapshot_at` | timestamp | yes | null | When expected quantities were frozen |
| `started_at` / `submitted_at` / `approved_at` / `rejected_at` / `abandoned_at` | timestamp | yes | null | |
| `rejection_reason` | string(255) | yes | null | |
| `lines_total` / `lines_counted` / `lines_variant` | unsignedInteger | no | `0` | Denormalised counters |
| `variance_units` | integer | no | `0` | Σ\|variance\| at submit |
| `variance_value` | decimal(15,2) | no | `0` | Σ (variance × price) at submit |
| `applied_log_batch` | uuid | yes | null | Groups the resulting `inventory_logs` rows |
| `created_at` / `updated_at` | timestamps | no | — | |

Indexes: `unique(['organization_id','code'])`, `index(['organization_id','status'])`,
`index(['assigned_user_id','status'])`.

`2026_07_22_000012_create_count_entries_table.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigIncrements | no | — | |
| `count_session_id` | foreignId → `count_sessions.id` | no | — | cascade |
| `product_id` / `product_variant_id` | foreignId (nullOnDelete) | yes | null | |
| `sku` | string(120) | yes | null | Snapshot |
| `name` | string(255) | yes | null | Snapshot |
| `stock_location_id` | foreignId → `stock_locations.id` | yes | null | `nullOnDelete` |
| `expected_qty` | integer | yes | null | Frozen at `expected_snapshot_at`. **Never sent to a blind-mode device** |
| `counted_qty` | integer | no | `0` | Absolute, not a delta |
| `live_qty_at_approval` | integer | yes | null | Re-read at approval; the actual apply base (§4.4) |
| `variance` | integer | yes | null | `counted_qty - live_qty_at_approval`, written at approval |
| `status` | string(16) | no | `'counted'` | `counted` \| `recount_requested` \| `recounted` \| `approved` \| `rejected` \| `skipped` |
| `recount_of_entry_id` | foreignId → `count_entries.id` | yes | null | `nullOnDelete` |
| `counted_by_user_id` | foreignId → `users.id` | yes | null | `nullOnDelete` |
| `client_counted_at` | timestamp | yes | null | Device clock — used for last-write-wins within a session |
| `counted_at` | timestamp | yes | null | Server receipt time |
| `note` | string(255) | yes | null | |
| `created_at` / `updated_at` | timestamps | no | — | |

Indexes: `index(['count_session_id','status'])`,
`unique(['count_session_id','product_variant_id','stock_location_id'])` — one live entry per
item-per-location per session; re-scans **update** it (see §4.4 accumulate vs replace).
Note MySQL treats NULL as distinct in unique indexes, so product-level (variant-null) lines are not
protected by this index; `CountService` guards them with a `lockForUpdate` + `firstOrCreate`.

---

### 3.8 `scan_events` — the audit + idempotency spine

`2026_07_22_000013_create_scan_events_table.php`

Every scan, accepted or rejected, online or replayed, lands here. This is the table that makes a
warehouse session auditable and makes offline replay safe.

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `id` | bigIncrements | no | — | |
| `uuid` | uuid | no | — | **Client-generated (UUID v4) idempotency key.** The single most important column |
| `organization_id` | foreignId → `organizations.id` | no | — | cascade |
| `user_id` | foreignId → `users.id` | yes | null | `nullOnDelete` |
| `device_id` | string(64) | yes | null | Stable per install (§6.7) |
| `session_type` | string(16) | no | — | `pick` \| `pack` \| `receive` \| `count` \| `lookup` |
| `session_id` | unsignedBigInteger | yes | null | FK-by-convention into the table implied by `session_type`. **No DB constraint** — deliberate, keeps one hot insert path |
| `target_type` | string(24) | yes | null | `pick_list_item` \| `receipt_item` \| `count_entry` \| `pack_session_item` |
| `target_id` | unsignedBigInteger | yes | null | |
| `action` | string(24) | no | `'scan'` | `scan` \| `location_scan` \| `qty_set` \| `short` \| `undo` \| `override` |
| `barcode` | string(128) | yes | null | Normalised |
| `barcode_raw` | string(160) | yes | null | |
| `symbology` | string(24) | yes | null | |
| `input_method` | string(12) | no | `'camera'` | `camera` \| `hid` \| `manual` |
| `resolved_product_id` / `resolved_product_variant_id` | foreignId (nullOnDelete) | yes | null | |
| `stock_location_id` | foreignId → `stock_locations.id` | yes | null | `nullOnDelete` |
| `qty` | integer | no | `1` | Signed. Case barcode with `pack_size=12` writes `12` |
| `result` | string(24) | no | — | `accepted` \| `duplicate` \| `unknown_barcode` \| `wrong_item` \| `over_pick` \| `wrong_location` \| `session_closed` \| `conflict` \| `error` |
| `reject_reason` | string(64) | yes | null | |
| `was_offline` | boolean | no | `false` | Captured while disconnected |
| `client_scanned_at` | timestamp(3) | yes | null | Device clock, millisecond precision |
| `client_seq` | unsignedBigInteger | yes | null | Monotonic per-device counter — the real ordering key |
| `received_at` | timestamp(3) | yes | null | Server clock on ingest |
| `response` | json | yes | null | The exact response returned; replayed verbatim on duplicate |
| `payload` | json | yes | null | Full original request body, for forensics |
| `created_at` / `updated_at` | timestamps | no | — | |

Indexes:
- `unique(['organization_id','uuid'])` — **the idempotency guard.**
- `index(['organization_id','session_type','session_id'])` — session audit trail.
- `index(['organization_id','created_at'])` — supervisor activity feed.
- `index(['user_id','created_at'])` — operator productivity.
- `index(['barcode'])` — "where did this barcode get scanned?"

**Retention:** `scan_events` is the highest-volume table in the system (a 20-picker warehouse
produces ~50k rows/week). `PruneScanEventsJob` runs nightly and deletes `result != 'accepted'` rows
older than 90 days and `accepted` rows older than 400 days, in 5k chunks. Aggregate productivity
metrics are rolled into daily analytics before pruning (reuse `GenerateDailyAnalyticsJob` pattern).

---

### 3.9 Minimum location model + dependency flag

> ⚠️ **DEPENDENCY FLAG — multi-warehouse / per-bin quantities are Phase 2.**
>
> Today, stock is a single scalar: `products.stock` and `product_variants.stock`
> (`2026_05_05_202910`, `2026_05_06_140000`). This spec **does not change that**, and must not.
>
> What this spec introduces is the **minimum viable location model**: `warehouses` and
> `stock_locations` exist, and every scan may record *where* it happened
> (`pick_list_items.stock_location_id`, `receipt_items.stock_location_id`,
> `count_entries.stock_location_id`, `scan_events.stock_location_id`). Locations are:
>
> - **Advisory** — they tell the picker where to walk and prove where a count happened.
> - **Auditable** — the location is on the immutable scan record forever.
> - **Not authoritative** — no `stock_location_quantities` table exists; the sum of counts at
>   locations is *not* reconciled against the scalar `stock`. A count session of a single location
>   in a multi-location warehouse would therefore produce a false variance, so **location-scoped
>   count sessions are hard-disabled unless the org has exactly one active location per warehouse**
>   (`CountService::assertScopeSupported()` throws `LocationScopedCountUnsupported` otherwise).
>
> **Phase 2 will add** `stock_location_quantities (organization_id, product_variant_id,
> stock_location_id, qty, reserved_qty, unique(variant, location))` plus a reconciliation job, and
> flip `stock` to a derived/cached sum. Every table in this spec already carries the
> `stock_location_id` column that migration needs, so Phase 2 is additive, not a rewrite.
>
> **Consequence to accept now:** a warehouse with real bins gets pick-path hints and count
> provenance, but their bin-level quantities live in their heads until Phase 2. This is the honest
> trade for shipping in one quarter. Say so in the pilot conversation.

---

### 3.10 Alterations to existing tables

`2026_07_22_000014_add_warehouse_fields_to_inventory_logs.php`

`inventory_logs` today cannot answer "who did this, from which device, as part of what, and was this
a replay?". Add:

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `organization_id` | foreignId → `organizations.id` | yes | null | `nullOnDelete`, `after('id')`. **Backfilled** from `products.organization_id` in the same migration (`UPDATE ... JOIN`, chunked). Kept nullable so the existing `whereHas` path in `InventoryController::logs()` keeps working during rollout; a later migration tightens it |
| `user_id` | foreignId → `users.id` | yes | null | `nullOnDelete` |
| `idempotency_key` | uuid | yes | null | Mirrors `scan_events.uuid` for the mutation that caused this row |
| `source_type` | string(32) | yes | null | `pick_list` \| `receipt` \| `count_session` \| `pack_session` \| `manual` \| `sync` |
| `source_id` | unsignedBigInteger | yes | null | |
| `stock_location_id` | foreignId → `stock_locations.id` | yes | null | `nullOnDelete` |
| `qty_before` / `qty_after` | integer | yes | null | Makes the ledger reconstructible without replaying every row |
| `batch_uuid` | uuid | yes | null | Groups all rows applied by one count approval / one receipt |

Indexes: `unique(['organization_id','idempotency_key'])` (nullable — multiple NULLs allowed, existing
rows unaffected), `index(['organization_id','created_at'])`, `index(['source_type','source_id'])`.

`2026_07_22_000015_add_product_links_to_order_items.php`

**This is a prerequisite, not a nice-to-have.** `order_items` has only a nullable `sku` string, so
today there is no deterministic way to say "the scanned barcode is this order line".

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `product_id` | foreignId → `products.id` | yes | null | `nullOnDelete`, `after('order_id')` |
| `product_variant_id` | foreignId → `product_variants.id` | yes | null | `nullOnDelete` |
| `barcode` | string(128) | yes | null | Snapshot from the channel payload when present |

Index: `index(['product_variant_id'])`, `index(['sku'])`.

A `BackfillOrderItemProductLinksJob` matches existing `order_items.sku` → `product_variants.sku`
(exact, case-insensitive) → `products.sku`, scoped through `orders.store.organization_id`. Rows that
do not match stay null; the picker falls back to SKU-string matching and shows a "not linked to
catalogue" chip. Sync services (`App\Services\Integrations\*`) should populate these fields going
forward — **[ASSUMPTION]** that requires touching each integration's order mapper; scope it into the
same PR.

`2026_07_22_000016_add_warehouse_scope_to_organization_user.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `default_warehouse_id` | foreignId → `warehouses.id` | yes | null | `nullOnDelete`. Which warehouse this member's app opens to |

The `warehouse_operator` role itself needs **no migration** — `organization_user.role` is already a
plain `string` with default `member`. It requires adding the value to
`OrganizationController::ROLES` (currently `['owner','admin','viewer']`) and to the dashboard role
picker. See §9.

`2026_07_22_000017_add_warehouse_settings_to_organizations.php`

| Column | Type | Null | Default | Notes |
| --- | --- | --- | --- | --- |
| `warehouse_settings` | json | yes | null | `{scanning_enabled, allow_negative_stock, require_location_scan, blind_count_default, count_approval_threshold_units, count_approval_threshold_value, duplicate_scan_window_ms, learn_unknown_barcodes}` |

Defaults applied in `Organization::warehouseSettings()` accessor, so no data migration is needed.

---

## 4. Domain logic

### 4.0 Barcode resolution (shared by every workflow)

`App\Services\Warehouse\BarcodeResolver::resolve(int $orgId, string $raw, ?ResolveContext $ctx): ResolveResult`

```
raw → normalize() → candidates:
  1. product_barcodes WHERE organization_id = ? AND barcode = ?       (authoritative)
  2. product_variants  WHERE sku = ? AND product.organization_id = ?   (SKU-as-barcode fallback)
  3. products          WHERE sku = ? AND organization_id = ?
  4. stock_locations   WHERE organization_id = ? AND (barcode = ? OR code = ?)
  5. pick_lists / receipts / count_sessions WHERE code = ?   (session handoff barcodes)
  6. orders WHERE external_id = ? (through store.organization_id)      (packing slip scan)
→ first hit wins, in that order.
→ no hit → ResolveResult::unknown($raw)
```

`ResolveResult` is a tagged union: `Item{product, variant, packSize}` | `Location{location}` |
`Session{type, id}` | `Order{order}` | `Unknown{barcode}`.

**Ordering rationale:** a stored barcode always beats a SKU coincidence. Location codes are checked
after items because a bin code like `A-01-3` could theoretically collide with a SKU; if it does, the
item wins and the operator sees the wrong thing — mitigated by warning at location-creation time if
the code collides with an existing SKU/barcode.

`learn_unknown_barcodes` (org setting, default **off**): when on, an unknown barcode scanned inside a
pick/receive session while a specific line is focused offers "Link this barcode to `<SKU>`?" and, if
confirmed by a user with `admin`+ role, writes a `product_barcodes` row with `source='scan_learned'`.
Operators alone cannot create mappings — a mislearned barcode silently corrupts every future pick.

---

### 4.1 Pick — state machine

**Pick list**

```
                 assign                start              all lines terminal
   draft ─────────────────► ready ──────────────► in_progress ────────────────► review
     │                        │                       │                            │
     │                        │                       │  supervisor / auto         │
     │                        │                       ▼                            ▼
     └──────────────────► cancelled ◄─────────── paused ◄──── heartbeat lost   completed
                                                    │  resume                       │
                                                    └────────────────────────────► (to pack)
```

| State | Meaning | Allowed transitions |
| --- | --- | --- |
| `draft` | Created by supervisor/automation, lines not finalised | → `ready`, `cancelled` |
| `ready` | Lines finalised, unassigned or assigned, not started | → `in_progress` (claim+start), `cancelled` |
| `in_progress` | An operator has it open | → `paused`, `review`, `cancelled` |
| `paused` | No scan and no heartbeat for 30 min, or explicit pause | → `in_progress` (resume by same or other operator), `cancelled` |
| `review` | Every line terminal but ≥1 line `short` | → `completed` (supervisor accepts), `in_progress` (re-open) |
| `completed` | All lines `picked`, or shorts accepted | terminal → Pack |
| `cancelled` | Abandoned; any picked quantities are **not** reversed (stock never moved — see below) | terminal |

**Pick list item**

```
pending ──► in_progress ──► picked
   │             │
   │             ├──► short          (qty_picked < qty_required, reason captured)
   │             └──► over_pick_hold (scan exceeded required; blocked until resolved)
   └──► skipped  (operator defers; returns to pending at end of walk)
```

**Critical decision — picking does not change stock.**
A pick moves goods from shelf to trolley to box; the units leave the building at *ship*, not at
*pick*. Deducting stock at pick and again at channel-order-sync would double-count. Therefore:

- `POST /pick-lists/{id}/items/{item}/pick` writes `pick_list_items.qty_picked` and a `scan_events`
  row. It writes **no** `inventory_logs` row and dispatches **no** `PushInventoryJob`.
- Stock deduction is the responsibility of the channel order lifecycle (already handled by
  `SyncOrdersJob` / order status), or of the Shipping spec at label creation.
- The one exception: `short_reason = 'damaged'` on a pick line **does** create a negative
  `inventory_logs` adjustment (`source_type='pick_list'`, `reason='Damaged at pick'`) because those
  units are genuinely gone. This is the only stock-mutating path in Pick.

This makes Pick safe to replay: it is a monotonic counter capped at `qty_required`, so a duplicate
delivery of the same event is a no-op even without the idempotency key.

**Validation rules on a pick scan**

| # | Rule | Failure result | Operator feedback |
| --- | --- | --- | --- |
| P1 | Session must be `in_progress` and owned by the caller (or caller is `admin`+) | `session_closed` | Red banner, scan rejected |
| P2 | `require_location_scan` on ⇒ the current line's `stock_location_id` must have been scanned since the last item scan | `wrong_location` | "Scan location A-01-3 first" |
| P3 | Resolved item must match a line in this pick list | `wrong_item` | Heavy haptic + error tone + red flash + the correct item's photo |
| P4 | Resolved item matches a line, but not the *focused* line | Auto-refocus to the matching line, `accepted` | Soft haptic, list scrolls |
| P5 | `qty_picked + delta > qty_required` | `over_pick` | Blocked. Sheet: "Over-pick — reduce, or flag for supervisor" |
| P6 | Same `barcode` + same line within `duplicate_scan_window_ms` (default **1200 ms**) | `duplicate` | Silently ignored, **no** haptic (double-read from the camera is the #1 false positive) |
| P7 | Same `uuid` already ingested | `duplicate` | Stored response replayed |
| P8 | Line already `picked` and at required qty | `over_pick` | "Line complete" chirp, no state change |

**Mispick prevention (the core value prop).** Three layers, all on by default:
1. **Barcode identity** — the item is right because its barcode matched, not because the operator
   thinks it looks right. This alone eliminates the dominant mispick class (similar-looking variants).
2. **Confirm-quantity gate** — for `qty_required > 1`, the operator must either scan N times or
   explicitly enter N in a large numeric pad. No "assume the rest".
3. **Distinct failure feedback** — success is one short tick + `HapticFeedback.selectionClick()`;
   failure is a 400 ms error tone + `HapticFeedback.heavyImpact()` + full-screen red overlay that
   requires a tap to dismiss. Failure must be *impossible to miss with the phone in a pocket*.

**Short-pick handling.** Operator taps "Can't pick" on the focused line → sheet with reason
(`not_found` / `damaged` / `insufficient` / `wrong_location` / `other`), quantity short (defaults to
remaining), optional note, optional photo (reuse `image_picker`, already a dependency). Effects:
- Line → `short`, `qty_short` set, `short_reason` stored, `scan_events` row with `action='short'`.
- Pick list, on completion with any short line, → `review` not `completed`.
- `PickListShortEvent` fires (Automation Rules Engine can route it — e.g. notify, re-allocate to
  another warehouse, cancel the line on the channel).
- `insufficient` shorts additionally raise a **suggested** cycle-count for that SKU: a
  `count_sessions` row is *not* auto-created, but a dashboard suggestion card is (avoids a flood of
  auto-sessions).

---

### 4.2 Pack — state machine

```
open ──► verifying ──► verified ──► labelled ──► closed
  │          │             │                       ▲
  │          │             └──► closed (label deferred / manual) ┘
  └──────────┴──► voided
```

| State | Entry condition |
| --- | --- |
| `open` | Session created for an order (optionally from a completed pick list) |
| `verifying` | First item scanned |
| `verified` | Every `pack_session_items.qty_packed == qty_required` |
| `labelled` | Shipping spec returned a `shipment_ref` + `label_url` |
| `closed` | Operator confirmed handoff; order status update dispatched |
| `voided` | Aborted; any packed counters discarded, `scan_events` retained |

**Validation rules**

| # | Rule | Failure |
| --- | --- | --- |
| K1 | Scanned item must appear in the order | `wrong_item` — hard block, red overlay. *An item in the wrong box is a return, a refund and a review.* |
| K2 | `qty_packed + delta > qty_required` | `over_pick` — blocked |
| K3 | Order must not already be `shipped`/`cancelled` | `session_closed` |
| K4 | Weight required before `labelled` if the org's carrier config demands it | Blocks label button only |
| K5 | Multi-box: an item may only be packed into one `pack_session` of the order | `duplicate` across sessions of the same order |

**Weight & dimensions capture.** Manual numeric entry, with unit toggle (g/kg, mm/cm) persisted per
user. Defaults pre-filled from `packaging_type` presets the org configures once on the dashboard.
Bluetooth scale integration is **out of scope** (Phase 3, BLE weight-scale profile).

**Label handoff (contract with Shipping & Labels spec).**
`POST /pack-sessions/{id}/complete` → `PackService::complete()` →
- If the Shipping module is enabled: dispatch to its service, receive `{shipment_ref, label_url,
  tracking_number, carrier}`, store on `pack_sessions`, return them.
- If not enabled (Phase 1 pilot): return `{shipment_ref: null, label_url: null}` and the app offers
  **"Share packing slip"** — a server-rendered PDF at `GET /pack-sessions/{id}/slip.pdf` shared via
  the already-installed `share_plus` to any printer app / AirPrint / Mopria. This keeps Pack useful
  before Shipping lands. Explicitly a stopgap.

---

### 4.3 Receive — state machine

```
draft ──► in_progress ──► completed
  │            │              ▲
  │            └──► review ───┘   (discrepancies present, supervisor accepts)
  └──► cancelled ◄────────────┘
```

**Stock effect:** unlike Pick, **Receive does mutate stock**, on `completed` — not per scan.
Per-scan writes update `receipt_items.qty_received` only. On `POST /receipts/{id}/complete`,
`ReceivingService` runs one transaction per line:

```
delta = qty_received - qty_damaged
StockMutator::apply(variant|product, +delta, [
  source: 'Warehouse Receive', source_type: 'receipt', source_id: $receipt->id,
  batch_uuid: $receipt->batch_uuid, idempotency_key: $line->uuid, user_id: ...
])
```

Damaged units are recorded but never added to sellable stock; they appear in the discrepancy report.

**Discrepancy handling**
- **Informed receiving** (`expected_lines` present): each line shows `expected` vs `received`.
  `discrepancy = received - expected`. Non-zero on any line ⇒ status `review` instead of `completed`;
  a user with `admin`+ must accept. Accepting still applies the stock.
- **Blind receiving** (no `expected_lines`): every line is `qty_expected = null`, `discrepancy = 0`,
  goes straight to `completed`. Unexpected SKUs are normal here.
- **Unknown barcode** during receive: offer "Create product" (role-gated to `admin`+) or
  "Receive as unidentified" — which records the raw barcode + qty in `receipt_items` with null
  product links, applies **no** stock, and raises a dashboard task.
- **Over-receipt beyond a tolerance** (org setting, default 10% or 5 units, whichever is greater)
  requires a supervisor PIN/override, recorded as `scan_events.action='override'`.

---

### 4.4 Stock / cycle count — state machine

```
draft ──► in_progress ──► submitted ──► under_review ──┬─► approved (applied)
   │           │                                        ├─► rejected
   │           └──► abandoned (no activity 12h)          └─► recount_requested ──► (child session)
   └──► cancelled
```

**Blind vs informed**
- `blind` (**default**): `expected_qty` is *never serialised to the device*. The API response for
  `GET /count-sessions/{id}` omits the field entirely for non-supervisor roles — not merely hides it
  in the UI, since a determined operator can read the JSON. This is the whole point of a blind count.
- `informed`: `expected_qty` is included; used for targeted recounts and for training.

**Accumulate vs replace (the classic footgun).** Counting is **absolute per (session, variant,
location)**, but operators naturally scan each physical unit. Resolution:
- The mobile UI has two explicit modes per line, toggled by a segmented control that is *visible at
  all times*: **"Scan to add +1"** (default) and **"Type total"**.
- In scan mode, the client accumulates locally and sends the **running absolute total** with a
  monotonically increasing `client_seq`. The server stores `counted_qty` = the value from the
  highest `client_seq` seen for that entry, never a sum. Replays are therefore idempotent by
  construction, independent of the `uuid` guard.
- In type mode, the typed value is sent as the absolute with a fresh `client_seq`.

**Variance calculation** (`App\Services\Warehouse\VarianceCalculator`)
- At **submit**: `variance_preview = counted_qty - expected_qty` (snapshot base). Shown to the
  supervisor as "as of session start".
- At **approval**: re-read live stock into `live_qty_at_approval`, then
  `variance = counted_qty - live_qty_at_approval`, and this is what gets applied. Both numbers are
  shown side by side. When they differ, the row is flagged **"stock moved during count"** with the
  intervening `inventory_logs` rows linked. This is the correct behaviour: applying a stale delta
  would erase legitimate sales that happened during the count.
- `variance_value = Σ variance × (variant.price ?? product.price)`. **[ASSUMPTION]** we have no cost
  field anywhere in the schema, so variance is valued at *price*, not cost, and the dashboard must
  label it "at retail value" to avoid a misleading shrinkage number. Adding `cost` to
  `product_variants` is a prerequisite for a real shrinkage report — flagged in §15.

**Approval before applying — always.** No count session mutates stock without an explicit approval
by a user whose role is `admin` or `owner`. Auto-approval below a threshold is configurable
(`count_approval_threshold_units` / `_value`, both default `0` = never auto-approve). Approval:

```
CountService::approve($session, $overrides):
  DB::transaction:
    session->lockForUpdate(); assert status == under_review
    batch = Str::uuid()
    foreach entries chunk(200):
       live = variant->lockForUpdate()->stock
       variance = counted_qty - live   (or override value)
       if variance == 0: mark approved, continue
       StockMutator::apply(..., +variance, source_type: 'count_session',
                           batch_uuid: batch, idempotency_key: entry-scoped uuid)
    session->update(status: approved, applied_log_batch: batch, approved_at, approved_by)
  after commit: dispatch ApplyCountPushJob(batch)  // one PushInventoryJob per touched variant, deduped
```

Chunked + queued because a full count of a 10k-SKU catalogue must not be one 30-second HTTP request.
For sessions above 500 entries, `approve` returns `202 Accepted` and the work runs in
`ApplyCountSessionJob` with progress polled at `GET /count-sessions/{id}` (`apply_progress` field).

---

### 4.5 Idempotency strategy

**Three independent layers**, because each defends a different failure:

1. **Client dedupe window** (device): identical `(barcode, target_line)` inside
   `duplicate_scan_window_ms` (1200 ms default) is dropped before it ever enters the outbox.
   Defends against the camera reading the same label 30×/second.
2. **Idempotency key** (transport): every mutating request carries `Idempotency-Key: <uuid v4>`
   header **and** a `uuid` in the body of each event. Server flow:
   ```php
   // App\Services\Warehouse\ScanIngestService::ingest(array $event, int $orgId): array
   $existing = ScanEvent::where('organization_id',$orgId)->where('uuid',$event['uuid'])->first();
   if ($existing) { return $existing->response + ['idempotent_replay' => true]; }
   DB::transaction(function () { /* insert ScanEvent first, then mutate, then store response */ });
   // Unique violation on (organization_id, uuid) inside the txn → catch QueryException 23000,
   // re-read the winning row, return its stored response. Handles the concurrent-double-flush race.
   ```
   The `scan_events` row is inserted **first** in the transaction so the unique index is the lock.
3. **Semantic idempotency** (domain): the domain operations are designed so a replay is harmless
   even if layers 1–2 fail —
   - Pick: `qty_picked` is a counter capped at `qty_required`; the client sends the *absolute* new
     `qty_picked`, not a delta, together with `client_seq`. Server takes `max(stored, incoming)`
     for the same `client_seq` lineage.
   - Count: absolute value + `client_seq`, highest-seq wins.
   - Receive: per-line absolute `qty_received` + `client_seq`.
   - Stock mutation: `inventory_logs.idempotency_key` has a unique index — a second attempt to write
     the same mutation fails loudly rather than double-adjusting.

   **This is the load-bearing design choice.** Deltas are unsafe over an unreliable network; every
   quantity that crosses the wire in this feature is an absolute with a sequence number.

`StockMutator` is the *only* place in the codebase (after this spec lands) that writes
`product_variants.stock` / `products.stock` outside the integration sync services:

```php
// App\Services\Warehouse\StockMutator::apply(Model $target, int $delta, array $ctx): InventoryLog
// - DB::transaction + lockForUpdate on the target row
// - if ($ctx['idempotency_key'] && InventoryLog::where(org, key)->exists()) return existing;  // replay
// - clamp: if (!$org->warehouseSettings()['allow_negative_stock'] && $before + $delta < 0)
//       → $delta = -$before  and flag $ctx['clamped'] = true
// - record qty_before / qty_after
// - InventoryLog::create([... 'source' => $ctx['source'], 'organization_id' => ..., 'user_id' => ...])
// - dispatch(new PushInventoryJob($variant))  ← closes the existing TODO at InventoryController.php:52
```

`InventoryController::adjust()` should be refactored onto `StockMutator` in the same PR; that single
change also fixes the pre-existing "adjustments never push to channels" bug.

---

### 4.6 Offline sync + conflict resolution

**Model:** the device is an *append-only journal producer*. It never invents authoritative state; it
records intent with a client sequence and reconciles.

**Local schema (Drift, `mobile/lib/features/warehouse/data/warehouse_db.dart`)**

| Table | Purpose |
| --- | --- |
| `catalog_items` | `(variantId PK, productId, sku, name, imageUrl, price, stock, updatedAt)` |
| `catalog_barcodes` | `(barcode PK, variantId, productId, packSize)` — **indexed by barcode**; this is why we use SQLite and not a JSON blob |
| `catalog_locations` | `(id PK, warehouseId, code, barcode, sequence)` |
| `sessions` | Mirror of the active pick/receive/count/pack session + its lines |
| `outbox` | `(id PK AUTOINC, uuid UNIQUE, endpoint, method, bodyJson, sessionKey, clientSeq, createdAt, attempts, nextAttemptAt, status, lastError, httpStatus)` |
| `sync_meta` | `(key PK, value)` — `catalog_cursor`, `last_full_sync_at`, `device_id`, `client_seq` |

`client_seq` is a single monotonic counter per device, incremented inside the same Drift transaction
that inserts the outbox row. It never resets (persisted), so ordering survives app restarts.

**Flush algorithm** (`mobile/lib/features/warehouse/data/sync_service.dart`)

```
triggers: connectivity → online | app resumed | every 15s while online & outbox non-empty
          | explicit pull-to-sync | session completion (flush-before-complete, blocking)

flush():
  if (!online || _inFlight) return
  _inFlight = true
  loop:
    batch = outbox.where(status == pending && nextAttemptAt <= now)
                  .orderBy(sessionKey, clientSeq)   // per-session ordering preserved
                  .limit(100)
    if batch.isEmpty: break
    mark batch inflight
    resp = POST /scan/flush { device_id, client_time, events: [...] }   // ONE request
    for each result in resp.results (matched by uuid):
       accepted | idempotent_replay  → outbox.delete(uuid)
       conflict                       → outbox.status = conflict, surface in Conflicts tray
       validation_error (4xx)         → outbox.status = failed_permanent, surface with reason
       transient (5xx/timeout/offline)→ attempts++, nextAttemptAt = now + backoff(attempts)
    apply resp.session_patches to local session rows   // server-authoritative reconciliation
  _inFlight = false

backoff(n) = min(2^n seconds, 300s) * jitter(0.8..1.2)
after 10 permanent failures on one row → status = dead_letter, banner + "Export queue" (JSON via share_plus)
```

**Never blocked on flush:** every workflow screen is fully usable offline. The only blocking sync is
*completing* a session (complete/submit/approve), which requires the outbox for that session to be
empty — with a clear "Syncing 14 scans…" progress sheet and a "Complete anyway (queued)" escape that
marks the session `pending_completion` locally and completes it server-side on next connection.

**Conflict resolution matrix**

| Conflict | Server rule | Device behaviour |
| --- | --- | --- |
| Pick line qty from two devices | Highest `client_seq` per `(device_id, line)`; different devices → **sum is wrong**, so the server rejects the second device with `conflict:line_owned_by_other_device` | Line shows a lock badge + operator name; operator can request handover |
| Order shipped/cancelled remotely mid-pick | `409 conflict:order_not_pickable` | Line greys out, banner "Order 1042 was cancelled — stop picking these", remaining lines continue |
| Count entry from two devices, same variant+location | Last-write-wins by `client_counted_at`, but **both** values retained (loser stored as a `recount_of_entry_id` child with `status='rejected'`) | Supervisor sees "2 counts disagree: 14 vs 17" in variance review |
| Stock changed remotely during a count | Not a conflict — resolved at approval via `live_qty_at_approval` (§4.4) | Flagged row in the variance report |
| Session completed on the dashboard while the device is offline | `409 conflict:session_closed`; queued scans for it are still **stored** (`result='session_closed'`) so nothing is lost | Read-only session, "This list was completed by Sara at 14:02", queued scans exported to the supervisor's review tray |
| Catalogue item deleted while queued | Scan stored with null resolution, `result='unknown_barcode'` | Line shows "Product removed" |
| Device clock badly skewed | Server compares `client_time` in the flush envelope to its own clock; skew > 5 min ⇒ `client_scanned_at` values are rewritten as `received_at - (client_time - client_scanned_at)` and a `clock_skew_ms` field is stored | Silent; skew shown in device diagnostics |

**Catalogue delta sync.** `GET /scan/catalog?since=<iso8601>&cursor=<opaque>&limit=1000` returns
`{items:[], barcodes:[], locations:[], deleted:{items:[],barcodes:[]}, next_cursor, server_time,
full_resync_required:bool}`. Device stores `server_time` as the next `since`. `full_resync_required`
is returned when `since` is older than the 30-day tombstone retention, forcing a clean rebuild.
A 20k-SKU catalogue is ~4 MB of JSON; gzip + paging keeps first sync under 20 s on 3G, and it
happens once, on the warehouse Wi-Fi, at login.

---

## 5. Backend

### 5.1 Models — `backend/app/Models/`

| File | Notes |
| --- | --- |
| `Warehouse.php` | `belongsTo(Organization)`, `hasMany(StockLocation)`; `scopeForOrg` |
| `StockLocation.php` | `belongsTo(Warehouse)`; `getRouteKeyName()` stays `id` |
| `ProductBarcode.php` | `belongsTo(Product)`, `belongsTo(ProductVariant)`, `belongsTo(Store)`; `setBarcodeAttribute` runs `BarcodeNormalizer` |
| `PickList.php` | `hasMany(PickListItem)`, `belongsToMany(Order, 'pick_list_orders')`, `belongsTo(User,'assigned_user_id')`; `casts: ['meta'=>'array']` |
| `PickListItem.php` | `belongsTo(PickList, OrderItem, ProductVariant, StockLocation)` |
| `PackSession.php`, `PackSessionItem.php` | |
| `Receipt.php` | `casts: ['expected_lines'=>'array']` |
| `ReceiptItem.php` | |
| `CountSession.php` | `casts: ['scope_ref'=>'array']`; `hidden = ['expected_qty']` handled at the Resource layer, not the model |
| `CountEntry.php` | |
| `ScanEvent.php` | `casts: ['response'=>'array','payload'=>'array']`; `$timestamps = true` |

Add to existing models: `Organization::warehouses()`, `Organization::warehouseSettings()` accessor,
`Product::barcodes()`, `ProductVariant::barcodes()`, `Order::pickListItems()`,
`OrderItem::variant()`, `InventoryLog` `$fillable` += the new columns.

### 5.2 Services — `backend/app/Services/Warehouse/`

(Directory is new; `app/Services/` already exists with `EdfaPayService.php` and `Integrations/`.)

| Service | Responsibility |
| --- | --- |
| `BarcodeResolver` | §4.0 resolution ladder. Pure, heavily unit-tested |
| `BarcodeService` | CRUD + import/dedupe + "one primary per item" invariant |
| `ScanIngestService` | Idempotency envelope, `scan_events` write, dispatch to the right workflow service, response caching |
| `StockMutator` | The single stock-write choke point (§4.5) |
| `PickService` | Build lists (order/batch), claim, start, pick, short, complete, cancel |
| `PackService` | Session lifecycle, verification, measurements, Shipping handoff |
| `ReceivingService` | Lines, discrepancy computation, completion → `StockMutator` |
| `CountService` | Session lifecycle, blind-mode serialisation guard, approval → `StockMutator` |
| `VarianceCalculator` | Snapshot vs live variance, valuation |
| `WarehouseService` | Default warehouse bootstrap, location import, org settings |
| `SequenceService` | `PL-`/`PK-`/`RC-`/`CC-` code generation (`{prefix}-{yymm}-{4-digit per-org counter}`) |

### 5.3 Jobs — `backend/app/Jobs/`

| Job | Trigger | Notes |
| --- | --- | --- |
| `ApplyCountSessionJob` | Count approval > 500 entries | Chunked, dispatches deduped `PushInventoryJob`s |
| `ApplyReceiptJob` | Receipt completion > 200 lines | Same shape |
| `BackfillOrderItemProductLinksJob` | One-off + nightly | §3.10 |
| `ExpireStaleWarehouseSessionsJob` | Scheduled hourly | `in_progress` pick lists idle > 30 min → `paused`; count sessions idle > 12 h → `abandoned` |
| `PruneScanEventsJob` | Scheduled nightly | §3.8 retention |
| `PushInventoryJob` | **Existing — reused unchanged** | Dispatched by `StockMutator` |

### 5.4 Events — `backend/app/Events/Warehouse/`

`PickListCreated`, `PickListStarted`, `PickListCompleted`, `PickListItemShorted`,
`PackSessionVerified`, `PackSessionCompleted`, `ReceiptCompleted`, `ReceiptDiscrepancyDetected`,
`CountSessionSubmitted`, `CountSessionApproved`, `ScanRejected`, `UnknownBarcodeScanned`.

Listeners in Phase 1 only write `Notification` rows (existing model) for supervisors. The Automation
Rules Engine spec subscribes to the same events later — that is why they are real events rather than
inline calls.

### 5.5 API endpoints

All new routes go inside the existing `auth:sanctum` → `org.member` group in
`backend/routes/api.php`, in the established flat style. Operator-facing endpoints additionally carry
a new `warehouse.access` middleware (§9). Controllers live in
`backend/app/Http/Controllers/Warehouse/`.

```php
// backend/routes/api.php — inside Route::middleware('org.member')->group(...)

// ── Warehouse setup (admin+) ─────────────────────────────────────────────
Route::get   ('/warehouses',                    [WarehouseController::class, 'index']);
Route::post  ('/warehouses',                    [WarehouseController::class, 'store']);
Route::put   ('/warehouses/{id}',               [WarehouseController::class, 'update']);
Route::get   ('/warehouses/{id}/locations',     [WarehouseController::class, 'locations']);
Route::post  ('/warehouses/{id}/locations',     [WarehouseController::class, 'storeLocation']);
Route::post  ('/warehouses/{id}/locations/import', [WarehouseController::class, 'importLocations']);

// ── Barcodes ─────────────────────────────────────────────────────────────
Route::get   ('/barcodes',                      [BarcodeController::class, 'index']);
Route::post  ('/barcodes',                      [BarcodeController::class, 'store']);
Route::delete('/barcodes/{id}',                 [BarcodeController::class, 'destroy']);
Route::post  ('/barcodes/import',               [BarcodeController::class, 'import']);
Route::post  ('/barcodes/generate',             [BarcodeController::class, 'generate']);

// ── Scan core (operator) ─────────────────────────────────────────────────
Route::post  ('/scan/resolve',                  [ScanController::class, 'resolve']);
Route::post  ('/scan/flush',                    [ScanController::class, 'flush']);
Route::get   ('/scan/catalog',                  [ScanController::class, 'catalog']);
Route::get   ('/scan/bootstrap',                [ScanController::class, 'bootstrap']);
Route::get   ('/scan/events',                   [ScanController::class, 'events']);

// ── Pick ─────────────────────────────────────────────────────────────────
Route::get   ('/pick-lists',                    [PickListController::class, 'index']);
Route::post  ('/pick-lists',                    [PickListController::class, 'store']);
Route::get   ('/pick-lists/{id}',               [PickListController::class, 'show']);
Route::post  ('/pick-lists/{id}/claim',         [PickListController::class, 'claim']);
Route::post  ('/pick-lists/{id}/start',         [PickListController::class, 'start']);
Route::post  ('/pick-lists/{id}/items/{itemId}/pick',  [PickListController::class, 'pick']);
Route::post  ('/pick-lists/{id}/items/{itemId}/short', [PickListController::class, 'short']);
Route::post  ('/pick-lists/{id}/complete',      [PickListController::class, 'complete']);
Route::post  ('/pick-lists/{id}/cancel',        [PickListController::class, 'cancel']);

// ── Pack ─────────────────────────────────────────────────────────────────
Route::post  ('/pack-sessions',                 [PackSessionController::class, 'store']);
Route::get   ('/pack-sessions/{id}',            [PackSessionController::class, 'show']);
Route::post  ('/pack-sessions/{id}/scan',       [PackSessionController::class, 'scan']);
Route::post  ('/pack-sessions/{id}/measurements',[PackSessionController::class, 'measurements']);
Route::post  ('/pack-sessions/{id}/complete',   [PackSessionController::class, 'complete']);
Route::post  ('/pack-sessions/{id}/void',       [PackSessionController::class, 'void']);
Route::get   ('/pack-sessions/{id}/slip',       [PackSessionController::class, 'slip']);

// ── Receive ──────────────────────────────────────────────────────────────
Route::get   ('/receipts',                      [ReceiptController::class, 'index']);
Route::post  ('/receipts',                      [ReceiptController::class, 'store']);
Route::get   ('/receipts/{id}',                 [ReceiptController::class, 'show']);
Route::post  ('/receipts/{id}/lines',           [ReceiptController::class, 'line']);
Route::post  ('/receipts/{id}/complete',        [ReceiptController::class, 'complete']);
Route::post  ('/receipts/{id}/cancel',          [ReceiptController::class, 'cancel']);

// ── Count ────────────────────────────────────────────────────────────────
Route::get   ('/count-sessions',                [CountSessionController::class, 'index']);
Route::post  ('/count-sessions',                [CountSessionController::class, 'store']);
Route::get   ('/count-sessions/{id}',           [CountSessionController::class, 'show']);
Route::post  ('/count-sessions/{id}/entries',   [CountSessionController::class, 'entries']);
Route::post  ('/count-sessions/{id}/submit',    [CountSessionController::class, 'submit']);
Route::get   ('/count-sessions/{id}/variance',  [CountSessionController::class, 'variance']);
Route::post  ('/count-sessions/{id}/approve',   [CountSessionController::class, 'approve']);
Route::post  ('/count-sessions/{id}/reject',    [CountSessionController::class, 'reject']);
Route::post  ('/count-sessions/{id}/recount',   [CountSessionController::class, 'recount']);
```

#### Selected endpoint contracts

**`POST /scan/resolve`** — role: any warehouse role. Online-only convenience; the device normally
resolves locally against its Drift cache and only calls this on a cache miss.

```jsonc
// request
{ "barcode": "6281006021234", "symbology": "ean13", "context": "pick", "session_id": 42 }
// validation
// barcode: required|string|max:160 ; symbology: nullable|string|max:24
// context:  nullable|in:pick,pack,receive,count,lookup ; session_id: nullable|integer
// 200
{
  "type": "item",                       // item | location | session | order | unknown
  "barcode": "6281006021234",
  "pack_size": 1,
  "product":  { "id": 91, "name": "Oud Perfume 50ml", "sku": "OUD-50", "image_url": "…" },
  "variant":  { "id": 310, "sku": "OUD-50-GOLD", "stock": 42, "price": "189.00" },
  "locations": [{ "id": 7, "code": "A-01-3", "warehouse": "MAIN" }],   // last seen, advisory
  "channels": [{ "store_id": 3, "platform": "salla", "synced": true, "price": "199.00" }]
}
// 404 → { "type": "unknown", "barcode": "…", "message": "No product matches this barcode.",
//         "can_link": true }   // can_link reflects the caller's role + org setting
```

**`POST /scan/flush`** — the offline drain. Always returns **200** with per-event results; the HTTP
status describes the transport, never the individual events.

```jsonc
// request
{
  "device_id": "a3f1…",
  "client_time": "2026-07-22T09:14:02.331Z",
  "events": [
    { "uuid": "9f0c…", "session_type": "pick", "session_id": 42, "target_type": "pick_list_item",
      "target_id": 903, "action": "scan", "barcode": "6281006021234", "symbology": "ean13",
      "input_method": "camera", "qty_absolute": 2, "client_seq": 10412,
      "client_scanned_at": "2026-07-22T09:11:44.120Z", "was_offline": true,
      "stock_location_id": 7 }
    // … up to 200
  ]
}
// validation
// device_id: required|string|max:64
// client_time: required|date
// events: required|array|max:200
// events.*.uuid: required|uuid
// events.*.session_type: required|in:pick,pack,receive,count,lookup
// events.*.session_id: nullable|integer
// events.*.action: required|in:scan,location_scan,qty_set,short,undo,override
// events.*.qty_absolute: nullable|integer|min:0|max:1000000
// events.*.client_seq: required|integer|min:0
// events.*.client_scanned_at: required|date
// 200
{
  "server_time": "2026-07-22T09:14:02.998Z",
  "clock_skew_ms": 412,
  "results": [
    { "uuid": "9f0c…", "result": "accepted", "idempotent_replay": false,
      "patch": { "pick_list_item": { "id": 903, "qty_picked": 2, "status": "picked" } } },
    { "uuid": "b71e…", "result": "conflict", "reason": "order_not_pickable",
      "message": "Order #1042 was cancelled.",
      "patch": { "pick_list_item": { "id": 904, "status": "cancelled" } } }
  ],
  "session_patches": [
    { "type": "pick_list", "id": 42, "status": "in_progress", "picked_count": 7, "item_count": 12 }
  ]
}
```

`patch` / `session_patches` are the reconciliation channel: the device applies them to its Drift
mirror verbatim, so server truth always wins without a second round trip.

**`POST /pick-lists`** (supervisor)

```jsonc
// request — either explicit orders, or a filter
{ "type": "batch", "warehouse_id": 1, "order_ids": [1042, 1043, 1051],
  "assigned_user_id": 12, "priority": 3 }
// or
{ "type": "order", "filter": { "status": "paid", "store_id": 3, "limit": 25 } }
// validation
// type: required|in:order,batch
// warehouse_id: nullable|integer|exists:warehouses,id  (+ org scope check in the FormRequest)
// order_ids: required_without:filter|array|min:1|max:100
// order_ids.*: integer|exists:orders,id                (+ org scope via orders.store.organization_id)
// assigned_user_id: nullable|integer   (must be a member of the org)
// 201
{ "id": 42, "code": "PL-2607-0042", "type": "batch", "status": "ready", "item_count": 12, "items": [...] }
// 422 → order already on an open pick list: { "message": "Order #1042 is already on PL-2607-0039." }
```

**`POST /pick-lists/{id}/items/{itemId}/pick`** — the single-scan online path (the offline path goes
through `/scan/flush` with identical semantics).

```jsonc
// headers: Idempotency-Key: <uuid>
// request
{ "uuid": "9f0c…", "qty_absolute": 2, "barcode": "6281006021234",
  "stock_location_id": 7, "client_seq": 10412, "client_scanned_at": "…", "input_method": "camera" }
// validation
// uuid: required|uuid ; qty_absolute: required|integer|min:0
// barcode: nullable|string|max:160 ; client_seq: required|integer
// 200  { "result": "accepted", "item": { "id":903, "qty_picked":2, "status":"picked" },
//        "pick_list": { "picked_count": 7, "item_count": 12 } }
// 409  { "result": "conflict", "reason": "over_pick", "message": "…", "item": { … } }
// 409  { "result": "conflict", "reason": "wrong_item", "expected_sku": "OUD-50-GOLD" }
// 423  { "result": "conflict", "reason": "line_owned_by_other_device", "owner": "Sara A." }
```

**`POST /count-sessions/{id}/entries`** — accepts one entry or an array (the offline path batches).

```jsonc
{ "entries": [
  { "uuid": "…", "barcode": "6281006021234", "counted_qty": 17, "stock_location_id": 7,
    "client_seq": 883, "client_counted_at": "…", "note": null }
]}
// validation: entries: required|array|max:500 ; entries.*.counted_qty: required|integer|min:0
// 200 { "results": [ { "uuid":"…", "result":"accepted",
//        "entry": { "id": 5501, "counted_qty": 17, "status": "counted" } } ],
//       "session": { "lines_counted": 44, "lines_total": 120 } }
// NOTE: expected_qty is absent from every response while mode == 'blind' and the
//       caller's role is warehouse_operator. It is a serialiser-level omission, not a UI concern.
```

**`POST /count-sessions/{id}/approve`** (admin+)

```jsonc
{ "overrides": [ { "entry_id": 5501, "counted_qty": 16, "note": "recounted with supervisor" } ],
  "note": "July cycle count, aisle A" }
// 200 (small sessions)  { "status":"approved", "applied": 44, "variance_units": -13,
//                         "variance_value": "-2457.00", "batch_uuid": "…" }
// 202 (>500 entries)    { "status":"applying", "job_id": "…", "poll": "/count-sessions/42" }
// 403 if role not in [admin, owner]
// 409 if status != 'under_review'
```

**Error envelope.** All warehouse endpoints return Laravel's standard
`{message, errors?}` shape so the existing `ApiClient.messageFrom()` keeps working unchanged, with an
added optional `reason` machine code the app switches on.

**Rate limits.** `/scan/flush` and `/scan/resolve`: `throttle:600,1` per user (a fast picker does
~1 scan/2 s; 600/min leaves headroom for batch drains). All others: default.

---

## 6. Mobile implementation

### 6.1 Scanning package choice — `mobile_scanner`

**Recommendation: `mobile_scanner: ^7.1.2`** (verify the latest patch at implementation time).

| Criterion | `mobile_scanner` | `google_mlkit_barcode_scanning` |
| --- | --- | --- |
| What it is | Full camera plugin **with** a ready `MobileScanner` widget, controller, torch, zoom, camera switch, scan window | A detector only — you must supply the camera (`camera` package), manage `CameraImage` → `InputImage` conversion, rotation, YUV planes, and lifecycle yourself |
| Android engine | MLKit Barcode Scanning (same engine) | MLKit Barcode Scanning |
| iOS engine | **AVFoundation native** `AVCaptureMetadataOutput` — no MLKit pods | MLKit iOS pods (~10–15 MB added to the IPA, and MLKit iOS is a heavier dependency graph) |
| App size | Android: choose bundled (`barcode-scanning`, ~2.5 MB) or Play-Services-unbundled | Effectively forces bundled model on both platforms |
| Boilerplate to first scan | ~15 lines | ~150 lines (camera stream, `InputImageRotation` per device orientation, throttling) |
| Torch / zoom / autofocus control | First-class on the controller | You implement it against the `camera` package |
| `detectionSpeed` + `detectionTimeoutMs` throttling | Built in (`DetectionSpeed.noDuplicates` is exactly our §4.1-P6 rule) | Hand-rolled |
| Analyze a still image (for tests / gallery) | `controller.analyzeImage(path)` | `InputImage.fromFilePath` |
| Maintenance | Actively maintained, large user base | Maintained, but as a raw detector |

**Decision:** `mobile_scanner`. The deciding factors are (a) it gives us a production camera widget
with torch/zoom/lifecycle that we would otherwise write and maintain ourselves, (b) the iOS path
avoids MLKit pods entirely, which matters for an app that is currently Android-only and will want a
lean first iOS build, and (c) `DetectionSpeed.noDuplicates` + `detectionTimeoutMs` implements our
duplicate-suppression requirement natively.

**Android model variant:** use the **bundled** MLKit model (`com.google.mlkit:barcode-scanning`),
not the Play-Services-unbundled variant. Warehouses run cheap Android devices that may lack current
Play Services and will be offline; a scanner that needs to download its model on first use is a
support ticket on day one. Cost: ~+2.5 MB APK. Worth it. Configure in
`android/app/build.gradle.kts` per the plugin README.

**Symbologies.** Restrict the detector to what warehouses actually use — narrowing formats measurably
improves decode latency:

```dart
formats: const [
  BarcodeFormat.ean13, BarcodeFormat.ean8,
  BarcodeFormat.upcA,  BarcodeFormat.upcE,
  BarcodeFormat.code128, BarcodeFormat.code39, BarcodeFormat.itf,
  BarcodeFormat.qrCode, BarcodeFormat.dataMatrix,
],
```
(Code93, PDF417, Aztec, Codabar deliberately excluded; add per-org later if a pilot needs them.)

**Controller configuration**

```dart
// mobile/lib/features/warehouse/scanner/scan_source.dart
MobileScannerController(
  formats: kWarehouseFormats,
  detectionSpeed: DetectionSpeed.noDuplicates,
  detectionTimeoutMs: 350,          // floor between distinct accepted reads
  facing: CameraFacing.back,
  torchEnabled: false,
  autoZoom: true,                   // helps with small labels
  cameraResolution: const Size(1280, 720),  // 720p: decodes fine, halves CPU vs 1080p
);
```

**Performance targets (pilot exit criteria):** camera warm-up (cold) < 800 ms, warm re-entry < 250 ms,
scan-to-feedback < 150 ms, sustained scanning ≥ 30 min without a thermal throttle stall on a
mid-range device (Galaxy A-series class). Achieved by: 720p, restricted formats, `scanWindow` limited
to the centre reticle (~40% of frame area), and **pausing the controller whenever a modal/sheet is
open** (`controller.stop()` in `deactivate()`, `start()` in `didChangeAppLifecycleState.resumed`).

**Permissions**

- **Android** — add to `mobile/android/app/src/main/AndroidManifest.xml` (currently has none):
  ```xml
  <uses-permission android:name="android.permission.CAMERA" />
  <uses-feature android:name="android.hardware.camera" android:required="false" />
  <uses-feature android:name="android.hardware.camera.autofocus" android:required="false" />
  ```
  `required="false"` keeps the app installable on camera-less devices (the rest of Hubby works fine).
  `minSdk` must be ≥ 21 — currently `flutter.minSdkVersion`, which satisfies this; pin it explicitly
  to `21` in `android/app/build.gradle.kts` to prevent a Flutter upgrade surprise.
- **iOS** — add to `mobile/ios/Runner/Info.plist`:
  ```xml
  <key>NSCameraUsageDescription</key>
  <string>Hubby uses the camera to scan product barcodes for picking, packing, receiving and stock counts.</string>
  ```
  Arabic localisation of the string goes in `ios/Runner/ar.lproj/InfoPlist.strings`. **[ASSUMPTION]**
  iOS is not currently a build target; this wiring is included so the first iOS build is not blocked.
- **Runtime** — `mobile_scanner` requests the permission itself on `start()`. We wrap it: the app
  shows a purpose-explaining screen *before* triggering the OS prompt (materially improves grant
  rate), and a "permanently denied → Open settings" state using `permission_handler` (new dep) for
  the `openAppSettings()` call.

### 6.2 New Flutter dependencies

Append to `mobile/pubspec.yaml` (versions latest-known at authoring; re-pin at implementation):

```yaml
  # Warehouse scanning (spec 08)
  mobile_scanner: ^7.1.2          # camera barcode/QR scanning
  drift: ^2.28.2                  # offline outbox + catalogue cache
  drift_flutter: ^0.2.6
  sqlite3_flutter_libs: ^0.5.40
  connectivity_plus: ^7.0.0       # flush triggers
  uuid: ^4.5.1                    # idempotency keys
  audioplayers: ^6.5.1            # scan success/failure tones
  device_info_plus: ^12.1.0       # stable device_id
  permission_handler: ^12.0.1     # openAppSettings on permanent denial

dev_dependencies:
  drift_dev: ^2.28.2
  build_runner: ^2.5.4
```

`path_provider` (DB path) and `share_plus` (queue export, packing slip) are **already present** — no
change needed.

**Why Drift over Hive/sembast/plain sqflite:** the catalogue cache needs an *indexed lookup on a
barcode string across up to 100k rows in under 5 ms* while the camera is running. That is a B-tree
index, i.e. SQLite. Over raw `sqflite`, Drift adds compile-time-checked queries, typed DAOs, and
first-class migrations — all of which matter for an outbox whose corruption means lost stock
movements. `NativeDatabase.memory()` also makes the sync logic unit-testable without a device.

### 6.3 File layout — `mobile/lib/features/warehouse/`

Matching the existing convention (`features/<name>/<name>_page.dart` + `cubit/<name>_cubit.dart`,
sheets as `*_sheet.dart`):

```
mobile/lib/features/warehouse/
├── warehouse_home_page.dart              # hub: 5 large tiles + sync status + open sessions
├── cubit/
│   └── warehouse_cubit.dart              # bootstrap, warehouse selection, org settings, online state
├── scanner/
│   ├── scan_source.dart                  # abstract ScanSource + MobileScannerSource + FakeScanSource
│   ├── scanner_view.dart                 # camera + reticle + torch + HID overlay (the shared widget)
│   ├── scan_feedback.dart                # haptics + tones + colour flash
│   ├── hid_scanner_listener.dart         # external Bluetooth keyboard-wedge capture
│   └── manual_entry_sheet.dart           # type a barcode (damaged label / no camera)
├── pick/
│   ├── pick_lists_page.dart              # queue: assigned to me / unassigned / in progress
│   ├── pick_session_page.dart            # the scanning screen
│   ├── short_pick_sheet.dart
│   └── cubit/pick_cubit.dart
├── pack/
│   ├── pack_order_picker_page.dart       # scan a packing slip / pick list, or choose an order
│   ├── pack_session_page.dart
│   ├── measurements_sheet.dart
│   └── cubit/pack_cubit.dart
├── receive/
│   ├── receipts_page.dart
│   ├── receive_session_page.dart
│   ├── receive_line_sheet.dart           # qty, damaged, location, unit cost
│   └── cubit/receive_cubit.dart
├── count/
│   ├── count_sessions_page.dart
│   ├── count_session_page.dart
│   ├── count_entry_sheet.dart
│   └── cubit/count_cubit.dart
├── lookup/
│   ├── lookup_page.dart                  # scan → product/stock/price/channels
│   └── cubit/lookup_cubit.dart
├── sync/
│   ├── sync_service.dart                 # flush loop, backoff, connectivity
│   ├── sync_status_bar.dart              # the persistent "12 queued • offline" strip
│   └── conflicts_page.dart               # conflicted / dead-lettered events tray
├── data/
│   ├── warehouse_db.dart                 # Drift database + tables
│   ├── warehouse_db.g.dart               # generated
│   ├── outbox_dao.dart
│   ├── catalog_dao.dart
│   └── session_dao.dart
└── widgets/
    ├── scan_target_card.dart             # big item card: photo, name, SKU, 12/20 progress ring
    ├── qty_stepper.dart                  # 56dp +/- with a numeric-pad sheet
    ├── location_chip.dart
    └── session_progress_bar.dart
```

Plus:
- `mobile/lib/data/repositories/warehouse_repository.dart` — the only class that talks to `ApiClient`
  for warehouse endpoints (matches `data/repositories/auth_repository.dart`).
- Registration in `mobile/lib/main.dart`: `RepositoryProvider.value(value: warehouseDb)`,
  `RepositoryProvider.value(value: warehouseRepo)`, `BlocProvider.value(value: warehouseCubit)`,
  and `syncService.start()` after `runApp` bootstrapping.

### 6.4 Routing

`mobile/lib/core/router/app_router.dart` — add to the flat `routes:` list (matching current style):

```dart
GoRoute(path: '/warehouse',            builder: (_, _) => const WarehouseHomePage()),
GoRoute(path: '/warehouse/pick',       builder: (_, _) => const PickListsPage()),
GoRoute(path: '/warehouse/pick/:id',   builder: (_, s) => PickSessionPage(id: int.parse(s.pathParameters['id']!))),
GoRoute(path: '/warehouse/pack',       builder: (_, _) => const PackOrderPickerPage()),
GoRoute(path: '/warehouse/pack/:id',   builder: (_, s) => PackSessionPage(id: int.parse(s.pathParameters['id']!))),
GoRoute(path: '/warehouse/receive',    builder: (_, _) => const ReceiptsPage()),
GoRoute(path: '/warehouse/receive/:id',builder: (_, s) => ReceiveSessionPage(id: int.parse(s.pathParameters['id']!))),
GoRoute(path: '/warehouse/count',      builder: (_, _) => const CountSessionsPage()),
GoRoute(path: '/warehouse/count/:id',  builder: (_, s) => CountSessionPage(id: int.parse(s.pathParameters['id']!))),
GoRoute(path: '/warehouse/lookup',     builder: (_, _) => const LookupPage()),
GoRoute(path: '/warehouse/conflicts',  builder: (_, _) => const ConflictsPage()),
```

**Entry point.** `warehouse_operator` users land on `/warehouse` instead of `AppShell` (redirect rule
added to the existing `redirect:` callback). Everyone else reaches it from a new "Warehouse" tile in
`mobile/lib/features/more/more_page.dart`, plus a scan FAB on `InventoryPage`.
The 5-tab `AppShell` is **not** changed — a sixth tab would crowd it and operators do not need the
other tabs.

### 6.5 Screen-by-screen

#### A. `warehouse_home_page.dart`
- `PageHeader(title: context.t('wh.title'))` + warehouse selector chip (hidden when the org has one
  warehouse).
- 2×3 grid of 96 dp `AppCard` tiles: Pick, Pack, Receive, Count, Lookup, Conflicts — each with a
  Lucide icon (`clipboardList`, `packageCheck`, `truck`, `listChecks`, `scanLine`, `alertTriangle`),
  a title, and a live count badge ("3 lists waiting").
- Persistent `SyncStatusBar` pinned above the bottom inset: `● Online — synced` /
  `◐ Offline — 12 scans queued` / `⚠ 2 conflicts`. Tapping opens `/warehouse/conflicts`.
- States: `loading` (Skeleton grid) → `ready` → `error` (`ErrorView` with retry) →
  `offline_ready` (identical, from cache, with the bar in offline styling).

#### B. `pick_lists_page.dart`
Segmented control: **Mine** / **Unassigned** / **In progress**. Each row is an `AppCard`:
code, order count, item count, priority dot, progress ring, and an oldest-order age chip.
Tap → claim (if unassigned) → `/warehouse/pick/:id`.
States: `loading` / `empty` (`EmptyState`, "No lists assigned to you") / `ready` / `error`.

#### C. `pick_session_page.dart` — the flagship screen

Layout, bottom-anchored for one-handed use:

```
┌──────────────────────────────────┐
│ PL-2607-0042      7/12   ⏸  ✕    │  compact app bar, 48dp targets
├──────────────────────────────────┤
│                                  │
│      camera preview (40% h)      │  reticle + torch FAB bottom-trailing
│      ┌──────────────┐            │  "Scan location A-01-3" hint when P2 pending
│      └──────────────┘            │
├──────────────────────────────────┤
│  📷 [photo]  Oud Perfume 50ml    │  ScanTargetCard — the focused line
│              OUD-50-GOLD          │  SKU in LTR isolate even in Arabic
│              📍 A-01-3            │
│                                  │
│         ( 2 ) / 5                │  56dp numerals, tap → numeric pad
│      [ − ]        [ + ]          │  64dp targets, thumb-reachable
├──────────────────────────────────┤
│  [ Can't pick ]   [ Confirm ✓ ]  │  full-width, 56dp, bottom-anchored
└──────────────────────────────────┘
   ▸ Remaining lines (5)  ← collapsed sheet, drag up
```

`PickCubit` state (Equatable + `copyWith`, matching `StoresState`):

```dart
enum PickPhase { loading, ready, scanning, awaitingLocation, confirming,
                 lineComplete, listComplete, conflict, error }

class PickState extends Equatable {
  const PickState({
    this.phase = PickPhase.loading,
    this.list,                      // PickListModel
    this.items = const [],          // List<PickItemModel>
    this.focusedIndex = 0,
    this.lastScan,                  // ScanOutcome (accepted/rejected + reason)
    this.pendingLocationId,
    this.queuedCount = 0,
    this.online = true,
    this.error,
  });
  // copyWith + props
}
```

Cubit methods: `load(id)`, `start()`, `onScan(RawScan)`, `focusLine(index)`, `setQty(int)`,
`confirmLine()`, `shortLine(reason, qty, note, photo)`, `undoLast()`, `pause()`, `complete()`.

`onScan` flow (all local-first, ≤ 16 ms of work on the UI isolate):
```
RawScan → normalize → dedupe window (1200ms) → catalogDao.resolveBarcode()
  → Location?  → set pendingLocationId, success feedback, return
  → Item?      → match against items:
        no match          → reject(wrong_item)  → error feedback + red overlay
        match, not focused→ focusLine(i)        → soft feedback
        match, focused    → qty+pack_size
              > required  → reject(over_pick)
              == required → phase=lineComplete, auto-advance after 600ms
              < required  → phase=confirming
  → accepted  → outboxDao.enqueue(PickScanEvent(uuid, qtyAbsolute, clientSeq…))
              → optimistic local state update
              → syncService.nudge()
```

**Auto-advance** after a line completes: 600 ms delay, then focus the next `pending` line and haptic
tick. Cancelable by touching the screen (operator wanted to review).

**Undo:** last accepted scan is undoable for 10 s — enqueues an `action:'undo'` event referencing the
prior `uuid`; server decrements back to the previous absolute. Beyond 10 s the operator uses the
quantity stepper.

#### D. `pack_session_page.dart`
Same camera-on-top / target-below skeleton. Differences:
- Entry: scan a picking-list barcode, a packing slip, or a channel order barcode
  (`BarcodeResolver` handles all three), or choose from a list.
- Body is a **checklist of all order lines** with per-line `packed/required`, not a single focus card
  — packers verify a whole box at a glance.
- `verified` state turns the header green and enables the bottom bar: **Weight & size** → then
  **Create label** (Shipping) or **Share packing slip** (fallback).
- Wrong-item scan is a full-screen red modal requiring an explicit "I removed it" tap. Not
  dismissible by another scan.

#### E. `receive_session_page.dart`
- Header shows supplier + reference; a mode chip **Informed** / **Blind**.
- Scan → line sheet: qty (default +1, stepper), damaged count, put-away location, optional unit cost,
  optional photo. Repeat scans of the same SKU increment the same line.
- Unknown barcode → sheet with **Create product** (role-gated) / **Link to existing** (role-gated) /
  **Receive as unidentified**.
- Bottom bar: `Complete` → discrepancy summary sheet → confirm.

#### F. `count_session_page.dart`
- **Blind mode**: expected quantity is not present in state at all (the model field is null because
  the API omitted it) — there is no way for the UI to leak it.
- Prominent per-line segmented control: **Scan +1** / **Type total**.
- Progress: "44 / 120 lines". A "Skip" action marks `status='skipped'`.
- `Submit` → local flush (blocking, with progress) → server computes the variance preview → summary
  screen: total lines, lines with variance, Σ units, Σ value, top 5 variances. Operator sees the
  variance *after* submitting even in blind mode (they earned it), but cannot change entries
  afterwards without a supervisor-requested recount.

#### G. `lookup_page.dart`
Continuous scanning, no session. Each scan pushes a result card: photo, name, SKU, barcode(s),
current stock, price, per-channel rows (platform logo via the existing `platform_logo.dart` +
`AppPalette.platform` colours), last 5 `inventory_logs` entries, and the last known location.
Actions: **Adjust stock** (reuses the existing `openAdjustSheet` from
`mobile/lib/features/inventory/adjust_sheet.dart` — no new UI), **Open product**, **Add barcode**
(role-gated). Works fully offline from the catalogue cache, with stock marked "as of 09:12".

### 6.6 Ergonomics

| Requirement | Implementation |
| --- | --- |
| One-handed | Every action control lives in the bottom 35% of the screen. The camera is on top (where the phone points anyway), the confirm/short buttons at the thumb. No top-right destructive actions. |
| Large targets | Minimum 56 dp; primary actions 64 dp; quantity steppers 64 dp with 12 dp spacing. Exceeds the 48 dp WCAG 2.1 AA (2.5.5) target-size guidance, because operators wear gloves. |
| Haptics | Success `HapticFeedback.selectionClick()`; line complete `HapticFeedback.mediumImpact()`; failure `HapticFeedback.heavyImpact()` twice, 120 ms apart. |
| Sound | `audioplayers` with `assets/sounds/scan_ok.wav` (1.2 kHz, 60 ms) and `scan_err.wav` (400 Hz, 350 ms, two pulses). Preloaded at session start; plays on the media channel so it is audible over warehouse noise; respects a per-user mute toggle stored in `SessionStore`. |
| Visual | 120 ms full-bleed colour flash — `AppPalette.secondary` (#4FD34A) success, `AppPalette.destructive` (#F24B4B) failure — plus the reticle turning solid. **Never colour-only**: success shows a check glyph, failure an X glyph, for colour-blind operators and for glare. |
| Glove / wet hands | No swipe-only actions. Every gesture has a button equivalent. |
| Screen | `WakelockPlus` is *not* added; instead the session screens call `SystemChrome.setEnabledSystemUIMode(immersiveSticky)` and we document setting device screen timeout to 5 min. **[ASSUMPTION]** — if pilot feedback demands it, add `wakelock_plus`. |
| Accessibility | Every scan outcome is also announced via `SemanticsService.announce(msg, direction)` so TalkBack/VoiceOver users get it; all icon buttons carry `tooltip`/`Semantics(label:)`. |

**Arabic / RTL correctness (non-negotiable details)**

1. **The camera preview must never mirror.** No `Transform.scale(x:-1)` and no
   `Directionality`-driven flip on the preview widget — wrap `MobileScanner` in
   `Directionality(textDirection: TextDirection.ltr, child: …)`.
2. **Codes stay LTR.** SKUs, barcodes and location codes are bidi-neutral strings that Arabic context
   will reorder (`A-01-3` can render as `3-01-A`). Every such string renders through a helper:
   ```dart
   // mobile/lib/features/warehouse/widgets/code_text.dart
   Widget codeText(String v, TextStyle? s) => Directionality(
       textDirection: TextDirection.ltr,
       child: Text('⁦$v⁩', style: s));   // LRI … PDI isolate
   ```
3. **Progress ratios** (`7 / 12`) use the same isolate — otherwise Arabic renders `12 / 7`.
4. **Digits stay Latin** in operational numerals. `intl` with locale `ar` yields Arabic-Indic digits
   (٧/١٢). Warehouse quantities use `NumberFormat.decimalPattern('en')` even in Arabic; prose and
   money keep the locale formatting. **Flagged as an open question** (§15) — validate with Gulf pilot
   operators, some prefer Arabic-Indic.
5. Layout uses `EdgeInsetsDirectional` / `AlignmentDirectional` throughout (the codebase already does
   this — see `inventory_page.dart:113`).
6. Directional icons (`chevronRight`) get `Transform.flip(flipX: isRtl)` or the mirrored Lucide glyph.
7. Arabic UI font is Alexandria (already bundled); Satoshi stays for Latin codes/numbers.

### 6.7 Offline storage & device identity

- DB file at `${getApplicationDocumentsDirectory()}/hubby_warehouse.sqlite`, opened via
  `driftDatabase(name: 'hubby_warehouse')`.
- **Multi-tenant safety:** every cached row carries `organizationId`. On active-org change or logout,
  `WarehouseDb.wipeOrg(orgId)` runs, and `SessionStore.clear()` gains a companion
  `warehouseDb.wipeAll()` call in `AuthBloc`'s logout handler. **Cached catalogue data must never
  survive a logout** — it is another tenant's data the moment someone else signs in.
- `device_id`: generated once (`Uuid().v4()`), stored in `sync_meta`, *not* derived from
  `device_info_plus` hardware IDs (privacy + Android 10+ restrictions). `device_info_plus` is used
  only for a human-readable model name in diagnostics.
- **Outbox durability:** enqueue happens in the same Drift transaction as the optimistic local state
  update. If the app is killed between them, either both or neither happened.
- **Size guard:** if the outbox exceeds 5,000 rows or the DB exceeds 200 MB, new sessions are blocked
  with "Sync required — 5,000 scans pending". Prevents an unbounded silent backlog.

### 6.8 External Bluetooth scanner (HID)

See §8 for hardware. The Flutter side:

```dart
// mobile/lib/features/warehouse/scanner/hid_scanner_listener.dart
// A HID scanner is a Bluetooth keyboard: it types the barcode fast, then Enter/Tab.
// Detection heuristic: >=4 chars, mean inter-key interval < 30ms, terminated by Enter/Tab
// within 500ms of the first key. Human typing is an order of magnitude slower.
class HidScannerListener extends StatefulWidget { ... }
```

Implementation notes:
- Wrap each scan screen in `HidScannerListener` → a `Focus` node with
  `HardwareKeyboard.instance.addHandler` (Flutter 3.x API; `RawKeyboard` is deprecated).
- Keep an always-focused, zero-size `TextField` with `keyboardType: TextInputType.none` and
  `showCursor: false` as a fallback capture path on devices where key events do not reach the
  handler; `TextInputType.none` prevents the soft keyboard from covering the UI.
- The buffer flushes on `Enter`/`Tab` or on a 120 ms idle timeout, then feeds the *same*
  `ScanSource.onScan` pipeline with `input_method: 'hid'`. **Zero workflow code changes** — this is
  why `ScanSource` is an abstraction rather than the camera widget calling the cubit directly.
- When an HID device is connected (heuristic: first successful HID scan in the session), the camera
  auto-pauses and a "🔗 Scanner connected" chip appears with a tap-to-reenable-camera action. Saves
  significant battery.
- Mixed mode is allowed: camera and HID can both feed the same session.

### 6.9 i18n keys

Append to **both** the `'en'` and `'ar'` maps in `mobile/lib/l10n/strings.dart` (flat keys, matching
the existing convention). Full set:

```dart
// ── en ──────────────────────────────────────────────────────────────────
'wh.title': 'Warehouse',
'wh.pick': 'Pick', 'wh.pack': 'Pack', 'wh.receive': 'Receive',
'wh.count': 'Stock count', 'wh.lookup': 'Lookup', 'wh.conflicts': 'Conflicts',
'wh.selectWarehouse': 'Warehouse',
'wh.online': 'Online — synced',
'wh.offline': 'Offline — working from cache',
'wh.queued': 'scans queued',
'wh.syncing': 'Syncing…',
'wh.syncNow': 'Sync now',
'wh.conflictsCount': 'items need attention',
'wh.cameraPermTitle': 'Camera access',
'wh.cameraPermBody': 'Hubby uses the camera to scan barcodes. Nothing is recorded or uploaded.',
'wh.cameraPermGrant': 'Allow camera',
'wh.cameraPermDenied': 'Camera access is off. Open settings to turn it on.',
'wh.openSettings': 'Open settings',
'wh.torch': 'Torch',
'wh.lowLight': 'Too dark — turn on the torch',
'wh.manualEntry': 'Type barcode',
'wh.manualEntryHint': 'Enter the number under the barcode',
'wh.scannerConnected': 'Bluetooth scanner connected',
'wh.useCamera': 'Use camera',
'wh.scanPrompt': 'Point at a barcode',
'wh.scanLocationFirst': 'Scan location {code} first',
'wh.unknownBarcode': 'Unknown barcode',
'wh.unknownBarcodeBody': 'No product in your catalogue matches {code}.',
'wh.linkBarcode': 'Link to a product',
'wh.wrongItem': 'Wrong item',
'wh.wrongItemBody': 'This is not on the list. Put it back and scan {sku}.',
'wh.overPick': 'Too many',
'wh.overPickBody': 'You already have {qty} of {qty_required}.',
'wh.duplicateScan': 'Already scanned',
'wh.pickLists': 'Pick lists',
'wh.pickMine': 'Mine', 'wh.pickUnassigned': 'Unassigned', 'wh.pickActive': 'In progress',
'wh.pickEmpty': 'No pick lists waiting.',
'wh.pickClaim': 'Claim list', 'wh.pickStart': 'Start picking',
'wh.pickProgress': '{done} of {total} picked',
'wh.pickConfirm': 'Confirm',
'wh.pickCantPick': "Can't pick",
'wh.pickShortTitle': 'Report a short pick',
'wh.pickShortQty': 'How many are missing?',
'wh.pickShortReason': 'Reason',
'wh.reasonNotFound': 'Not on the shelf', 'wh.reasonDamaged': 'Damaged',
'wh.reasonInsufficient': 'Not enough stock', 'wh.reasonWrongLocation': 'Wrong location',
'wh.reasonOther': 'Other',
'wh.pickComplete': 'Complete list', 'wh.pickCompleted': 'Pick list complete',
'wh.pickPause': 'Pause', 'wh.pickResume': 'Resume',
'wh.pickUndo': 'Undo',
'wh.packTitle': 'Pack order',
'wh.packScanOrder': 'Scan a packing slip or pick list',
'wh.packVerified': 'All items verified',
'wh.packRemaining': '{n} items left',
'wh.packMeasure': 'Weight & size',
'wh.packWeight': 'Weight', 'wh.packLength': 'Length', 'wh.packWidth': 'Width',
'wh.packHeight': 'Height', 'wh.packBoxType': 'Packaging',
'wh.packCreateLabel': 'Create label', 'wh.packShareSlip': 'Share packing slip',
'wh.packClose': 'Close package', 'wh.packVoid': 'Void package',
'wh.receiveTitle': 'Receive stock',
'wh.receiveNew': 'New receipt', 'wh.receiveSupplier': 'Supplier',
'wh.receiveReference': 'PO / reference',
'wh.receiveMode': 'Mode', 'wh.receiveInformed': 'Against a list', 'wh.receiveBlind': 'Blind',
'wh.receiveQty': 'Quantity received', 'wh.receiveDamaged': 'Damaged',
'wh.receiveLocation': 'Put away at', 'wh.receiveUnitCost': 'Unit cost',
'wh.receiveUnexpected': 'Not on the list',
'wh.receiveComplete': 'Complete receipt',
'wh.receiveDiscrepancies': '{n} discrepancies',
'wh.receiveOver': 'Over', 'wh.receiveShort': 'Short',
'wh.countTitle': 'Stock count',
'wh.countNew': 'New count', 'wh.countMode': 'Count type',
'wh.countBlind': 'Blind', 'wh.countInformed': 'Show expected',
'wh.countScope': 'What to count',
'wh.countScopeFull': 'Everything', 'wh.countScopeLocation': 'One location',
'wh.countScopeCategory': 'A category', 'wh.countScopeSkus': 'Selected SKUs',
'wh.countScanAdd': 'Scan +1', 'wh.countTypeTotal': 'Type total',
'wh.countCounted': '{done} of {total} counted',
'wh.countSkip': 'Skip', 'wh.countSubmit': 'Submit count',
'wh.countSubmitted': 'Count submitted for approval',
'wh.countVariance': 'Variance', 'wh.countVarianceUnits': '{n} units',
'wh.countVarianceValue': 'Value at retail',
'wh.countPendingApproval': 'Waiting for approval',
'wh.lookupTitle': 'Scan to look up',
'wh.lookupPrompt': 'Scan any barcode to see stock, price and channels.',
'wh.lookupStock': 'In stock', 'wh.lookupPrice': 'Price',
'wh.lookupChannels': 'Channels', 'wh.lookupHistory': 'Recent movements',
'wh.lookupAsOf': 'As of {time}',
'wh.lookupAddBarcode': 'Add barcode',
'wh.conflictsTitle': 'Needs attention',
'wh.conflictsEmpty': 'Everything is synced.',
'wh.conflictRetry': 'Retry', 'wh.conflictDiscard': 'Discard',
'wh.conflictExport': 'Export queue',
'wh.conflictSessionClosed': 'This session was finished by someone else.',
'wh.conflictOrderCancelled': 'This order was cancelled.',
'wh.conflictLineLocked': '{name} is picking this line.',
'wh.sessionAbandoned': 'This session was left open and has been paused.',
'wh.stockChangedDuringCount': 'Stock moved during the count',
'wh.soundOn': 'Scan sound', 'wh.hapticsOn': 'Vibration',
'wh.noPermission': 'You do not have access to warehouse operations.',
```

```dart
// ── ar ──────────────────────────────────────────────────────────────────
'wh.title': 'المستودع',
'wh.pick': 'التجهيز', 'wh.pack': 'التغليف', 'wh.receive': 'الاستلام',
'wh.count': 'جرد المخزون', 'wh.lookup': 'استعلام', 'wh.conflicts': 'تعارضات',
'wh.selectWarehouse': 'المستودع',
'wh.online': 'متصل — تمت المزامنة',
'wh.offline': 'غير متصل — نعمل من النسخة المحفوظة',
'wh.queued': 'عملية مسح في الانتظار',
'wh.syncing': 'جارٍ المزامنة…',
'wh.syncNow': 'مزامنة الآن',
'wh.conflictsCount': 'عنصر يحتاج مراجعة',
'wh.cameraPermTitle': 'الوصول إلى الكاميرا',
'wh.cameraPermBody': 'يستخدم Hubby الكاميرا لمسح الباركود. لا يتم تسجيل أو رفع أي شيء.',
'wh.cameraPermGrant': 'السماح بالكاميرا',
'wh.cameraPermDenied': 'الوصول إلى الكاميرا معطّل. افتح الإعدادات لتفعيله.',
'wh.openSettings': 'فتح الإعدادات',
'wh.torch': 'الإضاءة',
'wh.lowLight': 'الإضاءة ضعيفة — شغّل الكشاف',
'wh.manualEntry': 'إدخال الباركود يدويًا',
'wh.manualEntryHint': 'أدخل الرقم أسفل الباركود',
'wh.scannerConnected': 'تم توصيل قارئ بلوتوث',
'wh.useCamera': 'استخدام الكاميرا',
'wh.scanPrompt': 'وجّه الكاميرا نحو الباركود',
'wh.scanLocationFirst': 'امسح الموقع {code} أولًا',
'wh.unknownBarcode': 'باركود غير معروف',
'wh.unknownBarcodeBody': 'لا يوجد منتج في كتالوجك يطابق {code}.',
'wh.linkBarcode': 'ربط بمنتج',
'wh.wrongItem': 'منتج خاطئ',
'wh.wrongItemBody': 'هذا المنتج ليس في القائمة. أعِده وامسح {sku}.',
'wh.overPick': 'الكمية زائدة',
'wh.overPickBody': 'لديك {qty} من أصل {qty_required}.',
'wh.duplicateScan': 'تم مسحه مسبقًا',
'wh.pickLists': 'قوائم التجهيز',
'wh.pickMine': 'قوائمي', 'wh.pickUnassigned': 'غير مُسندة', 'wh.pickActive': 'قيد التنفيذ',
'wh.pickEmpty': 'لا توجد قوائم تجهيز في الانتظار.',
'wh.pickClaim': 'استلام القائمة', 'wh.pickStart': 'بدء التجهيز',
'wh.pickProgress': 'تم تجهيز {done} من {total}',
'wh.pickConfirm': 'تأكيد',
'wh.pickCantPick': 'تعذّر التجهيز',
'wh.pickShortTitle': 'الإبلاغ عن نقص',
'wh.pickShortQty': 'كم العدد الناقص؟',
'wh.pickShortReason': 'السبب',
'wh.reasonNotFound': 'غير موجود على الرف', 'wh.reasonDamaged': 'تالف',
'wh.reasonInsufficient': 'الكمية غير كافية', 'wh.reasonWrongLocation': 'موقع خاطئ',
'wh.reasonOther': 'أخرى',
'wh.pickComplete': 'إنهاء القائمة', 'wh.pickCompleted': 'اكتملت قائمة التجهيز',
'wh.pickPause': 'إيقاف مؤقت', 'wh.pickResume': 'متابعة',
'wh.pickUndo': 'تراجع',
'wh.packTitle': 'تغليف الطلب',
'wh.packScanOrder': 'امسح إيصال التغليف أو قائمة التجهيز',
'wh.packVerified': 'تم التحقق من كل المنتجات',
'wh.packRemaining': 'متبقٍ {n} منتج',
'wh.packMeasure': 'الوزن والأبعاد',
'wh.packWeight': 'الوزن', 'wh.packLength': 'الطول', 'wh.packWidth': 'العرض',
'wh.packHeight': 'الارتفاع', 'wh.packBoxType': 'نوع التغليف',
'wh.packCreateLabel': 'إنشاء بوليصة', 'wh.packShareSlip': 'مشاركة إيصال التغليف',
'wh.packClose': 'إغلاق الطرد', 'wh.packVoid': 'إلغاء الطرد',
'wh.receiveTitle': 'استلام بضاعة',
'wh.receiveNew': 'استلام جديد', 'wh.receiveSupplier': 'المورّد',
'wh.receiveReference': 'رقم أمر الشراء / المرجع',
'wh.receiveMode': 'الطريقة', 'wh.receiveInformed': 'مقابل قائمة', 'wh.receiveBlind': 'بدون قائمة',
'wh.receiveQty': 'الكمية المستلمة', 'wh.receiveDamaged': 'تالف',
'wh.receiveLocation': 'التخزين في', 'wh.receiveUnitCost': 'تكلفة الوحدة',
'wh.receiveUnexpected': 'غير مدرج في القائمة',
'wh.receiveComplete': 'إنهاء الاستلام',
'wh.receiveDiscrepancies': '{n} فروقات',
'wh.receiveOver': 'زيادة', 'wh.receiveShort': 'نقص',
'wh.countTitle': 'جرد المخزون',
'wh.countNew': 'جرد جديد', 'wh.countMode': 'نوع الجرد',
'wh.countBlind': 'أعمى', 'wh.countInformed': 'إظهار المتوقع',
'wh.countScope': 'نطاق الجرد',
'wh.countScopeFull': 'كل المخزون', 'wh.countScopeLocation': 'موقع واحد',
'wh.countScopeCategory': 'تصنيف', 'wh.countScopeSkus': 'أصناف محددة',
'wh.countScanAdd': 'مسح ‎+1', 'wh.countTypeTotal': 'إدخال الإجمالي',
'wh.countCounted': 'تم جرد {done} من {total}',
'wh.countSkip': 'تخطٍ', 'wh.countSubmit': 'إرسال الجرد',
'wh.countSubmitted': 'تم إرسال الجرد للاعتماد',
'wh.countVariance': 'الفرق', 'wh.countVarianceUnits': '{n} وحدة',
'wh.countVarianceValue': 'القيمة بسعر البيع',
'wh.countPendingApproval': 'بانتظار الاعتماد',
'wh.lookupTitle': 'امسح للاستعلام',
'wh.lookupPrompt': 'امسح أي باركود لعرض المخزون والسعر والقنوات.',
'wh.lookupStock': 'المتوفر', 'wh.lookupPrice': 'السعر',
'wh.lookupChannels': 'القنوات', 'wh.lookupHistory': 'آخر الحركات',
'wh.lookupAsOf': 'حتى {time}',
'wh.lookupAddBarcode': 'إضافة باركود',
'wh.conflictsTitle': 'يحتاج مراجعة',
'wh.conflictsEmpty': 'كل شيء متزامن.',
'wh.conflictRetry': 'إعادة المحاولة', 'wh.conflictDiscard': 'تجاهل',
'wh.conflictExport': 'تصدير قائمة الانتظار',
'wh.conflictSessionClosed': 'تم إنهاء هذه الجلسة بواسطة شخص آخر.',
'wh.conflictOrderCancelled': 'تم إلغاء هذا الطلب.',
'wh.conflictLineLocked': '{name} يقوم بتجهيز هذا السطر.',
'wh.sessionAbandoned': 'تُركت هذه الجلسة مفتوحة وتم إيقافها مؤقتًا.',
'wh.stockChangedDuringCount': 'تغيّر المخزون أثناء الجرد',
'wh.soundOn': 'صوت المسح', 'wh.hapticsOn': 'الاهتزاز',
'wh.noPermission': 'ليس لديك صلاحية الوصول إلى عمليات المستودع.',
```

**Placeholder note.** `strings.dart` has no interpolation helper today — `context.t()` returns a raw
string. Add a sibling extension rather than changing existing behaviour:

```dart
// mobile/lib/l10n/strings.dart
extension TrArgs on BuildContext {
  String tp(String key, Map<String, Object?> args) {
    var s = t(key);
    args.forEach((k, v) => s = s.replaceAll('{$k}', '$v'));
    return s;
  }
}
```

---

## 7. Dashboard (Next.js)

New route group members under `frontend/src/app/(dashboard)/warehouse/`, matching the existing
`page.tsx`-per-feature pattern with `'use client'`, `@/lib/api`, `useT()`, and the `@/components/ui/*`
primitives (`Card`, `Button`, `Input`, `Modal`, `Toast`).

```
frontend/src/app/(dashboard)/warehouse/
├── page.tsx                    # Overview: active sessions, operator activity, today's scans
├── pick-lists/page.tsx         # List + create
├── pick-lists/[id]/page.tsx    # Detail: lines, scan timeline, shorts, reassign
├── receipts/page.tsx
├── receipts/[id]/page.tsx      # Discrepancy review + accept
├── counts/page.tsx
├── counts/[id]/page.tsx        # Variance review + approve/reject/recount  ← the key screen
├── barcodes/page.tsx           # Barcode manager: search, add, import CSV, generate Code128
└── locations/page.tsx          # Warehouses + locations, CSV import, printable shelf labels
frontend/src/components/warehouse/
├── PickListCreateModal.tsx
├── VarianceTable.tsx
├── ScanTimeline.tsx
├── OperatorActivityCard.tsx
└── BarcodeImportModal.tsx
```

**Pick list creation** (`PickListCreateModal`): filter orders by store/status/date → multi-select →
choose `order` vs `batch` → warehouse → assignee (optional) → priority. Shows a live "12 orders,
38 lines, 6 unique SKUs" preview and warns about orders already on an open list.

**Count approval** (`counts/[id]`): the highest-stakes screen in the feature.
- Header: session code, mode, scope, operator, duration, counted/total.
- Summary tiles: lines with variance, Σ units, Σ value at retail, largest single variance.
- `VarianceTable`: SKU · name · location · expected (at start) · counted · live now · variance ·
  value · flags. Sorted by `|value|` desc. Rows where `expected ≠ live now` carry a
  **"stock moved during count"** badge with a popover listing the intervening `inventory_logs`.
- Per-row actions: accept, override quantity, request recount.
- Footer: **Approve & apply** (destructive-styled, typed confirmation for sessions over a configurable
  value threshold), **Reject**, **Request recount for flagged**.
- After approval: a read-only applied view linking to the `inventory_logs` rows by `batch_uuid`.

**Variance review across sessions** (`warehouse/page.tsx`): 30-day shrinkage trend (`fl_chart`
equivalent on web is already in use — reuse the existing chart component), top-variance SKUs, and a
"SKUs that keep varying" list — a genuine analytics differentiator, since Linnworks' own weakness
list flags reporting as weak and profitability as requiring a paid partner.

**i18n.** New dict `frontend/src/i18n/dicts/warehouse.ts` shaped `{ en: {...}, ar: {...} }`, imported
and registered in `frontend/src/i18n/dictionary.ts` under both `en` and `ar` as `warehouse:
warehouse.en / warehouse.ar` (exactly matching the existing 11 dicts). Nav entry added to the
`common` dict's nav block and to the sidebar component. Key namespaces mirror the mobile keys minus
the `wh.` prefix: `warehouse.title`, `warehouse.pickLists.*`, `warehouse.counts.*`,
`warehouse.barcodes.*`, `warehouse.locations.*`, `warehouse.variance.*`.

---

## 8. Hardware

### 8.1 External Bluetooth scanners (supported, Phase 1)

**Mode: HID / keyboard-wedge only.** SPP and BLE-GATT vendor protocols are out of scope — HID is
universally supported, needs no per-vendor SDK, and works on both platforms.

| Requirement | Detail |
| --- | --- |
| Pairing | OS-level Bluetooth pairing; the app needs no Bluetooth permission at all in HID mode (a real advantage — no `BLUETOOTH_CONNECT` runtime prompt on Android 12+) |
| Terminator | Scanner must be configured to append **Enter (CR)**. Document the config barcode for each recommended model in the help centre |
| Detection | §6.8 heuristic (fast keystrokes + terminator) |
| Verified models to document | Generic 2D HID ring scanners (~$40–70), Zebra DS2278 / DS8178 in HID mode, Honeywell Voyager 1602g, Netum/Eyoyo BT ring scanners. **[ASSUMPTION]** — none of these have been tested against this app yet; the pilot must validate at least one ring scanner and one gun |
| Ring scanners | The recommended accessory: phone in a lanyard/holster, ring scanner on the index finger = genuinely two-handed picking at a fraction of a rugged-terminal price. This is the "you already own the computer" story |
| Fallback | Camera always remains available; mixed camera+HID in one session is supported |

### 8.2 Label printers

Hubby does **not** drive printers directly in Phase 1. Three supported paths, in preference order:

1. **Share-to-print (Phase 1, ships with this spec).** Server renders PDF/PNG; the app hands it to
   the OS via the existing `share_plus`. Works with Brother iPrint&Label, Zebra Print, Mopria, and
   every vendor app on both platforms. Zero integration cost, zero vendor lock-in.
   - Packing slip: `GET /pack-sessions/{id}/slip` (A4/A5 PDF).
   - Shelf labels: `GET /warehouses/{id}/locations/labels` (Code128 of the location code, 50×25 mm
     grid, PDF) — printable on a normal office printer onto label sheets.
   - Product barcode labels: `POST /barcodes/generate` produces Code128 for items with no
     manufacturer barcode, then `GET /barcodes/labels?ids=` renders the sheet.
2. **Shipping labels** — owned by the Shipping & Labels spec. This spec only stores and displays
   `label_url`.
3. **Direct thermal printing (Phase 3).** Native ESC/POS + ZPL over Bluetooth for Zebra ZD-series and
   generic 58/80 mm thermal printers. Deliberately deferred: it is a multi-week driver project with
   long support tail, and share-to-print covers the pilot.

**Recommended pilot hardware kit** (documented for customers, not sold by us): any Android 10+ phone
with ≥ 3 GB RAM · a $50 BT ring scanner · a Brother QL-820NWB or generic 58 mm BT thermal printer ·
label sheets. Total under $250 vs a $900+ rugged terminal per picker.

---

## 9. Permissions & multi-tenancy

### 9.1 Roles

Current: `OrganizationController::ROLES = ['owner', 'admin', 'viewer']` (and the DB default is the
unlisted `'member'` — an existing inconsistency worth fixing in this PR).

Add **`warehouse_operator`**:

| Capability | owner | admin | viewer | warehouse_operator |
| --- | :-: | :-: | :-: | :-: |
| See warehouse home / lookup | ✅ | ✅ | ✅ (read-only) | ✅ |
| Claim/start/execute pick, pack, receive, count | ✅ | ✅ | ❌ | ✅ |
| Create pick lists / receipts / count sessions | ✅ | ✅ | ❌ | ❌ |
| Approve a count session | ✅ | ✅ | ❌ | ❌ |
| Accept receipt discrepancies | ✅ | ✅ | ❌ | ❌ |
| Create/link/delete barcodes | ✅ | ✅ | ❌ | ❌ (unless `learn_unknown_barcodes` + explicit grant) |
| Create products | ✅ | ✅ | ❌ | ❌ |
| Manual stock adjust (`/inventory/adjust`) | ✅ | ✅ | ❌ | ❌ |
| Manage warehouses/locations | ✅ | ✅ | ❌ | ❌ |
| See prices / margins | ✅ | ✅ | ✅ | **❌ — hidden** |
| See other operators' productivity | ✅ | ✅ | ✅ | ❌ (own only) |
| Access orders/products/analytics/billing tabs | ✅ | ✅ | ✅ | ❌ |

`warehouse_operator` is a **deliberately narrow** role: warehouse staff are often temporary or
contracted, and giving them the full commerce dashboard is a real data-exposure risk. On login, a
`warehouse_operator` is redirected to `/warehouse` and the 5-tab shell is not reachable.

Changes required:
- `OrganizationController::ROLES` → `['owner','admin','viewer','warehouse_operator']`.
- `frontend` member-management role picker gains the option + a help tooltip.
- `AuthController::me()` response must include the active-org role so both clients can gate — verify
  it does; **[ASSUMPTION]** it may not, in which case add `organization_role` to the payload.

### 9.2 Enforcement

Two new middlewares registered in `bootstrap/app.php` alongside the existing `org.member`:

- **`warehouse.access`** — role ∈ {owner, admin, viewer, warehouse_operator} **and**
  `Organization::warehouseSettings()['scanning_enabled']` is true. 403 with
  `{message, reason: 'warehouse_disabled' | 'role_denied'}`.
- **`warehouse.supervise`** — role ∈ {owner, admin}. Applied to create/approve/accept/barcode routes.

Plus a `WarehousePolicy` for object-level checks (a pick list assigned to operator A cannot be
mutated by operator B unless the actor is admin+).

### 9.3 Org scoping — the rule that must not be broken

Every warehouse table has a direct `organization_id`; **every query filters on it explicitly**, and
never relies on a join for tenant isolation. The single highest-risk path is barcode resolution: a
missing org filter there would let org A scan and mutate org B's stock.

Mitigations:
1. `unique(['organization_id','barcode'])` — the org column is part of the resolution index, so a
   query missing it is a full scan and shows up immediately in slow-query logs.
2. A `BelongsToOrganization` trait adding a **global scope** on every new model, resolving the org
   from a request-scoped `CurrentOrganization` singleton (populated by `EnsureOrganizationMember`).
   The controllers currently re-read `$request->header('X-Organization-Id')` by hand
   (`InventoryController:14`) — the trait makes forgetting it impossible for new code.
3. A dedicated `tests/Feature/Warehouse/MultiTenancyTest.php` that, for **every** warehouse endpoint,
   asserts a 403/404 when the resource belongs to another org. Non-negotiable, enforced in review.
4. `product_variants.sku` is **globally unique** across all tenants today (migration
   `2026_05_05_202910`). That is a latent cross-tenant design smell — resolution rung 2 in §4.0 must
   still filter by org through `product.organization_id`, never on SKU alone. Flagged in §15.

---

## 10. Edge cases & failure modes

| # | Scenario | Handling |
| --- | --- | --- |
| 1 | **Camera double-reads the same label** 30×/s | `DetectionSpeed.noDuplicates` + `detectionTimeoutMs: 350` + the 1200 ms client dedupe window keyed on `(barcode, targetLine)`. Suppressed silently — **no feedback at all**, because a "duplicate!" buzz on every scan trains operators to ignore feedback |
| 2 | **Operator deliberately scans the same unit twice** (2 identical units) | The dedupe window is 1200 ms; scanning two physical units takes longer. If they are faster, the qty stepper `+` is always available and the "Type total" mode exists |
| 3 | **Wrong item scanned during pick** | Hard block. Red full-screen overlay, error tone, heavy haptic, the *correct* item's photo + SKU shown, dismissal requires a tap. `scan_events` records `result='wrong_item'` — this stream is the mispick-prevention metric we report to the customer |
| 4 | **Wrong item scanned during pack** | Same, but the overlay text is "Remove it from the box" and dismissal requires an explicit confirm button, not a tap anywhere |
| 5 | **Damaged / unreadable barcode** | Torch toggle → auto-zoom → **Type barcode** manual entry (`input_method:'manual'`, flagged in the audit trail) → **Search by name/SKU** as the last resort. Manual entries are visible to supervisors so systematic label problems surface |
| 6 | **Low light** | Ambient-light heuristic: no successful decode for 4 s while frames are arriving → show the `wh.lowLight` hint and pulse the torch button. (No light sensor API is used; the heuristic avoids another permission) |
| 7 | **Item genuinely has no barcode** | Pick line shows a "no barcode" chip and allows tap-to-confirm with a mandatory long-press (600 ms) to prevent accidental confirmation. Supervisors get a "SKUs without barcodes" report that drives label printing |
| 8 | **Unknown barcode** | Sheet: "Link to product" (role-gated), "Create product" (role-gated), "Skip". Every occurrence writes `scan_events` with `result='unknown_barcode'`; the dashboard aggregates them into a "top unknown barcodes" list — the fastest possible catalogue-completion loop |
| 9 | **Offline for hours** | Fully functional from cache. `SyncStatusBar` shows queue depth. Hard stop at 5,000 queued events or 200 MB. Catalogue staleness banner after 24 h: "Stock figures are from yesterday" |
| 10 | **Offline for days, then a huge flush** | Batches of 100, sequential, resumable; progress sheet with a cancel that leaves the remainder queued. Server accepts events with old `client_scanned_at` unconditionally — chronology is preserved in the audit trail, not used to reject |
| 11 | **App killed mid-session** | Everything is in Drift, written in the same transaction as the optimistic update. On relaunch, the app restores the session and shows "Resumed — 7 of 12 picked" |
| 12 | **Session abandoned** (operator went home) | `ExpireStaleWarehouseSessionsJob`: pick lists idle > 30 min → `paused` and unassigned (any operator can resume); count sessions idle > 12 h → `abandoned` with the supervisor notified. Nothing is ever silently deleted |
| 13 | **Stock changed remotely mid-pick** (a channel sale) | Pick does not touch stock (§4.1), so nothing breaks. If the line becomes unpickable the operator reports a short and the automation engine handles it |
| 14 | **Stock changed remotely mid-count** | Resolved at approval via `live_qty_at_approval` (§4.4); the row is flagged and the supervisor sees the intervening movements |
| 15 | **Order cancelled/shipped while being picked** | `409 conflict:order_not_pickable`; the line greys out with an explanation, the rest of the list continues |
| 16 | **Two operators on the same list** | Per-line device ownership: the first accepted scan on a line locks it to that `device_id` for the session. Second device gets `423 line_owned_by_other_device` with the owner's name and a handover request |
| 17 | **Two devices count the same SKU+location** | Both values retained; the loser becomes a rejected child entry; the supervisor sees "two counts disagree" and can request a recount |
| 18 | **Duplicate flush** (network retried a request the server already processed) | `unique(organization_id, uuid)` → stored response replayed verbatim, `idempotent_replay: true`. Zero double-adjustment |
| 19 | **Device clock badly wrong** | Server measures skew from the flush envelope, rewrites `client_scanned_at` relative to `received_at`, stores `clock_skew_ms`. `client_seq` (not the clock) is the ordering key everywhere |
| 20 | **Barcode maps to two products** | Prevented by `unique(organization_id, barcode)`. Import attempts that would collide are rejected per-row with a report; the barcode manager shows the conflict |
| 21 | **Case barcode scanned as a unit** | `pack_size` on the barcode row; the UI shows "×12 case" explicitly before adding, so a case scan can never be mistaken for one unit |
| 22 | **Negative stock** | `allow_negative_stock` org setting, default **false** ⇒ `StockMutator` clamps at zero and sets `clamped: true` on the log; the supervisor sees a "clamped" flag. Never silently negative |
| 23 | **Count approval on a 10k-SKU session** | `202 Accepted` + `ApplyCountSessionJob`, chunked 200, deduped `PushInventoryJob`s, progress polled |
| 24 | **`PushInventoryJob` fails for one channel** | Already handled: it catches per-store and logs (`PushInventoryJob.php`). The local stock and audit log remain correct; the sync-health card surfaces the failure |
| 25 | **Camera permission permanently denied** | Dedicated screen explaining the impact + `openAppSettings()` + HID and manual-entry paths still fully usable. The feature degrades, never dies |
| 26 | **Device has no camera / camera hardware fails** | `uses-feature required="false"` keeps the app installable; `ScanSource` falls back to HID/manual with no code-path change |
| 27 | **App backgrounded mid-scan** | `WidgetsBindingObserver` stops the controller on `paused`, restarts on `resumed`. Prevents the "black preview after unlock" class of bug |
| 28 | **Thermal throttling in a hot warehouse** | 720p + restricted formats + `scanWindow` cut CPU; the controller stops whenever any sheet/modal is open. Pilot exit criterion: 30 min sustained without a stall |
| 29 | **Battery drain** | HID mode auto-pauses the camera. A "camera idle" auto-stop after 60 s with no decode, resumed by tapping the preview |
| 30 | **Operator removed from the org mid-session** | Next flush returns 403; the app locks to a read-only screen and offers "Export queue" so their work is recoverable by a supervisor |
| 31 | **Org's subscription lapses mid-session** | `CheckSubscription` middleware returns 402; same treatment as #30 — the queue is never destroyed |
| 32 | **Two orgs, same physical barcode** | Correct by design: `unique(organization_id, barcode)` is per-tenant |
| 33 | **Catalogue tombstone expiry** (device offline > 30 days) | `full_resync_required: true` forces a clean catalogue rebuild; the outbox is untouched and flushes normally |
| 34 | **RTL numeral confusion** (`A-01-3` → `3-01-A`) | Every code renders through the LRI/PDI isolate helper (§6.6). Covered by a widget test asserting rendered order under `Locale('ar')` |

---

## 11. Testing

### 11.1 Backend — `backend/tests/Feature/Warehouse/` (matching the existing `tests/Feature` layout)

Run per the project memory: PHPUnit in Docker forcing sqlite with `-e DB_CONNECTION=sqlite`.

| Test | Asserts |
| --- | --- |
| `BarcodeResolverTest` (Unit) | Normalisation table (whitespace, AIM prefixes, UPC-A↔EAN-13 expansion, UPC-E), resolution ladder order, org isolation, check-digit-advisory behaviour |
| `ScanIdempotencyTest` | Posting the same `uuid` twice creates **one** `scan_events` row, **one** `inventory_logs` row, and returns the identical body with `idempotent_replay: true`. Also the concurrent case (two parallel inserts → unique violation → replay) |
| `ScanFlushTest` | 200-event batch, mixed accepted/conflict/duplicate; per-event results keyed by uuid; ordering by `client_seq`; clock-skew rewrite |
| `PickFlowTest` | draft→ready→in_progress→completed; over-pick 409; wrong-item 409; short-pick sets `review`; **asserts picking creates no `inventory_logs` rows**; damaged-short creates exactly one negative row |
| `PickConcurrencyTest` | Two devices, same line → second gets 423 |
| `PackFlowTest` | Verification gate, wrong-item block, multi-box, slip endpoint returns a PDF, Shipping handoff is called with the right payload (mocked) |
| `ReceivingTest` | Blind vs informed; discrepancy computation; damaged units excluded from the stock delta; completion applies exactly one `inventory_logs` row per line and dispatches `PushInventoryJob` (`Bus::fake`) |
| `CountSessionTest` | Blind mode **never serialises `expected_qty`** for an operator role (assert the raw JSON, not the UI); absolute-value + `client_seq` last-write-wins; submit computes preview variance |
| `CountApprovalTest` | Approval uses `live_qty_at_approval`, not the snapshot; overrides applied; `batch_uuid` groups the logs; >500 entries returns 202 and the job applies them; non-admin gets 403 |
| `StockMutatorTest` (Unit) | Clamping at zero when negatives disallowed; `qty_before`/`qty_after`; idempotency-key replay returns the existing log; `PushInventoryJob` dispatched exactly once |
| `WarehouseMultiTenancyTest` | **Every** endpoint × cross-org resource → 403/404. Data-provider driven so a new route without a test fails the suite |
| `WarehousePermissionTest` | The §9.1 capability matrix, one assertion per cell |
| `OfflineReplayTest` | Simulates a 3-hour offline session: 300 queued events flushed out of order with duplicates, asserts final state equals the online-equivalent run (a property-style test) |

Extend the existing `InventorySyncTest` to cover `InventoryController::adjust` after its refactor onto
`StockMutator` — including the previously-missing `PushInventoryJob` dispatch.

### 11.2 Mobile — `mobile/test/warehouse/`

`bloc_test` and `flutter_test` are already dev dependencies; add `drift` in-memory usage.

| Test | Asserts |
| --- | --- |
| `pick_cubit_test.dart` (bloc_test) | Scan of a matching barcode → focused line qty increments and an outbox row is enqueued; wrong barcode → `lastScan.rejected(wrong_item)` and **no** outbox row; over-pick blocked; auto-advance; undo within 10 s |
| `count_cubit_test.dart` | Scan-mode accumulation sends absolutes with increasing `client_seq`; type-mode replaces; blind mode never holds an expected value |
| `outbox_dao_test.dart` | Uses `NativeDatabase.memory()`. Enqueue/dequeue ordering by `(sessionKey, clientSeq)`, backoff scheduling, dead-lettering after 10 failures, transactional enqueue+state-update atomicity |
| `sync_service_test.dart` | Fake `WarehouseRepository` returning mixed results; asserts deletion of accepted rows, retention of conflicts, exponential backoff timing (fake async), and that a 4xx never retries |
| `barcode_normalizer_test.dart` | Mirrors the backend normaliser table exactly — **the two implementations must agree**, so the fixture list is a shared JSON file at `docs/fixtures/barcode-normalization.json` consumed by both suites |
| `scan_feedback_test.dart` | Success/failure produce the expected haptic + audio calls (mocked platform channels) |
| `pick_session_page_test.dart` (widget) | Renders with `FakeScanSource`; pumping a fake scan advances the UI; tap targets are ≥ 56 dp (`tester.getSize`) |
| `rtl_codes_test.dart` (widget) | Under `Locale('ar')`, `codeText('A-01-3')` renders with LRI/PDI isolates and the progress ratio reads `7 / 12`, not `12 / 7` |
| `warehouse_smoke_test.dart` | Follows the existing `products_page_smoke_test.dart` pattern for the home + lookup pages |

### 11.3 Testing scanning without hardware — four layers

This is the part teams usually get wrong, so it is specified explicitly.

1. **`ScanSource` abstraction (the foundation).**
   ```dart
   // mobile/lib/features/warehouse/scanner/scan_source.dart
   abstract class ScanSource {
     Stream<RawScan> get scans;   // RawScan(value, symbology, inputMethod, at)
     Future<void> start(); Future<void> stop();
     Future<void> toggleTorch();
   }
   class MobileScannerSource implements ScanSource { /* wraps MobileScannerController */ }
   class FakeScanSource implements ScanSource {      /* a StreamController you push into */ }
   ```
   **No workflow code ever touches `MobileScannerController` directly.** Every cubit and widget test
   injects `FakeScanSource` and calls `emit(RawScan('6281006021234', ...))`. This makes ~95% of the
   feature testable with zero camera involvement, and it is the same seam that makes HID input free.

2. **Still-image decoding (proves the real decoder works).** A fixture set at
   `mobile/test/fixtures/barcodes/` containing generated PNGs — `ean13_valid.png`,
   `ean13_glare.png`, `code128_long.png`, `qr_location.png`, `damaged_partial.png`,
   `low_contrast.png`, `rotated_45.png` — plus an integration test (`integration_test/`) calling
   `controller.analyzeImage(path)` on each and asserting the decoded payload. Generated once by a
   small Dart/Python script committed at `docs/tools/gen_barcode_fixtures.py`.

3. **Android emulator with a virtual camera.** `emulator -avd <name> -camera-back webcam0` maps the
   host webcam so a barcode on the developer's screen or a printed sheet decodes for real; the
   default `emulated` virtual scene can also be given a custom poster texture containing barcodes.
   Documented in `mobile/README.md` as the manual-QA path.

4. **HID path in pure widget tests.** `simulateKeyDownEvent`/`simulateKeyUpEvent` from
   `flutter_test` replay a barcode as fast keystrokes + Enter, asserting `HidScannerListener`
   produces exactly one `RawScan` with `inputMethod: hid` — and that slow "human" typing produces
   none.

5. **Debug affordances.** With `--dart-define=FAKE_SCANNER=true`, the scan screens render a debug
   panel of 8 buttons that emit fixture barcodes (correct item, wrong item, unknown, location, case
   barcode, damaged-checksum, order code, session code). Guarded by `kDebugMode &&` the define, so it
   cannot ship. This alone makes demoing and manual QA possible on a laptop.

**Backend load check (not a unit test):** a `k6`/`artillery` script posting 50 concurrent
`/scan/flush` batches of 100 events, asserting p95 < 400 ms and zero double-adjustments. Run before
the pilot.

---

## 12. Rollout

**Feature flag.** Two gates, both required:
1. **Plan-level** — `plans.features` JSON (column exists, migration `2026_05_06_082834`) gains
   `"warehouse_scanning": true`. Per strategy doc §"Never gate capability behind tiers", this is
   `true` on **every paid plan** — the flag exists for staged rollout, not monetisation.
2. **Org-level** — `organizations.warehouse_settings.scanning_enabled` (default `false` during
   rollout, flipping to `true` at GA). Toggled by staff.

Mobile reads both from `GET /scan/bootstrap`; the Warehouse tile is simply absent when disabled, so
there is no dead UI.

| Phase | Scope | Exit criteria |
| --- | --- | --- |
| **0 — Foundations** (weeks 1–2) | Migrations, models, `BarcodeResolver`, `StockMutator`, `ScanIngestService`, `/scan/*` endpoints, barcode manager on the dashboard, `InventoryController` refactored onto `StockMutator`. **No mobile UI.** | Backend suite green incl. multi-tenancy + idempotency; existing inventory adjust still works and now pushes to channels |
| **1 — Lookup + Receive** (weeks 3–5) | `mobile_scanner` integrated, `ScanSource`, feedback, permissions, Drift + outbox + sync, Lookup screen, Receive workflow, dashboard receipt review | An internal tester receives 100 units offline and syncs cleanly; scan-to-feedback < 150 ms measured on a mid-range device |
| **2 — Pick + Pack** (weeks 6–9) | Pick lists (dashboard creation + mobile execution), short-pick, Pack with verification, weight/dims, packing-slip share | 3 consecutive days of internal picking with zero mispicks reaching pack |
| **3 — Count** (weeks 10–11) | Blind/informed counts, variance review, approval, apply job | A full count of a 500-SKU test catalogue applies correctly, including a deliberate mid-count stock change |
| **4 — Pilot** (weeks 12–15) | **One** design-partner warehouse. Daily check-ins for week 1. Feature flag on for that org only | See below |
| **5 — GA** (week 16+) | Flag on for all paid plans; docs, help-centre videos (EN + AR), and the competitive landing section: *"The scanner you already own."* | — |

**Pilot selection criteria:** a single-warehouse Gulf seller, 500–5,000 SKUs, 20–200 orders/day,
already on Hubby, with at least one Arabic-first operator (RTL must be validated by a native speaker
doing real work, not by us reading screenshots). Poor warehouse Wi-Fi is a **feature** of the ideal
pilot, not a disqualifier — we need the offline path exercised in anger.

**Pilot exit criteria (all required for GA):**
- ≥ 95% of picks completed without falling back to manual entry.
- Zero incorrect stock adjustments attributable to sync (audited row-by-row against `scan_events`).
- Mispick rate at pack verification down measurably vs their pre-Hubby baseline.
- p95 scan-to-feedback < 150 ms on their actual devices.
- An operator who has never used a WMS is productive within 15 minutes with no training material.
- Arabic operator completes a full shift without a layout/bidi complaint.

**Instrumentation from day one:** `scan_events` already gives us decode success rate, mispick blocks,
unknown-barcode rate, manual-entry rate, offline duration, queue depth and time-per-pick — every
number needed to prove the ROI story to the next prospect. Surface these in the dashboard overview.

---

## 13. Acceptance criteria

**Scanning**
- [ ] Camera scans EAN-13, EAN-8, UPC-A, UPC-E, Code128, Code39, ITF, QR and DataMatrix.
- [ ] Scan-to-feedback p95 < 150 ms; cold camera start < 800 ms on a mid-range Android device.
- [ ] Torch toggles; auto-zoom assists small labels; scan window limits decoding to the reticle.
- [ ] Duplicate reads of the same label within 1200 ms are suppressed with no feedback.
- [ ] Success = light haptic + short tick + green flash + check glyph; failure = heavy haptic +
      error tone + red overlay + X glyph requiring dismissal.
- [ ] Camera permission is requested after an explanatory screen; permanent denial offers settings
      plus fully functional HID and manual-entry fallbacks.
- [ ] An HID Bluetooth scanner drives every workflow with no code path differences beyond
      `input_method`, and auto-pauses the camera.

**Workflows**
- [ ] Pick: claim → start → scan location (when required) → scan SKU → confirm qty → next line →
      complete, with the state machine of §4.1 enforced server-side.
- [ ] Wrong-item and over-pick scans are **blocked**, not warned.
- [ ] Short pick captures qty, reason, optional note and photo; the list lands in `review`.
- [ ] Picking creates **no** `inventory_logs` rows (verified by test).
- [ ] Pack verifies every line against the order before a label or slip can be produced; weight and
      dimensions are captured; the slip shares via the OS print sheet.
- [ ] Receive supports blind and informed modes, damaged quantities, unexpected SKUs, and applies
      stock exactly once on completion.
- [ ] Count supports blind (expected never serialised to the operator) and informed modes, submit →
      variance → **explicit supervisor approval** before any stock changes.
- [ ] Count approval computes variance against **live** stock and flags rows that moved mid-count.
- [ ] Lookup resolves any barcode to product, stock, price, per-channel status and recent movements,
      offline included.

**Offline & integrity**
- [ ] All five workflows are fully usable with the network off.
- [ ] Queued scans survive app kill and device restart.
- [ ] Reconnect flushes automatically in batches with exponential backoff.
- [ ] Replaying an entire flush batch produces byte-identical final state (no double adjustment).
- [ ] Every quantity crossing the wire is an absolute with a `client_seq` — no deltas.
- [ ] Conflicts surface in a dedicated tray with plain-language explanations and per-item actions.
- [ ] The outbox can be exported via the share sheet when something is unrecoverable.
- [ ] Catalogue cache is wiped on logout and on active-org change.

**Data & platform**
- [ ] A product may have many barcodes; a barcode resolves to exactly one item per organization.
- [ ] Case barcodes with `pack_size > 1` are shown explicitly as "×N case" before being applied.
- [ ] Every scan — accepted or rejected — is an immutable `scan_events` row tied to a session.
- [ ] All stock writes flow through `StockMutator`, land in `inventory_logs` with `qty_before`/
      `qty_after`/`user_id`/`idempotency_key`, and dispatch `PushInventoryJob`.
- [ ] Negative stock is clamped and flagged unless the org opts in.
- [ ] `warehouse_operator` role exists, is limited to the §9.1 matrix, and never sees prices.
- [ ] Every warehouse endpoint has a passing cross-org isolation test.

**i18n & ergonomics**
- [ ] Every new string exists in both the `en` and `ar` maps of `strings.dart` and both locales of
      `frontend/src/i18n/dicts/warehouse.ts`.
- [ ] SKUs, barcodes, location codes and progress ratios render LTR under Arabic.
- [ ] The camera preview is never mirrored in RTL.
- [ ] All primary controls sit in the bottom third; every target is ≥ 56 dp.
- [ ] Scan outcomes are announced to screen readers.

---

## 14. Effort estimate + dependencies

### Effort (one backend engineer + one Flutter engineer, part-time design/QA)

| Workstream | Backend | Mobile | Dashboard | Notes |
| --- | --- | --- | --- | --- |
| Migrations + models + org scoping trait | 4 d | — | — | 17 migrations, backfills |
| `BarcodeResolver` + normaliser + barcode CRUD/import | 4 d | — | 3 d | Shared fixture file |
| `ScanIngestService` + idempotency + `/scan/*` | 5 d | — | — | The riskiest backend piece |
| `StockMutator` + `InventoryController` refactor | 3 d | — | — | Also fixes the existing push TODO |
| Pick services + endpoints | 5 d | — | 4 d | |
| Pack services + endpoints + slip PDF | 4 d | — | 2 d | |
| Receiving services + endpoints | 4 d | — | 3 d | |
| Count services + variance + approval + job | 6 d | — | 5 d | Variance review is the biggest web screen |
| Scanner integration + `ScanSource` + feedback + permissions | — | 5 d | — | |
| Drift schema + outbox + catalogue sync + `SyncService` | — | 8 d | — | The riskiest mobile piece |
| Pick screens + cubit | — | 7 d | — | Flagship UX, expect iteration |
| Pack screens + cubit | — | 5 d | — | |
| Receive screens + cubit | — | 4 d | — | |
| Count screens + cubit | — | 5 d | — | |
| Lookup + home + conflicts tray + sync bar | — | 5 d | — | |
| HID scanner support | — | 2 d | — | |
| i18n EN/AR + RTL hardening | — | 3 d | 2 d | Includes native-speaker review |
| Tests (both suites) | 7 d | 6 d | 2 d | |
| Docs, help centre, pilot support | 2 d | 2 d | 1 d | |
| **Subtotal** | **44 d** | **52 d** | **22 d** | |

**≈ 118 engineer-days ≈ 16 calendar weeks** with 2 engineers in parallel and normal overheads —
matching the 5-phase plan in §12. A **6-week thin slice** (Lookup + Receive + offline core, phases
0–1 only) is a legitimate early-value cut if the pilot needs to start sooner, and it already beats
Linnworks' and Rithum's zero.

### Dependencies

**Hard blockers**
1. `order_items` product/variant FKs + backfill (§3.10) — Pick and Pack cannot be correct without
   them.
2. Integration order mappers populating those FKs going forward (touches all 7 services in
   `app/Services/Integrations/`).
3. `warehouse_operator` added to `OrganizationController::ROLES` and to `/me`'s payload.
4. `PushInventoryJob` reliability — it is currently fire-and-forget with per-store `try/catch`; count
   approvals will dispatch it in bulk, so it needs a retry policy and a failure surface.

**Soft dependencies**
5. **Shipping & Labels spec** — Pack degrades to a shared packing slip until it lands. Contract:
   `PackService` calls it with `{order_id, weight_grams, dimensions, package_index}` and receives
   `{shipment_ref, label_url, tracking_number, carrier}`.
6. **Returns/RMA spec** — `receipts.type = 'return'` is the agreed hook.
7. **Automation Rules Engine spec** — subscribes to the §5.4 events. No coupling in this direction.
8. **Multi-warehouse/bins (Phase 2)** — §3.9. This spec ships the columns it needs.
9. Product **cost** field — required before "shrinkage value" means anything (§15).
10. iOS build target, if the pilot wants iPhones.

**External**
- `mobile_scanner` (BSD-3) + MLKit barcode scanning (Google, free, on-device, no network) —
  license-clean, no per-scan cost, no data leaves the device.
- Android `minSdk` pinned to 21.

---

## 15. Open questions

1. **Arabic-Indic vs Latin digits for warehouse quantities.** This spec forces Latin digits in
   operational numerals for scanning speed and error avoidance. Gulf operators may disagree.
   *Owner: pilot. Decide by phase 4, week 1.* Cheap to make a per-user preference if needed.
2. **No cost field anywhere in the schema.** Variance is valued at *price*, which is not shrinkage.
   Do we add `product_variants.cost` (+ `products.cost`) in this spec's scope, or wait for the
   profit-analytics workstream that the competitive doc also prioritises? *Recommendation: add a
   simple nullable `cost` now — it is one migration and makes the count feature honest.*
3. **`product_variants.sku` is globally unique across tenants.** A latent scaling and isolation
   problem (org B cannot use a SKU org A already took). Do we fix it here
   (`unique(organization_id, sku)` via a denormalised org column) or open a separate ticket?
   *Recommendation: separate ticket, but before GA — a customer will hit it.*
4. **Does `GET /me` return the caller's organization role?** Not verified. If not, both clients
   cannot gate the UI and it must be added. *Owner: backend, phase 0.*
5. **Pick ownership granularity.** This spec locks *lines* to a device. Should a whole list be
   exclusively locked instead? Line-level allows two pickers to split a big batch list, but the
   handover UX is more complex. *Owner: pilot.*
6. **Does picking need to reserve stock?** Currently no (§4.1) — two pick lists can be created for
   the same last unit. A `reserved_qty` column would fix it but is a real inventory-model change.
   *Recommendation: defer to the multi-warehouse phase, but surface an "already on PL-x" warning at
   list creation.*
7. **Count session concurrency.** Multiple operators on one big count is supported at the entry
   level, but should sessions be partitionable into assignable zones? *Defer to Phase 2 (needs the
   location quantity model anyway).*
8. **Barcode learning permissions.** Should a trusted operator be allowed to link unknown barcodes
   without a supervisor? It is a large time-saver during initial catalogue onboarding and a large
   integrity risk afterwards. *Recommendation: an org setting that defaults off, plus a time-boxed
   "onboarding mode" that auto-expires after 14 days.*
9. **Scan-event retention.** 90 days (rejected) / 400 days (accepted) is proposed. Any regulatory or
   customer-contractual requirement in KSA/UAE that demands longer? *Owner: legal/CS.*
10. **Should `warehouse_operator` seats be billable?** A 20-picker warehouse means 20 accounts. Free
    operator seats are a strong competitive weapon against per-user WMS pricing; free forever may be
    expensive. *Owner: pricing. Recommendation: free during pilot and GA-launch, revisit at 100 orgs.*
11. **Offline hard limits.** 5,000 queued events / 200 MB — are these right for a warehouse that
    loses connectivity for a full week? *Owner: pilot telemetry.*
12. **Multi-box pack UX.** This spec models one `pack_session` per box. Is splitting an order across
    boxes on the phone the right interaction, or should it be dashboard-driven? *Owner: design,
    phase 2.*
13. **Do we need a dedicated rugged-device build?** If pilot operators fight with phones in cold
    storage or with heavy gloves, DataWedge intent support (Phase 3) may move up.
