# Hubby — Feature Specs

Implementation-ready specs for the roadmap in [../COMPETITIVE_STRATEGY.md](../COMPETITIVE_STRATEGY.md)
(نسخة مصرية: [../COMPETITIVE_STRATEGY.ar.md](../COMPETITIVE_STRATEGY.ar.md)).

Every spec was written against the **actual codebase** — real column names, real file paths, real
route conventions — not generic advice. Anything the author could not verify is explicitly marked
`[UNVERIFIED]` or flagged as an assumption. That distinction matters most in `05-zatca` (legal
exposure) and in the per-platform/per-carrier sections (marketplace APIs genuinely differ).

---

## ⛔ Blocking defects — fix before building any of this

The spec work doubled as a code audit. **Eight defects were found and independently verified.**
Several spec authors hit the same ones from different directions, and each was then confirmed
directly against the schema.

| # | Defect | Impact | Where |
|---|---|---|---|
| 1 | `SyncOrdersJob::dispatch($externalId, $platform)` vs `__construct(Store $store = null)` — 6 call sites | **All webhook order syncs fail** | `WebhookController` |
| 2 | `OrderItem::updateOrCreate` writes `external_id` + `product_name`; neither column exists, and `product_name` isn't in `$fillable` so NOT NULL `name` is never set | **Any order with line items breaks the whole sync** | `SyncOrdersJob` |
| 3 | `Order::updateOrCreate` keyed on `external_id` alone despite `unique(store_id, external_id)` | One store's order can overwrite another's | `SyncOrdersJob` |
| 4 | `// TODO: Dispatch job to push new inventory` — `PushInventoryJob` never dispatched from adjust | **Manual stock adjustments never reach any channel** | `InventoryController::adjust` |
| 5 | `product_variants.sku` is globally `->unique()` | Two organizations cannot use the same SKU — multi-tenant blocker | migration |
| 6 | `integrations.access_token` / `refresh_token` stored plaintext (no `encrypted` cast) | Credential exposure on any DB/backup leak | `Integration` model |
| 7 | Analytics bucket by `created_at` (row insert time); `orders` has **no order-date column** | **Revenue/timeline/period-comparison numbers are wrong** — a first sync dumps months of history into "today" | `AnalyticsController` |
| 8 | Amazon SP-API **SigV4 signing unimplemented** (flagged in its own docblock) | Production Amazon calls can't authenticate; blocks Amazon fee capture | `AmazonService` |

**#2 and #4 together mean the core promise — orders in, inventory out — does not work end-to-end
against a live store.** It looks healthy in the dashboard because that data arrived by other means.

Several specs declare these as hard dependencies (e.g. Returns cannot map marketplace lines without
a stable per-line identity; Shipping treats #6 as a security prerequisite because carrier
credentials can create billable shipments).

---

## Spec index

| # | Spec | Tier | Why it matters |
|---|---|---|---|
| [01](01-profit-cost-engine.md) | **Profit & Cost Engine** | 1 | `product_costs`, `order_fees`, `expenses`, `ad_spend`. Nothing else can report profit without it. Sellerboard is the benchmark; Linnworks needs a paid partner for margin. |
| [02](02-automation-rules-engine.md) | **Automation Rules Engine** | 1 | Linnworks' crown jewel. Ours ships **ungated in every plan** — enforced by test, not by promise. |
| [03](03-returns-rma.md) | **Returns / RMA** | 1 | 15-status lifecycle, partial per-line returns, restock-vs-scrap ledger, **RTO for COD** as a first-class type. |
| [04](04-shipping-labels.md) | **Shipping & Labels** | 1 | `ShippingCarrierInterface` mirroring our integration pattern. **Aramex, SMSA, Naqel, J&T** + DHL/FedEx — no competitor carries Gulf carriers. |
| [05](05-zatca-einvoicing-vat.md) | **ZATCA e-invoicing + VAT** | 2 | Regulatory moat: a Saudi merchant legally cannot use Linnworks/Rithum as system of record without it. |
| [06](06-cod-reconciliation.md) | **COD Reconciliation** | 2 | COD dominates MENA and **nobody models it**. Remittance matching, RTO cost, cash-in-transit, risk scoring. |
| [07](07-whatsapp-business.md) | **WhatsApp Business** | 2 | *The* MENA channel. COD confirmation before dispatch is the RTO-reduction wedge. |
| [08](08-mobile-warehouse-scanning.md) | **Mobile Warehouse Scanning** | 2 | Linnworks' own docs say no camera-as-scanner and no iOS app; Rithum has no app at all. We already ship Flutter. |

