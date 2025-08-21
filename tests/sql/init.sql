-- Initialize PostgreSQL database for multi-tenant testing

-- Enable Row-Level Security
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Create test tables
CREATE TABLE IF NOT EXISTS test_tenants (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(255) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS test_products (
    id SERIAL PRIMARY KEY,
    tenant_id INTEGER NOT NULL REFERENCES test_tenants(id) ON DELETE CASCADE,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Enable Row-Level Security on test_products
ALTER TABLE test_products ENABLE ROW LEVEL SECURITY;

-- Create RLS policy for tenant isolation
CREATE POLICY tenant_isolation_policy ON test_products
    FOR ALL
    TO PUBLIC
    USING (tenant_id::text = current_setting('app.tenant_id', true))
    WITH CHECK (tenant_id::text = current_setting('app.tenant_id', true));

-- Grant necessary permissions
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO test_user;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO test_user;

-- Insert test data
INSERT INTO test_tenants (slug, name) VALUES 
    ('tenant-a', 'Tenant A'),
    ('tenant-b', 'Tenant B')
ON CONFLICT (slug) DO NOTHING;