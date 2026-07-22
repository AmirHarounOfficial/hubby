# Hubby — Competitive Analysis & Plan to Win

Researched: Linnworks, Sellerboard, Rithum (ChannelAdvisor + CommerceHub + Dsco).
Hubby's current state below is taken from the codebase (routes, services, migrations), not marketing.

---

## 0. The one-sentence strategy

> **All three competitors are structurally blind to MENA, gate evaluation behind sales, and ship dated desktop-only UX. Hubby already has the MENA channels, Arabic/RTL, and a real mobile app — so win the Gulf as the Arabic-first, self-serve, mobile-first commerce OS with true profit truth, then expand outward.**

We do **not** try to out-Rithum Rithum (enterprise EDI/dropship network) or out-Linnworks Linnworks (15 years of WMS depth) on their home turf. We take the market they cannot serve, with the pricing model they refuse to offer.

---

## 1. Competitor profiles

### 1.1 Linnworks — the direct competitor
Multichannel IMS/OMS + bolt-on WMS. SMB→mid-market (10–50 staff, $1–10M rev). UK-owned (Marlin Equity), acquired SkuVault 2022. Scale: $18B GMV, 242M orders/yr.

**Modules:** Order management (routing, splitting, tagging, fraud screening, returns/refunds) · Inventory (real-time 2-way sync, multi-location, per-channel buffers, kits/bundles with component substitution, batch + expiry, FIFO/LIFO/weighted-average costing) · **WMS add-on** (pick-path optimization, wave picking, bins/zones, barcode receiving/packing, QC) · **Listings add-on** (bulk create/edit, per-channel field overrides, repricing) · Shipping (batch labels, rate shopping, manifests, branded tracking) · Purchase orders · **Forecasting add-on** · Reporting.

**Channels:** 100+ marketplaces; 60–85 shipping integrations (their own site is inconsistent). Amazon, eBay, Walmart, Shopify, BigCommerce, Magento, WooCommerce, TikTok Shop, Etsy, Temu, SHEIN, Allegro, bol.com, OnBuy, Wayfair. Carriers: Royal Mail, DPD, EVRi, DHL, FedEx, UPS, Parcelforce, Stamps.com.

**Automation — their crown jewel:** Rules Engine, **unlimited and included in every plan, ungated**. Orders evaluated on download; multiple rules chain per order. Conditions: channel, destination, weight, value, product type, delivery speed, stock, location, buyer risk. Actions: route to warehouse, assign carrier, tag, split, folder, park/lock, fraud-route, quarantine returns. "Spotlight AI" (Jan 2026) is only a *rules-recommendation* layer, not agentic.

**Pricing:** No public price. Monthly **order volume** basis, **no GMV %**. Overage fees. Listings/WMS/Forecasting/Analytics are **separate paid add-ons**. One-time onboarding fee; **~60 days to go live**; no free trial — demo only. Third-party estimates ($4k–$14.4k/yr) are unverified.

**Ratings:** G2 4.0 (130+), Capterra 4.1 (47), SoftwareAdvice 4.1. (Trustpilot 4/5 carries a Trustpilot review-solicitation warning — discount it.)

**Weaknesses:** Dated/complex UI + steep learning curve (most repeated complaint) · **Mobile is the biggest hole — confirmed by their own docs**: no dedicated mobile OS app, camera-as-scanner explicitly unsupported, "mobile app in BETA for a long time"; a third-party ecosystem exists purely to fill it · Support slow, UK-hours-centric · High implementation cost/effort · Limited customization · Integration breakage (Walmart, WooCommerce, POS) · Reporting weak — **real profitability requires paid partner Conjura** · **No webhooks in the public API**, no published rate limits · Only QuickBooks Online native for accounting.

**Gaps:** **No MENA marketplaces at all** (no Noon, Amazon.ae/.sa, Trendyol, Jumia); region selector is US/CA/GB/AU/NZ only; **no Arabic/RTL** · No first-class mobile · No webhooks · Thin AI · Not an ERP.

