# —— 🛠️ Configuration ————————————————————————————————————————————————————————————————
.DEFAULT_GOAL := help
.PHONY: help csfixer phpstan installdeps updatedeps composer test test-unit test-integration clean bundle-validate docs-validate test-tenant-migrate

PHP_IMAGE := php:8.5.9-cli
DOCKER_VOLUME := -v "$(PWD)":/app -w /app
DOCKER_RUN := docker run --rm $(DOCKER_VOLUME) $(PHP_IMAGE)

## —— 🎵 🐳 Zhortein's Multi-Tenant Bundle Makefile 🐳 🎵 ——————————————————————————————————
help: ## 📖 Show available commands
	@echo ""
	@echo "📖 Available make commands:"
	@echo ""
	@grep -E '(^[a-zA-Z0-9\./_-]+:.*?##.*$$)|(^##)' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}{printf "\033[32m%-30s\033[0m %s\n", $$1, $$2}' \
		| sed -e 's/\[32m##/[33m/'

## —— 🐳 Docker-based Composer actions ————————————————————————————————————————————
installdeps: ## Install Composer deps in container
	$(DOCKER_RUN) bash -c "apt update && apt install -y unzip git zip curl > /dev/null && \
		curl -sS https://getcomposer.org/installer | php && \
		php composer.phar install"

updatedeps: ## Update Composer deps in container
	$(DOCKER_RUN) bash -c "apt update && apt install -y unzip git zip curl > /dev/null && \
		php composer.phar update"

composer: ## Run composer in container (usage: make composer ARGS="require symfony/yaml")
	@$(DOCKER_RUN) php composer.phar $(ARGS)

composer-validate: ## Validate composer.json
	$(DOCKER_RUN) php composer.phar validate --strict --no-check-lock

php: ## Open the reference PHP 8.5.9 shell in container
	@$(DOCKER_RUN) bash

## —— 🧪 Testing ———————————————————————————————————————————————————————————————————————————
test: ## Run all PHPUnit tests
	$(DOCKER_RUN) vendor/bin/phpunit --configuration phpunit.xml.dist --no-coverage

test-unit: ## Run unit tests only
	$(DOCKER_RUN) vendor/bin/phpunit tests/Unit --no-coverage

test-integration: ## Run integration tests only
	$(DOCKER_RUN) vendor/bin/phpunit tests/Integration --no-coverage

test-coverage: ## Run tests with coverage report
	$(DOCKER_RUN) vendor/bin/phpunit --configuration phpunit.xml.dist --coverage-html coverage

test-kit: ## Run Test Kit integration tests
	$(DOCKER_RUN) vendor/bin/phpunit tests/Integration --no-coverage

test-rls: ## Run RLS isolation tests (requires PostgreSQL)
	docker compose -f tests/docker-compose.yml run --rm php-rls vendor/bin/phpunit --group rls --no-coverage

test-resolvers: ## Run resolver chain tests
	$(DOCKER_RUN) vendor/bin/phpunit tests/Integration/ResolverChainHttpTest.php tests/Integration/ResolverChainTest.php --no-coverage

test-messenger: ## Run Messenger tenant propagation tests
	$(DOCKER_RUN) vendor/bin/phpunit tests/Integration/MessengerTenantPropagationTest.php --no-coverage

test-cli: ## Run CLI tenant context tests
	$(DOCKER_RUN) vendor/bin/phpunit tests/Integration/CliTenantContextTest.php --no-coverage

test-decorators: ## Run decorator tests
	$(DOCKER_RUN) vendor/bin/phpunit tests/Integration/DecoratorsTest.php --no-coverage

test-tenant-migrate: ## Run real tenant:migrate tests (requires PostgreSQL)
	docker compose -f tests/docker-compose.yml run --rm php-rls vendor/bin/phpunit --group tenant-migrate --no-coverage

## —— 🧪 QA tools ———————————————————————————————————————————————————————————————————————————
csfixer: ## Run PHP-CS-Fixer on src/ and tests/
	$(DOCKER_RUN) vendor/bin/php-cs-fixer fix --verbose

csfixer-check: ## Check code style without fixing
	$(DOCKER_RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

phpstan: ## Run PHPStan static analysis
	$(DOCKER_RUN) vendor/bin/phpstan analyse src -c phpstan.neon --memory-limit=512M

phpstan-baseline: ## Generate PHPStan baseline
	$(DOCKER_RUN) vendor/bin/phpstan analyse src -c phpstan.neon --generate-baseline --memory-limit=512M

## —— 🐘 PostgreSQL Test Environment ——————————————————————————————————————————————————————
postgres-start: ## Start PostgreSQL test container
	@echo "🐘 Starting PostgreSQL test container..."
	cd tests && docker compose up -d postgres
	@echo "⏳ Waiting for PostgreSQL to be ready..."
	cd tests && docker compose exec postgres pg_isready -U test_user -d multi_tenant_test || sleep 5
	@echo "✅ PostgreSQL is ready!"

postgres-stop: ## Stop PostgreSQL test container
	@echo "🛑 Stopping PostgreSQL test container..."
	cd tests && docker compose down

postgres-logs: ## Show PostgreSQL logs
	cd tests && docker compose logs postgres

postgres-shell: ## Connect to PostgreSQL shell
	cd tests && docker compose exec postgres psql -U test_user -d multi_tenant_test

POSTGRES_IMAGE ?= postgres:18-alpine

test-with-postgres: ## Run RLS and tenant:migrate tests with PostgreSQL
	@set -e; \
	trap 'POSTGRES_IMAGE=$(POSTGRES_IMAGE) docker compose -f tests/docker-compose.yml down -v' EXIT; \
	POSTGRES_IMAGE=$(POSTGRES_IMAGE) docker compose -f tests/docker-compose.yml up -d --wait postgres; \
	POSTGRES_IMAGE=$(POSTGRES_IMAGE) docker compose -f tests/docker-compose.yml exec -T postgres postgres --version; \
	POSTGRES_IMAGE=$(POSTGRES_IMAGE) docker compose -f tests/docker-compose.yml run --rm php-rls vendor/bin/phpunit --group rls --no-coverage; \
	POSTGRES_IMAGE=$(POSTGRES_IMAGE) docker compose -f tests/docker-compose.yml run --rm php-rls vendor/bin/phpunit --group tenant-migrate --no-coverage

test-with-postgres-16: ## Run mandatory RLS and multi-database tests with PostgreSQL 16
	$(MAKE) test-with-postgres POSTGRES_IMAGE=postgres:16-alpine

test-with-postgres-18: ## Run mandatory RLS and multi-database tests with PostgreSQL 18
	$(MAKE) test-with-postgres POSTGRES_IMAGE=postgres:18-alpine

validate-testkit: ## Validate Test Kit setup and configuration
	docker compose -f tests/docker-compose.yml run --rm --no-deps php-rls php tests/validate-testkit.php

docs-validate: ## Validate documentation links and release claims
	$(DOCKER_RUN) php tests/validate-documentation.php

## —— 🔧 Bundle-specific ———————————————————————————————————————————————————————————————————
bundle-validate: ## Validate bundle structure
	@echo "🔍 Validating bundle structure..."
	@test -f src/ZhorteinMultiTenantBundle.php || (echo "❌ Bundle class missing" && exit 1)
	@test -f src/DependencyInjection/ZhorteinMultiTenantExtension.php || (echo "❌ Extension class missing" && exit 1)
	@test -f src/DependencyInjection/Configuration.php || (echo "❌ Configuration class missing" && exit 1)
	@echo "✅ Bundle structure is valid!"

## —— 🧹 Cleanup ———————————————————————————————————————————————————————————————————————————
clean: ## Clean generated files
	rm -rf coverage/
	rm -rf .phpunit.cache/
	rm -rf var/cache/
	rm -rf var/log/

clean-vendor: ## Remove vendor directory
	rm -rf vendor/

## —— 🚀 Development workflow ——————————————————————————————————————————————————————————————
dev-setup: installdeps validate-testkit ## Complete development setup
	@echo "✅ Development environment setup complete!"
	@echo "Run 'make test' to verify everything works"

dev-check: composer-validate phpstan csfixer-check test-unit test-kit ## Run all development checks

ci-check: composer-validate docs-validate phpstan test ## Run CI checks

ci-check-full: composer-validate phpstan test test-with-postgres ## Run CI checks with PostgreSQL

all: clean installdeps dev-check ## Clean, install, and run all checks

quick-check: phpstan test-unit ## Quick development check

test-all: test test-kit ## Run all tests including Test Kit
