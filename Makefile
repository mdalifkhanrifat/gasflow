# GasFlow development shortcuts. Run "make" to list them.

DC   := docker compose --profile dev
APP  := $(DC) exec app
SECRETS := DB_SUPERUSER_PASSWORD DB_OWNER_PASSWORD DB_APP_PASSWORD REDIS_PASSWORD

.DEFAULT_GOAL := help
.PHONY: help env setup build up down restart ps logs shell artisan composer npm \
        migrate fresh tinker test lint check psql redis-cli queue-restart geoip

help: ## List the available commands
	@grep -hE '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F':.*## ' '{printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

env: ## Create .env from .env.example with random passwords and your user id
	@if [ -f .env ]; then echo ".env already exists, nothing changed"; exit 1; fi
	@cp .env.example .env
	@for v in $(SECRETS); do sed -i.bak "s|^$$v=$$|$$v=$$(openssl rand -hex 24)|" .env; done
	@sed -i.bak "s|^HOST_UID=.*|HOST_UID=$$(id -u)|; s|^HOST_GID=.*|HOST_GID=$$(id -g)|" .env
	@rm -f .env.bak
	@echo ".env created"

setup: ## First run: env, images, containers, dependencies, app key, migrations
	@[ -f .env ] || $(MAKE) --no-print-directory env
	$(DC) build
	$(DC) up -d
	$(APP) composer install
	@grep -q '^APP_KEY=$$' .env && $(APP) php artisan key:generate || true
	$(APP) php artisan migrate --database=pgsql_migrations
	@echo "GasFlow is running on http://localhost:$$(grep '^APP_PORT=' .env | cut -d= -f2)"

build: ## Build the images
	$(DC) build

up: ## Start every service (including Vite and Mailpit)
	$(DC) up -d

down: ## Stop and remove the containers (data volumes are kept)
	$(DC) down

restart: ## Restart every service
	$(DC) restart

ps: ## Show the service status
	$(DC) ps

logs: ## Follow logs (one service: make logs s=queue-default)
	$(DC) logs -f --tail=100 $(s)

shell: ## Open a shell in the app container
	$(APP) sh

artisan: ## Run artisan (make artisan a="route:list")
	$(APP) php artisan $(a)

composer: ## Run composer (make composer a="require vendor/package")
	$(APP) composer $(a)

npm: ## Run npm in the node container (make npm a="run build")
	$(DC) exec node npm $(a)

migrate: ## Run the database migrations (as the owner role)
	$(APP) php artisan migrate --database=pgsql_migrations

fresh: ## Drop every table and migrate again (development only)
	$(APP) php artisan migrate:fresh --database=pgsql_migrations

tinker: ## Open tinker
	$(APP) php artisan tinker

test: ## PHP checks: Pint, PHPStan and the test suite
	$(APP) composer test

lint: ## Format PHP code with Pint
	$(APP) vendor/bin/pint --parallel

check: ## Frontend lint, format and type checks
	$(DC) exec node npm run check
	$(DC) exec node npm run types:check

psql: ## Open psql on the app database as the superuser
	$(DC) exec postgres psql -U postgres -d $$(grep '^DB_DATABASE=' .env | cut -d= -f2)

redis-cli: ## Open redis-cli
	$(DC) exec redis redis-cli

queue-restart: ## Restart the queue workers after code changes
	$(APP) php artisan queue:restart

geoip: ## Download the DB-IP City Lite database (IP → city lookups)
	$(APP) php artisan audit:geoip-update
