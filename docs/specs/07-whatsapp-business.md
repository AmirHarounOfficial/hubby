# Spec 07 — WhatsApp Business Messaging

> Status: draft · Owner: Backend Architecture · Target: Laravel 12 / PHP 8.3 backend, Next.js 16 dashboard, Flutter mobile
> Related specs (referenced, not redefined): **COD Reconciliation** (`cod_transactions`, risk bands), **Automation Rules Engine** (`send_whatsapp` action), **Shipping & Labels** (tracking events), **Profit & Cost Engine**.

---

## 1. Why this exists (competitive rationale)

In MENA, WhatsApp is not a marketing channel. It is *the* channel. Penetration in Saudi Arabia, UAE, Egypt and Jordan is effectively universal among online shoppers, and merchants already run order confirmation, delivery coordination and customer service through it — manually, on a personal phone, with no record, no attribution and no automation.

The competitive picture:

| Product | WhatsApp | Consequence |
|---|---|---|
| Linnworks | None. Email/channel messaging only. | A MENA merchant runs WhatsApp in a separate window forever. |
| Sellerboard | None — it is an analytics product. | — |
| Rithum | None. | — |

There are standalone WhatsApp BSPs (Wati, Twilio, 360dialog, Unifonic), but they don't know what an order is. **Hubby's advantage is not "we send WhatsApp messages" — it is that we send them with the order, the shipment, the COD amount, the AWB and the customer's RTO history already in hand.** Nobody else in the ops-platform category can do that.

The single highest-value use case, and the one that ties this spec to Spec 06:

> **COD confirmation before dispatch.** Send a template to the customer asking them to confirm the COD order before it ships. Unconfirmed and refused orders never enter the carrier network, so they never become RTO. Merchants and BSPs in the region routinely claim RTO reductions in the 20–40% range from this one flow (**unverified marketing claim — do not repeat it without our own measured data**). Because we own the COD ledger, we can actually *measure* it: RTO rate for confirmed vs unconfirmed cohorts, per merchant. That measurement is itself a product.

---

## 2. Scope — in / out

### In scope

- **WhatsApp Cloud API (Meta-hosted)** integration only. Not On-Premises (deprecated), not a BSP reseller path in v1.
- Onboarding via Meta **Embedded Signup**, plus a manual credential path for merchants who already have a WABA.
- Phone number registration state, quality rating and messaging tier tracking.
- Message template lifecycle: author → submit to Meta → poll/receive status → use. Arabic **and** English versions of every template.
- Sending: template (business-initiated) and freeform (inside an open window), with a hard guard that prevents freeform outside the window.
- Receiving: inbound messages via webhook, routed into a per-customer conversation and a shared inbox.
- **24-hour customer service window** tracking, and **72-hour free-entry-point window** tracking, as first-class state.
- Delivery status tracking: sent / delivered / read / failed, with Meta error codes surfaced in plain language.
- Opt-in / opt-out consent capture with evidence, and enforcement on every business-initiated send.
- Per-message cost attribution (category + country), so merchants see what WhatsApp costs them.
- Integration with the existing `notifications` table and with the Automation Rules Engine as a `send_whatsapp` action.
- The 7 order/commerce use cases in §6.3, including COD confirmation wired to Spec 06.

### Out of scope

- Being a general-purpose CRM or a full helpdesk (SLAs, macros, CSAT, ticket routing). We ship a functional shared inbox, not Zendesk.
- WhatsApp Flows, Catalog/Product messages, Payments in WhatsApp, Click-to-WhatsApp ad creation. All are plausible v2; none are needed for the wedge.
- Instagram / Messenger channels, despite sharing the Meta webhook shape.
- Becoming a Meta **Tech Provider / Solution Partner** and reselling messaging. That is a business and legal track (Meta app review, business verification), not an engineering one — but note §12.3: *without* it, every merchant must bring their own Meta app, which is a significantly worse onboarding experience. **This is the single biggest product decision attached to this spec.**
- Voice/video calling, WhatsApp Business App (the consumer app) sync.
- Broadcast/campaign management with audience segmentation. v1 sends transactional messages triggered by order events; bulk marketing is a separate product with different compliance exposure.

---

## 3. Data model

### 3.0 Conventions and verification status

Repo conventions are as described in Spec 06 §3.0 (anonymous-class migrations, `decimal(15,2)` money, denormalised `organization_id`, `foreignId()->constrained()`).

**Research status.** The Meta-facing facts below were checked against Meta's developer documentation and secondary sources in July 2026:

| Fact | Status |
|---|---|
| Pricing moved from **per-conversation** to **per-message** on **1 July 2025**; template messages are billed on delivery, priced by **category × recipient country code** | **Verified** (Meta pricing docs) |
| Categories: **marketing**, **utility**, **authentication**; **service** messages (non-template, inside an open window) are **free** | **Verified** |
| **Utility templates are free when sent inside an open customer service window** | **Verified** (Meta pricing docs) — this is the single most important cost lever in this spec |
| **24-hour customer service window**, opened/reset by any inbound customer message; any message type may be sent inside it | **Verified** |
| **72-hour free entry point window** from Click-to-WhatsApp ads / Page CTA, independent of the 24-hour window | **Verified** |
| Business-initiated messages outside the window **must** use a pre-approved template | **Verified** |
| Templates are approved **per language**; one language can be approved while another is rejected | **Verified** (secondary sources; consistent with Meta docs) |
| Default limit **~250 approved templates per WABA**; edit limits ~10 per 30 days / 1 per 24 h | **Unverified — secondary sources only.** Treat as a soft limit; the code must handle Meta returning a limit error rather than assuming a number. |
| Embedded Signup is the default onboarding path in 2026; **v2 deprecated 15 Oct 2026, migrate to v4** | **Unverified — secondary source.** Confirm the current version against Meta's changelog before implementing; the spec pins the version in config for exactly this reason. |
| Exact per-message rates for SA / AE / EG | **Not captured here on purpose.** Rates change and are country-specific. We store a rate card in config, never in code, and we prefer Meta's own reported price on the webhook when present (§4.7). |

Anything the code *depends on* numerically is configuration, not a constant.

### 3.1 `whatsapp_accounts`

One row per connected WhatsApp Business phone number. Migration: `2026_07_22_000101_create_whatsapp_accounts_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete` |
| `waba_id` | `string(64)` | no | — | WhatsApp Business Account id |
| `business_id` | `string(64)` | yes | `null` | Meta Business Portfolio id |
| `phone_number_id` | `string(64)` | no | — | the id used on every send |
| `display_phone_number` | `string(32)` | no | — | E.164, shown in UI |
| `verified_name` | `string(255)` | yes | `null` | the display name Meta approved |
| `access_token` | `text` | yes | `null` | **`encrypted` cast**; long-lived system-user token |
| `token_type` | `string(24)` | no | `'system_user'` | `system_user` \| `user` \| `bsp` |
| `token_expires_at` | `timestamp` | yes | `null` | null = non-expiring system user token |
| `webhook_verify_token` | `text` | yes | `null` | **`encrypted` cast**; per-account, generated by us |
| `webhook_secret` | `text` | yes | `null` | **`encrypted` cast**; app secret used for `X-Hub-Signature-256` |
| `app_id` | `string(64)` | yes | `null` | Meta app the token belongs to |
| `quality_rating` | `string(16)` | yes | `null` | `GREEN` \| `YELLOW` \| `RED` \| `UNKNOWN` |
| `messaging_limit_tier` | `string(24)` | yes | `null` | `TIER_250` \| `TIER_1K` \| `TIER_10K` \| `TIER_100K` \| `UNLIMITED` |
| `throughput_level` | `string(16)` | yes | `null` | `STANDARD` \| `HIGH` |
| `status` | `string(24)` | no | `'pending'` | see §4.1 |
| `status_reason` | `string(255)` | yes | `null` | Meta's reason on restriction/ban |
| `default_locale` | `string(8)` | no | `'ar'` | template language preference for this org |
| `fallback_locale` | `string(8)` | no | `'en'` | |
| `connected_at` | `timestamp` | yes | `null` | |
| `disconnected_at` | `timestamp` | yes | `null` | |
| `last_synced_at` | `timestamp` | yes | `null` | last successful metadata refresh |
| `last_error` | `text` | yes | `null` | |
| `metadata` | `json` | yes | `null` | |
| `timestamps`, `softDeletes` | | | | soft-delete so message history survives a disconnect |

Indexes: `unique(['phone_number_id'])` (a phone number id is globally unique at Meta and must not be claimable by two orgs), `unique(['organization_id','display_phone_number'])`, `index(['organization_id','status'])`, `index(['waba_id'])`.

> **Token storage.** `access_token`, `webhook_verify_token`, `webhook_secret` all use Laravel's `encrypted` cast and are in `$hidden`. They are never returned by any endpoint, never logged, and never included in exception context. This deliberately does **not** follow the existing `integrations.access_token` plaintext precedent — see Spec 06 §3.0.

### 3.2 `whatsapp_templates`

Migration: `2026_07_22_000102_create_whatsapp_templates_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete` |
| `whatsapp_account_id` | `foreignId` → `whatsapp_accounts` | no | — | `cascadeOnDelete` |
| `meta_template_id` | `string(64)` | yes | `null` | Meta's id, set after submission |
| `name` | `string(120)` | no | — | Meta rule: lowercase, digits, `_` only |
| `language` | `string(12)` | no | — | Meta language code: `ar`, `en`, `en_US`, `ar_EG`… |
| `category` | `string(24)` | no | — | `MARKETING` \| `UTILITY` \| `AUTHENTICATION` |
| `use_case` | `string(48)` | yes | `null` | our key: `order_confirmation`, `cod_confirmation`, … (§6.3) |
| `status` | `string(24)` | no | `'DRAFT'` | see §4.2 |
| `rejected_reason` | `string(255)` | yes | `null` | |
| `quality_score` | `string(16)` | yes | `null` | `GREEN`/`YELLOW`/`RED` |
| `header_type` | `string(16)` | yes | `null` | `NONE`\|`TEXT`\|`IMAGE`\|`VIDEO`\|`DOCUMENT`\|`LOCATION` |
| `header_text` | `string(60)` | yes | `null` | Meta limit 60 chars |
| `body` | `text` | no | — | with `{{1}}`-style placeholders |
| `footer` | `string(60)` | yes | `null` | |
| `buttons` | `json` | yes | `null` | `[{type, text, url?, phone_number?}]` |
| `variables` | `json` | yes | `null` | see below |
| `example_payload` | `json` | yes | `null` | samples Meta requires on submission |
| `allow_category_change` | `boolean` | no | `true` | let Meta re-categorise rather than reject |
| `is_system` | `boolean` | no | `false` | seeded Hubby template — not user-deletable |
| `submitted_at` | `timestamp` | yes | `null` | |
| `approved_at` | `timestamp` | yes | `null` | |
| `paused_at` | `timestamp` | yes | `null` | |
| `last_synced_at` | `timestamp` | yes | `null` | |
| `edit_count_30d` | `unsignedTinyInteger` | no | `0` | local guard against Meta's edit limit |
| `last_edited_at` | `timestamp` | yes | `null` | |
| `timestamps` | | | | |

Indexes: `unique(['whatsapp_account_id','name','language'])`, `index(['organization_id','status'])`, `index(['organization_id','use_case','language'])`, `index(['meta_template_id'])`.

`variables` schema — this is what makes templates usable by non-technical merchants and by the rules engine:

```json
[
  { "index": 1, "component": "body", "key": "customer_name", "source": "order.customer_name", "sample": "سارة", "required": true },
  { "index": 2, "component": "body", "key": "order_number",  "source": "order.external_id",   "sample": "1043",  "required": true },
  { "index": 3, "component": "body", "key": "cod_amount",    "source": "cod.expected_amount", "sample": "349.00","required": true, "format": "money" }
]
```

`source` is a whitelisted dotted path resolved by `TemplateVariableResolver` (§5.2). **Free-form expressions are not permitted** — arbitrary property paths against Eloquent models is a data-leak primitive, not a feature.

### 3.3 `whatsapp_conversations`

The 24-hour window is the core commercial object here. Getting it wrong costs merchants real money and gets messages rejected.

