# External object storage consumer fixture

This disposable application uses only production bundle APIs. It owns its tenant
entity, namespace map, explicit backend services and Messenger message/handler.
Its non-debug kernel compiles with object storage disabled and no optional
packages. The enabled test variant adds exactly pinned development dependencies.

Copy this directory outside the bundle. Using Composer in the project's Docker
environment, configure a non-symlinked path repository to the bundle checkout.
Run `composer update --no-dev` and `php bin/validate.php` to prove actual absence
of Flysystem, the adapter and SDK. Then run `composer install`, set
`MTB_OBJECT_ENABLED=1`, and run `php bin/validate.php` again.

Enabled configuration needs caller-supplied `MTB_OBJECT_ENDPOINT`,
`MTB_OBJECT_SIGNING_ENDPOINT`, `MTB_OBJECT_BUCKET`, `MTB_OBJECT_ACCESS_KEY`,
`MTB_OBJECT_SECRET_KEY` and `MTB_OBJECT_CA` (CA file path). Both endpoints are
HTTPS S3-compatible origins. Compilation performs no storage I/O. Never put real
credentials in these files.

The bundle's MinIO suite reuses this kernel and handler with fresh test buckets.
It simulates JSON-column persistence, serializes references through Messenger,
and handles A/B/A on one Worker and kernel, including a controlled failure and
context cleanup. Dedicated/default-provider overrides and historical generations
are covered by the accompanying real MinIO tests.

The executable Docker recipe is `sh tests/ObjectStorage/run-minio.sh` from the
bundle checkout. The external installation steps for Symfony 7.4, 8.0 and 8.1 are
in `.github/workflows/object-storage.yml`. No Amazon hostname or business
authorization is required or provided.
