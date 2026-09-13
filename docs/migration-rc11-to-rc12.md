# RC11 to RC12: future optional audit adoption

RC12 is not published by this development batch. Continue using the published
RC11 version until a separate release decision. This document describes future
adoption of the [optional audit contract](object-storage-audit.md).

1. Validate the candidate bundle and its exact dependency graph in a disposable
   consumer. Existing RC11 calls, implementations and v1 reference JSON need no edits.
2. Keep `object_storage.audit.enabled: false` unless adopting the new capability.
   Minimal production installations continue without Flysystem or the S3 adapter.
3. Assign every registered historical generation to its logical provider. Preserve
   immutable location IDs, effective bindings, tenant namespaces and allowlists.
   Do not discover targets or allocate namespaces as part of inventory.
4. If adopting audit, provision an independent random application key outside code
   and compiled configuration. Register its runtime codec, explicitly select capable
   backends and enable the audit block. Keep old verification keys during rotation.
5. Opt **new writes** into `writeWithIdentity` or `writeFromStreamWithIdentity`,
   persisting the application's expected identity separately as appropriate. Such
   consumer persistence design is outside the bundle and introduces no bundle SQL.
6. Read/audit existing objects without changing them. Missing historical identity
   remains indeterminate for logical provenance. Never infer it from content or
   physical-key fragments. There is no backfill, object rewrite, metadata migration,
   reference conversion or automatic repair.
7. Preserve uncertainty and incomplete pages in the consumer's own audit result.
   Test A/B/A, resets, historical generation routing, credential/key rotation,
   anomaly continuation and failure handling before any separate consumer adoption.

Disabling audit leaves the existing facade and historical references available.
Retain keys securely if later observations need them. Disabling audit does not
remove metadata or objects. No tag, release, package publication, destructive
cleanup or application integration is authorized by this document.
