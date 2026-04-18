.PHONY: install up down restart build logs shell migrate migrate-fresh \
        seed horizon test lint ps help

# ── Config ────────────────────────────────────────────────────────────────────
APP = docker compose exec app

# ── First-time setup ──────────────────────────────────────────────────────────
install: ## Bootstrap the project from scratch
	@echo "Copying .env.example → .env ..."
	@[ -f .env ] || cp .env.example .env
	@echo "Building and starting containers ..."
	docker compose up -d --build
	@echo "Waiting for MySQL to be ready ..."
	@until docker compose exec mysql mysqladmin ping -h localhost --silent; do sleep 2; done
	@echo "Generating app key ..."
	$(APP) php artisan key:generate
	@echo "Discovering packages ..."
	$(APP) php artisan package:discover --ansi
	@echo "Running migrations ..."
	$(APP) php artisan migrate --force
	@echo "Seeding database ..."
	$(APP) php artisan db:seed
	@echo ""
	@echo "Done. App running at http://localhost:$${APP_PORT:-8000}"
	@echo "Horizon dashboard → http://localhost:$${APP_PORT:-8000}/horizon"

# ── Docker lifecycle ──────────────────────────────────────────────────────────
up: ## Start all containers
	docker compose up -d

down: ## Stop all containers
	docker compose down

restart: ## Restart all containers
	docker compose restart

build: ## Rebuild images without cache
	docker compose build --no-cache

ps: ## Show running containers
	docker compose ps

logs: ## Tail logs from all containers (Ctrl+C to exit)
	docker compose logs -f

logs-app: ## Tail app container logs only
	docker compose logs -f app

logs-horizon: ## Tail Horizon worker logs
	docker compose logs -f horizon

# ── App commands ──────────────────────────────────────────────────────────────
shell: ## Open a shell inside the app container
	docker compose exec app bash

migrate: ## Run pending migrations
	$(APP) php artisan migrate

migrate-fresh: ## Drop all tables and re-run migrations
	$(APP) php artisan migrate:fresh

seed: ## Seed sample coupon data
	$(APP) php artisan db:seed --class=CouponSeeder

migrate-seed: ## Fresh migrate + seed
	$(APP) php artisan migrate:fresh --seed

# ── Queue & Horizon ───────────────────────────────────────────────────────────
horizon: ## Open Horizon dashboard URL
	@echo "Horizon → http://localhost:$${APP_PORT:-8000}/horizon"

horizon-pause: ## Pause all Horizon workers
	$(APP) php artisan horizon:pause

horizon-continue: ## Resume paused Horizon workers
	$(APP) php artisan horizon:continue

queue-flush: ## Flush all pending jobs from Redis queues
	$(APP) php artisan queue:flush

# ── Testing & linting ─────────────────────────────────────────────────────────
test: ## Run the test suite
	$(APP) php artisan test

lint: ## Auto-fix code style with Pint
	$(APP) ./vendor/bin/pint

lint-check: ## Check code style without fixing
	$(APP) ./vendor/bin/pint --test

# ── Help ──────────────────────────────────────────────────────────────────────
help: ## List all available commands
	@grep -E '^[a-zA-Z_-]+:.*##' $(MAKEFILE_LIST) | \
	awk 'BEGIN {FS = ":.*##"}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
