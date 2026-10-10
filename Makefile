# SccIT shortcuts.  Usage: make <target>
# (Requires GNU make. On Windows you can instead run the scripts/*.sh
#  files via Git Bash, or the raw `docker compose` commands.)
#
# The development stack (compose.yaml, :8080) was RETIRED on 2026-10-10. Its
# targets survive only as stubs that stop with a pointer (RETIRED, below), so
# an old habit cannot recreate that stack on its kept volumes. Production
# (:8081) is the only stack.
.DEFAULT_GOAL := help
SHELL := /bin/sh

RETIRED := up down restart build rebuild logs ps shell migrate fresh test lint format \
           psql redis backup restore smoke-dev e2e e2e-a11y e2e-smoke

.PHONY: help $(RETIRED) \
        prod-build prod-up prod-setup prod-down prod-logs prod-ps prod-shell \
        gates smoke backup-prod verify-backup restore-prod deploy-prod rollback-prod releases

# Production stack (compose.prod.yaml). The explicit `-p sccit_prod` is
# REQUIRED: the root .env's COMPOSE_PROJECT_NAME=sccit would otherwise place
# this stack in a different Compose project.
PROD := docker compose -p sccit_prod -f compose.prod.yaml

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-14s\033[0m %s\n",$$1,$$2}'

$(RETIRED):
	@echo "make $@: retired with the development stack on 2026-10-10." >&2
	@echo "  Production (:8081): make prod-ps | make smoke | make deploy-prod     Tests: make gates" >&2
	@echo "  See docs/PRODUCTION_DEVELOPER_FILE_MAP.md" >&2
	@exit 1

prod-build: ## [prod] Build the production images (deploy-prod also tags them)
	$(PROD) build

prod-up: ## [prod] Start the production stack (:8081)
	$(PROD) up -d && $(PROD) ps

prod-setup: ## [prod] First-time setup only: migrate + seed the production database
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

gates: ## Full quality gate in a disposable environment (Pint, PHPStan, Pest, tsc, ESLint, Prettier, Vitest, build)
	sh scripts/gates.sh

smoke: ## [prod] HTTP smoke test against :8081
	sh scripts/smoke.sh --stack prod

backup-prod: ## [prod] Snapshot database + uploads to backups/prod/ and prove it restores
	sh scripts/backup.sh --stack prod --verify

verify-backup: ## [prod] Prove a snapshot restores, in a disposable DB (make verify-backup FILE=backups/prod/<ts>)
	sh scripts/verify-backup.sh $(FILE)

restore-prod: ## [prod] Restore a production snapshot (make restore-prod FILE=backups/prod/<ts>)
	sh scripts/restore.sh --stack prod $(FILE)

deploy-prod: ## [prod] Gate, snapshot, build, tag, deploy, migrate and smoke-test a release
	sh scripts/deploy-prod.sh

rollback-prod: ## [prod] Roll back to a retained release (make rollback-prod REL=<sha>)
	sh scripts/rollback-prod.sh $(REL)

releases: ## [prod] List retained release artifacts
	sh scripts/releases.sh list
