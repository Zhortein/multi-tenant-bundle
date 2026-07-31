# Issue 12 Security Contract Audit

Date: 2026-07-31

## Previous state

The integration suite exercised selected happy paths but did not establish a uniform no-tenant contract. Tenant storage used an implicit `default/` fallback, allowing ambiguity with a tenant named `default`, and path examples normalized unsafe input. Cache decorators could fall through to unprefixed keys without context and generic `clear()` could clear data outside the active tenant. Templated mail added tenant identifier and name headers without explicit consent. Observability copied unbounded or confidential event fields into labels and logs.

Those passing tests were a false positive for the complete integration-isolation contract: they did not prove all operations failed closed or that tenant-less/global namespaces were distinct.

## Effective coverage

The repaired suite verifies:

- local and S3 tenant namespaces, safe nested paths, missing context, unsafe tenant identifiers, traversal and encoded paths;
- tenant A/B read, overwrite, list, and delete isolation;
- isolation of a tenant named `default` from explicit global storage;
- local symbolic-link escape prevention;
- cache tenant A/B isolation, no-context failure, hashed namespaces, scoped clearing, and rejection of unsafe generic clearing;
- no email metadata headers by default, independent opt-in, preservation of application headers, injection rejection, and serialization behavior;
- bounded observability fields;
- existing Messenger tenant propagation and cleanup contracts;
- the external consumer container in shared-database and multi-database modes.

PostgreSQL RLS remains independently verified with raw DBAL queries and the Doctrine tenant filter disabled where required.
