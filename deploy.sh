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
# untouched. Fails loudly if it can't fetch instead of deploying old code. ------
if [ -d .git ]; then
  echo "📥 Syncing to origin/main..."
  git fetch origin main
  git reset --hard origin/main
  echo "   now at: $(git rev-parse --short HEAD) $(git log -1 --pretty=%s)"
fi

# --- Build & start -------------------------------------------------------------
echo "🐳 Building and starting containers..."
docker compose up -d --build --remove-orphans

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

echo "⚡ Caching config/routes/views..."
docker compose exec -T app php artisan optimize

echo "🔁 Restarting Horizon workers..."
docker compose exec -T app php artisan horizon:terminate || true

echo "✅ Deployment successful!  →  https://hubbynetwork.com"