Migration: `2026_07_22_000103_create_whatsapp_conversations_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete` |
| `whatsapp_account_id` | `foreignId` → `whatsapp_accounts` | no | — | `cascadeOnDelete` |
| `contact_phone` | `string(32)` | no | — | E.164, normalised |
| `wa_id` | `string(32)` | yes | `null` | Meta's id for the contact (usually the phone without `+`) |
| `contact_name` | `string(255)` | yes | `null` | WhatsApp profile name |
| `customer_key` | `string(191)` | yes | `null` | joins to `cod_customer_risk.customer_key` |
| `order_id` | `foreignId` → `orders` | yes | `null` | `nullOnDelete`; most-recent related order |
| `window_type` | `string(24)` | yes | `null` | `service` \| `free_entry_point` |
| `window_opened_at` | `timestamp` | yes | `null` | |
| `window_expires_at` | `timestamp` | yes | `null` | **the operative field** |
| `last_inbound_at` | `timestamp` | yes | `null` | |
| `last_outbound_at` | `timestamp` | yes | `null` | |
| `last_message_at` | `timestamp` | yes | `null` | for inbox sorting |
| `last_message_preview` | `string(255)` | yes | `null` | |
| `status` | `string(16)` | no | `'open'` | `open` \| `closed` \| `archived` |
| `open_key` | `string(191)` | yes | `null` | see the unique-index note |
| `assigned_user_id` | `foreignId` → `users` | yes | `null` | `nullOnDelete` |
| `unread_count` | `unsignedInteger` | no | `0` | |
| `is_opted_out` | `boolean` | no | `false` | denormalised from `whatsapp_opt_ins` for fast inbox rendering |
| `message_count` | `unsignedInteger` | no | `0` | |
| `total_cost` | `decimal(12,6)` | no | `0.000000` | 6dp: per-message prices are sub-cent |
| `currency` | `char(3)` | no | `'USD'` | Meta bills in USD by default |
| `metadata` | `json` | yes | `null` | |
| `timestamps` | | | | |

Indexes: `index(['organization_id','status','last_message_at'])` (the inbox query), `index(['whatsapp_account_id','contact_phone'])`, `index(['organization_id','window_expires_at'])`, `index(['organization_id','assigned_user_id','status'])`, `index(['customer_key'])`, `unique(['whatsapp_account_id','open_key'])`.

