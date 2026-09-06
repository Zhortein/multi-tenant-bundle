#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
MTB_OBJECT_TLS_DIR=$(mktemp -d "${TMPDIR:-/tmp}/mtb-object-tls.XXXXXXXX")
export MTB_OBJECT_TLS_DIR
project="mtb-object-test-$(date +%s)-$$"
compose_file=tests/ObjectStorage/compose.yml
cleanup() {
    docker compose -p "$project" -f "$compose_file" down --volumes --remove-orphans
    # Only the fresh directory created by this invocation is removed.
    rm -rf -- "$MTB_OBJECT_TLS_DIR"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker run --rm --network none -v "$PWD:/app:ro" -v "$MTB_OBJECT_TLS_DIR:/tls" \
    "${MTB_OBJECT_PHP_IMAGE:-php:8.5.9-cli}" php /app/tests/ObjectStorage/generate-certificates.php /tls
docker compose -p "$project" -f "$compose_file" config --quiet
docker compose -p "$project" -f "$compose_file" up --detach --wait --wait-timeout 60 minio
docker compose -p "$project" -f "$compose_file" run --rm provision
docker compose -p "$project" -f "$compose_file" run --rm tests "$@"
