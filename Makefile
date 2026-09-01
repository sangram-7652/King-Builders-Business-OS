# King Builders Business OS — developer shortcuts
# Everything runs inside Docker; no local PHP/Composer/MySQL/Redis required.

DC := docker compose

.PHONY: help build up up-dev down restart ps logs shell tinker \
        key migrate migrate-fresh test pint about queue-status assets

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

build: ## Build the PHP image
	$(DC) build

up: ## Start the full stack (detached)
	$(DC) up -d --build

up-dev: ## Start the stack + Vite dev server (HMR)
	$(DC) --profile dev up -d --build

down: ## Stop and remove containers
	$(DC) down

restart: ## Restart all services
	$(DC) restart

ps: ## Container status
	$(DC) ps

logs: ## Tail logs
	$(DC) logs -f --tail=100

shell: ## Shell into the app container
	$(DC) exec app bash

tinker: ## Laravel Tinker
	$(DC) exec app php artisan tinker

key: ## Generate APP_KEY
	$(DC) run --rm app php artisan key:generate

migrate: ## Run migrations
	$(DC) exec app php artisan migrate

migrate-fresh: ## Drop everything and re-migrate
	$(DC) exec app php artisan migrate:fresh

test: ## Run the Pest suite
	$(DC) exec app php artisan test

pint: ## Run Laravel Pint (code style)
	$(DC) exec app ./vendor/bin/pint

about: ## php artisan about
	$(DC) exec app php artisan about

assets: ## Build front-end assets once
	$(DC) run --rm vite sh -c "npm install && npm run build"
