#!/bin/sh

set -eu

php bin/provision-tenant-migration-databases.php
php bin/assert-tenant-migrate-state.php clean

dry_run_output="$(php bin/console tenant:migrate --dry-run --no-interaction)"
printf '%s\n' "$dry_run_output"
printf '%s\n' "$dry_run_output" | grep -F 'CREATE TABLE consumer_migration_probe'
php bin/assert-tenant-migrate-state.php clean

php bin/console tenant:migrate --no-interaction
php bin/assert-tenant-migrate-state.php migrated

second_run_output="$(php bin/console tenant:migrate --no-interaction)"
printf '%s\n' "$second_run_output"
printf '%s\n' "$second_run_output" | grep -F 'No migrations to execute.'
php bin/assert-tenant-migrate-state.php migrated

php bin/seed-migration-tenants.php

if [ -n "${TENANT_DATABASE_A_URL:-}" ] && [ -n "${TENANT_DATABASE_B_URL:-}" ]; then
    MIGRATION_STATE_DATABASE_URL="$TENANT_DATABASE_A_URL" php bin/assert-tenant-migrate-state.php clean
    MIGRATION_STATE_DATABASE_URL="$TENANT_DATABASE_B_URL" php bin/assert-tenant-migrate-state.php clean

    multi_dry_run_output="$(APP_ENV=tenant_migration_multi DATABASE_STRATEGY=multi_db php bin/console tenant:migrate --dry-run --no-interaction)"
    printf '%s\n' "$multi_dry_run_output"
    printf '%s\n' "$multi_dry_run_output" | grep -F 'Migrating tenant: migration-a'
    printf '%s\n' "$multi_dry_run_output" | grep -F 'Migrating tenant: migration-b'
    MIGRATION_STATE_DATABASE_URL="$TENANT_DATABASE_A_URL" php bin/assert-tenant-migrate-state.php clean
    MIGRATION_STATE_DATABASE_URL="$TENANT_DATABASE_B_URL" php bin/assert-tenant-migrate-state.php clean

    APP_ENV=tenant_migration_multi DATABASE_STRATEGY=multi_db php bin/console tenant:migrate --no-interaction
    MIGRATION_STATE_DATABASE_URL="$TENANT_DATABASE_A_URL" php bin/assert-tenant-migrate-state.php migrated
    MIGRATION_STATE_DATABASE_URL="$TENANT_DATABASE_B_URL" php bin/assert-tenant-migrate-state.php migrated

    multi_second_output="$(APP_ENV=tenant_migration_multi DATABASE_STRATEGY=multi_db php bin/console tenant:migrate --no-interaction)"
    printf '%s\n' "$multi_second_output"
    printf '%s\n' "$multi_second_output" | grep -F 'No migrations to execute for tenant migration-a'
    printf '%s\n' "$multi_second_output" | grep -F 'No migrations to execute for tenant migration-b'

    APP_ENV=tenant_migration_multi DATABASE_STRATEGY=multi_db php bin/console tenant:migrate --tenant=migration-a --no-interaction
    APP_ENV=tenant_migration_multi DATABASE_STRATEGY=multi_db php bin/console tenant:migrate --tenant=migration-b --no-interaction
    APP_ENV=tenant_migration_multi DATABASE_STRATEGY=multi_db php bin/console tenant:migrate --tenant=migration-a --no-interaction

    if APP_ENV=tenant_migration_failure MIGRATION_FAILURE=1 DATABASE_STRATEGY=multi_db php bin/console tenant:migrate --tenant=migration-a --no-interaction; then
        printf '%s\n' 'tenant:migrate unexpectedly accepted the controlled failing migration.' >&2
        exit 1
    fi
    MIGRATION_STATE_DATABASE_URL="$TENANT_DATABASE_A_URL" php bin/assert-tenant-migrate-state.php migrated
    MIGRATION_STATE_DATABASE_URL="$TENANT_DATABASE_B_URL" php bin/assert-tenant-migrate-state.php migrated
else
    php bin/console tenant:migrate --tenant=migration-a --no-interaction
    php bin/console tenant:migrate --tenant=migration-b --no-interaction
fi

empty_output="$(APP_ENV=tenant_migration_empty MIGRATIONS_EMPTY=1 php bin/console tenant:migrate --allow-no-migration --no-interaction)"
printf '%s\n' "$empty_output"
printf '%s\n' "$empty_output" | grep -F 'No migrations to execute.'

if php bin/console tenant:migrate --tenant=unknown --no-interaction; then
    printf '%s\n' 'tenant:migrate unexpectedly accepted an unknown tenant.' >&2
    exit 1
fi

php bin/assert-tenant-migrate-state.php migrated
