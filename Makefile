# Thai Lottery — developer helpers.
#
# Thin wrappers around composer / artisan / cargo / npm / docker so there is
# no hidden magic: reading any target shows exactly what runs.
#
#   make install          composer + npm + cargo deps
#   make test             narrow CI gate (Deployment/Health/Infra/Transfer + Rust)
#   make migrate-fresh    wipe + rebuild DB (APP_ENV=testing)
#   make lint             php -l + bash -n + cargo fmt/clippy
#   make docker-build     build both production images (requires dockerd)
#   make help             list all targets

SHELL        := /bin/bash
.SHELLFLAGS  := -eu -o pipefail -c
.DEFAULT_GOAL := help

APP_IMAGE     ?= ghcr.io/$(shell echo "$${GITHUB_REPOSITORY:-local/thai-lottery}" | tr '[:upper:]' '[:lower:]')/app
WORKER_IMAGE  ?= ghcr.io/$(shell echo "$${GITHUB_REPOSITORY:-local/thai-lottery}" | tr '[:upper:]' '[:lower:]')/settlement-worker

PHP_FILES     := app config database routes scripts
SH_FILES      := $(wildcard docker/**/*.sh docker/*.sh)

.PHONY: help install install-php install-js install-rust
.PHONY: assets assets-dev
.PHONY: migrate migrate-fresh seed
.PHONY: test test-php test-rust test-infra
.PHONY: lint lint-php lint-sh lint-rust
.PHONY: audit phpstan env-parity
.PHONY: docker-build docker-build-app docker-build-worker docker-up docker-down
.PHONY: clean

help: ## Show this help
	@echo "Thai Lottery — developer helpers"
	@echo ""
	@grep -E '^[a-zA-Z0-9_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
	  | awk 'BEGIN{FS=":.*?## "}; {printf "  \033[36mmake %-20s\033[0m %s\n", $$1, $$2}'
	@echo ""
	@echo "Overridable:"
	@echo "  APP_IMAGE=$${APP_IMAGE:-ghcr.io/local/thai-lottery/app}"
	@echo "  WORKER_IMAGE=$${WORKER_IMAGE:-ghcr.io/local/thai-lottery/settlement-worker}"

install: install-php install-js install-rust ## Install all dependencies (composer + npm + cargo)

install-php: ## composer install
	composer install --prefer-dist --no-interaction --no-progress

install-js: ## npm ci
	npm ci

install-rust: ## cargo fetch (Rust deps)
	cd crates/settlement-worker && cargo fetch --locked

assets: ## Build production frontend -> public/build/
	npm run build

assets-dev: ## Vite dev server with HMR
	npm run dev

migrate: ## Run migrations (APP_ENV from .env)
	php artisan migrate --force

migrate-fresh: ## Wipe & rebuild (APP_ENV=testing)
	APP_ENV=testing php artisan migrate:fresh

seed: ## Run database seeders (dev)
	php artisan db:seed

test: test-rust test-infra ## Narrow CI gate (Rust + infra PHPUnit)

test-rust: ## cargo test --locked (settlement-worker)
	cd crates/settlement-worker && cargo test --locked

test-infra: ## PHPUnit: Deployment/Health/Infrastructure/Transfer
	php artisan test tests/Feature/Deployment/ tests/Feature/HealthCheckTest.php tests/Unit/Infrastructure/ tests/Unit/Transfer/

test-php: ## Full phpunit (slow; may contain pre-existing failures out of DEVOPS scope)
	php artisan test

lint: lint-php lint-sh lint-rust ## Run all syntax/format checks

lint-php: ## php -l over every project PHP file
	@set +e; rc=0; \
	find $(PHP_FILES) -name '*.php' -print0 | while IFS= read -r -d '' f; do \
	  php -l "$$f" >/dev/null || { echo "SYNTAX ERROR in $$f"; rc=1; }; \
	done; \
	exit $$rc

lint-sh: ## bash -n over every docker/*.sh
	@for f in $(SH_FILES); do bash -n "$$f" && echo "ok: $$f"; done

lint-rust: ## cargo fmt --check + cargo clippy -D warnings
	cd crates/settlement-worker && cargo fmt --all -- --check
	cd crates/settlement-worker && cargo clippy --all-targets -- -D warnings

audit: ## composer audit + env parity (+ cargo audit if installed)
	composer audit --no-interaction
	php scripts/check-env-parity.php
	@if command -v cargo-audit >/dev/null 2>&1; then cd crates/settlement-worker && cargo audit; fi

phpstan: ## Run PHPStan at configured level
	./vendor/bin/phpstan analyse --memory-limit=1G

env-parity: ## Check .env.ci / .env.production.example / config alignment
	php scripts/check-env-parity.php

docker-build: docker-build-app docker-build-worker ## Build both production images locally

docker-build-app: ## Build PHP app image
	docker build -f docker/php/Dockerfile -t $(APP_IMAGE):dev .

docker-build-worker: ## Build Rust settlement-worker image
	docker build -f docker/rust/Dockerfile -t $(WORKER_IMAGE):dev crates/settlement-worker/

docker-up: ## Start production compose stack
	docker compose -f docker-compose.production.yml up -d

docker-down: ## Stop production compose stack
	docker compose -f docker-compose.production.yml down

clean: ## Remove build artifacts (keeps vendor/node_modules)
	rm -rf public/build
	cd crates/settlement-worker && cargo clean
	php artisan optimize:clear
