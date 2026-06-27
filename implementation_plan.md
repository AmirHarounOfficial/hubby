# HubbyGlobal — Full Implementation Plan

A SaaS platform enabling merchants to connect, manage, and operate multiple e-commerce stores from a single unified dashboard.

---

## User Review Required

> [!IMPORTANT]
> **Monorepo Structure**: This plan uses a monorepo (`/backend`, `/frontend`, `/mobile`) under `d:\work\HubbyGlobal`. Confirm if you prefer separate repositories.

> [!IMPORTANT]
> **MVP Scope**: Shopify + Salla for Phase 1 integrations only (WooCommerce, Zid deferred). Confirm if you need a different priority order.

> [!WARNING]
> **edfapay Integration**: The payment gateway requires merchant credentials & sandbox API keys. These must be provided before billing features can be built.

> [!WARNING]
> **Flutter Mobile**: Mobile app is scoped to read-only dashboard (orders, analytics, inventory view) in MVP. Full parity with web in Phase 2. Confirm if acceptable.

---

## Open Questions

> [!IMPORTANT]
> 1. **Email verification** — PRD marks it "optional MVP." Include it or skip entirely?
> 2. **Master store logic** — When inventory syncs, is the master store fixed per organization, or can the user switch it at any time?
> 3. **Multi-currency** — Orders from different stores may have different currencies. Should totals in analytics be converted to a base currency, or displayed per-currency?
> 4. **Webhook vs Cron** — Prefer webhooks (real-time) or polling crons (simpler)? Plan defaults to webhooks + fallback cron.
> 5. **Hetzner VPS specs** — How many CPU cores / RAM? Determines Redis concurrency and queue worker count.

---

## Tech Stack Summary

| Layer | Technology |
|---|---|
| Web Frontend | Next.js 14 (App Router) + TypeScript + Tailwind CSS |
| Mobile | Flutter 3.x (Dart) |
| Backend API | Laravel 11 (PHP 8.3) |
| Database | MySQL 8.0 |
| Queue | Redis + Laravel Horizon |
| Auth | Laravel Sanctum (JWT-style tokens) |
| Payments | edfapay |
| Infra | Hetzner VPS · Docker Compose · Nginx · PHP-FPM |
| CI/CD | GitHub Actions |

---

## Proposed Changes

### 1 — Repository & Monorepo Scaffold

#### [NEW] Root `d:\work\HubbyGlobal\`
```
HubbyGlobal/
├── backend/          # Laravel 11
├── frontend/         # Next.js 14
├── mobile/           # Flutter
├── docker/           # Compose files, Nginx configs
│   ├── docker-compose.yml
│   ├── docker-compose.prod.yml
│   └── nginx/
│       └── default.conf
├── .github/
│   └── workflows/
│       ├── backend-ci.yml
│       └── frontend-ci.yml
└── README.md
```

---

### 2 — Backend (Laravel 11)

#### [NEW] `backend/` — Laravel Application

**Bootstrap**
```bash
composer create-project laravel/laravel backend
cd backend
composer require laravel/sanctum spatie/laravel-permission \
  guzzlehttp/guzzle predis/predis laravel/horizon
```

---

#### [NEW] Database Migrations

All tables from the PRD plus supporting tables:

| Migration File | Table | Purpose |
|---|---|---|
| `001_create_users_table` | `users` | Auth, profile |
| `002_create_organizations_table` | `organizations` | Tenant/workspace |
| `003_create_org_user_table` | `organization_user` | Pivot: role per org |
| `004_create_stores_table` | `stores` | Connected stores |
| `005_create_integrations_table` | `integrations` | OAuth tokens per store |
| `006_create_products_table` | `products` | Unified product catalog |
| `007_create_product_variants_table` | `product_variants` | SKU/size/color |
| `008_create_platform_products_table` | `platform_products` | Platform↔product mapping |
| `009_create_orders_table` | `orders` | Unified orders |
| `010_create_order_items_table` | `order_items` | Line items |
| `011_create_inventory_logs_table` | `inventory_logs` | Audit trail |
| `012_create_subscriptions_table` | `subscriptions` | Billing state |
| `013_create_plans_table` | `plans` | Plan definitions |
| `014_create_sync_logs_table` | `sync_logs` | Job run history |
| `015_create_webhook_logs_table` | `webhook_logs` | Inbound webhooks |

**Key Schema Details**

