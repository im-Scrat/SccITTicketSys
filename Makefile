# SccIT developer shortcuts.  Usage: make <target>
# (Requires GNU make. On Windows you can instead run the scripts/*.sh
#  files via Git Bash, or the raw `docker compose` commands.)
.DEFAULT_GOAL := help
SHELL := /bin/sh

.PHONY: help up down restart build rebuild logs ps shell migrate fresh test lint format backup restore psql redis \
        prod-build prod-up prod-setup prod-down prod-logs prod-ps prod-shell \
        gates smoke smoke-dev backup-prod restore-prod deploy-prod rollback-prod releases \
        e2e e2e-a11y e2e-smoke

# Production-like stack (compose.prod.yaml). The explicit `-p sccit_prod` is
# REQUIRED: the root .env's COMPOSE_PROJECT_NAME=sccit would otherwise place
# this stack in the dev project and clobber dev containers.
PROD := docker compose -p sccit_prod -f compose.prod.yaml

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

prod-build: ## [prod] Build the production images
	$(PROD) build

prod-up: ## [prod] Start the production stack (:8081)
	$(PROD) up -d && $(PROD) ps

prod-setup: ## [prod] Migrate + seed the production database
	$(PROD) exec app_prod php artisan migrate --force
	$(PROD) exec app_prod php artisan db:seed --force

prod-down: ## [prod] Stop the production stack (keeps data volumes)
	$(PROD) down

prod-logs: ## [prod] Tail production logs
	$(PROD) logs -f

prod-ps: ## [prod] List production services
	$(PROD) ps

prod-shell: ## [prod] Shell into the production app container
	$(PROD) exec app_prod sh

# ---------------------------------------------------------------
#  WP-2.7d — operational foundation.
#  Every target below delegates to scripts/, which source scripts/lib/stack.sh
#  so the production `-p sccit_prod` flags cannot be forgotten.
# ---------------------------------------------------------------

gates: ## Run the full quality gate (Pint, PHPStan, Pest, tsc, ESLint, Prettier, Vitest, build)
	sh scripts/gates.sh

smoke: ## [prod] HTTP smoke test against :8081
	sh scripts/smoke.sh --stack prod

smoke-dev: ## HTTP smoke test against :8080
	sh scripts/smoke.sh --stack dev

e2e: ## Browser suite — three-role auth, authorization, QR workflow (dev :8080)
	sh scripts/e2e.sh --project e2e

e2e-a11y: ## Accessibility regression against the committed axe baseline (dev :8080)
	sh scripts/e2e.sh --project a11y

e2e-smoke: ## [prod] Browser smoke — does the deployed bundle boot? (:8081)
	sh scripts/e2e.sh --project smoke

backup: ## Snapshot the dev database + uploads to backups/dev/
	sh scripts/backup.sh --stack dev

backup-prod: ## [prod] Snapshot the production database + uploads to backups/prod/
	sh scripts/backup.sh --stack prod

restore: ## Restore a dev snapshot (make restore FILE=backups/dev/<ts>)
	sh scripts/restore.sh --stack dev $(FILE)

restore-prod: ## [prod] Restore a production snapshot (make restore-prod FILE=backups/prod/<ts>)
	sh scripts/restore.sh --stack prod $(FILE)

deploy-prod: ## [prod] Gate, snapshot, build, tag, deploy, migrate and smoke-test a release
	sh scripts/deploy-prod.sh

rollback-prod: ## [prod] Roll back to a retained release (make rollback-prod REL=<sha>)
	sh scripts/rollback-prod.sh $(REL)

releases: ## [prod] List retained release artifacts
	sh scripts/releases.sh list

psql: ## Open a psql shell
	docker compose exec postgres psql -U postgres -d school_it_service_management

redis: ## Open a redis-cli shell
	docker compose exec redis sh -c 'redis-cli -a "$$REDIS_PASSWORD"'
