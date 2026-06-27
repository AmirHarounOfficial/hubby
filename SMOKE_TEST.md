# HubbyGlobal Smoke Test Checklist

Perform these tests after every major deployment to ensure system integrity.

## 1. Authentication & Security
- [ ] User can register a new account and organization.
- [ ] Email verification link is sent and works.
- [ ] Login/Logout works on both Web and Mobile.
- [ ] Unauthorized users cannot access `/dashboard` or `/api/me`.

## 2. Store Integrations
- [ ] "Connect a store" → token flow creates a store (works for all 6 platforms without operator OAuth).
- [ ] One-click OAuth button shows ONLY for platforms whose client keys are set in `.env`.
- [ ] Stores are listed correctly in the "Stores" page with real status (connected/syncing/error) + last-synced.
- [ ] Setting a "Master Store" updates the database correctly.

## 3. Order & Product Sync
- [ ] Recent orders appear in the dashboard after connecting a store.
- [ ] Product list is populated with correct SKUs and stock levels.
- [ ] Manual stock adjustment updates both HubbyGlobal and connected platforms.
- [ ] Webhook received from Shopify triggers a `SyncOrdersJob`.

## 4. Analytics & Billing
- [ ] Dashboard charts render with correct data points.
- [ ] Subscription status shows correctly after "subscribing" to a plan.
- [ ] Billing invoices/history are accessible.

## 5. Mobile App
- [ ] App launches and authenticates against the production API.
- [ ] Real-time push notification is received on a new order event.
- [ ] Inventory adjustment works from the mobile interface.

## 6. Infrastructure
- [ ] Laravel Horizon is active and processing queues.
- [ ] SSL certificate is valid for `hubbynetwork.com` (and `www.`) via aaPanel Let's Encrypt.
- [ ] `curl -I http://127.0.0.1:8001` returns 200 on the server (Docker nginx reachable).
- [ ] Database backups are configured (Manual check).