```sql
-- stores
platform ENUM('shopify','woocommerce','salla','zid','amazon','noon','trendyol')
status   ENUM('connected','disconnected','syncing','error')
is_master TINYINT(1) DEFAULT 0   -- master store for inventory push

-- orders
external_id VARCHAR(255)  -- platform's own order ID
platform    VARCHAR(50)
status      ENUM('pending','processing','shipped','delivered','cancelled','refunded')
currency    CHAR(3)

-- subscriptions
plan        ENUM('trial','starter','pro','enterprise')
status      ENUM('trial','active','past_due','cancelled')
trial_ends_at TIMESTAMP NULL
```

---

#### [NEW] Models & Relationships

```
User          → belongsToMany Organization (with role pivot)
Organization  → hasMany Store
               → hasOne Subscription
Store         → hasOne Integration
              → hasMany Order
              → belongsTo Organization
Product       → belongsTo Organization
              → hasMany ProductVariant
              → hasMany PlatformProduct
Order         → belongsTo Store
              → hasMany OrderItem
```

---

#### [NEW] Auth Module (`app/Http/Controllers/AuthController.php`)

| Endpoint | Method | Description |
|---|---|---|
| `/api/register` | POST | Create user + default org + start trial |
| `/api/login` | POST | Return Sanctum token |
| `/api/logout` | POST | Revoke token |
| `/api/me` | GET | Authenticated user profile |
| `/api/password/forgot` | POST | Send reset email |
| `/api/password/reset` | POST | Confirm reset |

**Token Strategy**: Laravel Sanctum personal access tokens stored in `personal_access_tokens`. No separate JWT library needed — Sanctum handles it natively.

---

#### [NEW] Organization & Role Module

Uses `spatie/laravel-permission` scoped per organization.

Roles (MVP):
- `owner` — full access
- `admin` — manage stores, orders, products
- `viewer` — read-only

```
POST   /api/organizations
GET    /api/organizations/{id}
PATCH  /api/organizations/{id}
POST   /api/organizations/{id}/invite   (Phase 2)
GET    /api/organizations/{id}/members
PATCH  /api/organizations/{id}/members/{userId}   (change role)
```

---

#### [NEW] Store Management (`app/Http/Controllers/StoreController.php`)

```
GET    /api/stores              List all stores for org
POST   /api/stores/connect      Initiate OAuth / save credentials
DELETE /api/stores/{id}         Disconnect store
GET    /api/stores/{id}/sync    Trigger manual sync
PATCH  /api/stores/{id}         Update store settings (master flag, name)
```

---

#### [NEW] Integration Services (`app/Services/Integrations/`)

```
app/Services/Integrations/
├── BaseIntegrationService.php      Abstract base
├── ShopifyService.php
├── SallaService.php
├── WooCommerceService.php          (Phase 1, lower priority)
└── ZidService.php                  (Phase 1, lower priority)
```

**BaseIntegrationService interface:**
```php
interface IntegrationServiceInterface {
    public function getAuthUrl(): string;
    public function exchangeCode(string $code): array;   // returns token data
    public function refreshToken(Integration $integration): void;
    public function fetchOrders(Store $store, array $params): array;
    public function fetchProducts(Store $store): array;
    public function fetchInventory(Store $store): array;
    public function updateInventory(Store $store, string $sku, int $qty): bool;
    public function updateOrderStatus(Store $store, string $externalId, string $status): bool;
}
```

**ShopifyService specifics:**
- OAuth 2.0 via `https://{shop}.myshopify.com/admin/oauth/authorize`
- REST Admin API v2024-01
- Webhooks: `orders/create`, `orders/updated`, `inventory_levels/update`

**SallaService specifics:**
- OAuth 2.0 via Salla Partner Portal
- Salla API v2
- Webhooks: `order.created`, `product.updated`

---

#### [NEW] OAuth Callback Controllers

```
GET /api/oauth/{platform}/redirect     Redirect user to platform OAuth
GET /api/oauth/{platform}/callback     Handle callback, store tokens, trigger sync
```

---

#### [NEW] Webhook Controllers (`app/Http/Controllers/WebhookController.php`)

```
POST /api/webhooks/shopify
POST /api/webhooks/salla
POST /api/webhooks/woocommerce
```

Each webhook:
1. Verifies HMAC signature
2. Logs to `webhook_logs`
3. Dispatches the appropriate job