---

### 1.2 Sellerboard — the analytics benchmark
Amazon seller profit analytics. German. 20,000+ users. **Not** an ops tool.

**Modules:** **Profit analytics** — claims 100+ Amazon fees modelled: referral, FBA fulfilment, FBM shipping, storage (monthly + long-term), inbound shipping, prep/labeling, PPC, refund commissions, remissions, COGS, indirect/fixed expenses, promos. **COGS flexibility is best-in-class: FIFO, fixed, batch-based, period-based, per-marketplace, auto-updated when a PO closes.** Refund breakdown incl. refundable vs non-refundable fees and lost COGS. Real-time "Today" view, SKU-level P&L, LTV, cashflow, amortized indirect expenses · Inventory forecasting + reorder qty/timing with supplier lead times, PO workflow, FBA shipments, AWD sync · **PPC** with **break-even ACOS per keyword**, bid automation, keyword harvesting, negative-keyword automation · Reimbursement detection (lost/damaged/short-reimbursed) · Listing change alerts + hijacker/Buy Box · Autoresponder · Scheduled reports · Reseller/arbitrage workflow with mobile barcode scanning.

**Channels:** Amazon (~21 countries incl. UAE) core; Walmart (reduced); **Shopify is a separate app with separate billing** ($9–79/mo); eBay unverified; Etsy unsupported.

**Pricing — the model to copy:** $19/29/39/79 monthly ($15/23/31/63 annual). **1-month free trial, no card.** Tiers scale by **order volume, not capability** — all analytics in the cheapest tier. Only automated reports are gated. Caps: 30k products.

**Ratings:** Capterra 5.0 (31), G2 4.7 (52), Trustpilot ~4.5.

**Weaknesses:** Dated/clunky UI, slow at times · Overwhelming onboarding (historical COGS entry) · Limited report customization · **Weak FBM** (no courier invoice upload) · Trustpilot minority reports serious data-accuracy disputes (miscalculated VAT, inflated margins), PPC automation damaging campaigns, poor escalation support · Search Term Performance **requires Brand Registry**.

**Gaps:** **No operational order/inventory management** — doesn't sync stock back to channels, isn't an OMS/WMS · No listing management · No repricer · **Reimbursements detected but not auto-filed** · **No public API, no Zapier, no accounting integration** · **No unified multi-channel P&L** (Shopify separate app) · **No Noon/Jumia; no Arabic**; Amazon.sa/.eg unverified.

---

### 1.3 Rithum — the enterprise incumbent
ChannelAdvisor + CommerceHub + Dsco, rebranded 2024. GTCR/Sycamore-owned. $50B+ GMV, 40,000+ brands/retailers, 600+ marketplaces, 41,000-supplier network.

**Modules:** Marketplace selling (600+ channels, listing error detection, bulk feeds, localization) · Inventory (buffers, multi-location, dynamic bundling, routing) · Order management (smart routing to DCs/FBA/WFS, returns) · **Dropship — the real moat** (OrderStream EDI + DSCO API, supplier onboarding at 1,000+ suppliers scale, Walmart/Best Buy/Target/Home Depot networks) · Private marketplaces for retailers (M&S, Michaels) · **SupplyExplorer** supplier discovery · **Retail media** across Amazon/Walmart/Target with **closed-loop ASIN-level attribution using margin data** · Product Feeds (A/B testing, near-real-time sync, AI-platform visibility) · Delivery suite (rate shopping, label management, EDD prediction) · Commerce Insights (SKU-level profitability, settlement integration) · **RithumIQ** AI (Sept 2025) + Stripe/Perplexity **agentic commerce** partnerships.

**Pricing:** Opaque — `/pricing` 404s. Platform fee **$2,000+/mo** + **2–4% of GMV** + per-channel/EDI fees + **paid onboarding**; single-module deals $12–18k/yr, $50k+ multi-module; typical first year **$30–100k**. Multi-year auto-renewing contracts, ~4% escalators, narrow cancellation windows. **8–16 weeks onboarding (6–9 months complex).**

