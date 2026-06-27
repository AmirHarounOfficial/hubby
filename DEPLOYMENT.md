# 🚀 HubbyGlobal — Deployment Guide (aaPanel + Docker)

A complete, first-time walkthrough for deploying HubbyGlobal to your server and
serving it at **https://hubbynetwork.com**.

| Thing | Value |
|---|---|
| Domain | `hubbynetwork.com` (registered at Namecheap) |
| Server IP | `178.105.25.194` |
| Server panel | aaPanel (with the Docker app installed) |
| Stack | Laravel 12 API + Next.js frontend + MySQL + Redis + Horizon |

> You can copy‑paste every command. Anything in `CHANGE_ME` style you must replace.

---

## 0. How it fits together (read once)

Everything runs in Docker containers. **One** small nginx container is the entry
point and splits traffic:

```
Browser ──HTTPS──▶ aaPanel nginx (port 443, SSL)
                        │  reverse proxy
                        ▼
              Docker nginx  (127.0.0.1:8001)
                 ├── "/"      ▶ Next.js frontend  (frontend:3000)
                 └── "/api"   ▶ Laravel API       (app:9000, php-fpm)
                                     ├── MySQL  (mysql:3306)
                                     └── Redis  (redis:6379)  + Horizon + Scheduler
```

- The frontend and API share **one domain**, so there are **no CORS problems**.
- The Docker stack only listens on `127.0.0.1:8001` (not public). **aaPanel**
  owns ports 80/443, terminates SSL, and forwards to `8001`.

---

## 1. Point the domain at the server (Namecheap)

1. Log in to **Namecheap → Domain List → `hubbynetwork.com` → Manage**.
2. Open the **Advanced DNS** tab.
3. Delete any default "parking" / CNAME records, then add these two **A records**:

   | Type | Host | Value | TTL |
   |------|------|-----------------|-----------|
   | A    | `@`  | `178.105.25.194` | Automatic |
   | A    | `www`| `178.105.25.194` | Automatic |

4. Save. DNS can take 5 minutes to a few hours to propagate. Check with:
   ```bash
   nslookup hubbynetwork.com
   ```
   When it returns `178.105.25.194`, you're good.

---

## 2. Prepare the server

SSH into the server:

```bash
ssh root@178.105.25.194
```

Confirm Docker + Docker Compose v2 are installed (the aaPanel **Docker** app
installs these):

```bash
docker --version
docker compose version
```

If `docker compose version` fails, install the plugin from aaPanel’s **App
Store → Docker**, or:
```bash
apt-get update && apt-get install -y docker-compose-plugin
```

**Open the firewall** (aaPanel → **Security**) so these ports are allowed:
`22` (SSH), `80` (HTTP), `443` (HTTPS). Do **not** expose `8001` — it stays private.

---

## 3. Get the code onto the server

Pick a folder. We'll use `/www/wwwroot/hubbynetwork`:

```bash
mkdir -p /www/wwwroot/hubbynetwork
cd /www/wwwroot/hubbynetwork
```

**Option A — Git (recommended).** Replace with your repo URL:
```bash
git clone https://github.com/CHANGE_ME/HubbyGlobal.git .
```

**Option B — Upload.** Use aaPanel **Files** (or `scp`) to upload the project so
that `docker-compose.yml` sits directly in `/www/wwwroot/hubbynetwork/`.

> ✅ After this step, `ls` should show `docker-compose.yml`, `backend/`,
> `frontend/`, `docker/`, `deploy.sh`, `.env.production.example`.

---

## 4. Create the environment file

```bash
cp .env.production.example .env
nano .env
```

Fill these in (leave the rest as-is for now):

- `DB_PASSWORD` → a strong password (used for MySQL **and** the app).
- `MAIL_*` → your SMTP details (needed for email verification / password resets).
- Leave `APP_KEY` **empty for now** — we generate it in the next step.

Save and exit nano: `Ctrl+O`, `Enter`, `Ctrl+X`.

---

## 5. Build images and generate the app key

Build the containers (first build downloads PHP/Node images and runs
`composer install` + `npm run build` — give it a few minutes):

```bash
docker compose build
```

Generate the Laravel application key and copy the output:

```bash
docker compose run --rm app php artisan key:generate --show
```

You'll get something like `base64:abc123...`. Put it into `.env`:

```bash
nano .env
# set:  APP_KEY=base64:abc123...
```

---

## 6. First launch

Run the deploy script. It starts everything, waits for the database, links
storage, runs migrations, and caches config:

```bash
chmod +x deploy.sh
./deploy.sh
```

Check all containers are healthy:

```bash
docker compose ps
```

You should see `nginx`, `app`, `frontend`, `mysql`, `redis`, `horizon`,
`scheduler` all **Up**. Quick local smoke test (still on the server):

```bash
curl -I http://127.0.0.1:8001            # frontend → expect HTTP/1.1 200
curl -i http://127.0.0.1:8001/api/login  # API → expect a JSON 405 (Method Not Allowed), NOT an HTML 404
```

If both respond, the stack is working — now we put a domain + SSL in front of it.

---

