#!/usr/bin/env sh
set -eu

usage() {
  echo "Usage: $0 <staging|production> <immutable-image-tag> --backup-id <id> --apply-migrations" >&2
  exit 64
}

[ "$#" -ge 5 ] || usage
environment=$1
image_tag=$2
shift 2
backup_id=
apply_migrations=false

while [ "$#" -gt 0 ]; do
  case "$1" in
    --backup-id)
      [ "$#" -ge 2 ] || usage
      backup_id=$2
      shift 2
      ;;
    --apply-migrations)
      apply_migrations=true
      shift
      ;;
    *) usage ;;
  esac
done

case "$environment" in
  staging|production) ;;
  *) usage ;;
esac

case "$image_tag" in
  *[!A-Za-z0-9._-]*|'')
    echo "Image tag must be an immutable release identifier (for example, a Git SHA)." >&2
    exit 64
    ;;
esac

[ -n "$backup_id" ] || usage
[ "$apply_migrations" = true ] || usage

if [ "$environment" = production ] && [ "${DEPLOY_PRODUCTION_APPROVED:-}" != "1" ]; then
  echo "Production deployment requires DEPLOY_PRODUCTION_APPROVED=1 after manual approval." >&2
  exit 77
fi

environment_file="/etc/cproviderprices/${environment}.env"
compose_file=infra/deployment/compose.production.yml
project="cproviderprices-${environment}"

if [ ! -f "$environment_file" ]; then
  echo "Runtime environment file does not exist: $environment_file" >&2
  exit 66
fi

export IMAGE_TAG=$image_tag

compose() {
  docker compose --env-file "$environment_file" -p "$project" -f "$compose_file" "$@"
}

compose config -q
compose pull
compose up -d postgres redis minio minio-init ingestion
compose run --rm migrate
compose up -d --wait --remove-orphans

echo "Release $image_tag is running in $environment after backup $backup_id."
echo "Run infra/deployment/scripts/smoke.sh $environment_file before declaring the release healthy."
