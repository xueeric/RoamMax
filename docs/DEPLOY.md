# Deploy to production (roammax.ca)

Use this runbook when the user asks to **push to live**, **deploy**, **go live**, or **push the update to live**.

The agent should **run the steps below** — do not only describe them.

## Production stack

| Piece | Location |
| --- | --- |
| Live site | https://roammax.ca |
| App on VPS | `/opt/stack/websites/starlink/` |
| Nginx docroot | `.../starlink/public/` |
| Secrets / DB | VPS only — `.env` and `data/*.db` are **never** uploaded |
| Deploy script | `./deploy-sj.sh` (repo root) |
| Source synced | `website/` → VPS app path |

Traffic path: **Cloudflare** → `cloudflared-roammax` → **Nginx** (`127.0.0.1:8081`) → **PHP 8.4** (Docker).

## Agent checklist

### 1. Pre-flight

From the repo root:

```bash
test -f deploy-sj.sh && test -d website && test -f website/.deployignore
test -f "$HOME/.ssh/vpssj.pem"
```

If `website/.deployignore` is missing, copy from VPS:

```bash
scp -i ~/.ssh/vpssj.pem -P 53816 \
  eric@10.10.0.100:/opt/stack/websites/starlink/.deployignore \
  website/.deployignore
```

Confirm VPN/network reachability (SSH must work):

```bash
ssh -i ~/.ssh/vpssj.pem -o BatchMode=yes -p 53816 eric@10.10.0.100 "echo ok"
```

### 2. Optional local checks

If PHP code or schema changed in this session, run before deploy:

```bash
cd website && php bin/migrate.php
cd .. && php dev/bin/self-test.php
```

Skip if the user only wants a quick content/config push and tests were already run.

### 3. Version bump (when app code changed)

If `website/` PHP, templates, or public assets changed, bump `app_version` in `website/version.php` and set `released` to today’s date. Skip for docs-only changes outside `website/`.

### 4. Deploy

```bash
./deploy-sj.sh
```

The script:

1. `rsync`s `website/` to `eric@10.10.0.100:/opt/stack/websites/starlink/`
2. Excludes `.env`, SQLite files, and logs (via `.deployignore` + `data/`)
3. Fixes `data/` permissions for `www-data`
4. Runs `php bin/migrate.php` on the server

Overrides (only if needed):

```bash
DEPLOY_KEY=/path/to/key.pem ./deploy-sj.sh
DEPLOY_SRC=/path/to/website/ ./deploy-sj.sh
```

### 5. Verify live

```bash
curl -sI https://roammax.ca/ | head -5
curl -sI https://roammax.ca/book | head -5
```

Expect HTTP responses from the app (not connection errors). Report status codes and any errors.

### 6. Report to user

Summarize:

- Deploy succeeded or failed
- What was synced (high level)
- Migration output if relevant
- Live URL check results

## Do not

- Upload or overwrite production `.env`
- Upload or overwrite `data/*.db` (production bookings live there)
- Restart Docker unless deploy failed and containers are down
- Use `dev/bin/cloudflare-*` scripts for production (those are for **dev.roammax.ca** local tunnel)

## Manual / first-time VPS setup

Only if production is broken or being rebuilt:

```bash
# On VPS
cd /opt/stack/websites && sudo docker compose up -d
cd /opt/stack/cloudflared-roammax && sudo docker compose up -d
```

Production `.env` lives at `/opt/stack/websites/starlink/.env`. Square webhook: `https://roammax.ca/webhooks/square`.

## Repo location

`deploy-sj.sh` resolves paths from its own directory. Moving the repo (e.g. into Synology Drive) does **not** break deploy as long as `website/` stays next to `deploy-sj.sh`.