## 7. Reverse proxy in aaPanel (domain → container)

1. aaPanel → **Website → Add site**.
   - **Domain**: `hubbynetwork.com` and on a new line `www.hubbynetwork.com`
   - Database: **No**, FTP: **No**, PHP version: **Pure static** (any is fine —
     we only use it as a proxy).
   - Click **Submit**.
2. Click the new site → **Reverse Proxy → Add reverse proxy**:
   - **Proxy Name**: `hubby`
   - **Target URL**: `http://127.0.0.1:8001`
   - **Send Domain**: `$host`
   - Save.

That makes aaPanel forward all traffic for the domain to the Docker nginx, which
then routes `/` and `/api` correctly.

> aaPanel’s reverse proxy already forwards WebSocket upgrade headers, so Next.js
> works without extra config.

---

## 8. Enable HTTPS (free SSL)

1. In the same site, open the **SSL** tab.
2. Choose **Let's Encrypt**, tick both `hubbynetwork.com` and `www.hubbynetwork.com`,
   and **Apply**. (DNS from step 1 must already point here.)
3. Turn on **Force HTTPS**.

Your site is now live at **https://hubbynetwork.com** 🎉

---

## 9. Verify

- Visit `https://hubbynetwork.com` → the animated landing page loads, the 3D
  shapes appear, and the language switcher toggles English/العربية.
- Visit `https://hubbynetwork.com/register` → create an account → you should be
  taken into onboarding/dashboard. (This confirms the API + DB are wired.)
- `https://hubbynetwork.com/login` → sign in.

---

## 🔄 Redeploying after code changes

From the project folder on the server:

```bash
cd /www/wwwroot/hubbynetwork
./deploy.sh
```

That pulls the latest `main`, rebuilds, migrates, and reloads workers. Zero extra
steps.

> 🤖 **Prefer push-to-deploy?** Set up automatic deploys on every push to `main`
> with **[CICD.md](./CICD.md)** — a full GitHub Actions guide (SSH keys, secrets,
> rollback, branch protection). The workflow lives at `.github/workflows/deploy.yml`.

---

## 🛠️ Useful commands

```bash
# View logs (all, or one service)
docker compose logs -f
docker compose logs -f app
docker compose logs -f frontend

# Open a shell in the API container
docker compose exec app bash

# Re-run migrations / clear caches manually
docker compose exec app php artisan migrate --force
docker compose exec app php artisan optimize:clear

# Restart everything
docker compose restart

# Stop / start the whole stack
docker compose down
docker compose up -d
```

---

## 💾 Database backups

Quick manual backup (run on the server):

```bash
docker compose exec -T mysql mysqldump -u root -p"$(grep ^DB_PASSWORD .env | cut -d= -f2)" hubby_global > backup_$(date +%F).sql
```

Restore:

```bash
cat backup_2026-06-17.sql | docker compose exec -T mysql mysql -u root -p"$(grep ^DB_PASSWORD .env | cut -d= -f2)" hubby_global
```

For automatic backups, schedule the dump above via aaPanel **Cron**.

---

## 🧯 Troubleshooting

**Site shows aaPanel default page, not the app**
→ The reverse proxy isn’t active or the domain isn’t pointing here yet. Re-check
step 7 and `nslookup hubbynetwork.com`.

**502 Bad Gateway**
→ The Docker stack isn’t up or `8001` isn’t responding. Run `docker compose ps`
and `curl -I http://127.0.0.1:8001`. Check `docker compose logs nginx frontend`.

**API returns 500 / "No application encryption key"**
→ `APP_KEY` is missing in `.env`. Re-do step 5, then `./deploy.sh`.

**Database connection refused / migrations fail**
→ MySQL is still starting on first boot. Wait ~30s and re-run `./deploy.sh`
(it waits for the DB). Confirm `DB_PASSWORD` in `.env` matches and check
`docker compose logs mysql`.

**Port 8001 already in use**
→ Something else uses it. Edit `docker-compose.yml` (`"127.0.0.1:8001:80"`) and
the aaPanel proxy target to a free port like `8011`, then `./deploy.sh`.

**Frontend changed but old version still shows**
→ Hard refresh (Ctrl+Shift+R). The image is rebuilt by `./deploy.sh`; if needed
force it: `docker compose build --no-cache frontend && docker compose up -d`.

**Uploaded product images 404 (`/storage/...`)**
→ Create the public symlink on the host once:
```bash
ln -s ../storage/app/public backend/public/storage
```

---

## 🔐 Security notes

- `.env` holds all secrets — it is git‑ignored and must **never** be committed.
- Only ports `80`/`443` (and `22`) are public; MySQL, Redis and the app are not
  reachable from the internet.
- Set `APP_DEBUG=false` in production (already the default in the template).
- Change the default `DB_PASSWORD` to something strong.
- Keep the server updated: aaPanel handles OS/panel updates; rebuild images
  periodically with `docker compose build --pull`.

---

Need to change the domain later? Update the A records (step 1), the aaPanel site
domain + SSL (steps 7–8), and `APP_URL` / `FRONTEND_URL` in `.env`, then `./deploy.sh`.
