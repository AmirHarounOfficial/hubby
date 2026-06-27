# HubbyGlobal — Unified E-commerce Management Platform

HubbyGlobal is a premium SaaS platform that enables merchants to connect, manage, and synchronize multiple e-commerce stores (Shopify, Salla, Amazon, Noon, WooCommerce, Zid) from a single unified dashboard.

## 🚀 Key Features

- **Unified Dashboard**: Real-time analytics and order monitoring across all platforms.
- **Global Inventory Sync**: Adjust stock levels once and push updates to every sales channel instantly.
- **Switchable Master Store**: Designate any store as your primary inventory source.
- **Multi-Tenant Architecture**: Secure data isolation for organizations and teams.
- **Cross-Platform Parity**: High-fidelity experiences on Web (Next.js) and Mobile (Flutter).

## 🛠️ Technology Stack

- **Backend**: Laravel 12 (PHP 8.3) + MySQL + Redis + Horizon
- **Web App**: Next.js 16 (React 19) + Tailwind CSS v4 + Zustand + Recharts, with an
  immersive WebGL landing page (React Three Fiber + GSAP + Lenis) and English/Arabic (RTL) i18n
- **Mobile App**: Flutter (Dio + GoRouter + Firebase Messaging)
- **Infrastructure**: Docker Compose + Nginx + GitHub Actions

## 📂 Project Structure

```text
HubbyGlobal/
├── backend/            # Laravel API
├── frontend/           # Next.js Dashboard
├── mobile/             # Flutter Application
├── docker/             # Docker configurations (Dev & Prod)
├── .github/            # CI/CD Workflows
└── deploy.sh           # VPS Deployment Script
```

## 💻 Local Development

### Prerequisites
- Docker & Docker Compose
- Flutter SDK (for mobile)

### Quick Start (Docker)
1. Clone the repository.
2. Run the **development** environment (note the explicit dev compose file — the
   root `docker-compose.yml` is the production stack):
   ```bash
   docker compose -f docker/docker-compose.yml up -d
   ```
3. The services will be available at:
   - App (single origin via nginx): `http://localhost:8000`
   - Frontend dev server (direct): `http://localhost:3000`

   > Use `http://localhost:8000` — it routes `/api` straight to the backend.
   > The `:3000` direct port proxies `/api` through the Next dev server (slower on Windows).

## 🚢 Deployment

See **[DEPLOYMENT.md](./DEPLOYMENT.md)** for the full first-time production guide
(aaPanel + Docker + domain + SSL). In short, on the server:

```bash
cp .env.production.example .env   # then edit secrets + APP_KEY
./deploy.sh                       # build, migrate, optimize, launch
```

Redeploys are just `./deploy.sh` again. For **push-to-deploy** (auto-deploy on
every push to `main`), follow **[CICD.md](./CICD.md)**.

## ✅ Verification

- **Backend Tests**: `php artisan test` (inside backend container)
- **Frontend Tests**: `npm run test` (inside frontend directory)
- **CI/CD**: every push to `main` runs frontend build + backend tests, then auto-deploys.

---
Developed by **Antigravity AI**
