# Production Readiness — King Builders Business OS (M12)

The M12 audit of the M1–M11 system. Nothing here changes business logic; the
fixes are infrastructure, response headers, health/observability and docs.

---

## 1. Environment

| Setting | Dev | Production (`.env.production.example`) |
|---|---|---|
| `APP_ENV` | `local` | **`production`** |
| `APP_DEBUG` | `true` | **`false`** |
| `APP_URL` | `http://localhost:8080` | **`https://<domain>`** |
| `APP_KEY` | committed dev key | generated per-host, kept in a secret store |
| `LOG_LEVEL` / `LOG_STACK` | `debug` / `single` | **`warning` / `daily`** (30-day retention) |
| `SESSION_DRIVER` | `redis` | `redis` (`SESSION_ENCRYPT=true`, `SESSION_SECURE_COOKIE=true`) |
| `CACHE_STORE` / `QUEUE_CONNECTION` | `redis` | `redis` (password-protected, `noeviction`) |
| DB / Redis passwords | `secret` / none | **required, no default** (`?` in compose) |
| DB / Redis host ports | published (`3307` / `6379`) | **not published** — private network only |
| PHP `display_errors` | `On` | **`Off`** (`docker/php/production.ini`) |
| PHP `opcache.validate_timestamps` | `1` | **`0`** + JIT (`docker/php/production.ini`) |
| Source in container | bind-mounted `./` | **baked into the image** (`app-storage` volume for `storage/app`) |

`config/app.php` already defaults `debug`→`false` and `env`→`production` when the
var is missing; `AppServiceProvider` already `URL::forceScheme('https')` and
relaxes `Model::shouldBeStrict()` in production.

## 2. Docker

- `docker-compose.yml` = dev; **`docker-compose.prod.yml`** overlay adds restart
  policies, healthchecks, named volumes, secret enforcement, prod PHP ini, and
  disables `vite`.
- `queue` + `scheduler` already had `restart: unless-stopped`; the overlay adds
  it to `app` + `nginx`.
- Persistent volumes: `mysql-data`, `redis-data` (AOF), **`app-storage`**
  (M9/M10 documents — survives an image swap), `app-logs`.
- Restart behaviour verified: `docker compose restart` / host reboot → all
  services return; DB + documents intact (volumes); Redis AOF replays.

## 3. Backups — `docker/backup/`

- `backup.sh` → single archive of `mysqldump` + `storage/app` tar + manifest +
  sha256. Retention, optional GPG-at-rest, optional S3 off-site. Non-zero exit
  on failure.
- `restore.sh` → checksum-verified restore, **`--into-scratch`** for a
  non-destructive monthly restore test.
- Cron: daily 02:30 (see `DISASTER-RECOVERY.md`).
- **A DB dump alone is not a backup** — documents are on disk, referenced by
  path. Both halves are in every archive.

## 4. Disaster recovery

`docs/DISASTER-RECOVERY.md` — backup, monthly restore test, full restore,
fresh-server rebuild (RTO ~30–45 min, RPO ≤ backup interval), scenario table.
**`APP_KEY` must be preserved** across a rebuild (encrypted KYC columns).

## 5. Queue & scheduler

- Worker: `queue:work redis --tries=3 --backoff=5 --max-time=3600` (recycles
  hourly to avoid leaks). `restart: unless-stopped`.
- Failed jobs → `failed_jobs` table; inspect `queue:failed`, retry
  `queue:retry`. **New (M12):** `queue:prune-failed --hours=168` scheduled daily.
- Scheduler tasks are all idempotent + `withoutOverlapping` + `ShouldBeUnique`
  jobs: `expire-plot-holds` (5 min), `refresh-collection-queue` (01:30),
  `expire-documents` (02:00), `queue:prune-batches` + `queue:prune-failed`
  (daily), a 15-min heartbeat. **No new scheduled business feature added.**
- `queue:restart` is issued on every deploy so workers pick up new code.

## 6. Storage security

- Three disks (`config/filesystems.php`): `public` (web-served), `local`
  (private, `storage/app/private`), **`documents`** (`storage/app/private/documents`,
  `serve:false`, `visibility:private`, **never web-served**).
- The **only** path to a document file is
  `DocumentDownloadController` → `Gate::authorize('download', $document)` +
  `abort_unless($version->document_id === $document->id, 404)` +
  `abort_unless($disk->exists(...), 404)`. IDOR on the id → 403/404.
- nginx `production.conf` additionally denies `^/(app|bootstrap|config|database|
  resources|routes|storage/(?!app/public)|tests|vendor)/` and any
  `.php/.env/.sql/.log` under `/storage/`.
- Uploads: `['required','file','max:'.DOCUMENT_MAX_KB,'mimes:pdf,jpg,jpeg,png,webp,tiff,heic']`
  — extension allowlist (no executables), real-MIME check, 15 MB cap.
  `DocumentStorage` writes with a random 40-char filename on the private disk;
  the client filename is metadata only. `sha256` stored per version.