---

#### [NEW] Background Jobs (`app/Jobs/`)

| Job Class | Trigger | Purpose |
|---|---|---|
| `SyncOrdersJob` | Webhook + 15min cron | Fetch & upsert orders from platform |
| `SyncProductsJob` | On connect + 1hr cron | Fetch & upsert products |
| `SyncInventoryJob` | Webhook + 5min cron | Detect stock changes |
| `PushInventoryJob` | After SyncInventoryJob | Push stock to non-master stores |
| `RefreshTokenJob` | Daily cron | Refresh expiring OAuth tokens |
| `GenerateDailyAnalyticsJob` | Daily cron | Cache analytics snapshots |

**Queue Channels:**
```
high      → SyncOrdersJob (real-time feel)
default   → SyncProductsJob, SyncInventoryJob, PushInventoryJob
low       → GenerateDailyAnalyticsJob, RefreshTokenJob
```

**Laravel Horizon** supervises all queues with auto-restart and failure handling.

---

#### [NEW] Orders Module (`app/Http/Controllers/OrderController.php`)

```
GET    /api/orders              Paginated list (filter: store, status, date, platform)
GET    /api/orders/{id}         Order detail + items
PATCH  /api/orders/{id}         Update status (delegates to platform API)
GET    /api/orders/export       CSV export
```

---

#### [NEW] Products Module (`app/Http/Controllers/ProductController.php`)

```
GET    /api/products            Paginated product list
GET    /api/products/{id}       Product detail + variants
PATCH  /api/products/{id}       Update basic fields (name, price, stock)
POST   /api/products/{id}/sync  Push product changes to connected stores
```

---

#### [NEW] Inventory Module (`app/Http/Controllers/InventoryController.php`)

```
GET    /api/inventory           Current stock levels across all stores
PATCH  /api/inventory/{sku}     Manual stock override
GET    /api/inventory/logs      Paginated audit log
```

---

#### [NEW] Analytics Module (`app/Http/Controllers/AnalyticsController.php`)

```
GET /api/analytics/summary          Total revenue, orders, avg order value
GET /api/analytics/by-platform      Revenue split by platform
GET /api/analytics/top-products     Best sellers
GET /api/analytics/orders-timeline  Orders over time (daily/weekly)
```

All endpoints use Redis cache with 15-minute TTL; invalidated by `GenerateDailyAnalyticsJob`.

---

#### [NEW] Subscription & Billing (`app/Http/Controllers/BillingController.php`)

Plans (suggested):
| Plan | Price | Stores | Orders/mo |
|---|---|---|---|
| Trial | Free / 14 days | 2 | 500 |
| Starter | $29/mo | 5 | 5,000 |
| Pro | $79/mo | 15 | 50,000 |
| Enterprise | Custom | Unlimited | Unlimited |

```
GET    /api/billing/plans          Available plans
POST   /api/billing/subscribe      Initiate edfapay checkout
POST   /api/billing/callback       edfapay success/fail webhook
GET    /api/billing/status         Current subscription state
POST   /api/billing/cancel         Cancel subscription
```

**edfapay Flow:**
1. Backend creates a payment session via edfapay API
2. Returns checkout URL to frontend
3. Frontend redirects user
4. edfapay posts to `/api/billing/callback`
5. Verify signature → activate subscription

---

#### [NEW] Middleware Stack

| Middleware | Purpose |
|---|---|
| `auth:sanctum` | All protected routes |
| `EnsureOrganizationMember` | User belongs to org in route |
| `CheckSubscription` | Block feature access if trial expired |
| `ThrottleRequests` | 60 req/min per user |
| `VerifyWebhookSignature` | On all `/webhooks/*` routes |

---

#### [NEW] Docker Setup (`docker/docker-compose.yml`)

```yaml
services:
  app:        # PHP-FPM 8.3
  nginx:      # Nginx 1.25
  mysql:      # MySQL 8.0
  redis:      # Redis 7
  horizon:    # Laravel Horizon worker
  scheduler:  # Cron container running artisan schedule:run
```

---

### 3 — Web Frontend (Next.js 14)

**Bootstrap:**
```bash
npx create-next-app@latest frontend --typescript --tailwind --app --src-dir
cd frontend
npm install axios react-query @tanstack/react-query zustand react-hook-form \
  zod @radix-ui/react-dialog recharts date-fns lucide-react
```

---

