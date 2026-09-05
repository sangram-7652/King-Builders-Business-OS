#!/usr/bin/env bash
###############################################################################
# King Builders Business OS — deploy (M12)
#
# Repeatable, production-safe deploy:
#   git → build → backup → migrate → optimise → workers → health → smoke
#
# Usage (from the project root on the production host):
#   ./docker/deploy.sh [git-ref]          # default: origin/main
#
# Rollback: ./docker/deploy.sh <previous-tag-or-sha>   (see docs/DEPLOYMENT.md
# for the DB side — code rollback alone is only safe for additive migrations).
###############################################################################
set -Eeuo pipefail

cd "$(dirname "$0")/.."
REF="${1:-origin/main}"
COMPOSE="docker compose -f docker-compose.yml -f docker-compose.prod.yml"

[ -f .env ] || { echo "no .env — copy .env.production.example first" >&2; exit 1; }
set -a && . ./.env && set +a
[ "${APP_ENV:-}" = "production" ] || { echo "APP_ENV is not 'production' — refusing" >&2; exit 1; }

TAG="$(git rev-parse --short "$REF" 2>/dev/null || echo "$REF")"
echo "==> deploying $REF ($TAG)"

# 1. code
git fetch --all --tags --prune
git checkout --force "$REF"
echo "APP_IMAGE_TAG=$TAG" > .deploy.env      # picked up by the compose commands below
export APP_IMAGE_TAG="$TAG"

# 2. preflight — fail BEFORE the maintenance window, not during it (F-M12-4)
echo "==> preflight"
command -v docker >/dev/null || { echo "docker not found" >&2; exit 1; }
$COMPOSE config -q || { echo "compose config invalid — aborting" >&2; exit 1; }
$COMPOSE up -d mysql redis
for i in $(seq 1 20); do
  $COMPOSE exec -T mysql sh -c 'mysqladmin ping -h127.0.0.1 -uroot -p"$MYSQL_ROOT_PASSWORD" --silent' && break
  [ "$i" = 20 ] && { echo "database not reachable — aborting before touching the site" >&2; exit 1; }
  echo "   waiting for the database ($i)…"; sleep 3
done
# Enough free space for the backup archive (rough: 2 GB).
FREE_KB="$(df -Pk "${BACKUP_DIR:-$(pwd)/storage/backups}" 2>/dev/null | awk 'NR==2{print $4}')"
[ "${FREE_KB:-0}" -ge 2000000 ] || echo "   WARNING: low disk on the backup target (${FREE_KB:-?} KB free)"

# 3. pre-deploy backup (so a bad migration is recoverable). backup.sh aborts
#    on an empty dump; verify an artifact actually landed.
echo "==> backup"
BEFORE_COUNT="$(ls -1 "${BACKUP_DIR:-$(pwd)/storage/backups}"/kb-backup-*.tar* 2>/dev/null | wc -l)"
./docker/backup/backup.sh
AFTER_COUNT="$(ls -1 "${BACKUP_DIR:-$(pwd)/storage/backups}"/kb-backup-*.tar* 2>/dev/null | wc -l)"
[ "$AFTER_COUNT" -gt "$BEFORE_COUNT" ] || { echo "backup produced no new archive — aborting" >&2; exit 1; }

# 4. build the new images (app carries the compiled assets; nginx copies them)
echo "==> build"
$COMPOSE build app
$COMPOSE build nginx

# 5. maintenance window + migrate
echo "==> migrate"
$COMPOSE up -d --no-deps app
# Show exactly what will run before it runs — an operator can still Ctrl-C here.
echo "--- pending migrations (dry run) ---"
$COMPOSE exec -T app php artisan migrate --force --pretend || true
echo "------------------------------------"
$COMPOSE exec -T app php artisan down --render="errors::503" --retry=15 || true
if ! $COMPOSE exec -T app php artisan migrate --force --isolated; then
  echo "" >&2
  echo "MIGRATION FAILED — the site is in maintenance mode." >&2
  echo "  * do NOT roll the fleet forward" >&2
  echo "  * restore from the archive taken above (./docker/backup/restore.sh)" >&2
  echo "  * then re-deploy the previous tag: ./docker/deploy.sh <previous-sha>" >&2
  echo "  * see docs/DISASTER-RECOVERY.md" >&2
  exit 1
fi

# 6. optimise (config/route/event/view cache)
echo "==> optimise"
$COMPOSE exec -T app php artisan optimize
$COMPOSE exec -T app php artisan storage:link || true

# 7. roll the rest of the fleet
echo "==> restart fleet"
$COMPOSE up -d
$COMPOSE exec -T app php artisan queue:restart
$COMPOSE exec -T app php artisan up

# 8. health + smoke
echo "==> health"
for i in $(seq 1 15); do
  $COMPOSE exec -T app php artisan health:check && break
  echo "   waiting for dependencies ($i)…"; sleep 3
done
./docker/smoke.sh "${APP_URL:-http://localhost}"

echo "==> deployed $TAG"
