# syntax=docker/dockerfile:1

###############################################################################
# King Builders Business OS — production web image (M12)
#
# nginx + a static copy of `public/` (index.php, compiled Vite/Tailwind assets,
# favicon, robots) taken straight from the app image, so the web tier can never
# serve assets that disagree with the running code. PHP is still handled by the
# separate `app` (php-fpm) container over fastcgi.
#
#   docker build -f docker/nginx/Dockerfile \
#     --build-arg APP_IMAGE=king-builders/app:<tag> \
#     -t king-builders/nginx:<tag> .
###############################################################################
ARG APP_IMAGE=king-builders/app:latest

FROM ${APP_IMAGE} AS app

FROM nginx:1.27-alpine

COPY --from=app /var/www/html/public /var/www/html/public
COPY docker/nginx/production.conf /etc/nginx/conf.d/default.conf

# `public/storage` is a symlink to a path that only exists at runtime (the
# mounted app-storage volume) — nginx follows it fine once the volume is there.
