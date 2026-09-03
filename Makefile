.PHONY: setup up down lint typecheck test e2e migrate seed logs

setup:
	sh infra/scripts/setup.sh

up:
	docker compose up -d --wait

down:
	docker compose down

lint:
	docker compose run --rm --no-deps web npm run lint
	docker compose run --rm --no-deps api ./vendor/bin/pint --test
	docker compose run --rm --no-deps ingestion ruff check src tests

typecheck:
	docker compose run --rm --no-deps web npm run typecheck
	docker compose run --rm --no-deps ingestion mypy src tests

test:
	docker compose run --rm --no-deps web npm run test
	docker compose run --rm --no-deps api php artisan test
	docker compose run --rm --no-deps ingestion pytest

e2e:
	node infra/scripts/smoke.mjs

migrate:
	docker compose exec api php artisan migrate --force

seed:
	docker compose exec api php artisan db:seed --force

logs:
	docker compose logs -f --tail=200
