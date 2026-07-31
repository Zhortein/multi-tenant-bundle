# Consumer Application Fixture

This fixture is a standalone Symfony application used only for compatibility validation. The workflow copies it outside the bundle source, configures the bundle as a non-symlinked Composer path repository, and installs it under the external application's vendor directory. Running outside the source is required by current Composer releases and proves that the bundle behaves as an external dependency.

GitHub Actions selects Symfony 7.4, 8.0, or 8.1, installs the fixture dependencies, and boots separate `shared_db` and `multi_db` kernels. Both configurations must compile with the optional Mailer and Messenger integrations enabled and without Twig, Monolog, or PSR-16.

From the repository root, reproduce the PHP 8.3 and Symfony 7.4 scenario in a fresh directory outside the repository:

```bash
cp -R tests/ConsumerApp /tmp/multi-tenant-consumer
docker run --rm -v "$PWD":/bundle -v /tmp/multi-tenant-consumer:/consumer -w /consumer composer:2 composer config --json repositories.bundle '{"type":"path","url":"/bundle","options":{"symlink":false}}'
docker run --rm -v "$PWD":/bundle -v /tmp/multi-tenant-consumer:/consumer -w /consumer composer:2 composer update --prefer-dist --no-progress --no-interaction
docker run --rm -v /tmp/multi-tenant-consumer:/consumer -w /consumer php:8.3-cli php bin/validate.php
docker run --rm -e DATABASE_STRATEGY=multi_db -v /tmp/multi-tenant-consumer:/consumer -w /consumer php:8.3-cli php bin/validate.php
```

The Symfony 8.1 job resolves the same direct components at `~8.1.0` and executes both validations with PHP 8.5. Use an isolated copy if a different dependency resolution is already present in the fixture.