> **The `open_key` trick.** A contact has many conversations over time but must have **at most one open** conversation per account. Partial unique indexes are not portable across MySQL/SQLite (the repo's tests run on SQLite — see the project memory note). So: `open_key = contact_phone` while `status = 'open'`, and `open_key = NULL` when closed/archived. NULLs are not compared by unique indexes in either engine, so `unique(whatsapp_account_id, open_key)` enforces exactly the invariant we want, portably, at the database level rather than in application code.

### 3.4 `whatsapp_messages`

Migration: `2026_07_22_000104_create_whatsapp_messages_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete` |
| `whatsapp_account_id` | `foreignId` → `whatsapp_accounts` | no | — | `cascadeOnDelete` |
| `whatsapp_conversation_id` | `foreignId` → `whatsapp_conversations` | no | — | `cascadeOnDelete` |
| `whatsapp_template_id` | `foreignId` → `whatsapp_templates` | yes | `null` | `nullOnDelete` |
| `order_id` | `foreignId` → `orders` | yes | `null` | `nullOnDelete` |
| `wamid` | `string(128)` | yes | `null` | Meta's message id |
| `idempotency_key` | `string(80)` | yes | `null` | our dedupe key (§4.5) |
| `direction` | `string(10)` | no | — | `outbound` \| `inbound` |
| `type` | `string(24)` | no | — | `template`\|`text`\|`image`\|`document`\|`audio`\|`video`\|`sticker`\|`location`\|`contacts`\|`interactive`\|`button`\|`reaction`\|`unsupported` |
| `from_phone` | `string(32)` | yes | `null` | |
| `to_phone` | `string(32)` | yes | `null` | |
| `body_preview` | `text` | yes | `null` | rendered text, for the inbox |
| `payload` | `json` | yes | `null` | exact request sent / webhook received |
| `media_id` | `string(128)` | yes | `null` | Meta media id (inbound) |
| `media_path` | `string(500)` | yes | `null` | our private-disk copy |
| `media_mime` | `string(100)` | yes | `null` | |
| `status` | `string(16)` | no | `'queued'` | see §4.4 |
| `sent_at` / `delivered_at` / `read_at` / `failed_at` | `timestamp` | yes | `null` | |
| `error_code` | `string(24)` | yes | `null` | Meta numeric code as string |
| `error_title` | `string(255)` | yes | `null` | |
| `error_details` | `text` | yes | `null` | |
| `pricing_category` | `string(24)` | yes | `null` | `marketing`\|`utility`\|`authentication`\|`service`\|`referral_conversion` |
| `pricing_model` | `string(24)` | yes | `null` | Meta's reported model (e.g. `PMP`/`CBP`) — stored verbatim |
| `billable` | `boolean` | no | `false` | |
| `price_amount` | `decimal(12,6)` | yes | `null` | Meta-reported when available, else our rate card |
| `price_source` | `string(16)` | yes | `null` | `meta` \| `rate_card` \| `unknown` |
| `price_currency` | `char(3)` | yes | `null` | |
| `triggered_by_type` | `string(32)` | yes | `null` | `automation_rule`\|`user`\|`system`\|`reply` |
| `triggered_by_id` | `unsignedBigInteger` | yes | `null` | |
| `retry_count` | `unsignedTinyInteger` | no | `0` | |
| `timestamps` | | | | |

Indexes: `unique(['wamid'])` (nullable — the webhook dedupe backbone), `unique(['organization_id','idempotency_key'])`, `index(['whatsapp_conversation_id','created_at'])`, `index(['organization_id','status'])`, `index(['organization_id','created_at'])`, `index(['order_id'])`, `index(['organization_id','pricing_category','created_at'])` (cost reporting).

### 3.5 `whatsapp_opt_ins`

Consent is a legal artefact. It gets its own table, it is append-friendly, and it stores evidence, not just a boolean.

Migration: `2026_07_22_000105_create_whatsapp_opt_ins_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | no | — | `cascadeOnDelete` |
| `phone` | `string(32)` | no | — | E.164 |
| `customer_key` | `string(191)` | yes | `null` | |
| `status` | `string(16)` | no | `'unknown'` | `opted_in` \| `opted_out` \| `unknown` |
| `scope` | `string(16)` | no | `'all'` | `all` \| `transactional` \| `marketing` |
| `source` | `string(32)` | no | — | `checkout`\|`store_sync`\|`whatsapp_reply`\|`manual`\|`import`\|`stop_keyword`\|`meta_block` |
| `consent_text` | `text` | yes | `null` | the exact wording the customer agreed to |
| `consent_locale` | `string(8)` | yes | `null` | `ar` / `en` |
| `evidence` | `json` | yes | `null` | `{order_id, checkbox_id, message_wamid, screenshot_path, …}` |
| `ip_address` | `string(45)` | yes | `null` | IPv6-safe |
| `user_agent` | `string(500)` | yes | `null` | |
| `opted_in_at` | `timestamp` | yes | `null` | |
| `opted_out_at` | `timestamp` | yes | `null` | |
| `opt_out_reason` | `string(255)` | yes | `null` | |
| `recorded_by` | `foreignId` → `users` | yes | `null` | `nullOnDelete` |
| `timestamps` | | | | |

Indexes: `unique(['organization_id','phone','scope'])`, `index(['organization_id','status'])`.

`whatsapp_opt_in_events` (`2026_07_22_000106_...`) is an append-only audit log of every consent transition: `organization_id`, `opt_in_id`, `from_status`, `to_status`, `source`, `evidence` json, `created_at`. The current-state table can be corrected; the event log cannot. Under a regulator's question — *"prove this person consented on this date"* — the event log is the answer.

### 3.6 `whatsapp_webhook_events`

The existing `webhook_logs` table (`platform`, `event`, `payload`, `processed`, `error`) has no `organization_id` and no dedupe key, so it cannot carry WhatsApp traffic safely at volume. This spec adds a dedicated table rather than degrading the shared one.

Migration: `2026_07_22_000107_create_whatsapp_webhook_events_table.php`

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `bigIncrements` | no | — | |
| `organization_id` | `foreignId` → `organizations` | yes | `null` | `nullOnDelete`; null until routed |
| `whatsapp_account_id` | `foreignId` → `whatsapp_accounts` | yes | `null` | `nullOnDelete` |
| `event_hash` | `char(64)` | no | — | SHA-256 of the raw body |
| `waba_id` | `string(64)` | yes | `null` | routing key from `entry[].id` |
| `field` | `string(48)` | yes | `null` | `messages`, `message_template_status_update`, `account_update`, … |
| `payload` | `json` | no | — | raw body |
| `signature_valid` | `boolean` | no | `false` | |
| `processed` | `boolean` | no | `false` | |
| `processed_at` | `timestamp` | yes | `null` | |
| `error` | `text` | yes | `null` | |
| `attempts` | `unsignedTinyInteger` | no | `0` | |
| `timestamps` | | | | |

Indexes: `unique(['event_hash'])`, `index(['processed','created_at'])`, `index(['waba_id'])`. Retention 30 days via a scheduled prune.

### 3.7 Reused / extended tables

- `orders` — `customer_phone` (Spec 06 §3.8). **Whichever spec ships first owns that migration.** WhatsApp is unusable without it.
- `notifications` — merchant-facing alerts (template rejected, account restricted, quality dropped to RED) reuse the existing table and shape.
- `users.notification_preferences` (json) gains a `whatsapp` block for which WhatsApp events produce in-app notifications.
- `plans.features` gains `whatsapp` and optionally `whatsapp_monthly_message_cap`.

---

## 4. Domain logic

### 4.1 Account status state machine

```
pending  ──connect──►  connecting ──ok──►  connected
   ▲                        │                  │
   │                        └──fail──► failed  │
   │                                           │
   │                        ┌──────────────────┤
   │                        │                  │
   │           token_expired│      restricted ◄┤ (Meta quality/policy action)
   │                        │                  │
   │                        ▼                  ▼
   └──────reconnect──── disconnected  ◄──── banned (terminal until Meta lifts it)
```

- `connected` requires a valid token **and** a registered phone number **and** a verified webhook subscription. All three are asserted by `WhatsAppAccountService::verify()`; passing two of three is `failed`, not `connected`. A "connected" badge that lies is worse than no badge.
- `restricted` (quality-based messaging limits) still allows sending — with a visible warning and a reduced tier. `banned` blocks all sends immediately at the service layer, not at Meta's API, so we do not burn quota on guaranteed failures.
- `token_expired` triggers a merchant notification and blocks sends. There is no silent degradation.

### 4.2 Template status state machine

Meta owns most of this; we mirror it.

```
DRAFT ──submit──► PENDING ──► APPROVED ──► PAUSED ──► APPROVED (recovers)
                     │            │            │
                     └► REJECTED  └► DISABLED  └► DISABLED (repeat quality failures)
APPROVED ──edit──► PENDING (re-review)
```

- Only `APPROVED` templates may be sent. Attempting to send a `PENDING`/`REJECTED`/`PAUSED`/`DISABLED` template returns `422` **before** any Meta call.
- `PAUSED` happens when recipients report a template. Meta un-pauses automatically after a period; repeated pauses lead to `DISABLED`. We surface pause/disable prominently, because a merchant whose order-confirmation template is paused is losing the flow without knowing it.
- Status arrives via the `message_template_status_update` webhook field **and** is reconciled by a poller (§5.3) — webhooks get missed, and a stale `PENDING` that is actually `APPROVED` blocks the merchant for no reason.

### 4.3 The window rules — the commercial heart of this spec

Two independent windows:

| Window | Opens | Duration | Effect |
|---|---|---|---|
| **Customer service window** | any inbound message from the customer | 24 h, **reset** by each new inbound | freeform (non-template) messages allowed and **free**; **utility templates are free**; marketing templates still billed |
| **Free entry point window** | customer messages via a Click-to-WhatsApp ad or Page CTA, *and the business replies* | 72 h | all messages free |

`window_expires_at` = `last_inbound_at + 24h` for `service`; `+72h` for `free_entry_point`. The two are tracked in one pair of columns with `window_type` recording which is active; when both would apply, the **later** expiry wins and `window_type = 'free_entry_point'`.

**The send decision** — `WindowPolicy::decide(Conversation $c, ?Template $t): SendDecision`:

```
isOpen = window_expires_at !== null && window_expires_at > now()

if (message is freeform):
    if (!isOpen)                    → REJECT  'window_closed'
    else                            → ALLOW   category=service,  billable=false

if (message is template):
    if (!optedIn(phone, scope(t)))  → REJECT  'not_opted_in'
    if (t.status !== APPROVED)      → REJECT  'template_not_approved'
    if (t.category === UTILITY && isOpen)
                                    → ALLOW   category=utility,  billable=false
    if (t.category === UTILITY)     → ALLOW   category=utility,  billable=true
    if (t.category === AUTHENTICATION) → ALLOW category=authentication, billable=true
    if (t.category === MARKETING)   → ALLOW   category=marketing, billable=true
    if (window_type === 'free_entry_point' && isOpen)
                                    → ALLOW   billable=false (overrides the above)
```

Two consequences worth stating explicitly because they are where the money is:

1. **Reply-first ordering.** If a customer has messaged us in the last 24 hours, a utility template is free. So the outbound scheduler should, where it is safe, prefer sending order updates to customers with open windows — and the COD confirmation flow deliberately *invites a reply*, which opens a window that makes every subsequent shipping update free. That is a real, defensible cost argument for a merchant, and no competitor can even express it.
2. **`billable` is a prediction, not a fact.** Meta's status webhook carries the authoritative pricing. We set `billable`/`pricing_category` optimistically at send and **overwrite them from the webhook** when it arrives (§4.7). The UI labels pre-webhook costs as "estimated".

**Clock skew.** All window arithmetic uses UTC from the database, never PHP-local time, and windows are evaluated at send time inside the sending job — not at enqueue time. A message queued at hour 23 and sent at hour 25 must be re-evaluated, or it fails at Meta and the merchant sees a mystery error.

### 4.4 Message status machine

```
queued ──► sending ──► sent ──► delivered ──► read
              │          │          │
              └─► failed ◄┴──────────┘   (Meta can fail after "sent")
```

- Statuses only move forward. A late `sent` webhook arriving after `read` is ignored — out-of-order webhook delivery is normal and a naive `update(status: …)` would corrupt the timeline. Enforced by an ordered rank map (`queued 0 … read 4`, `failed 9`) with `failed` always accepted.
- `deleted` is recorded as a separate boolean, not a status.

### 4.5 Idempotency & deduplication

- **Outbound:** every send carries an `idempotency_key`. For automated sends it is deterministic: `"{use_case}:{order_id}:{template_id}:{yyyy-mm-dd}"` — so a rules-engine misfire or a job retry cannot send the customer two order confirmations. `unique(organization_id, idempotency_key)` makes this a database guarantee, not a hope.
- **Inbound:** `unique(wamid)` on messages and `unique(event_hash)` on webhook events. Meta retries webhooks aggressively; both guards are required.
- Meta's send API is not idempotent on its side, so the sequence is: insert the message row with `status=queued` **first** (claiming the idempotency key), then call Meta, then update with the `wamid`. If the Meta call succeeds but our update fails, the record is `queued` with no `wamid` — a reconciler flags these as `unknown_outcome` after 5 minutes rather than blindly retrying, because a blind retry is how you double-message a customer.

### 4.6 Template rendering

`TemplateVariableResolver::resolve(Template $t, array $context): array` where `context` may contain `order`, `cod_transaction`, `shipment`, `customer`, `store`, `organization`.

- Every `source` must be on an allow-list, one entry per exposed field. No dynamic property traversal.
- **Meta's content rules** (enforced pre-send, because violations get the message rejected and hurt quality rating):
  - no newlines, tabs, or 4+ consecutive spaces inside a variable value
  - a variable may not be empty
  - a variable may not be the entire body
  - body ≤ 1024 chars, header ≤ 60, footer ≤ 60
  - `{{n}}` must be sequential from 1 with no gaps
- Money formatting uses the order's currency with Western digits (`349.00 SAR`) even in Arabic templates. Arabic-Indic digits inside template variables are a rejection risk and are hard to read back over the phone; **assumption — confirm with a design partner**, but the safe default is Western digits.
- Phone numbers and order references are always LTR-wrapped in Arabic bodies using U+200E where necessary, otherwise `الطلب 1043` can render with the number on the wrong side.

### 4.7 Cost attribution

At send: `price_source = 'rate_card'`, `price_amount` from `config('whatsapp.rates')[country][category]`, `billable` from `WindowPolicy`.
On status webhook: if `pricing` is present, overwrite `pricing_category`, `pricing_model`, `billable`, and set `price_source = 'meta'`. If Meta reports a price value, use it verbatim.

Rollups (`whatsapp_conversations.total_cost`, and a daily aggregate for the analytics screen) recompute from `whatsapp_messages`, never incrementally — incremental counters drift, and this number appears next to real money in the COD dashboard.

Reported metrics: messages by category, billable vs free split, cost per order, **cost avoided by the open-window rule** (count of free utility templates × their rate-card price). That last one is the number that sells the feature.

### 4.8 Opt-in enforcement

- Business-initiated (template) sends require `status = opted_in` for the applicable `scope`. `scope=transactional` covers order/shipping/COD; `scope=marketing` covers abandoned-order nudges and review requests.
- `unknown` is treated as **not consented** for `marketing` and — configurably, defaulting to **allowed** — for `transactional`, on the basis that a customer who placed an order and gave a phone number has a legitimate transactional expectation. **This is a legal judgement, not a technical one, and §15.3 flags it for counsel.** The config key is `whatsapp.transactional_requires_explicit_optin`, default `false`, with a per-org override.
- Inbound `STOP` / `إيقاف` / `الغاء` / `UNSUBSCRIBE` keywords auto-set `opted_out` (scope `marketing` by default, `all` if the message is unambiguous), write an opt-in event, set `conversations.is_opted_out`, and trigger an automatic confirmation reply (free — the inbound message opened a window).
- A Meta error indicating the user blocked the business also sets `opted_out` with `source = meta_block`.
- Opt-out is honoured **immediately and globally within the organization**, including cancelling already-queued messages to that number.

### 4.9 COD confirmation flow (the flagship, wired to Spec 06)

```
COD order created
      │  (Automation Rules Engine, or the built-in COD flow)
      ▼
cod_risk_band ∈ {medium, high, blocked}  OR  order_total > threshold  OR  always-on
      ▼
send template cod_confirmation (UTILITY) with quick-reply buttons:
   [ تأكيد الطلب / Confirm ]   [ إلغاء / Cancel ]
      ▼
inbound button reply → opens a 24-hour window (all follow-ups now free)
      │
      ├─ "confirm" → order tagged cod_confirmed; dispatch proceeds
      ├─ "cancel"  → order → cancelled; cod_transaction → cancelled;
      │              a cod_rto_event of type `refused` is NOT written
      │              (this is a pre-dispatch cancellation, not an RTO —
      │               conflating them would corrupt RTO analytics)
      └─ no reply within N hours (default 24) →
             one reminder template, then flag `cod_unconfirmed` for the merchant to decide
```

`whatsapp_messages.order_id` plus a `metadata.cod_confirmation_state` on the order gives us the cohort split needed for the measurement in §1: RTO rate of `cod_confirmed` orders vs `cod_unconfirmed` vs never-asked. **That comparison should be surfaced in the dashboard**, because it is the proof that the feature paid for itself.

### 4.10 Inbound routing

1. Verify `X-Hub-Signature-256` (`sha256=` + HMAC of the **raw** body with the app secret). Invalid → `401`, logged, event stored with `signature_valid = false`.
2. Store the raw event (`whatsapp_webhook_events`) with `event_hash`; on duplicate hash, return `200` immediately and stop. Meta must always get a fast `200` — slow responses cause retries and eventually webhook disablement.
3. Dispatch `ProcessWhatsAppWebhookJob` and return `200`. **No business logic in the request cycle.**
4. In the job: resolve the account by `entry[].id` (WABA id) then `value.metadata.phone_number_id`. Unresolvable → mark the event errored, notify ops. Never guess an organization.
5. For `messages[]`: find-or-create the conversation (locking on `whatsapp_account_id + contact_phone`), open/extend the window, insert the message, link an order if one can be inferred (most recent order for that phone in that org within 90 days), run keyword handlers (STOP, and the COD confirm/cancel button ids), increment `unread_count`, emit `WhatsAppMessageReceived`.
6. For `statuses[]`: locate by `wamid`, apply the forward-only status rule, absorb `pricing`, and on `failed` map the error code (§6.4).
7. For `message_template_status_update`: update the template row, notify the merchant on `REJECTED` / `PAUSED` / `DISABLED`.
8. For `account_update` / `phone_number_quality_update`: update quality rating, tier, and status; notify on `RED` or restriction.

---

## 5. Backend

### 5.1 Models — `backend/app/Models/`

`WhatsappAccount`, `WhatsappTemplate`, `WhatsappConversation`, `WhatsappMessage`, `WhatsappOptIn`, `WhatsappOptInEvent`, `WhatsappWebhookEvent`.

Notable casts:

```php
// WhatsappAccount
protected $casts = [
    'access_token'         => 'encrypted',
    'webhook_verify_token' => 'encrypted',
    'webhook_secret'       => 'encrypted',
    'token_expires_at'     => 'datetime',
    'connected_at'         => 'datetime',
    'metadata'             => 'array',
];
protected $hidden = ['access_token', 'webhook_verify_token', 'webhook_secret'];
```

`WhatsappConversation` exposes `isWindowOpen(): bool`, `windowRemainingSeconds(): int`, and a `booted()` hook keeping `open_key` in sync with `status`.
`WhatsappMessage` exposes `scopeBillable()`, `scopeForCategory()`, and `applyStatus(string $status, ?Carbon $at)` implementing the forward-only rule.
`Order` gains `whatsappMessages(): HasMany` and `whatsappConversation(): HasOne` (via phone).

### 5.2 Services — `backend/app/Services/WhatsApp/`

| Class | Responsibility |
|---|---|
| `CloudApiClient` | Thin HTTP wrapper over Graph API. Base URL and version from `config('whatsapp.graph_version')` — **never hardcoded**, because Meta deprecates versions on a schedule. Timeout 10 s, connect timeout 3 s, retry only on 429/5xx with exponential backoff and jitter, honours `Retry-After`. Redacts tokens from all exception messages. |
| `WhatsAppAccountService` | Embedded-Signup code exchange, token exchange to a long-lived token, phone number registration, webhook subscription, `verify()`, `refreshMetadata()`, `disconnect()`. |
| `TemplateService` | CRUD, submit to Meta, sync from Meta, edit-limit guard, content validation (§4.6 rules) **before** submission so we fail fast locally instead of burning a Meta review cycle. |
| `TemplateVariableResolver` | Allow-listed `source` → value, with formatters (`money`, `date`, `phone`, `url`, `truncate`). Throws `MissingTemplateVariableException` when a required variable resolves empty — we never send a template with a blank placeholder. |
| `WindowPolicy` | §4.3. Pure, no I/O, fully unit-testable. |
| `MessageDispatcher` | The one and only send path. Claims the idempotency key, evaluates `WindowPolicy`, checks opt-in and account status, renders, calls `CloudApiClient`, persists. Everything else in the codebase goes through this. |
| `ConversationService` | Find-or-create with locking, window open/extend, unread counts, assignment, close. |
| `OptInService` | Record consent + evidence + event; keyword detection (Arabic + English); enforcement helper `canSend(phone, scope)`. |
| `WebhookProcessor` | §4.10 steps 4–8, one handler per `field`. |
| `WhatsAppCostService` | §4.7 rollups and the "cost avoided" metric. |
| `CodConfirmationFlow` | §4.9 orchestration; the bridge to Spec 06. |

### 5.3 Jobs — `backend/app/Jobs/`

| Job | Trigger | Notes |
|---|---|---|
| `SendWhatsAppMessageJob(int $messageId)` | `MessageDispatcher` | Re-evaluates the window at execution time. `tries = 3`, `backoff = [10, 60, 300]`, `WithoutOverlapping($messageId)`. Fails permanently (no retry) on 4xx policy errors — retrying a "not opted in" is pointless and looks like abuse. |
| `ProcessWhatsAppWebhookJob(int $eventId)` | webhook controller | `tries = 5`, exponential backoff. |
| `SyncWhatsAppTemplatesJob(?int $accountId)` | hourly + manual | Reconciles statuses the webhook missed. |
| `RefreshWhatsAppAccountJob(?int $accountId)` | every 6 h | Quality rating, tier, phone status; notifies on degradation. |
| `SendCodConfirmationJob(int $orderId)` | COD order created | |
| `SendCodConfirmationReminderJob(int $orderId)` | delayed N hours | Skipped if already confirmed/cancelled. |
| `RollupWhatsAppCostsJob` | daily 05:00 | |
| `PruneWhatsAppWebhookEventsJob` | daily | 30-day retention. |
| `ReconcileStuckWhatsAppMessagesJob` | every 15 min | Messages `queued`/`sending` older than 5 min → `unknown_outcome` flag + ops notification (§4.5). |
| `DownloadWhatsAppMediaJob(int $messageId)` | inbound media | Meta media URLs expire; fetch immediately to a private disk. |

`routes/console.php`:

```php
Schedule::job(new SyncWhatsAppTemplatesJob)->hourly();
Schedule::job(new RefreshWhatsAppAccountJob)->everySixHours();
Schedule::job(new RollupWhatsAppCostsJob)->dailyAt('05:00');
Schedule::job(new ReconcileStuckWhatsAppMessagesJob)->everyFifteenMinutes();
Schedule::job(new PruneWhatsAppWebhookEventsJob)->dailyAt('05:30');
```

### 5.4 Events — `backend/app/Events/WhatsApp/`

`WhatsAppAccountConnected`, `WhatsAppAccountDegraded` (quality RED / restricted / banned), `WhatsAppMessageSent`, `WhatsAppMessageDelivered`, `WhatsAppMessageRead`, `WhatsAppMessageFailed`, `WhatsAppMessageReceived`, `WhatsAppTemplateStatusChanged`, `WhatsAppOptOutRecorded`, `WhatsAppWindowOpened`, `CodOrderConfirmedViaWhatsApp`, `CodOrderCancelledViaWhatsApp`.

Listeners:
- `CreateWhatsAppNotification` — writes to the existing `notifications` table for merchant-facing events (template rejected, quality RED, account restricted, inbound message when nobody is assigned). Respects `users.notification_preferences.whatsapp`.
- `NotifyAutomationRules` — makes every event available as a rules-engine trigger.
- `ApplyCodConfirmationOutcome` — updates the order and `cod_transactions` per §4.9.

**Rules engine contract.** The `send_whatsapp` action config:

```json
{
  "action": "send_whatsapp",
  "template_use_case": "cod_confirmation",
  "language": "auto",
  "to": "order.customer_phone",
  "variables": { "override_key": "literal or allow-listed source" },
  "respect_window": true,
  "skip_if_sent_within_hours": 24
}
```
`language: "auto"` resolves customer locale → org `default_locale` → `fallback_locale`, and requires an `APPROVED` template in the chosen language; if none exists it falls back rather than failing, and records which language was actually used.

### 5.5 API endpoints

All under `auth:sanctum` + `org.member`, prefix `whatsapp`, controllers in `App\Http\Controllers\WhatsApp\`.

#### Account

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/whatsapp/account` | Current account or `{"connected": false}`. Never returns tokens. |
| `GET` | `/api/whatsapp/connect/embedded-signup` | Returns `{app_id, config_id, state, graph_version}` for the Meta JS SDK popup. `state` is a signed, single-use, 10-minute nonce. |
| `POST` | `/api/whatsapp/connect` | Body `{code, waba_id, phone_number_id, state}` → exchange, register, subscribe. `201` |
| `POST` | `/api/whatsapp/connect/manual` | `{waba_id, phone_number_id, access_token}` for merchants with an existing setup. Token validated before persisting. |
| `POST` | `/api/whatsapp/account/verify` | Re-runs the three-way check; returns `{token_valid, number_registered, webhook_subscribed}`. |
| `POST` | `/api/whatsapp/account/refresh` | Pull quality/tier/name. |
| `DELETE` | `/api/whatsapp/account` | Soft-disconnect; history retained. `409` if messages are queued. |

`POST /api/whatsapp/connect` validation:
```php
'code'            => ['required','string','max:1024'],
'waba_id'         => ['required','string','max:64'],
'phone_number_id' => ['required','string','max:64'],
'state'           => ['required','string'],
```
Errors: `422` invalid/expired `state`; `409` `{"message":"This WhatsApp number is already connected to another Hubby organization."}` (from the `unique(phone_number_id)` index — a real scenario when an agency manages several merchants); `502` `{"message":"Meta rejected the connection.","meta_error":{"code":"190","title":"..."}}`.

#### Templates

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/whatsapp/templates` | Filters `status`, `category`, `language`, `use_case`, `search` |
| `POST` | `/api/whatsapp/templates` | Create a draft |
| `GET` | `/api/whatsapp/templates/{id}` | |
| `PUT` | `/api/whatsapp/templates/{id}` | Draft edit, or approved edit (consumes an edit allowance) |
| `DELETE` | `/api/whatsapp/templates/{id}` | Deletes locally and at Meta; `403` when `is_system` |
| `POST` | `/api/whatsapp/templates/{id}/submit` | → `PENDING` |
| `POST` | `/api/whatsapp/templates/{id}/preview` | Render with a sample order; no send |
| `POST` | `/api/whatsapp/templates/sync` | Pull all from Meta |
| `POST` | `/api/whatsapp/templates/seed` | Install the §6.3 catalogue as drafts in `ar` + `en` |

`POST /api/whatsapp/templates` validation:
```php
'name'        => ['required','string','max:120','regex:/^[a-z0-9_]+$/'],
'language'    => ['required','string','max:12'],
'category'    => ['required', Rule::in(['MARKETING','UTILITY','AUTHENTICATION'])],
'use_case'    => ['nullable','string','max:48'],
'header_type' => ['nullable', Rule::in(['NONE','TEXT','IMAGE','VIDEO','DOCUMENT','LOCATION'])],
'header_text' => ['nullable','string','max:60','required_if:header_type,TEXT'],
'body'        => ['required','string','max:1024'],
'footer'      => ['nullable','string','max:60'],
'buttons'     => ['nullable','array','max:10'],
'buttons.*.type' => ['required', Rule::in(['QUICK_REPLY','URL','PHONE_NUMBER','COPY_CODE'])],
'buttons.*.text' => ['required','string','max:25'],
'variables'   => ['nullable','array'],
'variables.*.index'  => ['required','integer','min:1'],
'variables.*.source' => ['required','string', Rule::in(TemplateVariableResolver::allowedSources())],
```
Plus a custom rule asserting `{{n}}` in the body is sequential from 1 and matches `variables`. `422` returns the offending placeholder, not a generic message.

#### Conversations & inbox

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/whatsapp/conversations` | `status`, `assigned_to`, `unread`, `window_open`, `search`, sorted by `last_message_at` |
| `GET` | `/api/whatsapp/conversations/{id}` | Includes `window_open`, `window_expires_at`, `window_remaining_seconds` |
| `GET` | `/api/whatsapp/conversations/{id}/messages` | Cursor-paginated (`before_id`) |
| `POST` | `/api/whatsapp/conversations/{id}/messages` | Freeform reply — **`422 window_closed` when the window is shut**, with `suggested_templates` in the error body so the agent has a next action |
| `POST` | `/api/whatsapp/conversations/{id}/read` | Zero the unread count |
| `POST` | `/api/whatsapp/conversations/{id}/assign` | `{user_id}` |
| `POST` | `/api/whatsapp/conversations/{id}/close` | Clears `open_key` |
| `POST` | `/api/whatsapp/conversations/{id}/link-order` | `{order_id}` |

`POST .../messages` validation: `'type' => required|in:text,image,document`, `'body' => required_if:type,text|string|max:4096`, `'media' => required_if:type,image,document|file|max:16384`.
Error body on a closed window:
```json
{ "message": "This conversation's 24-hour window has closed.",
  "errors": { "window": ["window_closed"] },
  "window_expired_at": "2026-07-21T18:40:00Z",
  "suggested_templates": [ { "id": 12, "use_case": "order_update", "language": "ar", "category": "UTILITY" } ] }
```

#### Sending

| Method | Path | Notes |
|---|---|---|
| `POST` | `/api/whatsapp/messages/send` | Template send |
| `GET` | `/api/whatsapp/messages/{id}` | Status + error detail |
| `POST` | `/api/whatsapp/messages/{id}/retry` | Only from `failed` with a retryable code |
| `POST` | `/api/whatsapp/orders/{orderId}/cod-confirmation` | Trigger §4.9 manually |

`POST /api/whatsapp/messages/send`:
```php
'to'              => ['required','string','max:32'],       // E.164, normalised server-side
'template_id'     => ['required_without:use_case','integer','exists:whatsapp_templates,id'],
'use_case'        => ['required_without:template_id','string','max:48'],
'language'        => ['nullable','string','max:12'],       // default: org default_locale
'order_id'        => ['nullable','integer'],
'variables'       => ['nullable','array'],
'idempotency_key' => ['nullable','string','max:80'],
```
`202` `{"message_id":9912,"status":"queued","estimated_cost":"0.0340","pricing_category":"utility","billable":true,"free_reason":null}`.
`422` for `not_opted_in`, `template_not_approved`, `invalid_phone`, `missing_variable` (naming the variable), `account_not_connected`, `account_banned`.
Rate limited per organization: `throttle:120,1` on this route, plus a per-account send-rate governor derived from `messaging_limit_tier`.

#### Opt-ins, analytics

| Method | Path | Notes |
|---|---|---|
| `GET` | `/api/whatsapp/opt-ins` | `status`, `scope`, `search` |
| `POST` | `/api/whatsapp/opt-ins` | Record consent with evidence |
| `POST` | `/api/whatsapp/opt-ins/{phone}/opt-out` | Manual opt-out |
| `GET` | `/api/whatsapp/opt-ins/{phone}/events` | The audit trail |
| `POST` | `/api/whatsapp/opt-ins/import` | CSV bulk import — **requires an attested consent source**, see §10 |
| `GET` | `/api/whatsapp/analytics` | `from`, `to`, `group_by=day\|category\|template\|use_case` |
| `GET` | `/api/whatsapp/analytics/cod-impact` | The confirmed-vs-unconfirmed RTO comparison |

`GET /api/whatsapp/analytics` response:
```json
{
  "range": {"from":"2026-06-22","to":"2026-07-22"},
  "sent": 4820, "delivered": 4711, "read": 3902, "failed": 109,
  "delivery_rate": 0.977, "read_rate": 0.828,
  "inbound": 1240, "windows_opened": 981,
  "cost": { "currency":"USD", "billable_messages": 2140, "free_messages": 2680,
            "total": "72.760000", "estimated_portion": "4.120000",
            "avoided_by_open_window": "31.400000" },
  "by_category": [ {"category":"utility","count":3900,"billable":1240,"cost":"38.44"} ],
  "by_use_case": [ {"use_case":"cod_confirmation","sent":812,"read_rate":0.91} ]
}
```

`GET /api/whatsapp/analytics/cod-impact`:
```json
{ "confirmed":   {"orders":812,"rto":41,"rto_rate":0.050},
  "unconfirmed": {"orders":133,"rto":37,"rto_rate":0.278},
  "not_asked":   {"orders":611,"rto":112,"rto_rate":0.183},
  "low_confidence": false }
```

### 5.6 Inbound webhook

Public routes (no auth), defined **outside** the `auth:sanctum` group in `routes/api.php`, alongside the existing `/webhooks/{platform}`:

```php
Route::get('/webhooks/whatsapp',  [WhatsAppWebhookController::class, 'verify']);
Route::post('/webhooks/whatsapp', [WhatsAppWebhookController::class, 'handle'])
     ->middleware(\App\Http\Middleware\VerifyWhatsAppSignature::class);
```

**`GET` (verification handshake).** Meta calls with `hub.mode=subscribe`, `hub.challenge`, `hub.verify_token`. We compare `hub.verify_token` against the configured value with `hash_equals` and return the raw `hub.challenge` as `text/plain`, status 200; mismatch → 403.

> Because the verify token is checked *before* we know which account is calling, v1 uses a **single app-level verify token** from `config('whatsapp.verify_token')` (all merchants' WABAs subscribe to the same Hubby app). `whatsapp_accounts.webhook_verify_token` exists for a future per-tenant-app model. **This is a real constraint of the shared-app design and is called out in §12.3.**

**`VerifyWhatsAppSignature` middleware** — new, alongside the existing `VerifyWebhookSignature`, following its structure:

```php
$secret   = config('whatsapp.app_secret');
$expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
$provided = (string) $request->header('X-Hub-Signature-256', '');
if ($secret && (! $provided || ! hash_equals($expected, $provided))) {
    Log::warning('Rejected WhatsApp webhook: invalid signature.');
    return response()->json(['message' => 'Invalid webhook signature'], 401);
}
```
Unlike the existing middleware, a **missing secret in production is a hard failure**, not a warning-and-pass. The existing lenient behaviour is acceptable for store webhooks in dev; it is not acceptable for a channel that can forge customer messages. The bypass is allowed only when `app()->environment('local','testing')`.

The controller stores the raw event, dispatches the job, and returns `200` in under 100 ms. Nothing else.

### 5.7 Configuration — `backend/config/whatsapp.php`

```php
return [
    'graph_version'  => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),   // pin; review each Meta release
    'app_id'         => env('WHATSAPP_APP_ID'),
    'app_secret'     => env('WHATSAPP_APP_SECRET'),
    'config_id'      => env('WHATSAPP_ES_CONFIG_ID'),             // Embedded Signup configuration
    'es_version'     => env('WHATSAPP_ES_VERSION', 'v4'),         // see §3.0 — v2 deprecation
    'verify_token'   => env('WHATSAPP_VERIFY_TOKEN'),
    'api_base'       => env('WHATSAPP_API_BASE', 'https://graph.facebook.com'),
    'timeout'        => 10,
    'transactional_requires_explicit_optin' => env('WHATSAPP_TX_REQUIRES_OPTIN', false),
    'cod_confirmation' => [
        'reminder_after_hours' => 24,
        'expire_after_hours'   => 48,
    ],
    'rates' => [  // USD per delivered message; UNVERIFIED placeholders — load from a maintained rate card
        'SA' => ['marketing' => 0.0384, 'utility' => 0.0140, 'authentication' => 0.0129],
        'AE' => ['marketing' => 0.0384, 'utility' => 0.0140, 'authentication' => 0.0129],
        'EG' => ['marketing' => 0.1073, 'utility' => 0.0044, 'authentication' => 0.0044],
        'default' => ['marketing' => 0.0500, 'utility' => 0.0200, 'authentication' => 0.0200],
    ],
    'stop_keywords' => [
        'en' => ['stop','unsubscribe','cancel subscription'],
        'ar' => ['إيقاف','ايقاف','الغاء','إلغاء','توقف','لا ترسل'],
    ],
];
```

> **The `rates` numbers above are placeholders and must not be trusted.** Meta publishes per-country rate cards that change; the correct implementation loads a versioned rate card (a JSON file updated by ops, or Meta's own reported pricing on the status webhook) and treats config as a fallback. The UI must label any rate-card-derived figure as "estimated".

`config/services.php` is left alone — WhatsApp gets its own config file because it has more than four keys and mixing it into the store-integration block would be misleading.

---

## 6. Provider notes & template catalogue

### 6.1 Provider choice

**Cloud API direct (Meta-hosted)** is the v1 choice.

| Option | Pros | Cons |
|---|---|---|
| **Cloud API direct** (chosen) | No per-message BSP markup; Meta hosts everything; Embedded Signup is first-class; the merchant owns the WABA | We must handle Meta app review, business verification, and (for the best onboarding) the Tech Provider programme |
| BSP resale (360dialog, Twilio, Wati, Unifonic) | Faster to market, they own compliance | Markup on every message; the merchant's WABA sits with the BSP; we inherit their downtime; margin compression forever |
| On-Premises API | — | Deprecated. Do not. |

The abstraction boundary is `CloudApiClient` + `MessageDispatcher`. If a BSP path is ever needed for a specific market, it is a second `CloudApiClient` implementation behind the same interface — but nothing else in the codebase should know.

### 6.2 What is genuinely uncertain

- **Tech Provider / Solution Partner status.** Without it, merchants must create their own Meta app and paste credentials — a conversion killer for a merchant in Riyadh who has never seen Meta Business Manager. With it, Embedded Signup does everything in one popup. Getting it requires business verification and app review with Meta and has a real calendar cost. **This gates the good onboarding experience and must start in parallel with engineering, not after it.**
- **Embedded Signup version.** Secondary sources say v2 is deprecated 15 Oct 2026 and v4 is current. Unverified. Pin the version in config and re-check against Meta's changelog at implementation time.
- **Per-country rates.** Change periodically. Never hardcode.
- **Template limits** (~250/WABA, ~10 edits/30 days). Unverified. Handle Meta's limit error gracefully; do not pre-block on a guessed number.
- **Arabic template review strictness.** Anecdotally, Arabic templates are rejected more often for tone/marketing-in-a-utility-template than English ones. **Unverified.** Mitigation: the seeded catalogue is written conservatively, every template ships with `allow_category_change: true`, and rejection reasons are surfaced verbatim so merchants learn.

### 6.3 Template catalogue (seeded, `ar` + `en`)

Seeded as **drafts** — the merchant reviews, edits the brand voice, and submits. We never auto-submit content in a merchant's name to a platform that can penalise their account for it.

| # | `use_case` | Category | Trigger | Notes |
|---|---|---|---|---|
| 1 | `order_confirmation` | UTILITY | order created | free if a window is open |
| 2 | `cod_confirmation` | UTILITY | COD order created (optionally risk-gated) | quick-reply buttons; **the flagship** |
| 3 | `cod_confirmation_reminder` | UTILITY | 24 h with no reply | |
| 4 | `order_shipped` | UTILITY | shipment created (Shipping spec) | URL button to tracking |
| 5 | `out_for_delivery` | UTILITY | carrier event | the highest-leverage RTO reducer after #2 |
| 6 | `order_delivered` | UTILITY | delivered | |
| 7 | `abandoned_order` | MARKETING | abandoned checkout | requires `scope=marketing` opt-in |
| 8 | `review_request` | MARKETING | N days after delivery | requires `scope=marketing` opt-in |
| 9 | `order_cancelled` | UTILITY | cancellation | |

**Template 2 — `cod_confirmation`, Arabic (`ar`)**

```
name:     cod_confirmation
language: ar
category: UTILITY
header:   TEXT — "تأكيد طلبك"
body:
مرحباً {{1}} 👋
طلبك رقم {{2}} من {{3}} جاهز للشحن.
المبلغ المطلوب عند الاستلام: {{4}} {{5}}
يرجى تأكيد الطلب حتى نتمكن من شحنه.
footer:   يمكنك الرد بـ"إيقاف" لإيقاف الرسائل
buttons:  [QUICK_REPLY "تأكيد الطلب"] [QUICK_REPLY "إلغاء الطلب"]
variables:
  1 customer_name  ← order.customer_name
  2 order_number   ← order.external_id
  3 store_name     ← store.name
  4 cod_amount     ← cod_transaction.expected_amount   (format: money)
  5 currency       ← order.currency
```

**Template 2 — English (`en`)**

```
header:  TEXT — "Confirm your order"
body:
Hi {{1}} 👋
Your order {{2}} from {{3}} is ready to ship.
Amount due on delivery: {{4}} {{5}}
Please confirm so we can dispatch it.
footer:  Reply STOP to opt out
buttons: [QUICK_REPLY "Confirm order"] [QUICK_REPLY "Cancel order"]
```

**Template 4 — `order_shipped`, Arabic**

```
body:
تم شحن طلبك رقم {{1}} ✅
شركة الشحن: {{2}}
رقم الشحنة: {{3}}
buttons: [URL "تتبع الشحنة" → {{tracking_url}}]
```

**Template 5 — `out_for_delivery`, Arabic**

```
body:
مندوب التوصيل في الطريق إليك اليوم بطلبك رقم {{1}}.
يرجى تجهيز مبلغ {{2}} {{3}} نقداً.
```
(Reminding the customer to have cash ready is, per merchant anecdote, one of the cheapest RTO reductions available. **Unverified as a quantified claim.**)

Catalogue rules baked into the seeder:
- Every template carries an opt-out line in the **footer** (footers are not billed differently and keep the body clean).
- `MARKETING` templates always include an opt-out and are only sendable with `scope=marketing` consent.
- No template contains a price *promise* or a discount code in a `UTILITY` category — that is the classic re-categorisation/rejection trap.
- Every template exists in `ar` and `en` with identical `name` and `use_case`, so `language: "auto"` always resolves.

### 6.4 Meta error code mapping

Errors are mapped to `(retryable, merchant_message_en, merchant_message_ar, action)`. Codes below are the commonly-encountered set; **treat the list as a starting map, not an exhaustive contract — Meta adds codes.** Unmapped codes fall back to "temporary problem, we'll retry" only for 5xx, and to a generic non-retryable message otherwise.

| Code | Meaning | Retryable | Merchant-facing action |
|---|---|---|---|
| 131026 | Message undeliverable (not on WhatsApp / cannot receive) | no | Mark the phone unreachable; suggest SMS |
| 131047 | Re-engagement required (window closed) | no | Use a template |
| 131051 | Unsupported message type | no | — |
| 131053 | Media upload failed | yes | Retry once, then surface |
| 132000/132001/132005/132007/132012/132015 | Template mismatch / not found / paused / param problem | no | Point at the specific template with the exact issue |
| 130429 | Rate limit hit | yes | Backoff; governor slows the queue |
| 131048 | Spam rate limit | no | Stop the campaign; warn loudly about quality |
| 190 | Token invalid/expired | no | Account → `token_expired`, prompt reconnect |
| 100 | Invalid parameter | no | Developer-facing; log with full context (tokens redacted) |
| 368 | Temporarily blocked for policy violations | no | Account → `restricted`, escalate |
| 80007 | Rate limit (business account) | yes | Backoff |
| 4 / 613 | API throttling | yes | Backoff with `Retry-After` |

---

## 7. Dashboard

### 7.1 Routes

```
src/app/(dashboard)/whatsapp/page.tsx                 → overview: connection, health, activity, cost
src/app/(dashboard)/whatsapp/inbox/page.tsx           → shared inbox (two-pane)
src/app/(dashboard)/whatsapp/inbox/[id]/page.tsx      → deep-linkable thread
src/app/(dashboard)/whatsapp/templates/page.tsx       → template list
src/app/(dashboard)/whatsapp/templates/new/page.tsx   → editor
src/app/(dashboard)/whatsapp/templates/[id]/page.tsx  → editor + status + rejection reason
src/app/(dashboard)/whatsapp/opt-ins/page.tsx         → consent register
src/app/(dashboard)/whatsapp/analytics/page.tsx       → delivery, read, cost, COD impact
src/app/(dashboard)/whatsapp/settings/page.tsx        → connection, defaults, keywords
```

Sidebar entry with an unread badge (`unread_count` sum), gated on the `whatsapp` feature flag.

### 7.2 Components — `src/components/whatsapp/`

| Component | Notes |
|---|---|
| `ConnectionCard.tsx` | Number, verified name, quality-rating pill, tier, and a truthful state: connected / needs reconnect / restricted / banned — each with one clear next action. |
| `EmbeddedSignupButton.tsx` | Loads the Meta JS SDK, opens the popup, posts the code to `/connect`. Handles user-cancelled, popup-blocked, and partial-completion. |
| `ConversationList.tsx` | Left pane: avatar, name, preview, unread dot, **window countdown pill**. |
| `WindowCountdown.tsx` | Live "23h 12m left" pill; amber under 2 h; grey and labelled "closed" at zero. This one component prevents most support tickets this feature would otherwise generate. |
| `MessageThread.tsx` | Bubbles with per-message status ticks; failed messages show the mapped plain-language reason plus retry when retryable. |
| `MessageComposer.tsx` | **Disabled when the window is closed**, replaced inline by a template picker with a one-line explanation — not a silent failure and not an error toast after the fact. |
| `TemplatePicker.tsx` | Approved templates only, grouped by use case, with a live-rendered preview against the linked order. |
| `TemplateEditor.tsx` | Live WhatsApp-style preview, variable mapper (dropdowns of allow-listed sources), character counters, and inline validation of Meta's content rules before submit. |
| `TemplateStatusBadge.tsx` | Draft / Pending / Approved / Rejected / Paused / Disabled, with the rejection reason on hover. |
| `OptInTable.tsx` | Phone, status, scope, source, consent date, evidence link. |
| `WhatsAppCostPanel.tsx` | Billable vs free split, cost per order, **"saved by replying inside the window"**. |
| `CodImpactCard.tsx` | Confirmed vs unconfirmed vs not-asked RTO rates. The proof screen. |

### 7.3 States

`not connected` (explains what it does + one button) · `connecting` · `connection failed` (Meta's error verbatim + what to do) · `token expired` (banner across all WhatsApp screens) · `quality RED` (persistent warning) · `restricted` / `banned` · `no templates` (offer the seeded catalogue) · `template pending` (with expected review time) · `template rejected` (reason + edit CTA) · `inbox empty` · `window closed` (composer replaced by templates) · `send failed` (mapped reason + retry) · `not opted in` (blocked send, with the opt-in capture path) · `loading` / `network error`.

Real-time: v1 polls `/conversations` every 15 s while the inbox is focused, and the open thread every 5 s. WebSockets are the right answer and are noted as a follow-up (§15.6) — polling is honest about being a v1 compromise rather than pretending to be live.

### 7.4 i18n — `src/i18n/dicts/whatsapp.ts`

Registered in `dictionary.ts` as `whatsapp: whatsapp.en` / `whatsapp: whatsapp.ar`.

```ts
export const whatsapp = {
  en: {
    title: 'WhatsApp',
    subtitle: 'Talk to your customers where they already are.',
    nav: { overview: 'Overview', inbox: 'Inbox', templates: 'Templates', optIns: 'Consent', analytics: 'Analytics', settings: 'Settings' },
    connection: {
      notConnected: 'WhatsApp is not connected',
      notConnectedHint: 'Connect your WhatsApp Business number to send order updates, confirm COD orders and reply to customers — all from Hubby.',
      connect: 'Connect WhatsApp', connectManual: 'I already have a WhatsApp Business API account',
      connecting: 'Connecting…', connected: 'Connected', reconnect: 'Reconnect', disconnect: 'Disconnect',
      number: 'Business number', verifiedName: 'Display name', quality: 'Quality rating', tier: 'Messaging limit',
      tokenExpired: 'Your WhatsApp connection expired. Reconnect to keep sending messages.',
      restricted: 'Meta has limited this number. Sending still works but at a reduced rate.',
      banned: 'Meta has blocked this number. Contact Meta support to resolve it.',
      alreadyConnected: 'This number is already connected to another organization.',
      qualityRed: 'Your quality rating dropped. Too many customers blocked or reported your messages.',
    },
    window: {
      open: 'Reply window open', closed: 'Reply window closed',
      remaining: '{time} left to reply freely',
      explain: 'After a customer messages you, you can reply freely for 24 hours. After that you can only send an approved template.',
      closedComposer: 'This window has closed. Send an approved template to reopen the conversation.',
      free: 'Free while the window is open',
    },
    inbox: {
      title: 'Inbox', search: 'Search conversations…', unassigned: 'Unassigned', assignedToMe: 'Assigned to me',
      all: 'All', unread: 'Unread', openWindow: 'Window open', assign: 'Assign', close: 'Close conversation',
      linkOrder: 'Link an order', linkedOrder: 'Order {number}', empty: 'No conversations yet.',
      emptyHint: 'Messages from your customers will appear here.', typeMessage: 'Type a message…',
      sendTemplate: 'Send a template', markRead: 'Mark as read',
    },
    message: {
      status: { queued: 'Queued', sending: 'Sending', sent: 'Sent', delivered: 'Delivered', read: 'Read', failed: 'Failed' },
      retry: 'Try again', failedReason: 'Why it failed', estimatedCost: 'Estimated cost', free: 'Free',
      billable: 'Billable', category: 'Category',
    },
    template: {
      title: 'Message templates',
      subtitle: 'WhatsApp requires Meta to approve any message you send first. Templates are those pre-approved messages.',
      create: 'New template', seed: 'Install starter templates', sync: 'Sync from Meta', submit: 'Submit for approval',
      preview: 'Preview', name: 'Template name', nameHint: 'Lowercase letters, numbers and underscores only.',
      language: 'Language', category: 'Category',
      categories: { MARKETING: 'Marketing', UTILITY: 'Utility', AUTHENTICATION: 'Authentication' },
      categoryHint: 'Utility messages are free while a reply window is open. Marketing messages are always billed.',
      header: 'Header', body: 'Message', footer: 'Footer', buttons: 'Buttons', variables: 'Variables',
      variableHint: 'Pick what fills each blank. Blanks cannot be empty when the message is sent.',
      status: { DRAFT: 'Draft', PENDING: 'Under review', APPROVED: 'Approved', REJECTED: 'Rejected', PAUSED: 'Paused', DISABLED: 'Disabled' },
      pendingHint: 'Meta usually reviews templates within a few minutes to 24 hours.',
      rejectedReason: 'Meta’s reason', pausedHint: 'Too many customers reported this template. It will resume automatically.',
      editLimit: 'You have used {used} of {limit} edits this month.',
      useCases: {
        order_confirmation: 'Order confirmation', cod_confirmation: 'COD confirmation',
        cod_confirmation_reminder: 'COD reminder', order_shipped: 'Order shipped',
        out_for_delivery: 'Out for delivery', order_delivered: 'Delivered',
        abandoned_order: 'Abandoned order', review_request: 'Review request', order_cancelled: 'Order cancelled',
      },
    },
    optIn: {
      title: 'Consent', subtitle: 'Who has agreed to receive WhatsApp messages from you.',
      status: { opted_in: 'Opted in', opted_out: 'Opted out', unknown: 'Not recorded' },
      scope: { all: 'All messages', transactional: 'Order updates', marketing: 'Marketing' },
      source: { checkout: 'Checkout', store_sync: 'Store', whatsapp_reply: 'WhatsApp reply', manual: 'Added manually', import: 'Imported', stop_keyword: 'Replied STOP', meta_block: 'Blocked you' },
      record: 'Record consent', optOut: 'Opt out', evidence: 'Evidence', history: 'History',
      importWarning: 'Only import numbers that gave you explicit permission to message them on WhatsApp. Meta can ban your number for messaging people who did not opt in.',
      blocked: 'This customer opted out. You cannot send them messages.',
    },
    analytics: {
      title: 'WhatsApp performance', sent: 'Sent', delivered: 'Delivered', read: 'Read', failed: 'Failed',
      deliveryRate: 'Delivery rate', readRate: 'Read rate', inbound: 'Replies received',
      cost: 'Cost', billableMessages: 'Billed messages', freeMessages: 'Free messages',
      avoided: 'Saved by replying in the window', estimated: 'Estimated',
      estimatedHint: 'Meta confirms the exact price shortly after delivery.',
      codImpact: 'Effect on returns', confirmed: 'Confirmed by customer', unconfirmed: 'No reply',
      notAsked: 'Not asked', rtoRate: 'Return rate',
      codImpactHint: 'Return rate for COD orders the customer confirmed on WhatsApp, compared with those they did not.',
      lowConfidence: 'Not enough orders yet to be reliable.',
    },
    settings: {
      title: 'WhatsApp settings', defaultLanguage: 'Default language', fallbackLanguage: 'Fallback language',
      stopKeywords: 'Opt-out keywords', codConfirmation: 'COD confirmation',
      codConfirmationEnabled: 'Ask customers to confirm COD orders before shipping',
      codRiskOnly: 'Only for medium and high risk customers', reminderAfter: 'Send a reminder after (hours)',
      expireAfter: 'Give up after (hours)',
    },
    toast: {
      connected: 'WhatsApp connected.', disconnected: 'WhatsApp disconnected.',
      templateSubmitted: 'Template sent to Meta for review.', templateApproved: 'Template approved.',
      messageSent: 'Message sent.', messageFailed: 'Message could not be sent.',
      optedOut: 'Customer opted out.', windowClosed: 'The reply window closed. Send a template instead.',
    },
  },
  ar: {
    title: 'واتساب',
    subtitle: 'تواصل مع عملائك حيث هم بالفعل.',
    nav: { overview: 'نظرة عامة', inbox: 'الرسائل', templates: 'القوالب', optIns: 'الموافقات', analytics: 'التحليلات', settings: 'الإعدادات' },
    connection: {
      notConnected: 'واتساب غير مرتبط',
      notConnectedHint: 'اربط رقم واتساب للأعمال لإرسال تحديثات الطلبات، وتأكيد طلبات الدفع عند الاستلام، والرد على العملاء — من داخل هَبي.',
      connect: 'ربط واتساب', connectManual: 'لدي حساب واتساب للأعمال (API) بالفعل',
      connecting: 'جارٍ الربط…', connected: 'مرتبط', reconnect: 'إعادة الربط', disconnect: 'فصل الربط',
      number: 'رقم العمل', verifiedName: 'الاسم الظاهر', quality: 'تقييم الجودة', tier: 'حد الإرسال',
      tokenExpired: 'انتهت صلاحية ربط واتساب. أعد الربط لمواصلة إرسال الرسائل.',
      restricted: 'قيّدت ميتا هذا الرقم. الإرسال ما زال يعمل ولكن بحد أقل.',
      banned: 'حظرت ميتا هذا الرقم. تواصل مع دعم ميتا لحل المشكلة.',
      alreadyConnected: 'هذا الرقم مرتبط بمؤسسة أخرى.',
      qualityRed: 'انخفض تقييم الجودة. عدد كبير من العملاء حظر رسائلك أو أبلغ عنها.',
    },
    window: {
      open: 'نافذة الرد مفتوحة', closed: 'نافذة الرد مغلقة',
      remaining: 'يتبقى {time} للرد بحرية',
      explain: 'بعد أن يراسلك العميل، يمكنك الرد بحرية لمدة ٢٤ ساعة. بعدها لا يمكن إرسال سوى قالب معتمد.',
      closedComposer: 'أُغلقت النافذة. أرسل قالباً معتمداً لإعادة فتح المحادثة.',
      free: 'مجاني طالما النافذة مفتوحة',
    },
    inbox: {
      title: 'الرسائل', search: 'ابحث في المحادثات…', unassigned: 'غير مُسندة', assignedToMe: 'مُسندة إليّ',
      all: 'الكل', unread: 'غير مقروءة', openWindow: 'نافذة مفتوحة', assign: 'إسناد', close: 'إغلاق المحادثة',
      linkOrder: 'ربط بطلب', linkedOrder: 'الطلب {number}', empty: 'لا توجد محادثات بعد.',
      emptyHint: 'ستظهر رسائل عملائك هنا.', typeMessage: 'اكتب رسالة…',
      sendTemplate: 'إرسال قالب', markRead: 'تعليم كمقروء',
    },
    message: {
      status: { queued: 'في الانتظار', sending: 'جارٍ الإرسال', sent: 'أُرسلت', delivered: 'وصلت', read: 'قُرئت', failed: 'فشلت' },
      retry: 'إعادة المحاولة', failedReason: 'سبب الفشل', estimatedCost: 'التكلفة التقديرية', free: 'مجاني',
      billable: 'مدفوع', category: 'التصنيف',
    },
    template: {
      title: 'قوالب الرسائل',
      subtitle: 'يشترط واتساب اعتماد ميتا لأي رسالة ترسلها أولاً. القوالب هي تلك الرسائل المعتمدة مسبقاً.',
      create: 'قالب جديد', seed: 'تثبيت القوالب الجاهزة', sync: 'مزامنة من ميتا', submit: 'إرسال للاعتماد',
      preview: 'معاينة', name: 'اسم القالب', nameHint: 'حروف إنجليزية صغيرة وأرقام وشرطة سفلية فقط.',
      language: 'اللغة', category: 'التصنيف',
      categories: { MARKETING: 'تسويقي', UTILITY: 'خدمي', AUTHENTICATION: 'رمز تحقق' },
      categoryHint: 'الرسائل الخدمية مجانية طالما نافذة الرد مفتوحة. الرسائل التسويقية مدفوعة دائماً.',
      header: 'الترويسة', body: 'نص الرسالة', footer: 'التذييل', buttons: 'الأزرار', variables: 'المتغيرات',
      variableHint: 'اختر ما يملأ كل فراغ. لا يمكن أن يكون الفراغ خالياً عند الإرسال.',
      status: { DRAFT: 'مسودة', PENDING: 'قيد المراجعة', APPROVED: 'معتمد', REJECTED: 'مرفوض', PAUSED: 'موقوف مؤقتاً', DISABLED: 'معطّل' },
      pendingHint: 'تراجع ميتا القوالب عادةً خلال دقائق إلى ٢٤ ساعة.',
      rejectedReason: 'سبب الرفض من ميتا', pausedHint: 'أبلغ عدد كبير من العملاء عن هذا القالب. سيُستأنف تلقائياً.',
      editLimit: 'استخدمت {used} من {limit} تعديلات هذا الشهر.',
      useCases: {
        order_confirmation: 'تأكيد الطلب', cod_confirmation: 'تأكيد الدفع عند الاستلام',
        cod_confirmation_reminder: 'تذكير التأكيد', order_shipped: 'تم الشحن',
        out_for_delivery: 'خارج للتوصيل', order_delivered: 'تم التسليم',
        abandoned_order: 'طلب متروك', review_request: 'طلب تقييم', order_cancelled: 'إلغاء الطلب',
      },
    },
    optIn: {
      title: 'الموافقات', subtitle: 'من وافق على استقبال رسائل واتساب منك.',
      status: { opted_in: 'موافق', opted_out: 'منسحب', unknown: 'غير مسجَّل' },
      scope: { all: 'كل الرسائل', transactional: 'تحديثات الطلبات', marketing: 'التسويق' },
      source: { checkout: 'صفحة الدفع', store_sync: 'المتجر', whatsapp_reply: 'رد على واتساب', manual: 'إضافة يدوية', import: 'استيراد', stop_keyword: 'ردّ بإيقاف', meta_block: 'حظرك' },
      record: 'تسجيل موافقة', optOut: 'إلغاء الاشتراك', evidence: 'الإثبات', history: 'السجل',
      importWarning: 'استورد فقط الأرقام التي منحتك إذناً صريحاً بمراسلتها على واتساب. قد تحظر ميتا رقمك عند مراسلة من لم يوافق.',
      blocked: 'هذا العميل ألغى الاشتراك. لا يمكن إرسال رسائل إليه.',
    },
    analytics: {
      title: 'أداء واتساب', sent: 'المُرسلة', delivered: 'الواصلة', read: 'المقروءة', failed: 'الفاشلة',
      deliveryRate: 'نسبة الوصول', readRate: 'نسبة القراءة', inbound: 'الردود الواردة',
      cost: 'التكلفة', billableMessages: 'رسائل مدفوعة', freeMessages: 'رسائل مجانية',
      avoided: 'وفّرته بالرد داخل النافذة', estimated: 'تقديري',
      estimatedHint: 'تؤكد ميتا السعر الدقيق بعد الوصول بقليل.',
      codImpact: 'الأثر على المرتجعات', confirmed: 'أكّدها العميل', unconfirmed: 'بدون رد',
      notAsked: 'لم يُسأل', rtoRate: 'نسبة المرتجعات',
      codImpactHint: 'نسبة المرتجعات لطلبات الدفع عند الاستلام التي أكّدها العميل عبر واتساب، مقارنةً بغيرها.',
      lowConfidence: 'عدد الطلبات غير كافٍ بعد.',
    },
    settings: {
      title: 'إعدادات واتساب', defaultLanguage: 'اللغة الافتراضية', fallbackLanguage: 'اللغة البديلة',
      stopKeywords: 'كلمات إلغاء الاشتراك', codConfirmation: 'تأكيد الدفع عند الاستلام',
      codConfirmationEnabled: 'اطلب من العملاء تأكيد طلبات الدفع عند الاستلام قبل الشحن',
      codRiskOnly: 'فقط للعملاء متوسطي ومرتفعي الخطورة', reminderAfter: 'أرسل تذكيراً بعد (ساعات)',
      expireAfter: 'التوقف بعد (ساعات)',
    },
    toast: {
      connected: 'تم ربط واتساب.', disconnected: 'تم فصل واتساب.',
      templateSubmitted: 'أُرسل القالب إلى ميتا للمراجعة.', templateApproved: 'تم اعتماد القالب.',
      messageSent: 'أُرسلت الرسالة.', messageFailed: 'تعذّر إرسال الرسالة.',
      optedOut: 'ألغى العميل الاشتراك.', windowClosed: 'أُغلقت نافذة الرد. أرسل قالباً بدلاً من ذلك.',
    },
  },
} as const;
```

**RTL specifics.** The thread mirrors (own messages on the left in RTL). Phone numbers, order references and template `name` values are always `dir="ltr"`. The window countdown uses Western digits for legibility. Template previews render in the **template's own language direction**, not the dashboard's — an Arabic template must preview RTL even when the merchant is browsing in English, or the preview is a lie.

---

## 8. Mobile (Flutter)

WhatsApp is the surface merchants most want on their phone — a customer message needs answering in minutes, not at the next desk session. So mobile gets **more** here than it does for COD.

`mobile/lib/features/whatsapp/`:

| Screen | Content |
|---|---|
| `whatsapp_inbox_page.dart` | Conversation list, unread badges, window-countdown pill, filters (unread / assigned to me / window open). |
| `whatsapp_thread_page.dart` | Full thread, **freeform reply when the window is open**, template picker when it is closed, delivery ticks, failure reasons. The composer is disabled with an inline explanation, never a silent failure. |
| `whatsapp_templates_page.dart` | Read-only list with statuses, so a merchant can see *why* a flow stopped. Authoring stays on desktop. |
| `whatsapp_overview_page.dart` | Connection health + 7-day activity + cost. |

Push notifications: new inbound message, message failed, template rejected, quality dropped to RED, account restricted. Delivered through the existing notifications feature and respecting `users.notification_preferences`.

Not on mobile: connecting/disconnecting the account, template authoring/submission, consent import, settings.

`mobile/lib/l10n/` gets the mirrored `en`/`ar` keys. Arabic RTL is already handled by `locale_cubit`; the thread must additionally set per-message direction based on detected content language, because a merchant replying in English inside an Arabic UI otherwise gets mangled bidi.

---

## 9. Permissions & multi-tenancy

### Tenancy

- Every table carries `organization_id`; every query scopes on it from `X-Organization-Id` via `org.member`.
- **Webhook tenancy is the hard part.** Inbound events carry no Hubby identity — they carry a WABA id and a `phone_number_id`. Resolution is: `phone_number_id` → `whatsapp_accounts` → `organization_id`. `unique(phone_number_id)` guarantees this resolves to at most one org. If it resolves to none, the event is stored unrouted, flagged for ops, and **never** processed against a guessed organization.
- Conversations, messages, templates and opt-ins are resolved with `forOrganization($orgId)->findOrFail($id)` — a foreign id yields 404.
- Opt-out is **organization-scoped**. A customer who opts out of merchant A still receives merchant B's messages; that is correct, since consent was given to a specific business.

### Roles

| Capability | owner | admin | member | viewer |
|---|---|---|---|---|
| View inbox / conversations | ✔ | ✔ | ✔ | ✔ |
| Reply (freeform, in-window) | ✔ | ✔ | ✔ | ✘ |
| Send a template manually | ✔ | ✔ | ✔ | ✘ |
| Assign / close conversations | ✔ | ✔ | ✔ | ✘ |
| Create / edit templates | ✔ | ✔ | ✘ | ✘ |
| Submit templates to Meta | ✔ | ✔ | ✘ | ✘ |
| Connect / disconnect the account | ✔ | ✘ | ✘ | ✘ |
| Record / import consent | ✔ | ✔ | ✘ | ✘ |
| Change WhatsApp settings | ✔ | ✔ | ✘ | ✘ |

Same assumption as Spec 06 §9: the role vocabulary beyond `owner` is not visible in the repo and must be confirmed.

### Data protection

- Tokens and secrets: `encrypted` casts, `$hidden`, redacted from logs and exception context. `CloudApiClient` scrubs `access_token` from every logged request/response.
- Message bodies contain customer PII and are retained per an org-configurable policy (default: indefinite for order-linked, 24 months for others). **A retention decision is required from counsel — §15.5.**
- Inbound media is downloaded to a private disk and served only through an authenticated, org-scoped endpoint. Never a public URL.
- Customer phone numbers are never written to application logs above `debug`; log conversation ids.
- A per-customer data-export/erasure path is needed for PDPL/GDPR requests: erase message bodies and media, retain the opt-in event log (legal basis: proving consent history), and record the erasure.

---

## 10. Edge cases & failure modes

| # | Case | Handling |
|---|---|---|
| 1 | Window closes between enqueue and send | `WindowPolicy` re-evaluated inside the job. Freeform → `failed` with `window_closed` and a merchant notification suggesting a template. Never sent blind. |
| 2 | Meta webhook arrives out of order (`read` before `sent`) | Forward-only status rank (§4.4). |
| 3 | Duplicate webhook delivery | `unique(event_hash)` + `unique(wamid)`. |
| 4 | Send succeeds at Meta but our DB write fails | Row stays `queued` with no `wamid`; `ReconcileStuckWhatsAppMessagesJob` flags `unknown_outcome` and alerts. **We do not auto-retry** — double-messaging a customer is worse than a missing status. |
| 5 | Customer replies from a different number than the order's | The conversation links to no order. Merchant links it manually; the matcher also tries "most recent order in this org with this phone within 90 days". |
| 6 | Two agents reply simultaneously | Both send — WhatsApp has no locking. Mitigation: assignment + a "{name} is typing/viewing" presence hint (v2). Accepted for v1 and stated in the UI. |
| 7 | Template approved in `en`, rejected in `ar` | `language: "auto"` falls back to `en` and records `language_fallback_used`. The merchant is notified that Arabic customers are receiving English. |
| 8 | Template paused mid-flow | Sends fail with 132007. The automation is flagged, the merchant is notified, and the flow degrades to an in-app notification rather than silently stopping. |
| 9 | Merchant's token expires | Account → `token_expired`; all sends blocked with a clear reason; a persistent banner; queued messages held (not failed) for 72 h so a reconnect resumes them. |
| 10 | Quality drops to RED | Warning surfaced; marketing templates auto-paused at the Hubby level (config-gated) to protect the number before Meta restricts it. Protecting the merchant's number is worth blocking a campaign. |
| 11 | Customer sends media we cannot handle | Stored as `unsupported` with the raw payload; the inbox shows "unsupported message type" rather than an empty bubble. |
| 12 | Media URL expires before download | `DownloadWhatsAppMediaJob` runs immediately; on failure, the message is kept with `media_id` and a "media unavailable" state. |
| 13 | Customer opts out while messages are queued | `OptInService` cancels queued messages to that number in the same transaction as the opt-out. |
| 14 | Consent import of a purchased list | The endpoint requires an explicit `consent_source` + `consent_text` + an attestation checkbox, logs the importing user, and the UI carries an unmissable warning (§7.4 `optIn.importWarning`). We cannot technically prevent misuse, but we can refuse to make it frictionless and we can keep an audit trail. **We should also rate-limit first-time sends to imported numbers** so a bad list degrades gradually rather than nuking the merchant's number in one batch. |
| 15 | Phone number formatting (`05xxxxxxxx` vs `+9665xxxxxxxx`) | All numbers normalised to E.164 on write using the store's country as the default region. Un-normalisable numbers are rejected at send with `invalid_phone`, never sent hopefully. |
| 16 | Arabic text with emoji/RTL marks breaking template validation | Pre-send validation runs Meta's content rules on the **rendered** message, not the template, catching variable-injected newlines. |
| 17 | Meta rate limit (130429 / 80007) | Governor derived from `messaging_limit_tier`; the queue slows rather than failing. `Retry-After` honoured. |
| 18 | Meta API outage | Circuit breaker in `CloudApiClient`: 5 consecutive 5xx opens it for 60 s. Messages stay `queued` and drain on recovery. Merchant sees "WhatsApp is temporarily unavailable", not 200 failed messages. |
| 19 | Webhook endpoint down / slow | Meta retries, then can disable the subscription. Controller must always return 200 fast; a health check verifies the subscription every 6 h and re-subscribes automatically. |
| 20 | Merchant disconnects and reconnects a different number | Old account soft-deleted, history retained and readable, new account created. Conversations do not migrate — they belong to the old number. Stated in the UI so it is not a surprise. |
| 21 | Same WABA connected to two Hubby orgs (agency) | Blocked by `unique(phone_number_id)` with a clear 409. Multi-number-per-org is supported; same-number-two-orgs is not. |
| 22 | COD confirmation sent for an order that is then cancelled elsewhere | The reminder job checks order state before sending and no-ops. |
| 23 | Customer replies "تأكيد" as text instead of pressing the button | Keyword handler accepts both button payloads and a configurable Arabic/English keyword set for confirm/cancel. |
| 24 | Clock skew / timezone | All window arithmetic in UTC from the DB. Countdown rendered client-side from an absolute timestamp, never from a server-computed remaining-seconds that goes stale. |

---

## 11. Testing

### Unit — `tests/Unit/WhatsApp/`

| Test | Asserts |
|---|---|
| `WindowPolicyTest` | The full §4.3 matrix: freeform open/closed; utility in/out of window (free vs billed); marketing always billed; free-entry-point overriding; opted-out rejected; unapproved template rejected. This is the highest-value unit test in the spec — it is where money and compliance meet. |
| `WindowExpiryTest` | 24 h from `last_inbound_at`, reset on each inbound; 72 h for free entry point; the later expiry wins; boundary at exactly 24 h → closed. |
| `MessageStatusRankTest` | Forward-only: `read` then a late `sent` stays `read`; `failed` always applies. |
| `TemplateVariableResolverTest` | Allow-listed sources resolve; a non-allow-listed source throws; empty required variable throws; money/date/phone formatters; newline-in-variable rejected; `{{n}}` gap detection. |
| `TemplateContentValidatorTest` | Body >1024, header >60, footer >60, variable-only body, 4+ spaces — each rejected with a specific message. |
| `PhoneNormaliserTest` | `05xxxxxxxx` + SA → `+9665xxxxxxxx`; already-E.164 passthrough; garbage rejected. |
| `MetaErrorMapperTest` | Each §6.4 code → correct retryability and message; an unknown 4xx is non-retryable; an unknown 5xx is retryable. |
| `StopKeywordDetectorTest` | Arabic and English variants, with and without diacritics/spacing; a false positive check ("لا ترسل لي الطلب الخطأ" should not be treated as an unambiguous global opt-out — it maps to `marketing` scope at most, or is escalated to a human). |
| `CostEstimatorTest` | Rate-card lookup by country + category; unknown country → default; free when the window policy says free. |
| `IdempotencyKeyTest` | Deterministic key generation; same order + same day + same use case → same key. |

### Feature — `tests/Feature/WhatsApp/`

| Test | Asserts |
|---|---|
| `WhatsAppConnectTest` | Embedded-Signup code exchange (HTTP faked) → account created, token encrypted at rest (assert the raw DB value is not the plaintext), webhook subscribed. |
| `WhatsAppConnectConflictTest` | A `phone_number_id` already owned by another org → 409, no row created. |
| `WhatsAppAccountSecrecyTest` | No endpoint returns `access_token` / `webhook_secret` / `webhook_verify_token`; asserted over the whole response body, recursively. |
| `WebhookVerificationTest` | Correct `hub.verify_token` → raw challenge as `text/plain` 200; wrong → 403. |
| `WebhookSignatureTest` | Valid `X-Hub-Signature-256` → 200 + event stored; invalid → 401 + `signature_valid=false`; missing secret in `production` → 401. |
| `WebhookDedupeTest` | The same body twice → one event row, one message row. |
| `InboundMessageTest` | Creates the conversation, opens a 24 h window, increments unread, links the order by phone, emits the event. |
| `InboundResetsWindowTest` | A second inbound extends `window_expires_at`. |
| `OpenKeyUniquenessTest` | Two open conversations for the same contact/account cannot exist; closing one frees the key. **Runs on SQLite** (the project's test DB) to prove the portable-unique trick works. |
| `SendTemplateTest` | Approved template → 202, message row `queued`, job dispatched, Meta called with the exact expected component payload. |
| `SendTemplateNotApprovedTest` | `PENDING`/`REJECTED`/`PAUSED` → 422 and **zero** outbound HTTP calls. |
| `SendFreeformWindowOpenTest` | 202, `billable=false`, `pricing_category=service`. |
| `SendFreeformWindowClosedTest` | 422 `window_closed` with `suggested_templates` populated. |
| `SendNotOptedInTest` | Marketing template to `unknown` consent → 422; utility to `unknown` → allowed under default config, blocked when `transactional_requires_explicit_optin=true`. |
| `SendIdempotencyTest` | The same `idempotency_key` twice → one message, second returns the first with 200. |
| `StatusWebhookTest` | sent → delivered → read applied with timestamps; pricing absorbed, `price_source` becomes `meta`. |
| `FailedMessageTest` | 131047 → `failed`, mapped reason, not retried; 130429 → retried with backoff. |
| `TemplateSubmitTest` | Local validation runs first; Meta call made; status → `PENDING`; `meta_template_id` stored. |
| `TemplateStatusWebhookTest` | `APPROVED`/`REJECTED`/`PAUSED` update the row and create a `notifications` row. |
| `TemplateSeedTest` | Seeds all 9 use cases in `ar` + `en` as drafts with correct categories and variable maps. |
| `OptOutFlowTest` | Inbound "إيقاف" → opt-out recorded, event logged, `is_opted_out` set, queued messages to that number cancelled, confirmation reply sent free. |
| `OptInEvidenceTest` | Recording consent stores `consent_text`, locale, IP, evidence, and appends an immutable event. |
| `CodConfirmationFlowTest` | COD order → template sent with the right amount; button "confirm" → order tagged confirmed; "cancel" → order cancelled, `cod_transaction` cancelled, **no `cod_rto_events` row created** (the §4.9 rule). |
| `CodConfirmationReminderTest` | No reply → reminder after N h; already confirmed → no reminder. |
| `CodImpactAnalyticsTest` | Cohort rates computed correctly; `low_confidence` under threshold. |
| `WhatsAppCostAnalyticsTest` | Billable/free split; `avoided_by_open_window` matches free utility count × rate. |
| `WhatsAppTenancyTest` | Every endpoint, foreign org id → 404/403. Parameterised over the route list. |
| `WhatsAppPermissionTest` | Role matrix in §9. |
| `WhatsAppRateLimitTest` | 121st send in a minute → 429. |

All Meta calls are `Http::fake()`d with fixtures in `tests/Fixtures/whatsapp/` (send response, status webhook, inbound text, inbound button reply, template status update, each error code). **No test touches the real Graph API.**

### Non-functional targets

- Webhook `POST` responds in < 100 ms p95 (store + dispatch only).
- Inbox list p95 < 250 ms at 100k conversations.
- Thread load (50 messages) p95 < 200 ms.
- A send from API call to Meta request < 2 s p95.
- No plaintext token in any log line — asserted by a CI grep over test output and a code review checklist item.

---

## 12. Rollout

### 12.1 Feature flags

- `whatsapp` — master, per organization, via `plans.features`. Positioned as a paid MENA-tier feature alongside `cod`.
- `whatsapp.inbox` — the two-way inbox can ship after one-way sending.
- `whatsapp.cod_confirmation` — per org, default **off** until the merchant has approved templates.
- `whatsapp.marketing_templates` — default off; a merchant must acknowledge the consent rules to enable it.
- `whatsapp.auto_pause_marketing_on_red` — default on.

### 12.2 Phases

**Phase 0 — schema + config.** Migrations `000101`–`000107`, `config/whatsapp.php`, models. No behaviour.

**Phase 1 — connection + webhook.** Embedded Signup (or manual), verification handshake, signature verification, event storage, account metadata sync. Ship with **no sending at all**. This phase de-risks the hardest external integration before any product surface depends on it.

**Phase 2 — templates.** Editor, seeded catalogue, submit/sync, status webhooks. Merchants get templates approved while we build sending. Template review latency is external and should be absorbed early.

**Phase 3 — outbound (one-way).** `MessageDispatcher`, `WindowPolicy`, opt-in enforcement, status tracking, cost attribution. Wire `order_confirmation` + `order_shipped` first. Rules-engine `send_whatsapp` action.

**Phase 4 — COD confirmation.** The flagship. Only after Spec 06 has COD transactions and after Phase 3 is stable. Turn on for 2–3 design partners, measure the cohort split, and only then generalise.

**Phase 5 — inbox.** Inbound routing, conversation UI, freeform replies, assignment, media.

**Phase 6 — analytics + marketing templates.** Cost dashboard, COD impact, abandoned-order and review-request flows behind the marketing consent gate.

**Rollback.** All phases are flag rollbacks. The webhook subscription can be removed at Meta without touching data. Schema `down()` on `whatsapp_messages` destroys customer conversation history — the migration carries a comment saying it must never run in production without an export.

### 12.3 Credentials, sandbox, and the Tech Provider decision

- **App-level** secrets (`app_id`, `app_secret`, `config_id`, `verify_token`) are Hubby's, in `.env` / `config/whatsapp.php`. Rotating `app_secret` invalidates signature verification for all tenants at once — so rotation needs a dual-secret window (accept old or new for 24 h). **Build that in from day one**; retrofitting it during an incident is not fun.
- **Tenant-level** secrets (`access_token` and friends) live encrypted per `whatsapp_accounts` row.
- **Sandbox.** Meta provides a test business phone number per app with a small allowance of free messages to a handful of verified recipients. That is enough for development and CI is fully faked. **Unverified: the current allowance and recipient limits.** Every developer needs their own test number added to the app's allow-list; document that in the repo README rather than leaving it tribal knowledge.
- **The decision.** If Hubby becomes a Meta **Tech Provider**, merchants onboard in one popup with no Meta account juggling. If not, every merchant creates their own Meta app, generates their own token, and pastes it into `POST /connect/manual` — which will lose a meaningful share of merchants at the first screen. Engineering supports both paths (that is why `connect/manual` exists), but **the business track should start immediately** because Meta's verification and app review are calendar-bound, not effort-bound, and they will be the long pole.

---

## 13. Acceptance criteria

**Connection**
- [ ] A merchant connects via Embedded Signup in one flow; the account reaches `connected` only when token, number registration and webhook subscription are all verified.
- [ ] Manual connection works for merchants with an existing WABA.
- [ ] Tokens, verify token and app secret are encrypted at rest and never appear in any API response, log line or exception.
- [ ] A `phone_number_id` already connected to another organization is rejected with a clear 409.
- [ ] Quality rating, messaging tier and account status sync at least every 6 hours and on webhook.
- [ ] Token expiry blocks sending, notifies the merchant, and holds queued messages for 72 h.

**Webhooks**
- [ ] `GET` verification returns the raw challenge for a correct token and 403 otherwise.
- [ ] `X-Hub-Signature-256` is verified against the raw body; invalid → 401. Missing secret in production → 401.
- [ ] The `POST` handler stores and dispatches only, responding in < 100 ms p95.
- [ ] Duplicate events and duplicate `wamid`s are deduplicated at the database level.
- [ ] An event whose `phone_number_id` matches no account is stored unrouted and flagged, never processed against a guessed org.

**Templates**
- [ ] Templates can be authored with a live preview and a variable mapper limited to allow-listed sources.
- [ ] Meta's content rules are validated locally before submission.
- [ ] The 9-use-case catalogue seeds in `ar` and `en` as drafts.
- [ ] Status changes arrive by webhook **and** are reconciled hourly by the poller.
- [ ] Rejection reasons are shown verbatim with an edit path.
- [ ] Sending a non-`APPROVED` template returns 422 with zero outbound HTTP calls.

**Windows, sending, compliance**
- [ ] The full §4.3 matrix is implemented and unit-tested.
- [ ] Utility templates inside an open window are recorded as free; marketing is always billable.
- [ ] Freeform outside the window is refused with `window_closed` and suggested templates — never attempted.
- [ ] The window is re-evaluated at send time, not enqueue time.
- [ ] Business-initiated sends respect opt-in scope; opt-out is honoured immediately and cancels queued messages.
- [ ] STOP keywords in Arabic and English are detected and produce an immutable consent event.
- [ ] Consent import requires an attested source and warns unmissably.
- [ ] Message status is forward-only and absorbs Meta's pricing when it arrives.
- [ ] Identical idempotency keys never produce two messages.

**COD flow**
- [ ] A COD order can trigger `cod_confirmation` with the correct amount, currency and store name in Arabic.
- [ ] A "confirm" reply marks the order confirmed; "cancel" cancels the order and the COD transaction **without** creating an RTO event.
- [ ] A customer reply opens a 24-hour window, making subsequent utility updates free — and the dashboard shows the saving.
- [ ] `/analytics/cod-impact` reports confirmed vs unconfirmed vs not-asked RTO rates with a low-confidence guard.

**Inbox, tenancy, UX**
- [ ] The inbox shows a live window countdown; the composer is disabled with an explanation when the window is closed.
- [ ] Assignment, close, link-order and read state work; unread counts are accurate.
- [ ] Inbound media is downloaded to a private disk and served only through an authenticated org-scoped endpoint.
- [ ] Every endpoint is covered by `WhatsAppTenancyTest`.
- [ ] The §9 role matrix is enforced by policy and tested.
- [ ] Full `en` + `ar` coverage; template previews render in the template's own direction.
- [ ] Mobile supports reading and replying, including template sends when the window is closed.

---

## 14. Effort estimate + dependencies

| Workstream | Effort |
|---|---|
| Migrations, models, encrypted casts, config | 3 d |
| `CloudApiClient` (retries, circuit breaker, redaction, version pinning) | 3 d |
| Connection: Embedded Signup + manual + verify + metadata sync | 6 d |
| Webhook: verification, signature middleware, event store, processor, routing | 5 d |
| Template service: CRUD, validation, submit, sync, seeder (9 × 2 languages) | 7 d |
| `TemplateVariableResolver` + allow-list + formatters | 3 d |
| `WindowPolicy` + `MessageDispatcher` + idempotency + opt-in enforcement | 6 d |
| Conversations, inbound routing, media download, keyword handlers | 5 d |
| Opt-in service, events, STOP handling, import with attestation | 4 d |
| Cost attribution + rollups + analytics endpoints | 4 d |
| COD confirmation flow + reminder + cohort analytics (Spec 06 bridge) | 5 d |
| Rules-engine `send_whatsapp` action + event triggers | 3 d |
| Backend tests (unit + feature + tenancy + fixtures) | 9 d |
| **Backend subtotal** | **~63 d** |
| Dashboard: connection + settings + overview | 4 d |
| Dashboard: template list + editor + preview + variable mapper | 8 d |
| Dashboard: inbox (list, thread, composer, window countdown, template picker) | 9 d |
| Dashboard: consent register + analytics + COD impact | 5 d |
| i18n en/ar + RTL + per-message direction | 4 d |
| Frontend tests | 4 d |
| **Frontend subtotal** | **~34 d** |
| Mobile: inbox, thread, reply, templates read-only, push | 8 d |
| Docs, runbook, Meta app setup guide, developer test-number guide | 3 d |
| **Total** | **≈ 108 engineer-days (~10–11 calendar weeks with 2 engineers + partial mobile)** |

**Not included, and possibly longer than all of the above:** Meta business verification, app review, and Tech Provider approval. Start on day 1.

### Hard dependencies

1. **A Meta app with business verification.** Blocking for anything past local development.
2. **`orders.customer_phone`** — shared with Spec 06. WhatsApp is impossible without it.
3. **Spec 06 (COD)** — blocking for Phase 4 only. Phases 1–3 and 5 are independent.
4. **Automation Rules Engine** — blocking for the `send_whatsapp` action; manual and built-in flows work without it.
5. **A public HTTPS webhook endpoint with a valid certificate.** Meta will not deliver otherwise. Local development needs a tunnel (ngrok/cloudflared) — document it.

### Soft dependencies

- **Shipping & Labels** for `order_shipped` / `out_for_delivery` triggers. Without it those two templates have no trigger, though everything else works.
- A queue worker with reasonable throughput; WhatsApp sends are latency-sensitive in a way order syncs are not. Consider a dedicated queue (`whatsapp`) so a slow product sync never delays a customer reply.
- WebSocket/broadcast infrastructure for a real-time inbox (v2 — §15.6).

---

## 15. Open questions

1. **Tech Provider or not?** The single biggest question in this spec. It determines whether onboarding is one popup or a 12-step Meta Business Manager guide, and it has a calendar cost we do not control. Needs a business decision now, not at Phase 1.
2. **Who pays Meta?** If merchants connect their own WABA, Meta bills them directly and we display costs we do not control (and cannot fully verify). If Hubby ever becomes a BSP, we bill and mark up — a different business, different compliance, different spec. v1 assumes merchant-billed. Confirm before building the cost dashboard, because "estimated cost" means something different in each model.
3. **Does transactional messaging require explicit opt-in in KSA/UAE/Egypt?** The config default (`false` — an order implies transactional consent) is a **legal position taken by engineering**, which is the wrong place to take it. Needs counsel per market, and possibly a per-country default rather than a global one.
4. **Meta's stance on our COD confirmation template.** It is `UTILITY` in our reading (a transactional confirmation of an existing order). Meta could re-categorise it as `MARKETING`, which changes the economics and the consent requirements. Submit one early in Phase 2 and find out — do not build Phase 4's business case on an assumption.
5. **Message retention.** Indefinite for order-linked messages is convenient and probably wrong under PDPL/GDPR. Needs a policy, a default, and a per-org override.
6. **Real-time inbox.** Polling is a v1 compromise. Laravel Reverb / Pusher would give a genuinely live inbox; it is a platform decision (infra, cost, mobile support) beyond this spec.
7. **Multiple numbers per organization.** The schema allows it (`unique(organization_id, display_phone_number)`), but nothing in the UI or routing picks *which* number sends a given message. If merchants want per-store or per-country numbers, sending needs a resolution rule. Defer, but do not paint over it.
8. **Do we auto-translate merchant-authored templates?** Tempting (submit `en`, we generate `ar`). Risky: a machine-translated template that gets rejected damages the merchant's template quality history, and a subtly wrong Arabic message damages their brand. Recommend no in v1; offer a review-before-submit assist later.
9. **Group chats and non-customer inbound.** Suppliers, spam, and wrong numbers will land in the inbox. Is there a block/spam action, and does it write an opt-out?
10. **Rate-card maintenance.** Who updates `config('whatsapp.rates')` when Meta changes prices, and how do merchants know their historical cost estimates were computed on an old card? Suggest storing the rate-card version on each message.