**Ratings:** Capterra 3.8 (61), 21% negative.

**Weaknesses:** Cost — "astronomical," "uneconomical for SMEs"; **not viable below ~$5M GMV** · Reported **4–7x price hikes post-merger** · Support degradation post-merger · "VERY dated and not intuitive" UI, steep curve · Long onboarding · Contract lock-in and hard exits · Rigidity ("pay more, wait longer") · Orchestration only — no warehousing/pick-pack/carrier negotiation.

**Gaps:** **No self-serve anything** — no public pricing, no trial, no signup; `/pricing` and `/integrations` both 404 · **No evidence of MENA coverage anywhere** (no Noon, Amazon.ae/.sa, Namshi) · **No mobile app mentioned anywhere** · Carrier list undisclosed · Thin BI/data-out story.

---

## 2. Hubby today (verified from code)

**Have:** 7 integrations with genuine **two-way** capability — Shopify, Salla, Amazon, Noon, Zid, WooCommerce, Trendyol — each implementing `fetchOrders`, `fetchProducts`, `fetchInventory`, `updateInventory`, `updateOrderStatus`, `cancelOrder`, OAuth + token refresh. Orders (+CSV export) · Products/variants/categories, image upload, per-platform sync toggles · Inventory adjust + audit logs, master-store push, `SyncInventoryJob`/`PushInventoryJob` · Cross-channel customers · Analytics (dashboard, timeline, by-platform, top products/customers, daily job) · Billing/plans/subscriptions · Org + members/roles · Notifications + preferences · HMAC webhooks, sync/webhook logs · **Next.js dashboard + Flutter mobile app, both EN/AR with RTL** · Hardened CI/CD.

**Don't have (the honest list):** no rules/automation engine · no shipping carriers or labels · no returns/RMA · no purchase orders/suppliers · no listing creation (only sync) · no multi-warehouse/bins/picking · no forecasting · **no COGS field and no fee/shipping/tax breakdown → no true profit** · no public API/webhooks-out · no accounting integrations · no repricer.

**Schema reality:** `products` has `price` but **no cost**. `orders` have `total`/`currency` but **no fee, shipping, tax, or ad-spend columns**. Tables today: organizations, users, stores, integrations, products, product_variants, platform_products, categories, orders, order_items, inventory_logs, sync_logs, webhook_logs, notifications, plans, subscriptions.

---

## 3. Head-to-head

| Capability | Linnworks | Sellerboard | Rithum | **Hubby now** |
|---|---|---|---|---|
| Multichannel order mgmt | ✅ deep | ❌ | ✅ | 🟡 basic |
| 2-way inventory sync | ✅ | ❌ | ✅ | ✅ |
| Automation rules engine | ✅ **ungated** | ❌ | ✅ | ❌ |
| Listing create/edit | 🟡 add-on | ❌ | ✅ | ❌ |
| Shipping labels/carriers | ✅ 60–85 | ❌ | 🟡 opaque | ❌ |
| Warehouse/picking | 🟡 add-on | ❌ | ❌ | ❌ |
| Returns/RMA | ✅ | 🟡 analysis | ✅ | ❌ |
| PO / suppliers | ✅ | ✅ | ✅ | ❌ |
| Forecasting | 🟡 add-on | ✅ | ✅ | ❌ |
| **True net profit (COGS+fees)** | ❌ partner | ✅ **best** | ✅ ent. | ❌ |
| Public API | ✅ no webhooks | ❌ | 🟡 EDI | ❌ |
| **MENA channels** | ❌ | ❌ | ❌ | ✅ **only one** |
| **Arabic / RTL** | ❌ | ❌ | ❌ | ✅ **only one** |
| **Mobile app** | ❌ | ✅ | ❌ | ✅ |
| Self-serve + public price | ❌ | ✅ | ❌ | 🟡 |
| Modern UX | ❌ | ❌ | ❌ | ✅ |