#### Pages & Routes

| Route | Page | Access |
|---|---|---|
| `/` | Landing page | Public |
| `/login` | Login | Public |
| `/register` | Register | Public |
| `/onboarding` | Platform picker + store connect | Auth |
| `/dashboard` | Main dashboard (metrics) | Auth |
| `/orders` | Orders table + filters | Auth |
| `/orders/[id]` | Order detail | Auth |
| `/products` | Product table | Auth |
| `/products/[id]` | Product edit | Auth |
| `/inventory` | Inventory table + logs | Auth |
| `/stores` | Connected store cards | Auth |
| `/stores/connect` | Add new store wizard | Auth |
| `/analytics` | Analytics charts | Auth |
| `/billing` | Plans + current subscription | Auth |
| `/settings` | Org settings, members, profile | Auth |

---

#### [NEW] Design System (`frontend/src/styles/globals.css`)

Color palette matching PRD:
```css
:root {
  --color-primary:    #4F46E5;   /* Indigo */
  --color-secondary:  #10B981;   /* Emerald */
  --color-bg:         #0F172A;   /* Slate 900 */
  --color-surface:    #1E293B;   /* Slate 800 */
  --color-border:     #334155;   /* Slate 700 */
  --color-text:       #F1F5F9;   /* Slate 100 */
  --color-muted:      #94A3B8;   /* Slate 400 */
  --color-danger:     #EF4444;
  --color-warning:    #F59E0B;
  --color-success:    #10B981;
}
```

Typography: **Inter** from Google Fonts.

---

#### [NEW] Component Library (`frontend/src/components/`)

```
components/
├── ui/
│   ├── Button.tsx
│   ├── Input.tsx
│   ├── Badge.tsx          (order status colors)
│   ├── Card.tsx
│   ├── Modal.tsx
│   ├── Table.tsx          (sortable, paginated)
│   ├── Skeleton.tsx
│   ├── Toast.tsx
│   └── Sidebar.tsx
├── layout/
│   ├── AppShell.tsx       (sidebar + topbar wrapper)
│   ├── Topbar.tsx
│   └── PageHeader.tsx
├── charts/
│   ├── RevenueChart.tsx   (Recharts line chart)
│   ├── PlatformPie.tsx    (donut chart by platform)
│   └── OrdersTimeline.tsx
├── stores/
│   ├── StoreCard.tsx
│   └── ConnectStoreWizard.tsx
├── orders/
│   ├── OrdersTable.tsx
│   ├── OrderFilters.tsx
│   └── OrderDetailModal.tsx
├── products/
│   ├── ProductsTable.tsx
│   └── ProductEditModal.tsx
└── billing/
    ├── PlanCard.tsx
    └── SubscriptionBanner.tsx
```

---

#### [NEW] State Management (`frontend/src/store/`)

Uses **Zustand** for global state:
- `authStore` — user, org, token
- `uiStore` — sidebar open/collapsed, active org

**React Query** (`@tanstack/react-query`) handles all server state (orders, products, analytics, etc.) with automatic caching and background refetching.

---

#### [NEW] API Client (`frontend/src/lib/api.ts`)

Axios instance with:
- Base URL from `NEXT_PUBLIC_API_URL`
- Request interceptor: attach `Authorization: Bearer {token}`
- Response interceptor: auto-redirect to `/login` on 401

---

#### [NEW] Landing Page (`frontend/src/app/page.tsx`)

Sections:
1. **Hero** — Headline + subtext + CTA "Start Free Trial" + animated platform logos
2. **Features** — 3-column cards (Unified Orders, Inventory Sync, Analytics)
3. **How It Works** — 3-step visual flow
4. **Pricing** — Plan cards (Trial / Starter / Pro / Enterprise)
5. **Testimonials** — Placeholder carousel
6. **Footer** — Links + social

---

#### [NEW] Onboarding Flow

Multi-step wizard:
1. **Step 1** — "Welcome! Select your platform" (platform icon grid)
2. **Step 2** — Platform-specific connection (enter store URL or OAuth redirect)
3. **Step 3** — Waiting for initial sync animation
4. **Step 4** — "You're ready!" → redirect to dashboard

---

### 4 — Mobile App (Flutter)

**Scope (MVP):** Read-only companion app — view dashboard metrics, orders, and inventory. Push notifications for new orders.

