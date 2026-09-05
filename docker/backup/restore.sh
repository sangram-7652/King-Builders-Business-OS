#!/usr/bin/env bash
###############################################################################
# King Builders Business OS — restore (M12)
#
# Restores a backup produced by ./docker/backup/backup.sh — BOTH the database
# and the M9/M10 document store.
#
# Usage (from the project root):
#   ./docker/backup/restore.sh  path/to/kb-backup-YYYYMMDD-HHMMSSZ.tar[.gpg]
#
#   # restore-test into a throwaway database (does NOT touch production data):
#   ./docker/backup/restore.sh  <archive>  --into-scratch
#
# This is DESTRUCTIVE for the target database + document volume. It refuses to
# run without an explicit "yes" unless --into-scratch is given.
###############################################################################
set -Eeuo pipefail

cd "$(dirname "$0")/../.."
[ -f .env ] && set -a && . ./.env && set +a

COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"
ARCHIVE="${1:?usage: restore.sh <archive> [--into-scratch]}"
MODE="${2:-live}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

[ -f "$ARCHIVE" ] || { echo "no such file: $ARCHIVE" >&2; exit 1; }

# --- verify checksum if present -----------------------------------------
if [ -f "$ARCHIVE.sha256" ]; then
  echo "[restore] verifying checksum"
  ( cd "$(dirname "$ARCHIVE")" && sha256sum -c "$(basename "$ARCHIVE").sha256" )
fi

# --- decrypt if needed --------------------------------------------------
case "$ARCHIVE" in
  *.gpg) echo "[restore] decrypting"; gpg --yes --batch --decrypt "$ARCHIVE" > "$WORK/bundle.tar"; BUNDLE="$WORK/bundle.tar" ;;
  *)     BUNDLE="$ARCHIVE" ;;
esac

tar xf "$BUNDLE" -C "$WORK"
echo "[restore] manifest:"; sed 's/^/    /' "$WORK/MANIFEST"

if [ "$MODE" = "--into-scratch" ]; then
  TARGET_DB="${DB_DATABASE:-king_builders}_restore_test"
  echo "[restore] SCRATCH mode → database '$TARGET_DB' (production untouched)"
else
  TARGET_DB="${DB_DATABASE:-king_builders}"
  echo
  echo "  !! This will OVERWRITE database '$TARGET_DB' and the document store !!"
  read -r -p "  Type 'yes' to continue: " ok
  [ "$ok" = "yes" ] || { echo "aborted"; exit 1; }
fi

# --- 1. database -------------------------------------------------------
echo "[restore] restoring database → $TARGET_DB"
$COMPOSE exec -T mysql sh -c \
  "mysql -u root -p\"\$MYSQL_ROOT_PASSWORD\" -e \
     'DROP DATABASE IF EXISTS \`$TARGET_DB\`; CREATE DATABASE \`$TARGET_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'"
gzip -dc "$WORK/database.sql.gz" | \
  $COMPOSE exec -T mysql sh -c "mysql -u root -p\"\$MYSQL_ROOT_PASSWORD\" \"$TARGET_DB\""

if [ "$MODE" = "--into-scratch" ]; then
  echo "[restore] scratch DB row counts (sanity):"
  $COMPOSE exec -T mysql sh -c \
    "mysql -u root -p\"\$MYSQL_ROOT_PASSWORD\" -N -e \
      'SELECT CONCAT(t, \": \", n) FROM (
         SELECT \"users\" t, COUNT(*) n FROM \`$TARGET_DB\`.users UNION ALL
         SELECT \"bookings\", COUNT(*) FROM \`$TARGET_DB\`.bookings UNION ALL
         SELECT \"payments\", COUNT(*) FROM \`$TARGET_DB\`.payments UNION ALL
         SELECT \"documents\", COUNT(*) FROM \`$TARGET_DB\`.documents) x;'" | sed 's/^/    /'
  echo "[restore] scratch restore OK — drop it with:"
  echo "    $COMPOSE exec -T mysql sh -c 'mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\" -e \"DROP DATABASE \\\`$TARGET_DB\\\`\"'"
  exit 0
fi

# --- 2. document store ------------------------------------------------
echo "[restore] restoring document store"
$COMPOSE run --rm --no-deps -T \
  -v "$WORK:/backup:ro" \
  --entrypoint sh app -c \
  "find /var/www/html/storage/app -mindepth 1 -delete && \
   tar xzf /backup/storage-app.tar.gz -C /var/www/html/storage/app"

# --- 3. bring the app back consistent -------------------------------
echo "[restore] clearing caches + re-running migrations"
$COMPOSE exec -T app php artisan migrate --force
$COMPOSE exec -T app php artisan optimize:clear
$COMPOSE exec -T app php artisan optimize
$COMPOSE restart queue scheduler

echo "[restore] done — run ./docker/backup/smoke.sh to verify"
