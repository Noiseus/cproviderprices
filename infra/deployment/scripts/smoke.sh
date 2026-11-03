#!/usr/bin/env sh
set -eu

if [ "$#" -ne 1 ]; then
  echo "Usage: $0 <environment-file>" >&2
  exit 64
fi

environment_file=$1
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

# A Compose environment file is not a POSIX shell script: unquoted values may
# legitimately contain spaces (for example, MAIL_FROM_NAME). Read only the
# smoke values needed here instead of sourcing the whole protected file.
read_required() {
  key=$1
  value=$(sed -n "s/\r$//; /^${key}=/ { s/^${key}=//; p; q; }" "$environment_file")

  if [ -z "$value" ]; then
    echo "$key must be supplied from the secure deployment environment." >&2
    exit 78
  fi

  printf '%s\n' "$value"
}

WEB_URL=$(read_required WEB_URL)
APP_URL=$(read_required APP_URL)
SMOKE_EMAIL=$(read_required SMOKE_EMAIL)
SMOKE_PASSWORD=$(read_required SMOKE_PASSWORD)
SMOKE_READONLY_URL=$(read_required SMOKE_READONLY_URL)

cookie_jar=$(mktemp)
trap 'rm -f "$cookie_jar"' EXIT HUP INT TERM

curl --fail --silent --show-error "$WEB_URL/api/health" >/dev/null
curl --fail --silent --show-error "$APP_URL/api/v1/health" >/dev/null
curl --fail --silent --show-error "$APP_URL/api/v1/readiness" >/dev/null

curl --fail --silent --show-error --cookie-jar "$cookie_jar" "$WEB_URL/api/v1/auth/csrf-cookie" >/dev/null
xsrf_token=$(awk '$6 == "XSRF-TOKEN" { print $7 }' "$cookie_jar" | tail -n 1)
if [ -z "$xsrf_token" ]; then
  echo "CSRF cookie was not issued by the authenticated app origin." >&2
  exit 1
fi

curl --fail --silent --show-error \
  --cookie "$cookie_jar" \
  --cookie-jar "$cookie_jar" \
  --header "X-XSRF-TOKEN: $xsrf_token" \
  --data-urlencode "email=$SMOKE_EMAIL" \
  --data-urlencode "password=$SMOKE_PASSWORD" \
  "$WEB_URL/api/v1/auth/login" >/dev/null
curl --fail --silent --show-error --cookie "$cookie_jar" "$SMOKE_READONLY_URL" >/dev/null

docker compose --env-file "$environment_file" -p "$project" -f "$compose_file" \
  exec -T api php artisan app:verify-queue --timeout=15

echo "Web, API, readiness, authenticated read-only route, object storage, Redis, database, and queue smoke checks passed."
