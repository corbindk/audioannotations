# SPDX-FileCopyrightText: 2026 Corbin Keener
# SPDX-License-Identifier: AGPL-3.0-or-later

# Tooling runs in throwaway containers so nothing is installed on the host.
UID_GID := $(shell id -u):$(shell id -g)
NODE := docker run --rm -u $(UID_GID) -e HOME=/tmp -v $(CURDIR):/app -w /app node:22-alpine
COMPOSER := docker run --rm -u $(UID_GID) -e HOME=/tmp -v $(CURDIR):/app -w /app composer:2
PHP := docker run --rm -u $(UID_GID) -v $(CURDIR):/app -w /app php:8.3-cli
# End-to-end tests: the official Playwright image (version must match @playwright/test), on the host network
# so NC_URL (default http://localhost:8090) is reachable. Passwords come from the environment or ENV_FILE.
PLAYWRIGHT := docker run --rm --network host --ipc=host -u $(UID_GID) -e HOME=/tmp -v $(CURDIR):/app -w /app \
	-e NC_URL -e NC_ADMIN_PASSWORD -e TEST_USER_PASSWORD -e TEST_USER2_PASSWORD -e DEMO_PASSWORD mcr.microsoft.com/playwright:v1.64.1-noble
ENV_FILE ?= .env.e2e
VERSION := $(shell sed -n 's:.*<version>\(.*\)</version>.*:\1:p' appinfo/info.xml)

.PHONY: deps build watch test test-js test-e2e screenshots lint cs-fix appstore

deps:
	$(NODE) npm ci
	$(COMPOSER) install --no-interaction --no-progress

build:
	$(NODE) npm run build

watch:
	$(NODE) npm run watch

test:
	$(PHP) vendor/bin/phpunit

test-js:
	$(NODE) npx vitest run

# make test-e2e ARGS="--project=phone tests/e2e/notes.spec.js" to narrow it down.
test-e2e:
	set -a; if [ -f $(ENV_FILE) ]; then . ./$(ENV_FILE); fi; set +a; $(PLAYWRIGHT) npx playwright test $(ARGS)

# Regenerate screenshots/ with demo users and notes (needs NC_ADMIN_PASSWORD and DEMO_PASSWORD).
screenshots:
	set -a; if [ -f $(ENV_FILE) ]; then . ./$(ENV_FILE); fi; set +a; $(PLAYWRIGHT) node tools/screenshots.mjs

# Nextcloud's checkers: PHP coding standard, Psalm, ESLint, Stylelint.
lint:
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff
	$(PHP) php -d memory_limit=1G vendor/bin/psalm --no-cache --threads=4 --no-progress
	$(NODE) npx eslint src tests tools package.json vite.config.js vitest.config.js playwright.config.js eslint.config.js
	$(NODE) npx stylelint "src/**/*.{vue,css}"

cs-fix:
	$(PHP) vendor/bin/php-cs-fixer fix
	$(NODE) npx eslint --fix src tests tools vite.config.js vitest.config.js playwright.config.js eslint.config.js
	$(NODE) npx stylelint --fix "src/**/*.{vue,css}"

appstore: build
	mkdir -p build
	tar -czf build/audioannotations-$(VERSION).tar.gz --transform 's,^,audioannotations/,' \
		appinfo lib js css LICENSES README.md CHANGELOG.md
