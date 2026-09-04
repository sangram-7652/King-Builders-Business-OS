# Deployment — King Builders Business OS (M12)

Production runs the **same images** as dev, with the `docker-compose.prod.yml`
overlay. TLS terminates at the `nginx` container; PHP-FPM, MySQL 8, Redis 7, a
queue worker and the scheduler run alongside it. The code is **baked into the
image** — a deploy is an image swap, never a file sync.

```
docker compose -f docker-compose.yml -f docker-compose.prod.yml …
```

Set it once per shell:

```bash
export COMPOSE_FILE=docker-compose.yml:docker-compose.prod.yml
alias dcp='docker compose'
```

Requires Docker Engine ≥ 25 / Compose ≥ 2.24.

---

## 1. First deploy (new host)

See [`DISASTER-RECOVERY.md` § New server](DISASTER-RECOVERY.md#3-rebuild-on-a-fresh-server)
for the full checklist. Short version:

```bash
git clone <repo> /opt/king-builders && cd /opt/king-builders
git checkout <release-tag>

cp .env.production.example .env
$EDITOR .env                       # fill every CHANGE-ME (APP_URL, all passwords, mail…)

mkdir -p docker/nginx/certs
#  put fullchain.pem + privkey.pem in docker/nginx/certs/
#  (Let's Encrypt: symlink /etc/letsencrypt/live/<domain>/{fullchain,privkey}.pem)

export APP_IMAGE_TAG=$(git rev-parse --short HEAD)
dcp build app && dcp build nginx

dcp run --rm app php artisan key:generate --force        # writes APP_KEY into .env
dcp up -d mysql redis
dcp run --rm app php artisan migrate --force

# Super-admin bootstrap (ONE TIME). The seeder ABORTS in production unless
# SEED_SUPERADMIN_PASSWORD (>= 12 chars) is set — it never creates the account
# with a known default. Optionally also set SEED_SUPERADMIN_EMAIL / _NAME.
SEED_SUPERADMIN_PASSWORD='<a long random string>' \
  dcp run --rm -e SEED_SUPERADMIN_PASSWORD app php artisan db:seed --force

dcp run --rm app php artisan optimize
dcp run --rm app php artisan storage:link

dcp up -d
./docker/smoke.sh https://erp.example.com
```

Store that password in your secrets manager and **unset `SEED_SUPERADMIN_PASSWORD`
from the environment** once the account exists (re-seeding never rewrites an
existing user's password). Sign in as `SEED_SUPERADMIN_EMAIL`
(default `super@kingbuilders.test`) and, ideally, create a real named admin and
retire the bootstrap account.

---

## 2. Routine deploy

```bash
cd /opt/king-builders
./docker/deploy.sh <git-ref>          # default: origin/main
```

`deploy.sh` runs, in order:

| Step | What | Safety |
|---|---|---|
| code | `git fetch` + `checkout --force <ref>` | pinned ref, not a moving branch in prod |
| backup | `./docker/backup/backup.sh` | **pre-deploy** DB + document backup |
| build | `dcp build app` then `dcp build nginx` | assets compiled into the app image; nginx copies them |
| migrate | `artisan down` → `up -d app` → `migrate --force --isolated` | `--isolated` = only one node migrates; maintenance page for writers |
| optimise | `artisan optimize` (config/route/event/view cache) + `storage:link` | |
| fleet | `dcp up -d` → `queue:restart` → `artisan up` | workers pick up new code via graceful restart |
| verify | `health:check` loop, then `./docker/smoke.sh` | non-zero exit aborts the deploy visibly |

Zero-downtime is **not** claimed — there is a short maintenance window during
`migrate`. For a schema-only change with additive migrations you can skip
`artisan down` (edit the script or run the steps by hand).

### Production-safe artisan commands used

`migrate --force --isolated`, `optimize`, `optimize:clear`, `queue:restart`,
`storage:link`, `config:cache` (via `optimize`), `down`/`up`, `health:check`.
Never `migrate:fresh`, `migrate:refresh`, `db:wipe`, or `db:seed` (after the
first deploy) in production.

---

## 3. Rollback

Rollback is **not symmetric with deploy** — the database moved forward.

### 3a. Code only (safe when the release added only *additive* migrations)

New columns/tables/indexes that the previous code simply ignores:

```bash
./docker/deploy.sh <previous-tag>        # re-deploy the old image; leave the DB as-is
```

The `deploy.sh` migrate step is a no-op (older migration set is a subset).

### 3b. Code + schema (a migration dropped/renamed a column, changed a type, backfilled data)

`php artisan migrate:rollback` is **only safe if every `down()` in the release
is real and lossless** — many are not (a dropped column's data is gone). Treat
schema-breaking releases as forward-only and, if they must be undone:

```bash
dcp exec app php artisan down
./docker/backup/restore.sh /opt/king-builders/backups/kb-backup-<pre-deploy-stamp>.tar
./docker/deploy.sh <previous-tag>
dcp exec app php artisan up
```

i.e. **restore the pre-deploy backup** `deploy.sh` took, then re-deploy the old
code. Data written between the deploy and the rollback is lost — quantify it
from the app logs before deciding.

### 3c. Worker / config only

```bash
dcp exec app php artisan optimize:clear && dcp exec app php artisan optimize
dcp exec app php artisan queue:restart
dcp restart queue scheduler
```

### Rollback decision table

| Release contained | Rollback |
|---|---|
| code + additive migrations only | 3a — re-deploy old image |
| new config / worker flags | 3c |
| a destructive / data-migrating migration | 3b — restore pre-deploy backup |
| a bad data write (bug, not schema) | restore backup into scratch, extract the good rows, patch forward |

---

## 4. Health checks

| Endpoint | Purpose | Checks |
|---|---|---|
| `GET /up` | liveness (Laravel built-in) | the framework boots |
| `GET /healthz` | readiness | DB `SELECT 1`, cache round-trip, private disk read/write — returns `200 {"status":"ok"}` or `503 {"status":"degraded",…}`, **no** host/credential/version/path |
| `php artisan health:check` | container `HEALTHCHECK` for app/queue | same three probes, exit 0/1 |

Wire the load balancer / uptime monitor to `GET /healthz` (expect 200).
`docker compose ps` shows `(healthy)` once the container healthchecks pass.

---

## 5. Smoke test

`./docker/smoke.sh <base-url>` — read-only, unauthenticated. Verifies:

- `/up` + `/healthz` are 200
- `/login` renders
- every authenticated area **and every export format** redirects a guest to
  `/login` (302) — never 200 or 500
- a document download URL is not reachable anonymously
- the security headers are present
- HTTP → HTTPS redirect (when the URL is `https://`)

The full authenticated workflow (lead → … → reports) is covered by the Pest
suite (`docker compose exec app php artisan test`), which must be green before
tagging a release.

---

## 6. Post-deploy checklist

- [ ] `dcp ps` — all 6 services `Up` / `(healthy)`
- [ ] `dcp exec app php artisan about` — env=production, debug=false, cached config/routes/events
- [ ] `dcp exec app php artisan migrate:status` — nothing pending
- [ ] `./docker/smoke.sh https://…` — PASS
- [ ] `dcp logs --since 5m app | grep -iE 'error|exception'` — clean
- [ ] `dcp exec app php artisan queue:failed` — empty (or expected)
- [ ] SSL: `curl -sI https://… | grep -i strict-transport-security`
