#!/usr/bin/env sh
set -eu

if [ "$#" -ne 3 ]; then
  echo "Usage: $0 <environment-file> <backup.dump> <isolated_restore_database>" >&2
  exit 64
fi

environment_file=$1
backup_file=$2
restore_database=$3
compose_file=infra/deployment/compose.production.yml

case "$environment_file" in
  *staging.env) project=cproviderprices-staging ;;
  *production.env) project=cproviderprices-production ;;
  *)
    echo "Environment file must be the staging or production runtime file." >&2
    exit 64
    ;;
esac

case "$restore_database" in
  *_restore) ;;
  *)
    echo "Restore target must end in _restore; refusing to target a live database." >&2
    exit 64
    ;;
esac

if [ ! -f "$environment_file" ] || [ ! -s "$backup_file" ]; then
  echo "A readable runtime environment file and non-empty backup archive are required." >&2
  exit 66
fi

if [ -f "${backup_file}.sha256" ]; then
  (cd "$(dirname "$backup_file")" && sha256sum -c "$(basename "${backup_file}.sha256")")
fi

compose() {
  docker compose --env-file "$environment_file" -p "$project" -f "$compose_file" "$@"
}

compose exec -T postgres dropdb -U postgres --if-exists "$restore_database"
compose exec -T postgres createdb -U postgres "$restore_database"
compose exec -T -i postgres pg_restore -U postgres --exit-on-error --no-owner --no-acl --dbname "$restore_database" <"$backup_file"
compose exec -T postgres psql -U postgres -d "$restore_database" -c 'select count(*) as migration_count from migrations;'

echo "Restore completed only in isolated database: $restore_database"