## 7. HTTPS

- **New (M12):** `bootstrap/app.php` `trustProxies(at: TRUSTED_PROXIES ?? '*',
  headers: X-Forwarded-For/Host/Port/Proto/AWS-ELB)` — the app now learns the
  real scheme behind nginx / an LB, so `secure` cookies + `$request->secure()`
  work.
- nginx `production.conf`: `listen 80` → `301 https://` (ACME + `/up` + `/healthz`
  excepted); `listen 443 ssl http2`, TLS 1.2/1.3, modern cipher suite, OCSP
  session cache, `fastcgi_param HTTPS on`.
- `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true`, `same_site=lax`,
  `http_only=true` in the prod template. `session.use_strict_mode=1` in prod ini.
- HSTS is emitted by `SecurityHeaders` **only** over a real HTTPS request in
  production (`max-age=31536000; includeSubDomains`) — never on `http://` or
  localhost.

## 8. Security headers — `App\Http\Middleware\SecurityHeaders`

Appended to the `web` group. On every response:

`X-Content-Type-Options: nosniff` · `X-Frame-Options: SAMEORIGIN` ·
`Referrer-Policy: strict-origin-when-cross-origin` · `Permissions-Policy` (deny
camera/mic/geo/usb/…) · `Cross-Origin-Opener-Policy: same-origin` ·
`Cross-Origin-Resource-Policy: same-origin` ·
`X-Permitted-Cross-Domain-Policies: none` · removes `X-Powered-By` / `Server`.

**CSP:** `default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval';
style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; object-src 'none';
frame-ancestors 'self'; base-uri 'self'; form-action 'self'`.
`'unsafe-inline'`/`'unsafe-eval'` are required by Livewire 3 + Alpine + Tailwind
v4 and are a deliberate trade-off — it still blocks the high-value vector
(loading script/style/frame from an external origin). Override with
`SECURITY_HEADERS_CSP` or disable everything with `SECURITY_HEADERS_ENABLED=false`
(env, no code deploy).

## 9. Authentication

Login (rate-limited 5/attempt, `session()->regenerate()` on success, rejects
inactive users), logout (`invalidate()` + `regenerateToken()`),
`EnsureUserIsActive` middleware (kills a session whose user was deactivated
mid-session), password reset (`expire:60`, `throttle:60`), `BCRYPT_ROUNDS=12`.
**No change needed.**

## 10. RBAC / authorization

`spatie/laravel-permission`; every route gated by `permission:<Permission enum>`;
one `Gate::before` super-admin bypass; per-model policies; `leads.view_all` is
the single scoping flag. `App\Enums\Permission` is the source of truth — M12
**adds no permission**. Per-module authorization tests already exist
(`tests/Feature/*/​*AuthorizationTest.php`, `*SecurityTest.php`).

## 11. Tenant isolation / IDOR

**Single-tenant install** — there is no `tenant_id` column anywhere. "Isolation"
= RBAC + route-model-binding 404 + policy 403 + referential validation
(`Rule::exists`, block-belongs-to-project, scoped-salesperson lock in the
reporting layer). Reporting/exports carry **no** object-id URL parameter — only
validated filter ids. `tests/Feature/Production/ProductionHardeningTest.php`
adds a consolidated IDOR/guest-gating sweep across every module + export.
See `docs/reporting-m11` for the reporting-specific analysis.

## 12. Code security audit

- `dd/dump/var_dump/print_r/ray` in `app/`, `routes/`, `config/` → **none**.
- Hardcoded secrets/tokens/passwords in code → **none** (the only literal
  credentials are the *dev* `docker-compose.yml`; prod requires env).
- Mass assignment: models use explicit `$fillable`; `Model::shouldBeStrict` in
  dev catches regressions.
- Validation: Livewire `rules()` / FormRequest on every write path.
- SQL injection: query builder + bound `selectRaw`/`whereRaw` params throughout
  the reporting layer (audited in M11.6); no string interpolation into SQL.
- XSS: Blade `{{ }}` auto-escapes; no unescaped `{!! !!}` on user data.
- CSRF: Laravel `web` group; all writes are POST/Livewire (token-checked).
- Path traversal: uploads via `Storage::putFileAs` with a generated name; the
  download controller checks `document_id` ownership and `$disk->exists`.
- Open redirect: `redirectGuestsTo` → named route; no user-controlled redirect
  targets.

## 13. Database integrity

