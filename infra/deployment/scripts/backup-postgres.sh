#!/usr/bin/env sh
set -eu

if [ "$#" -ne 2 ]; then
  echo "Usage: $0 <environment-file> <backup-directory>" >&2
  exit 64
fi

environment_file=$1
backup_directory=$2
compose_file=infra/deployment/compose.production.yml

case "$environment_file" in
  *staging.env) project=cproviderprices-staging ;;
  *production.env) project=cproviderprices-production ;;
  *)
    echo "Environment file must be the staging or production runtime file." >&2
    exit 64
    ;;
esac

if [ ! -f "$environment_file" ]; then
  echo "Runtime environment file does not exist: $environment_file" >&2
  exit 66
fi

# Docker Compose reads --env-file itself, but pg_dump below also needs the
# database name in this shell. Compose environment files are not shell scripts:
# values such as MAIL_FROM_NAME may contain spaces, so extract only this value.
POSTGRES_DB=$(sed -n 's/\r$//; /^POSTGRES_DB=/ { s/^POSTGRES_DB=//; p; q; }' "$environment_file")
if [ -z "$POSTGRES_DB" ]; then
  echo "POSTGRES_DB must be supplied by the runtime environment file." >&2
  exit 78
fi

umask 077
mkdir -p "$backup_directory"
timestamp=$(date -u +%Y%m%dT%H%M%SZ)
backup_file="$backup_directory/${project}-${timestamp}.dump"

docker compose --env-file "$environment_file" -p "$project" -f "$compose_file" \
  exec -T postgres pg_dump -U postgres -Fc --no-owner --no-acl "$POSTGRES_DB" >"$backup_file"

if [ ! -s "$backup_file" ]; then
  rm -f "$backup_file"
  echo "PostgreSQL backup did not produce a non-empty archive." >&2
  exit 1
fi

sha256sum "$backup_file" >"${backup_file}.sha256"
printf '%s\n' "$backup_file"
