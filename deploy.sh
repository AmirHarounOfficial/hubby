#!/bin/bash
# =============================================================================
# HubbyGlobal — deploy / redeploy script
# Safe to run for the FIRST deploy and every redeploy after. Run from the repo
# root (the folder containing docker-compose.yml and your filled-in .env).
#
#   ./deploy.sh
# =============================================================================
set -euo pipefail

cd "$(dirname "$0")"

echo "🚀 HubbyGlobal deployment starting..."

# --- Sanity checks -------------------------------------------------------------
if [ ! -f .env ]; then
  echo "❌ .env not found. Copy it first:  cp .env.production.example .env  (then edit it)"
  exit 1
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  echo "❌ APP_KEY is not set in .env."
  echo "   Build the images first, then run:"
  echo "     docker compose run --rm app php artisan key:generate --show"
  echo "   and paste the value into APP_KEY= in .env, then re-run ./deploy.sh"
  exit 1
fi

# --- Sync to the latest origin/main (force, so a stuck/detached/diverged repo
# can never silently rebuild stale code). .env and other ignored files are left
# untouched. Fails LOUDLY instead of ever building stale code silently. ---------
echo "📂 Deploy directory: $(pwd)"
if [ ! -d .git ]; then
  echo "❌ $(pwd) is not a git checkout — deploy.sh cannot sync new code here."
  echo "   The site is likely served from a DIFFERENT directory than the one"
  echo "   GitHub Actions deploys into. Fix DEPLOY_PATH (or re-clone here)."
  exit 1
fi
echo "📥 Syncing to origin/main..."
echo "   remote: $(git remote get-url origin)"
git fetch origin main
git reset --hard origin/main
HEAD_SHA="$(git rev-parse HEAD)"
ORIGIN_SHA="$(git rev-parse origin/main)"
if [ "$HEAD_SHA" != "$ORIGIN_SHA" ]; then
  echo "❌ After reset, HEAD ($HEAD_SHA) != origin/main ($ORIGIN_SHA). Aborting."
  exit 1
fi
echo "   now at: $(git rev-parse --short HEAD) $(git log -1 --pretty=%s)"

# --- Build & start -------------------------------------------------------------
# Stamp the current commit into the build so the frontend image cache is busted
# on every deploy (a green deploy must never silently ship a stale image), and
# force-recreate so containers always adopt the freshly built image.
export GIT_SHA="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
echo "🐳 Building and starting containers (GIT_SHA=$GIT_SHA)..."
docker compose up -d --build --force-recreate --remove-orphans

# --- Wait for the database to accept connections -------------------------------
# Plain PDO connection test (works even before the migrations table exists).
echo "⏳ Waiting for the database..."
DB_READY=0
for i in $(seq 1 40); do
  if docker compose exec -T app php -r '
      try { new PDO("mysql:host=".getenv("DB_HOST").";port=".getenv("DB_PORT"), getenv("DB_USERNAME"), getenv("DB_PASSWORD")); }
      catch (Throwable $e) { exit(1); }' >/dev/null 2>&1; then
    DB_READY=1
    break
  fi
  sleep 3
done
if [ "$DB_READY" -ne 1 ]; then
  echo "❌ Database did not become ready in time. Check: docker compose logs mysql"
  exit 1
fi

# --- Laravel setup (all idempotent) --------------------------------------------
echo "🔗 Linking storage..."
docker compose exec -T app php artisan storage:link || true

echo "🗄️  Running migrations..."
docker compose exec -T app php artisan migrate --force

# Seed the Profit & Loss demo data. Runs once — the seeder no-ops if its PFD-* orders already
# exist, so repeat deploys don't duplicate data. Scoped to this one seeder on purpose (the full
# db:seed would also run RealDataSeeder, which wipes all orders on every run).
echo "🌱 Seeding profit demo data (first run only)..."
docker compose exec -T app php artisan db:seed --class="Database\\Seeders\\ProfitDemoSeeder" --force || true

# Reference data (idempotent, safe on every deploy): carrier status vocab + city aliases (spec 04).
echo "🌱 Seeding shipping reference data..."
docker compose exec -T app php artisan db:seed --class="Database\\Seeders\\CarrierStatusMapSeeder" --force || true
docker compose exec -T app php artisan db:seed --class="Database\\Seeders\\CityAliasSeeder" --force || true

echo "⚡ Caching config/routes/views..."
docker compose exec -T app php artisan optimize

echo "🔁 Restarting Horizon workers..."
docker compose exec -T app php artisan horizon:terminate || true

# --- Post-deploy self-check ----------------------------------------------------
# Prove the freshly built frontend container is actually serving current code.
# A brand-new asset (public/platforms/shopify.svg) must resolve; if it 404s, the
# container is running stale code and the "green" deploy is a lie — fail loudly.
echo "🔎 Verifying the frontend is serving current code..."
FE_OK=0
for i in $(seq 1 10); do
  CODE="$(docker compose exec -T frontend node -e '
    fetch("http://127.0.0.1:3000/platforms/shopify.svg")
      .then(r => { process.stdout.write(String(r.status)); process.exit(0); })
      .catch(() => { process.stdout.write("000"); process.exit(0); });' 2>/dev/null || echo 000)"
  if [ "$CODE" = "200" ]; then FE_OK=1; break; fi
  sleep 3
done
if [ "$FE_OK" -ne 1 ]; then
  echo "❌ Frontend is serving STALE code (platforms/shopify.svg not found)."
  echo "   The image did not rebuild from current source. Investigate the build"
  echo "   context / cache, then rerun. GIT_SHA built = $GIT_SHA"
  exit 1
fi
echo "   frontend OK (serving current build, GIT_SHA=$GIT_SHA)"

echo "✅ Deployment successful!  →  https://hubbynetwork.com"
