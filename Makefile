# —— 🛠️ Configuration ————————————————————————————————————————————————————————————————
.DEFAULT_GOAL := help
.PHONY: help csfixer phpstan installdeps updatedeps composer test test-unit test-integration clean bundle-validate

PHP_IMAGE := php:8.3-cli
DOCKER_VOLUME := -v "$(PWD)":/app -w /app
DOCKER_RUN := docker run --rm -it $(DOCKER_VOLUME) $(PHP_IMAGE)

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
	$(DOCKER_RUN) bash -c "php composer.phar update"

composer: ## Run composer in container (usage: make composer ARGS="require symfony/yaml")
	@$(DOCKER_RUN) php composer.phar $(ARGS)

composer-validate: ## Validate composer.json
	$(DOCKER_RUN) php composer.phar validate --strict

php: ## Open PHP 8.3 shell in container
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

## —— 🧪 QA tools ———————————————————————————————————————————————————————————————————————————
csfixer: ## Run PHP-CS-Fixer on src/ and tests/
	$(DOCKER_RUN) vendor/bin/php-cs-fixer fix src --rules=@Symfony --verbose
	$(DOCKER_RUN) vendor/bin/php-cs-fixer fix tests --rules=@Symfony --verbose

csfixer-check: ## Check code style without fixing
	$(DOCKER_RUN) vendor/bin/php-cs-fixer fix src --rules=@Symfony --dry-run --diff
	$(DOCKER_RUN) vendor/bin/php-cs-fixer fix tests --rules=@Symfony --dry-run --diff

phpstan: ## Run PHPStan static analysis
	$(DOCKER_RUN) vendor/bin/phpstan analyse src -c phpstan.neon --memory-limit=512M

phpstan-baseline: ## Generate PHPStan baseline
	$(DOCKER_RUN) vendor/bin/phpstan analyse src -c phpstan.neon --generate-baseline --memory-limit=512M

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
dev-setup: installdeps ## Complete development setup
	@echo "✅ Development environment setup complete!"
	@echo "Run 'make test' to verify everything works"

dev-check: composer-validate phpstan csfixer-check test-unit ## Run all development checks

ci-check: composer-validate phpstan test ## Run CI checks

all: clean installdeps dev-check ## Clean, install, and run all checks

quick-check: phpstan test-unit ## Quick development check