**Bootstrap:**
```bash
flutter create mobile --org com.hubbyglobal
cd mobile
flutter pub add dio provider go_router fl_chart intl
```

#### Screens (MVP)

| Screen | Description |
|---|---|
| Login | Email + password → store token |
| Dashboard | Revenue card, orders count, recent orders list |
| Orders | Scrollable list with filters |
| Order Detail | Full order info + items |
| Inventory | Stock list per product |
| Settings | Logout, profile |

**Architecture:** Clean Architecture with `provider` for state, `dio` for HTTP, `go_router` for navigation.

**Push Notifications:** Firebase Cloud Messaging (FCM) — backend dispatches notification when new order arrives via `SyncOrdersJob`.

---

### 5 — Infrastructure & DevOps

#### [NEW] `docker/docker-compose.yml`

```yaml
version: '3.8'
services:
  nginx:
    image: nginx:1.25-alpine
    ports: ["80:80", "443:443"]
    volumes:
      - ./nginx/default.conf:/etc/nginx/conf.d/default.conf
      - ../backend:/var/www/html
    depends_on: [app]

  app:
    build:
      context: ../backend
      dockerfile: Dockerfile
    environment:
      - APP_ENV=production
    volumes:
      - ../backend:/var/www/html

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_ROOT_PASSWORD}
      MYSQL_DATABASE: hubbyglobal
    volumes:
      - mysql_data:/var/lib/mysql

  redis:
    image: redis:7-alpine
    command: redis-server --requirepass ${REDIS_PASSWORD}

  horizon:
    build:
      context: ../backend
      dockerfile: Dockerfile
    command: php artisan horizon
    depends_on: [redis, mysql]

  scheduler:
    build:
      context: ../backend
      dockerfile: Dockerfile
    command: sh -c "while true; do php artisan schedule:run; sleep 60; done"
    depends_on: [mysql, redis]

volumes:
  mysql_data:
```

#### [NEW] Nginx Config

- HTTPS termination with Let's Encrypt (Certbot)
- `api.hubbyglobal.com` → Laravel
- `app.hubbyglobal.com` → Next.js static export or Node server

#### [NEW] GitHub Actions CI

- **Backend:** PHPUnit tests + PHP-CS-Fixer lint on push to `main`/`develop`
- **Frontend:** ESLint + TypeScript check + build on push

---

## Implementation Phases

### Phase 1 — Foundation (Week 1)

**Goal:** Working auth + store connection + first sync

| # | Task | Area |
|---|---|---|
| 1.1 | Scaffold monorepo structure | Infra |
| 1.2 | Docker Compose dev environment | Infra |
| 1.3 | Laravel migrations + models + relationships | Backend |
| 1.4 | Auth endpoints (register, login, logout, me) | Backend |
| 1.5 | Organization + role system (Spatie) | Backend |
| 1.6 | Shopify OAuth flow (redirect + callback) | Backend |
| 1.7 | Salla OAuth flow | Backend |
| 1.8 | ShopifyService: fetchOrders, fetchProducts | Backend |
| 1.9 | SallaService: fetchOrders, fetchProducts | Backend |
| 1.10 | SyncOrdersJob + SyncProductsJob | Backend |
| 1.11 | Laravel Horizon setup | Backend |
| 1.12 | Next.js scaffold + design system + layout | Frontend |
| 1.13 | Login + Register pages | Frontend |
| 1.14 | Onboarding wizard (platform select + connect) | Frontend |

---

### Phase 2 — Core Features (Week 2)

**Goal:** Unified dashboard, orders, products, inventory

| # | Task | Area |
|---|---|---|
| 2.1 | Orders API (list, detail, update status) | Backend |
| 2.2 | Products API (list, detail, edit) | Backend |
| 2.3 | Inventory sync logic (master store → push) | Backend |
| 2.4 | SyncInventoryJob + PushInventoryJob | Backend |
| 2.5 | Webhook handlers (Shopify, Salla) | Backend |
| 2.6 | Analytics endpoints + Redis caching | Backend |
| 2.7 | Subscription model + edfapay integration | Backend |
| 2.8 | Dashboard page (metrics cards + charts) | Frontend |
| 2.9 | Orders page (table + filters + detail modal) | Frontend |
| 2.10 | Products page (table + edit modal) | Frontend |
| 2.11 | Inventory page | Frontend |
| 2.12 | Stores page (cards + connect wizard) | Frontend |
| 2.13 | Analytics page (charts) | Frontend |
| 2.14 | Billing page (plans + subscribe) | Frontend |

