#!/bin/sh
set -eu

case "${APP_DB_USERNAME:-}" in
  ''|*[!A-Za-z0-9_]*)
    echo 'APP_DB_USERNAME must contain only letters, numbers, and underscores.' >&2
    exit 1
    ;;
esac

if [ -z "${APP_DB_PASSWORD:-}" ]; then
  echo 'APP_DB_PASSWORD is required.' >&2
  exit 1
fi

psql --set ON_ERROR_STOP=1 \
  --username "$POSTGRES_USER" \
  --dbname "$POSTGRES_DB" \
  --set app_database="$POSTGRES_DB" \
  --set app_user="$APP_DB_USERNAME" \
  --set app_password="$APP_DB_PASSWORD" <<'SQL'
CREATE ROLE :"app_user" LOGIN PASSWORD :'app_password' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
GRANT CONNECT, TEMPORARY, CREATE ON DATABASE :"app_database" TO :"app_user";
GRANT USAGE, CREATE ON SCHEMA public TO :"app_user";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLES TO :"app_user";
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO :"app_user";
SQL
