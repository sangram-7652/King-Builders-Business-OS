# Disaster Recovery — King Builders Business OS (M12)

The system state is **two things**, and a valid backup contains both:

1. **MySQL** — every business record (users, projects, plots, leads, buyers,
   bookings, payment plans/installments/payments/allocations, collection cases,
   documents *metadata*, registry / possession / transfer cases, the
   append-only ownership ledger…).
2. **`storage/app`** — the M9/M10 **document files** themselves (KYC scans,
   agreements, receipts, possession certificates). They live on the private
   `documents` disk and are referenced **by path** from `document_versions`.

> A `mysqldump` alone is **not** a recoverable backup — every document row would
> point at a file that no longer exists.

Redis (sessions / cache / queue) is **not** backed up: it holds nothing that
can't be rebuilt (users just log in again; the queue is idempotent). It runs
with `appendonly yes` + `maxmemory-policy noeviction` so it survives a restart
and fails loudly rather than silently dropping a job.

---

## 1. Backup

`./docker/backup/backup.sh` (from the project root). Cron it:

```cron
30 2 * * *  cd /opt/king-builders && ./docker/backup/backup.sh >> /var/log/kb-backup.log 2>&1
```

Produces `kb-backup-YYYYMMDD-HHMMSSZ.tar` (+ `.sha256`) in `$BACKUP_DIR`
(default `/opt/king-builders/backups`, set in `.env`):

```
kb-backup-<stamp>.tar
├── database.sql.gz        gzip'd mysqldump --single-transaction --routines --triggers --events
├── storage-app.tar.gz     the whole storage/app tree (documents + public assets)
└── MANIFEST               app image tag + UTC timestamp + db name
```

Options in `.env`:

| var | effect |
|---|---|
| `BACKUP_RETENTION_DAYS` | prune local archives older than N (default 14) |
| `BACKUP_GPG_RECIPIENT` | `gpg --encrypt` each archive at rest (**recommended** — backups contain KYC data) |
| `BACKUP_S3_BUCKET` | `aws s3 cp` each archive + checksum off-site (**required for real DR** — a host-local backup dies with the host) |

The script `exit`s non-zero on any failure (empty dump, tar error, upload
error) so cron mail / the monitor notices. It also aborts if the dump is
suspiciously small.

**3-2-1**: keep ≥ 3 copies, on ≥ 2 media, ≥ 1 off-site. The `BACKUP_S3_BUCKET`
copy is the off-site one — configure it.

---

## 2. Restore test (do this monthly — an untested backup is not a backup)

Restores into a **throwaway database**; production is untouched:

```bash
cd /opt/king-builders
./docker/backup/restore.sh  /opt/king-builders/backups/kb-backup-<stamp>.tar  --into-scratch
```

It:
1. verifies the `.sha256`,
2. decrypts if `.gpg`,
3. creates `king_builders_restore_test`, loads the dump,
4. prints sanity row counts (`users`, `bookings`, `payments`, `documents`),
5. tells you how to drop the scratch DB.

A green restore test = the DB half is recoverable. Once a quarter, also extract
`storage-app.tar.gz` somewhere and spot-check that a known document opens.

---

## 3. Full restore (production is lost / corrupted)

```bash
cd /opt/king-builders
dcp exec app php artisan down                       # if the app is still up
./docker/backup/restore.sh  <archive>               # prompts for "yes"
```

`restore.sh` (live mode):
1. verify checksum / decrypt,
2. `DROP`+`CREATE` the production DB, load `database.sql.gz`,
3. wipe `storage/app`, extract `storage-app.tar.gz` into it,
4. `artisan migrate --force` (in case the backup predates a migration),
5. `artisan optimize:clear && artisan optimize`,
6. `restart queue scheduler`.

Then: `dcp exec app php artisan up` and `./docker/smoke.sh https://…`.

---

## 4. Rebuild on a fresh server

Assumes: Docker Engine ≥ 25, the repo, the latest backup archive, and the TLS
cert (or ability to issue one).

```bash
# 1. host
apt-get update && apt-get install -y docker.io docker-compose-plugin git
mkdir -p /opt/king-builders && cd /opt/king-builders
git clone <repo> . && git checkout <release-tag matching the backup MANIFEST>

# 2. config
cp .env.production.example .env
$EDITOR .env                      # APP_URL, APP_KEY (keep the OLD key — see below), all passwords

#    ⚠ APP_KEY must be the SAME key the data was encrypted with. Encrypted
#    columns (buyer KYC) and any encrypted session data are unreadable with a
#    new key. Recover APP_KEY from your secret store / the old .env, NOT
#    `key:generate`.

# 3. TLS
mkdir -p docker/nginx/certs
#    copy fullchain.pem + privkey.pem (or run certbot and symlink them)

# 4. images
export APP_IMAGE_TAG=$(git rev-parse --short HEAD)
dcp build app && dcp build nginx

# 5. data
dcp up -d mysql redis
./docker/backup/restore.sh  /path/to/kb-backup-<stamp>.tar

# 6. go
dcp up -d
dcp exec app php artisan storage:link
dcp exec app php artisan optimize
./docker/smoke.sh https://erp.example.com

# 7. DNS → new host, watch /healthz + logs for 30 min
```

**RTO** (time to restore): ~30–45 min on a prepared host (mostly image build +
DB import). **RPO** (data loss): ≤ your backup interval — 24 h with the default
daily cron; tighten to hourly for the DB if that is too much.

---

## 5. Scenarios

| Scenario | Action |
|---|---|
| App container crash-loops | `dcp logs app`; `restart: unless-stopped` already retries. If a bad deploy: `./docker/deploy.sh <previous-tag>` |
| MySQL data volume corrupt | stop stack, `docker volume rm king-builders_mysql-data`, `dcp up -d mysql`, `./docker/backup/restore.sh <archive>` |
| Redis lost / flushed | non-event — `dcp restart redis`; users re-login, queue re-derives. Do **not** restore Redis from anywhere |
| Documents deleted, DB fine | `restore.sh` extracts only `storage-app.tar.gz`? — no, it does both; for docs-only: `tar xzf` the member into the `app-storage` volume manually (see § 3 step 3) |
| Whole host gone | § 4 |
| Ransomware / bad actor with DB access | restore last **known-good** off-site (S3, immutable/versioned bucket) archive into scratch, diff, forward-fix |
| Accidental `migrate:fresh` (never do this) | full restore, § 3 |

---

## 6. What is NOT covered here (out of scope for M12)

- Point-in-time recovery / binlog streaming (add MySQL binlog + `mysqlbinlog`
  replay if RPO must be minutes, not hours).
- Multi-region / hot standby.
- Automated failover.