FKs on every relation (`constrained()` + `restrictOnDelete`/`cascadeOnDelete`
per intent). Unique business identifiers enforced at the DB:
`bookings.booking_number`, `payments.payment_number`, `payments.idempotency_key`,
`receipts.receipt_number`, `registry_cases.case_number` (+ `booking_id` unique),
`possession_cases.case_number`, `transfer_requests.request_number`,
`installments (payment_plan_id, installment_number)`,
`booking_buyers (booking_id, buyer_id)`, `plots (project_id, block_id, plot_number)`,
generated `active_plot_id` / `active_plan_booking_id` columns for the
"one live … per …" invariant. Ownership history is append-only
(`plot_ownership_history`, no update path).

## 14. Logs

`LOG_LEVEL=warning` in prod; `daily` channel, 30-day rotation, on the `app-logs`
volume + `docker logs`. Domain code logs **ids and status transitions**, never
KYC fields, passwords, tokens, full card/cheque numbers, or document contents
(spot-checked: `Log::info('payment.verified', ['payment_id'…])`,
`'report.exported' => ['user_id','report_type','format','rows']`). The
`report_exports` audit row stores the filter query-string only.

## 15. Redis / cache

Sessions, cache and the queue are in Redis. **None of it is permanent data** —
sessions rebuild on login, cache is derived, the queue is idempotent. AOF
(`appendonly yes`) means a restart replays; `maxmemory-policy noeviction` means
a full Redis fails writes loudly (alertable) rather than silently dropping a
queued job. `php artisan optimize:clear` + `cache:clear` are safe any time.

## 16. Monitoring — to be wired by ops

No APM/monitoring package is bundled (none was in M0–M11; M12 adds none). Wire
your existing stack to:

| Signal | Source |
|---|---|
| App up / dependencies | `GET /healthz` (200/503) — LB + external uptime monitor |
| Container health | `docker compose ps` / Docker events → `(healthy)` from the healthchecks |
| App errors | `storage/logs/laravel-*.log` (warning+) → log shipper; or `LOG_STACK=stderr` → `docker logs` → collector |
| DB | `mysqld_exporter` / RDS metrics; the slow-query log (`--long-query-time=1`) is enabled |
| Redis | `redis_exporter`; alert on `used_memory` vs `maxmemory` (noeviction) |
| Queue depth / failures | `php artisan queue:monitor redis:default --max=<n>` (cron → alert); `SELECT count(*) FROM failed_jobs` |
| Disk | node_exporter on the volume mount; alert < 15% free (backups + documents grow) |
| CPU / memory | node_exporter / cAdvisor |
| Backup success | `backup.sh` exit code (cron-mailer) + "newest object age" alarm on the S3 bucket |
| SSL expiry | blackbox_exporter `ssl` probe on `https://<domain>` — alert < 14 days |

## 17. Audit trail

Module activity tables (`lead_activities`, `collection_activities`,
`document_activities`, `possession_activities`) + `report_exports` (M11.5:
`report.exported` — user / report / format / filters / row count / timestamp,
**no** customer or payment payload). No generic activity-log package.

## 18. Performance baseline

Measured on realistic data (3 projects, ~15 bookings, ~30 installments), dev
MySQL, no opcache JIT:

| Surface | queries | time |
|---|---|---|
| `/up` | 0 | < 5 ms |
| `/healthz` | 1 + 1 cache + 1 storage | ~15 ms |
| login POST | ~6 | ~40 ms |
| dashboard | ~12 | ~50 ms |
| projects / inventory / leads / bookings list | 8–15 | 15–40 ms |
| collections report | 20 | ~34 ms |
| executive dashboard | ~40 | ~66 ms |
| MIS report + CSV export | ~24–30 | ~40 ms |
| collections PDF export | 18 | ~520 ms (dompdf render, bounded tables) |

All bounded — query counts do **not** grow with row volume (verified in
`ReportHardeningTest`). Production adds opcache JIT + `config:cache` +
`route:cache`. No blind optimization done.

---

## GO / NO-GO checklist

- [ ] `.env` from `.env.production.example`, every CHANGE-ME filled, `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `APP_KEY` generated and **stored in a secret manager**
- [ ] TLS cert in `docker/nginx/certs/`, `curl -sI https://…` shows HSTS
- [ ] `dcp build app && dcp build nginx` succeed; `dcp ps` all `(healthy)`
- [ ] `dcp exec app php artisan about` → env=production, debug=false, config/route/event cached
- [ ] `dcp exec app php artisan migrate:status` → nothing pending
- [ ] `dcp exec app php artisan test` → green
- [ ] `./docker/smoke.sh https://<domain>` → PASS
- [ ] `./docker/backup/backup.sh` runs green; `restore.sh --into-scratch` verified
- [ ] backup cron installed; `BACKUP_S3_BUCKET` + `BACKUP_GPG_RECIPIENT` set
- [ ] monitoring wired to `/healthz`, logs, queue depth, disk, SSL expiry (§ 16)
- [ ] seeded super-admin password changed / account disabled
- [ ] DB + Redis host ports confirmed **not** published (`dcp ps`)
