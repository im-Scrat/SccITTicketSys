# SccIT developer shortcuts.  Usage: make <target>
# (Requires GNU make. On Windows you can instead run the scripts/*.sh
#  files via Git Bash, or the raw `docker compose` commands.)
.DEFAULT_GOAL := help
SHELL := /bin/sh

.PHONY: help up down restart build rebuild logs ps shell migrate fresh test lint format backup restore psql redis

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n",$$1,$$2}'

up: ## Start the stack (detached)
	docker compose up -d && docker compose ps

down: ## Stop the stack
	docker compose down

restart: ## Restart all services
	docker compose restart

build: ## Build images
	docker compose build

rebuild: ## Rebuild images (no cache) and restart
	docker compose build --no-cache && docker compose up -d

logs: ## Tail logs for all services
	docker compose logs -f

ps: ## List services
	docker compose ps

shell: ## Bash shell in the app container
	docker compose exec app bash

migrate: ## Run database migrations
	docker compose exec app php artisan migrate

fresh: ## Drop and re-run migrations
	docker compose exec app php artisan migrate:fresh

test: ## Run backend (Pest) + frontend (Vitest) tests
	docker compose exec -T app php vendor/bin/pest
	docker compose exec -T node npm run test

lint: ## Lint backend (Pint+Larastan) + frontend (ESLint)
	docker compose exec -T app php vendor/bin/pint --test
	docker compose exec -T app php vendor/bin/phpstan analyse --no-progress --memory-limit=512M
	docker compose exec -T node npm run lint

format: ## Auto-format backend (Pint) + frontend (Prettier)
	docker compose exec -T app php vendor/bin/pint
	docker compose exec -T node npm run format

backup: ## Back up the database to backups/
	sh scripts/backup.sh

restore: ## Restore the database (make restore FILE=backups/x.sql.gz)
	sh scripts/restore.sh $(FILE)

psql: ## Open a psql shell
	docker compose exec postgres psql -U postgres -d school_it_service_management

redis: ## Open a redis-cli shell
	docker compose exec redis sh -c 'redis-cli -a "$$REDIS_PASSWORD"'
