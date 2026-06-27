# 🤖 HubbyGlobal — Auto-Deploy (CI/CD) Full Guide

Push to `main` → GitHub checks your code → if it passes, GitHub SSHes into your
server and runs `./deploy.sh` for you. No manual deploys.

This guide assumes you've already done a **manual first deploy** following
[DEPLOYMENT.md](./DEPLOYMENT.md) (the app already runs at https://hubbynetwork.com).

| Thing | Value |
|---|---|
| Server IP | `178.105.25.194` |
| Deploy folder on server | `/www/wwwroot/hubbynetwork` |
| Workflow file | `.github/workflows/deploy.yml` |

---

## 0. How the pipeline works

```
git push main
     │
     ▼
GitHub Actions
 ├── job: frontend   →  npm ci · lint · build         (must pass)
 ├── job: backend    →  composer install · PHPUnit     (must pass)
 └── job: deploy     →  SSH into server · run ./deploy.sh   (only on push to main)
                                │
                                ▼
                       Server: git pull · docker build · migrate · reload
```

- On a **pull request**, only the `frontend` and `backend` checks run (no deploy).
- On a **push to `main`**, checks run first; only if they pass does `deploy` run.
- The build is the hard gate — if `npm run build` breaks, nothing deploys.

There are **two separate SSH keys** involved. Don't mix them up:

| Key | Direction | Public half goes to | Private half goes to |
|---|---|---|---|
| **Deploy SSH key** | GitHub Actions → your server | server `~/.ssh/authorized_keys` | GitHub secret `SSH_PRIVATE_KEY` |
| **GitHub deploy key** | your server → GitHub (for `git pull`) | GitHub repo → Deploy keys | server `~/.ssh/` |

> If your repo is **public**, you can skip the "GitHub deploy key" (server pulls
> over HTTPS with no auth). If it's **private**, you need both.

---

## 1. Server → GitHub: let the server pull the code (private repos)

On the **server** (`ssh root@178.105.25.194`):

```bash
# 1) Make a read-only key the server will use to pull from GitHub
ssh-keygen -t ed25519 -C "server-pull@hubbynetwork" -f ~/.ssh/github_hubby -N ""

# 2) Show the PUBLIC key — copy the whole line
cat ~/.ssh/github_hubby.pub
```

In **GitHub → your repo → Settings → Deploy keys → Add deploy key**:
- Title: `hubby-server-pull`
- Key: paste the public key
- **Allow write access: OFF** (read-only is safer)

Tell the server's git to use that key for GitHub:

```bash
cat >> ~/.ssh/config <<'EOF'
Host github.com
  IdentityFile ~/.ssh/github_hubby
  IdentitiesOnly yes
EOF
chmod 600 ~/.ssh/config
```

Point the deploy folder at the SSH remote and verify a pull works:

```bash
cd /www/wwwroot/hubbynetwork
git remote set-url origin git@github.com:CHANGE_ME_ORG/HubbyGlobal.git
git pull origin main          # should succeed without asking for a password
```

> Public repo instead? Just `git remote set-url origin https://github.com/ORG/HubbyGlobal.git`
> and skip the key.

Make the deploy script executable (and remember it for git):

```bash
chmod +x deploy.sh
```

---

## 2. GitHub Actions → Server: let GitHub SSH in

Create a **dedicated** key for the GitHub runner to log into the server.
Run this **on the server**:

```bash
# Generate the key pair
ssh-keygen -t ed25519 -C "github-actions@hubbynetwork" -f ~/.ssh/gh_deploy -N ""

# Authorise its PUBLIC half so the runner can log in as this user
cat ~/.ssh/gh_deploy.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys

# Print the PRIVATE half — copy ALL of it (including the BEGIN/END lines)
cat ~/.ssh/gh_deploy
```

Keep that private key handy for the next step.

> **aaPanel SSH note:** aaPanel sometimes runs SSH on a non-standard port and may
> restrict root login. Confirm your SSH port (aaPanel → **Security**) and that key
> login is allowed. Use whatever user/port you normally SSH with.

---

## 3. Add the GitHub repository secrets

In **GitHub → your repo → Settings → Secrets and variables → Actions →
New repository secret**, add these five:

| Secret name | Value |
|---|---|
| `SERVER_HOST` | `178.105.25.194` |
| `SERVER_USER` | `root` *(or your SSH user)* |
| `SERVER_PORT` | `22` *(or your custom SSH port)* |
| `DEPLOY_PATH` | `/www/wwwroot/hubbynetwork` |
| `SSH_PRIVATE_KEY` | the **entire** private key from step 2 (`~/.ssh/gh_deploy`) |

> Paste the private key exactly as printed, including
> `-----BEGIN OPENSSH PRIVATE KEY-----` and `-----END OPENSSH PRIVATE KEY-----`.

---

## 4. Trigger your first auto-deploy

The workflow is already committed at `.github/workflows/deploy.yml`. Trigger it by
pushing any change to `main`:

```bash
git commit --allow-empty -m "ci: trigger first auto-deploy"
git push origin main
```

Then watch it: **GitHub → your repo → Actions tab**. You'll see the run with the
three jobs. When `deploy` turns green, the server has rebuilt and reloaded.

> First time you can also trigger manually if you add a `workflow_dispatch:` later,
> but a push to `main` is all you need.

---

## 5. Watch / confirm a deploy

- **In GitHub:** Actions tab → click the run → expand the `deploy` job to see the
  live output of `deploy.sh` (build, migrate, optimize).
- **On the server:**
  ```bash
  cd /www/wwwroot/hubbynetwork
  docker compose ps          # all services Up
  docker compose logs -f app # tail the API
  ```
- **In the browser:** hard-refresh https://hubbynetwork.com (Ctrl+Shift+R).

---

## 6. Rolling back a bad deploy

Everything is in git, so a rollback is just deploying an older commit.

**Option A — revert the bad commit (recommended, keeps history):**
```bash
# locally
git revert <bad_commit_sha>
git push origin main          # CI redeploys the reverted state automatically
```

**Option B — roll back directly on the server (fastest):**
```bash
ssh root@178.105.25.194
cd /www/wwwroot/hubbynetwork
git log --oneline -n 10                 # find the last good commit
git checkout <good_commit_sha>          # detach to the good code
docker compose up -d --build            # rebuild from it
docker compose exec -T app php artisan migrate --force
```
Then fix forward and push to `main` to get back onto the branch tip.

> ⚠️ Migrations don't auto-rollback. If a deploy added a destructive migration,
> roll it back with `docker compose exec app php artisan migrate:rollback` before
> reverting code. Take a DB backup before risky migrations (see DEPLOYMENT.md).

---

## 7. (Recommended) Protect the `main` branch

So nothing reaches production without passing checks:

**GitHub → Settings → Branches → Add branch ruleset** for `main`:
- ✅ Require a pull request before merging
- ✅ Require status checks to pass → select **Frontend (lint + build)** and
  **Backend (tests)**

Now every change goes through a PR that must build green before it can merge and
deploy.

---

## 8. Troubleshooting CI/CD

**`deploy` job fails: `ssh: handshake failed` / `permission denied`**
→ The `SSH_PRIVATE_KEY` secret is wrong/incomplete, or its public half isn't in the
server's `~/.ssh/authorized_keys`. Re-do step 2 and re-paste the full private key.
Also confirm `SERVER_PORT` matches your real SSH port.

**`deploy` job fails: `Host key verification failed`**
→ Usually fine with `appleboy/ssh-action`. If it persists, SSH into the server once
manually so the host is known, or this is a port/user mismatch.

**`deploy.sh` fails: `git pull` permission denied / asks for password**
→ Server can't read the repo. Re-do step 1 (GitHub deploy key + `~/.ssh/config`),
or switch the remote to HTTPS for a public repo.

**`deploy.sh` fails: `Permission denied: ./deploy.sh`**
→ `chmod +x deploy.sh` on the server. To store the exec bit in git so it survives
future clones: `git update-index --chmod=+x deploy.sh && git commit -m "chmod deploy.sh"`.

**`deploy.sh` fails: `APP_KEY is not set`**
→ The `.env` on the server is missing/incomplete. This file is **not** in git by
design — set it up once per the DEPLOYMENT.md (steps 4–5). CI never touches it.

**`frontend` job fails on build**
→ Real breakage — the same error you'd get from `cd frontend && npm run build`
locally. Fix it and push again; nothing deploys until it's green.

**`backend` job fails on `composer install`**
→ A dependency/PHP-extension issue. Check `composer.lock` is committed and matches
`composer.json`.

---

## 9. Security notes

- The deploy SSH key only grants what its server user can do — consider a
  dedicated deploy user instead of `root` for least privilege.
- The GitHub **deploy key** for pulling is **read-only** (write access OFF).
- Secrets live only in GitHub Actions secrets; they're never printed in logs.
- The production `.env` stays on the server, git-ignored, and is never sent to
  GitHub or baked into images.

---

That's it — from now on, **push to `main` and your site updates itself.** For the
underlying infrastructure (Docker, nginx, SSL, env), see
[DEPLOYMENT.md](./DEPLOYMENT.md).
