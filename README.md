# King Builders Business OS

A modern real-estate ERP (Laravel 12 + Livewire 3 + Tailwind v4) replacing a
legacy PHP real-estate CRM.

> **Status: M0 — Foundation.** Docker environment, application shell, UI kit and
> conventions only. No business modules yet. M1 is Authentication + RBAC.

---

## Requirements

- Docker + Docker Compose v2

Nothing else. PHP, Composer, MySQL, Node and Redis all run in containers.

## Quick start

```bash
cp .env.example .env
docker compose up -d --build          # app, nginx, mysql, redis, queue, scheduler
docker compose run --rm app php artisan key:generate
docker compose run --rm vite sh -c "npm install && npm run build"   # build assets once
```

Open <http://localhost:8080>.

For front-end hot-reload instead of a one-off build:

```bash
docker compose --profile dev up -d    # also starts the `vite` container on :5173
```

## Services

| Service     | Image                     | Port (host) | Purpose                    |
| ----------- | ------------------------- | ----------- | -------------------------- |
| `app`       | `king-builders/app:local` | –           | Laravel / PHP-FPM          |
| `nginx`     | `nginx:1.27-alpine`       | `8080`      | Web server                 |
| `mysql`     | `mysql:8.0`               | `3307`      | Database `king_builders`   |
| `redis`     | `redis:7-alpine`          | `6379`      | Cache / sessions / queue   |
| `queue`     | `king-builders/app:local` | –           | `queue:work redis`         |
| `scheduler` | `king-builders/app:local` | –           | `schedule:work`            |
| `vite`      | `node:22-alpine`          | `5173`      | Asset dev server (profile `dev`) |

All services talk over the `king-builders` Docker network using service names
(`mysql`, `redis`, `app`).

## Common commands

```bash
make help            # list shortcuts
make up              # docker compose up -d --build
make test            # docker compose exec app php artisan test
make about           # docker compose exec app php artisan about
make shell           # bash into the app container
```

## Layout

```
app/Actions      app/Services      app/Enums       app/Policies
app/Support      app/Livewire      app/Exceptions
docker/php       docker/nginx
docs/CONVENTIONS.md   <-- validation / authz / transactions / auditability rules
resources/views/components/ui      <-- reusable UI kit (x-ui.*)
resources/views/components/app     <-- admin shell (sidebar, topbar, …)
resources/views/components/layouts/app.blade.php
```

See [`docs/CONVENTIONS.md`](docs/CONVENTIONS.md) for engineering conventions.
