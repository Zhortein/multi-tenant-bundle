# Consumer Application Fixture

This fixture is a standalone Symfony application used only for compatibility validation. Its Composer path repository disables symlinks, so the bundle is copied under the fixture vendor directory and behaves like an external dependency.

GitHub Actions selects Symfony 7.4, 8.0, or 8.1, installs the fixture dependencies, and boots separate `shared_db` and `multi_db` kernels. Both configurations must compile with the optional Mailer integration enabled and without Twig, Monolog, or PSR-16.

From the repository root, reproduce the PHP 8.3 and Symfony 7.4 scenario with:

```bash
docker run --rm -v "$PWD":/workspace -w /workspace/tests/ConsumerApp composer:2 composer update --prefer-dist --no-progress --no-interaction
docker run --rm -v "$PWD":/workspace -w /workspace/tests/ConsumerApp php:8.3-cli php bin/validate.php
```

The Symfony 8.1 job resolves the same direct components at `~8.1.0` and executes both validations with PHP 8.5. Use an isolated copy if a different dependency resolution is already present in the fixture.
