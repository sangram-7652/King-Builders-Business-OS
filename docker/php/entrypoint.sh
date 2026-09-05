#!/bin/sh
###############################################################################
# Container entrypoint — shared by app / queue / scheduler services.
#
# Keeps startup idempotent so `docker compose up` works from a clean checkout.
# Only the container with APP_BOOTSTRAP=true (the `app` service) is allowed to
# mutate shared, bind-mounted state (.env, APP_KEY, migrations); the queue and
# scheduler containers just wait for it.
###############################################################################
set -e

cd /var/www/html

BOOTSTRAP="${APP_BOOTSTRAP:-false}"

# Dev convenience only: bootstrap a .env from the example on a clean checkout.
# In production the real .env is bind-mounted read-only (docker-compose.prod.yml)
# and APP_ENV is already "production" in the environment — never overwrite it.
if [ ! -f .env ] && [ -f .env.example ] && [ "${APP_ENV:-local}" != "production" ]; then
    echo "[entrypoint] .env not found — copying from .env.example"
    cp .env.example .env
fi

if [ ! -f vendor/autoload.php ]; then
    echo "[entrypoint] vendor/ missing — running composer install"
    composer install --no-interaction --prefer-dist
fi

mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
chmod -R ug+rw storage bootstrap/cache || true

if [ "$BOOTSTRAP" = "true" ]; then
    if [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
        echo "[entrypoint] generating APP_KEY"
        php artisan key:generate --force --no-interaction || true
    fi

    echo "[entrypoint] running migrations"
    i=0
    until php artisan migrate --force --no-interaction; do
        i=$((i + 1))
        [ "$i" -ge 10 ] && { echo "[entrypoint] migrations failed after $i attempts"; break; }
        echo "[entrypoint] database not ready yet (attempt $i) — retrying in 3s"
        sleep 3
    done

    # Idempotent: creates roles/permissions and the seeded super admin if absent.
    if [ "${AUTO_SEED:-true}" = "true" ]; then
        echo "[entrypoint] seeding roles, permissions and the super admin"
        php artisan db:seed --force --no-interaction || true
    fi

    # F-M12-1: cache config / routes / events / views for production. Runs at
    # startup (not build) so the REAL .env is baked in, not the image defaults.
    # No runtime env() remains in app code (F-M12-3), so this is safe. `optimize`
    # = config:cache + event:cache + route:cache + view:cache and clears stale
    # caches first, so it is idempotent.
    if [ "${APP_ENV:-local}" = "production" ] || [ "${APP_CACHE_ON_BOOT:-false}" = "true" ]; then
        echo "[entrypoint] optimising (config/route/event/view cache)"
        php artisan optimize --no-interaction || true
    fi
else
    # queue / scheduler: don't start until the app has written an APP_KEY
    i=0
    while [ -z "${APP_KEY:-}" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; do
        i=$((i + 1))
        [ "$i" -ge 30 ] && { echo "[entrypoint] gave up waiting for APP_KEY"; break; }
        echo "[entrypoint] waiting for app container to initialise (.env) ..."
        sleep 2
    done
fi

exec "$@"
