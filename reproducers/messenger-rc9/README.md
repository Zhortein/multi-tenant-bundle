# Public RC9 Messenger composition reproducer

This is a diagnostic for **the vulnerable RC9 behavior**, not a passing RC10
regression test. Keep it outside the normal PHPUnit suite. A successful run
proves that an application's `validation` list removes the bundle middleware,
allows an unclassified message into a serialized Doctrine transport, and lets
a tenant A handler execute without its tenant after a real Worker receives it.
It also proves that Symfony validation still rejects an invalid payload.

The package must be `v1.0.0-rc.9` at
`9af00b6903803b627dd5b08119bcb5ab49d7a713`. Composer resolves it directly from
Packagist. There is no path, VCS, fork, or temporary package repository.
The consumer application comes from the official fixture at that same commit.

## Fresh consumer

Run from the bundle root. Use the existing Consumer App Docker image if one is
available; otherwise build the documented image with PHP 8.5.9:

```bash
docker build --tag mtb-repro-php859 --file tests/ConsumerApp/Dockerfile tests/ConsumerApp
REPRO_DIR="$(mktemp -d /tmp/mtb-rc9-consumer.XXXXXX)"
git archive 9af00b6903803b627dd5b08119bcb5ab49d7a713 tests/ConsumerApp \
    | tar -x --strip-components=2 -C "$REPRO_DIR"
docker run --rm --user "$(id -u):$(id -g)" \
    -v "$PWD":/bundle:ro -v "$REPRO_DIR":/consumer mtb-repro-php859 \
    php /bundle/reproducers/messenger-rc9/prepare.php /consumer 8.1 aligned
docker run --rm --user "$(id -u):$(id -g)" -e COMPOSER_HOME=/tmp/composer \
    -v "$REPRO_DIR":/consumer -w /consumer mtb-repro-php859 \
    composer update --prefer-dist --no-interaction --no-progress
```

Select `7.4`, `8.0`, or `8.1` in `prepare.php`. Use `8.1 exact` to select the
existing exact reference-consumer matrix: FrameworkBundle/Cache/Messenger/
Scheduler 8.1.5, Doctrine Messenger 8.1.4, SecurityBundle 8.1.6, ORM 3.6.8,
DBAL 4.4.4, DoctrineBundle 3.3.1, DoctrineMigrationsBundle 4.0.1 and migration
core 3.9.7. Validator and Yaml are additional aligned consumer dependencies.
The exact matrix is checked with `php bin/assert-exact-versions.php`.

Always export a **new** directory for each dependency graph. The preparation
script rejects an existing lock, vendor directory, or alternative repository.

## Isolated PostgreSQL and real Worker

Use a new, empty database. The test creates the Messenger table and inspects
its row count. Never point it at an application or production database.
This example creates its own Docker network and database without publishing
ports or mounting application volumes:

```bash
docker network create mtb-rc9-reproduction
docker run -d --rm --name rc9-repro-db --network mtb-rc9-reproduction \
    -e POSTGRES_HOST_AUTH_METHOD=trust -e POSTGRES_USER=repro \
    -e POSTGRES_DB=rc9_repro postgres:16-alpine
docker exec rc9-repro-db pg_isready -U repro -d rc9_repro
docker run --rm --user "$(id -u):$(id -g)" --network mtb-rc9-reproduction \
    -e DATABASE_URL='postgresql://repro@rc9-repro-db/rc9_repro?serverVersion=16' \
    -v "$REPRO_DIR":/consumer -w /consumer mtb-repro-php859 \
    vendor/bin/phpunit --colors=never --no-coverage tests/Rc9CompositionReproductionTest.php
```

Wait for `pg_isready` to succeed before the final command. PostgreSQL 18 uses
the same test code with `postgres:18-alpine` and `serverVersion=18`, in a
separate isolated database. The test isolates compiled caches by database URL.

The diagnostic prints the actual bus constructor references captured **after
Symfony's MessengerPass** and then exercises the dumped container's real bus.
The capture pass only observes definitions; it neither installs nor removes a
middleware. Its use of Symfony's generated definition shape is diagnostic
evidence, not a claim that this is a guaranteed public composition hook.

Expected outcome: `OK (1 test, 11 assertions)` with both defect messages.
An RC10 candidate must eventually invert the missing-middleware and unsafe
behavior assertions and add the full release matrix; this diagnostic alone
does not validate such a candidate.

See the [design audit](../../docs/audit-rc9-messenger-composition.md) for the
resumed prototype comparison and the permanent RC10 regression suite.
