-- Create the isolated databases required by the PostgreSQL test harness.
-- psql's \gexec executes CREATE DATABASE only for databases that are absent.

SELECT format('CREATE DATABASE %I', database_name)
FROM (VALUES
    ('multi_tenant_kernel_test'),
    ('messenger_tenant_a_test'),
    ('messenger_tenant_b_test'),
    ('messenger_global_test')
) AS required_databases(database_name)
WHERE NOT EXISTS (
    SELECT FROM pg_database WHERE datname = required_databases.database_name
)
\gexec
