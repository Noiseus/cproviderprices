#!/bin/sh
set -eu
cd "$(dirname "$0")/../.."
E2E_INGESTION_TOKEN=$(openssl rand -hex 32)
export E2E_INGESTION_TOKEN
compose() {
  docker compose -p cproviderprices-e2e -f docker-compose.yml -f docker-compose.e2e.yml "$@"
}
trap 'compose stop' EXIT
compose up -d --wait
compose exec -T api php artisan migrate --force
compose exec -T api php artisan app:prepare-auth-e2e
compose exec -T api php artisan app:verify-pilot-e2e
compose exec -T web npm run smoke -- "$@"
if [ "$#" -eq 0 ] || [ "$1" = imports.spec.ts ]; then
  compose exec -T api php artisan app:verify-import-e2e
fi
