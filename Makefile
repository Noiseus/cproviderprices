.PHONY: setup up down lint typecheck test contract e2e migrate seed logs

setup:
	sh infra/scripts/setup.sh

up:
	docker compose up -d --wait

down:
	docker compose down

lint:
	docker compose run --rm --no-deps web npm run lint
	docker compose run --rm --no-deps api ./vendor/bin/pint --test
	docker compose run --rm --no-deps ingestion ruff format --check src tests
	docker compose run --rm --no-deps ingestion ruff check src tests

typecheck:
	docker compose run --rm --no-deps web npm run typecheck
	docker compose run --rm --no-deps api ./vendor/bin/phpstan analyse --memory-limit=512M
	docker compose run --rm --no-deps ingestion mypy src tests

test:
	docker compose run --rm --no-deps web npm run test
	docker compose run --rm --no-deps -e APP_ENV=testing -e DB_DATABASE=cproviderprices_test -e SESSION_DRIVER=array -e CACHE_STORE=array api php artisan test
	docker compose run --rm --no-deps ingestion pytest

contract:
	docker compose run --rm --no-deps web npm run contract:lint
	docker compose run --rm --no-deps web npm run check:api-client

e2e:
	sh infra/scripts/test-browser.sh

migrate:
	docker compose exec api php artisan migrate --force

seed:
	docker compose exec api php artisan db:seed --force

logs:
	docker compose logs -f --tail=200
