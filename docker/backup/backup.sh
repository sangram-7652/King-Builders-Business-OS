#!/usr/bin/env bash
###############################################################################
# King Builders Business OS — backup (M12)
#
# Backs up BOTH halves of the system state:
#   1. the MySQL database  (mysqldump, single transaction, routines + triggers)
#   2. the M9/M10 document store  (the `app-storage` docker volume)
#
# A database dump ALONE is not a valid backup — the private documents live on
# disk, referenced by path from the DB.
#
# Usage (from the project root, on the production host):
#   ./docker/backup/backup.sh
#
# Cron (daily 02:30, log to syslog):
#   30 2 * * * cd /opt/king-builders && ./docker/backup/backup.sh >> /var/log/kb-backup.log 2>&1
#
# Env (read from ./.env, overridable):
#   BACKUP_DIR              where to write archives      (default ./storage/backups)
#   BACKUP_RETENTION_DAYS   prune archives older than N  (default 14)
#   BACKUP_GPG_RECIPIENT    if set, gpg-encrypt each archive to this key
#   BACKUP_S3_BUCKET        if set, `aws s3 cp` each archive off-site
#
# Exit code is non-zero on ANY failure so a monitor / cron-mailer notices.
###############################################################################
set -Eeuo pipefail

cd "$(dirname "$0")/../.."
[ -f .env ] && set -a && . ./.env && set +a

COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
BACKUP_DIR="${BACKUP_DIR:-$(pwd)/storage/backups}"
RETENTION="${BACKUP_RETENTION_DAYS:-14}"
STAMP="$(date -u +%Y%m%d-%H%M%SZ)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

mkdir -p "$BACKUP_DIR"
echo "[backup] $STAMP → $BACKUP_DIR"

# --- 1. database ---------------------------------------------------------------
echo "[backup] dumping database '${DB_DATABASE:-king_builders}'"
$COMPOSE exec -T mysql sh -c \
  "exec mysqldump --single-transaction --quick --routines --triggers --events \
     --default-character-set=utf8mb4 \
     -u root -p\"\$MYSQL_ROOT_PASSWORD\" \"${DB_DATABASE:-king_builders}\"" \
  | gzip -9 > "$WORK/database.sql.gz"

# fail loudly on a truncated / empty dump
if [ "$(gzip -dc "$WORK/database.sql.gz" | head -c 64 | wc -c)" -lt 32 ]; then
  echo "[backup] FATAL: database dump looks empty" >&2
  exit 1
fi

# --- 2. document store (the app-storage volume) ------------------------------
echo "[backup] archiving document store"
$COMPOSE run --rm --no-deps -T \
  -v "$WORK:/backup" \
  --entrypoint sh app -c \
  "tar czf /backup/storage-app.tar.gz -C /var/www/html/storage/app ."

# --- 3. bundle + checksum ---------------------------------------------------
ARCHIVE="$BACKUP_DIR/kb-backup-$STAMP.tar"
tar cf "$ARCHIVE" -C "$WORK" database.sql.gz storage-app.tar.gz
printf 'app_version=%s\ncreated_utc=%s\ndb=%s\n' \
  "${APP_IMAGE_TAG:-unknown}" "$STAMP" "${DB_DATABASE:-king_builders}" > "$WORK/MANIFEST"
tar rf "$ARCHIVE" -C "$WORK" MANIFEST
sha256sum "$ARCHIVE" | sed "s|$BACKUP_DIR/||" > "$ARCHIVE.sha256"
echo "[backup] wrote $(du -h "$ARCHIVE" | cut -f1) → $ARCHIVE"

# --- 4. optional encryption -------------------------------------------------
if [ -n "${BACKUP_GPG_RECIPIENT:-}" ]; then
  gpg --yes --batch --encrypt --recipient "$BACKUP_GPG_RECIPIENT" "$ARCHIVE"
  rm -f "$ARCHIVE"
  ARCHIVE="$ARCHIVE.gpg"
  sha256sum "$ARCHIVE" | sed "s|$BACKUP_DIR/||" > "$ARCHIVE.sha256"
  echo "[backup] encrypted → $ARCHIVE"
fi

# --- 5. optional off-site copy --------------------------------------------
if [ -n "${BACKUP_S3_BUCKET:-}" ]; then
  aws s3 cp "$ARCHIVE"        "${BACKUP_S3_BUCKET%/}/" --only-show-errors
  aws s3 cp "$ARCHIVE.sha256" "${BACKUP_S3_BUCKET%/}/" --only-show-errors
  echo "[backup] copied off-site → ${BACKUP_S3_BUCKET%/}/"
fi

# --- 6. retention ---------------------------------------------------------
find "$BACKUP_DIR" -maxdepth 1 -name 'kb-backup-*.tar*' -mtime "+$RETENTION" -print -delete || true

echo "[backup] OK"