---

### Phase 3 — Polish & Launch (Week 3)

**Goal:** Mobile MVP + testing + deployment

| # | Task | Area |
|---|---|---|
| 3.1 | Flutter app: Login + Dashboard + Orders | Mobile |
| 3.2 | Flutter app: Inventory + Settings | Mobile |
| 3.3 | FCM push notifications | Mobile + Backend |
| 3.4 | Backend: PHPUnit API tests | Backend |
| 3.5 | Frontend: Component + integration tests | Frontend |
| 3.6 | Landing page complete | Frontend |
| 3.7 | Hetzner VPS provisioning + Docker deploy | Infra |
| 3.8 | Nginx HTTPS + domain config | Infra |
| 3.9 | GitHub Actions CI/CD pipelines | Infra |
| 3.10 | WooCommerce + Zid integration (if time allows) | Backend |
| 3.11 | End-to-end smoke tests | QA |

---

## Verification Plan

### Automated Tests

**Backend (PHPUnit):**
```bash
cd backend
php artisan test --testsuite=Feature   # API endpoint tests
php artisan test --testsuite=Unit      # Service unit tests
```

Key test cases:
- Auth: register, login, logout, 401 on invalid token
- Store: connect Shopify (mocked OAuth), list, disconnect
- Orders: sync job runs, orders appear in DB, pagination correct
- Inventory: stock change on master → pushed to secondary stores
- Billing: trial creation on register, plan upgrade flow

**Frontend (Vitest + Testing Library):**
```bash
cd frontend
npm run test
npm run build  # TypeScript + build check
```

### Manual Verification

- [ ] Connect a live Shopify dev store → orders appear within 30s
- [ ] Connect a live Salla test store → products sync correctly
- [ ] Update inventory in Shopify → reflects in dashboard < 5 min
- [ ] Complete edfapay sandbox payment → subscription activates
- [ ] Flutter app login → orders visible on mobile
- [ ] API response time < 500ms (measure via Postman)
- [ ] Dashboard load < 2s (Lighthouse audit)

---

## File Map Summary

```
d:\work\HubbyGlobal\
├── backend/
│   ├── app/
│   │   ├── Http/Controllers/
│   │   │   ├── AuthController.php
│   │   │   ├── StoreController.php
│   │   │   ├── OrderController.php
│   │   │   ├── ProductController.php
│   │   │   ├── InventoryController.php
│   │   │   ├── AnalyticsController.php
│   │   │   ├── BillingController.php
│   │   │   ├── WebhookController.php
│   │   │   └── OAuthController.php
│   │   ├── Services/Integrations/
│   │   │   ├── BaseIntegrationService.php
│   │   │   ├── ShopifyService.php
│   │   │   ├── SallaService.php
│   │   │   ├── WooCommerceService.php
│   │   │   └── ZidService.php
│   │   ├── Jobs/
│   │   │   ├── SyncOrdersJob.php
│   │   │   ├── SyncProductsJob.php
│   │   │   ├── SyncInventoryJob.php
│   │   │   ├── PushInventoryJob.php
│   │   │   ├── RefreshTokenJob.php
│   │   │   └── GenerateDailyAnalyticsJob.php
│   │   ├── Models/
│   │   │   ├── User.php, Organization.php, Store.php
│   │   │   ├── Integration.php, Product.php, ProductVariant.php
│   │   │   ├── Order.php, OrderItem.php
│   │   │   ├── InventoryLog.php, SyncLog.php
│   │   │   └── Subscription.php, Plan.php
│   │   └── Http/Middleware/
│   │       ├── EnsureOrganizationMember.php
│   │       ├── CheckSubscription.php
│   │       └── VerifyWebhookSignature.php
│   └── database/migrations/ (015 migration files)
├── frontend/
│   ├── src/app/           (Next.js App Router pages)
│   ├── src/components/    (UI + feature components)
│   ├── src/lib/           (API client, utils)
│   ├── src/store/         (Zustand stores)
│   └── src/hooks/         (React Query hooks)
├── mobile/
│   └── lib/
│       ├── screens/
│       ├── services/
│       ├── providers/
│       └── widgets/
└── docker/
    ├── docker-compose.yml
    ├── docker-compose.prod.yml
    └── nginx/default.conf
```