---

## Dependency graph

```
        ┌─────────────────────────────┐
        │  0. Fix the six defects     │  ← everything below assumes ingest works
        └──────────────┬──────────────┘
                       │
        ┌──────────────▼──────────────┐
        │  01 Profit & Cost Engine    │  ← order_fees / product_costs are read by
        └───┬───────────┬─────────┬───┘     analytics, COD, returns, forecasting
            │           │         │
   ┌────────▼───┐  ┌────▼─────┐  ┌▼──────────────┐
   │ 02 Rules   │  │ 03 Returns│  │ 04 Shipping   │
   └────┬───────┘  └────┬──────┘  └───┬───────┬───┘
        │               │             │       │
        │          ┌────▼─────────────▼──┐    │
        │          │  06 COD Reconcile   │◄───┘  (carrier statements, COD fees)
        │          └────┬────────────────┘
        │               │
   ┌────▼───────────────▼───┐        ┌──────────────────────┐
   │  07 WhatsApp           │        │ 05 ZATCA (parallel)  │
   │  (rules fire messages) │        │ independent track    │
   └────────────────────────┘        └──────────────────────┘

   08 Mobile Scanning — depends on a minimal location model + order_items line identity (see #2)
```

**Read:** `01` is the true foundation — rules, returns, COD and analytics all read `order_fees` /
`product_costs`. Adding those columns later means rewriting everything built on top. `05 ZATCA` is
the one track that can run fully in parallel with its own specialist.

---

## Recommended build order

1. **Fix the six defects** (small, blocking, includes a live security issue)
2. **01 Profit & Cost Engine** — schema first, then per-platform fee capture
3. **02 Rules Engine** — hooks into the now-working ingest path
4. **03 Returns** → **04 Shipping** (Returns needs line identity; Shipping needs carrier creds secured)
5. **06 COD** (needs shipping + fees) → **07 WhatsApp** (needs rules to trigger)
6. **05 ZATCA** in parallel from day one — it has the longest external lead time (CSID onboarding)
7. **08 Mobile Scanning** once a minimal location model exists

## Effort (author estimates, engineer-days)

| Spec | Estimate |
|---|---|
| 03 Returns / RMA | ≈ 58.5 |
| 06 COD Reconciliation | ≈ 90 |
| 07 WhatsApp Business | ≈ 108 |
| 04 Shipping & Labels | ≈ 109 |
| 01 Profit & Cost Engine | ≈ 114 (~8 weeks for three engineers) |
| 08 Mobile Warehouse Scanning | ≈ 118 (16 weeks, with a 6-week Lookup+Receive thin slice) |
| 02, 05 | see each spec |

These are honest estimates including tests and rollout, not best-case coding time. Long poles are
**external, not technical**: live carrier statements, Meta Tech Provider approval, and ZATCA CSID
onboarding — all three should be started on day one regardless of build order.

## Spec structure

Each spec follows the same 15 sections: rationale · scope · data model (real column types, indexes,
FKs, migration names) · domain logic · backend (models/services/jobs/endpoints) · per-platform or
per-carrier notes · dashboard (with `en`/`ar` i18n keys) · mobile · permissions & multi-tenancy ·
edge cases · testing · rollout · acceptance criteria · effort · open questions.
