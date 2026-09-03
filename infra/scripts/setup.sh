#!/usr/bin/env sh
set -eu

copy_example() {
  destination="$1"
  source="$2"
  if [ ! -f "$destination" ]; then
    cp "$source" "$destination"
  fi
}

copy_example .env .env.example
copy_example apps/web/.env apps/web/.env.example
copy_example apps/api/.env apps/api/.env.example
copy_example services/ingestion/.env services/ingestion/.env.example

docker compose config --quiet
docker compose build
docker compose run --rm --no-deps api php artisan key:generate --force

echo "Setup complete. Run 'make up' to start the local stack."