**Read:** we are behind on *operational depth* and *profit truth*, and ahead on *region, language, mobile, UX, and go-to-market model*. Every competitor is weak in exactly the places we're strong, and vice-versa — so the plan is: **defend the wedge, close the four gaps that lose deals.**

---

## 4. Positioning

> **Hubby — the commerce operations platform for MENA. Every channel, in Arabic, with real profit per order, run from your phone.**

Three claims none of them can answer:
1. **"Salla, Zid, Noon, Trendyol, Amazon.sa — connected in minutes."** Linnworks/Rithum have zero MENA channels; adding them is a roadmap decision they haven't made in 15 years.
2. **"Arabic-first, RTL, riyal-native, ZATCA-ready."** Nobody else has an Arabic UI.
3. **"Start free in 10 minutes. No demo, no GMV %, no annual lock-in."** Linnworks = demo + 60-day onboarding; Rithum = $2k/mo + 2–4% GMV + multi-year.

---

## 5. The plan

### Phase 0 (0–3 months) — close the deal-losers
These are the four things a merchant asks in the first meeting that we currently fail.

1. **Automation Rules Engine** — the single biggest parity gap (Linnworks' moat).
   Tables: `automation_rules` (org, trigger, conditions JSON, actions JSON, priority, enabled), `automation_runs` (audit).
   Triggers: order created/updated, stock below threshold, sync failure. Conditions: channel, country, value, weight, SKU/category, payment method (**incl. COD**), stock. Actions: tag, route to location, assign carrier, split, hold for review, set status, notify (email/WhatsApp), webhook. Chain multiple rules per order + full audit trail. **Ship it ungated in every plan** — match Linnworks' one genuinely generous policy.

2. **True profit** — beat Sellerboard on breadth, match on rigor, and be the only one doing it cross-channel for MENA.
   Schema: `product_costs` (sku, cost, method FIFO/fixed/period/batch, valid_from, currency, per-store override), `order_fees` (order_id, type: commission/fulfilment/shipping/payment/refund/storage/ads, amount), `expenses` (recurring/one-off, amortization), `ad_spend` (channel, campaign, date, amount).
   Deliver: net profit per order / per SKU / per channel, margin after all fees, refund cost incl. lost COGS, VAT-aware. **This also unlocks the killer cross-channel P&L Sellerboard structurally cannot do** (their Shopify is a separate product).

3. **Returns / RMA** — request → approve → restock-or-scrap → refund, with reason codes and analytics. Every competitor has it.

4. **Shipping + labels with MENA carriers** — Aramex, SMSA, Naqel, J&T, Torod, plus DHL/FedEx. Rate shopping, label + AWB printing, manifests, tracking webhooks, branded Arabic tracking page. **None of the three carry Gulf carriers.**

### Phase 1 (3–6 months) — build the regional moat
5. **ZATCA Phase-2 e-invoicing (Saudi)** + UAE VAT. Cryptographic stamps, QR, XML submission, compliant Arabic invoices. **This is a regulatory moat: a Saudi merchant legally cannot use Linnworks/Rithum as their system of record without it.** Highest-leverage item on this list.
6. **COD reconciliation** — COD dominates MENA. Cash collected vs remitted per carrier, aging, discrepancies, RTO (return-to-origin) rates and cost. Nobody else models COD at all.
7. **WhatsApp Business** — order confirmations, shipping updates, COD confirmation, abandoned-order nudges, in Arabic. WhatsApp is *the* MENA channel; none of the three integrate it.
8. **Mobile warehouse ops** — pick/pack/receive/stock-count via **phone camera barcode scanning**. Linnworks explicitly does *not* support camera-as-scanner and has no iOS app; Rithum has no app. We already have a Flutter app — this turns our biggest asset into their biggest weakness.
9. **More channels** — Amazon.sa/.ae depth, Namshi, TikTok Shop MENA, Jumia (Egypt), Shein.

### Phase 2 (6–12 months) — operational depth
10. **Listing management** — create/edit listings per channel from one master SKU, per-channel overrides, bulk edit, **AI Arabic⇄English listing generation/translation** (nobody does Arabic listing content).
11. **Purchase orders + suppliers + replenishment** — lead times, reorder points, PO → receive → auto-update COGS (Sellerboard's best mechanic).
12. **Forecasting** — velocity + seasonality + lead-time-aware reorder suggestions, incl. Ramadan/Eid/White Friday seasonality that generic models miss.
13. **Multi-warehouse / locations / bins** — transfers, per-location stock, zone mapping (WMS-lite).
14. **Public API + outbound webhooks** — REST + webhooks + keys + rate limits + docs. **Linnworks has no webhooks; Sellerboard has no API at all.** Cheap to build, strong differentiator for agencies/integrators.
15. **Accounting** — QuickBooks, Xero, Zoho + **local: Qoyod, Wafeq**.

### Phase 3 (12 months+)
16. **AI ops** — demand sensing, auto-replenishment, anomaly detection (margin drop, fee spike, sync failure), Arabic support copilot. Position against Linnworks' Spotlight AI, which only *recommends rules*.
17. **B2B / wholesale + dropship supplier network** — the long-term Rithum answer, but regional.
18. **Expansion** — Egypt, UAE, Kuwait, Qatar, then Turkey (Trendyol already built).

---

## 6. Pricing (deliberately the opposite of Linnworks/Rithum)

- **Public, self-serve, free trial, no card.** Sellerboard proves this works; Linnworks and Rithum both refuse it.
- **Bill on monthly order volume — never a % of GMV.** (Rithum's 2–4% GMV is the #1 reason people leave it.)
- **Never gate capability behind tiers** — analytics, rules engine, API, mobile in every plan. Linnworks charges separately for listings, WMS, forecasting *and* analytics; that "all-in-one" pitch understates real cost. Ours should be genuinely all-in.
- **No mandatory onboarding fee**; self-serve go-live in a day vs their 60 days / 8–16 weeks.
- Monthly billing available; no multi-year lock-in.

## 7. Go-to-market

- **Distribution: get listed in the Salla App Store and Zid Market.** Instant access to thousands of Saudi merchants; neither competitor is present. Highest-ROI channel we have.
- **Comparison content:** "Linnworks alternative for Saudi/GCC", "Rithum alternative without GMV fees", "Sellerboard + OMS in one" — all three have unserved search demand in the region.
- **Free migration** from Linnworks/Sellerboard (importers for their exports) + free historical COGS import — directly targets Sellerboard's most-complained-about onboarding pain.
- **Arabic-first everything:** docs, support, onboarding, invoices. Support in GMT+3 hours (Linnworks' known weakness is UK-hours support).

## 8. Risks

- **Scope:** this is a large roadmap; Phase 0 alone is a quarter. Resist building WMS depth before profit + rules land.
- **Channel API maintenance** is a permanent cost — Linnworks breaks here regularly; budget for it.
- **ZATCA is compliance-critical** — bugs create legal exposure. Needs proper testing and possibly certification/partner.
- **Rithum could acquire a MENA player** to enter the region — speed matters; the window is open now.
- **Verification caveats:** competitor pricing is largely third-party/unverified (all three hide it); several critical reviews come from competitor-authored SEO content. Complaints cited above are the ones that recurred across *independent* review platforms.

---

## 9. If we only do four things

1. **Automation rules engine** (ungated) — closes the biggest functional gap.
2. **True profit with COGS + fees** — the one thing merchants pay for, and it needs schema work first.
3. **ZATCA e-invoicing + COD reconciliation** — the regulatory/behavioural moat nobody can copy quickly.
4. **Mobile warehouse scanning** — turns our existing app into a category-leading advantage against two competitors with no app at all.
