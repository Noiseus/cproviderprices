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
	docker compose exec \
		-e WEB_HEALTH_URL=http://localhost:3000/api/health \
		-e API_HEALTH_URL=http://api:8080/api/v1/health \
		-e API_READINESS_URL=http://api:8080/api/v1/readiness \
		-e INGESTION_HEALTH_URL=http://ingestion:8001/health \
		-e MAILPIT_URL=http://mailpit:8025/ \
		web node infra/scripts/smoke.mjs

migrate:
	docker compose exec api php artisan migrate --force

seed:
	docker compose exec api php artisan db:seed --force

logs:
	docker compose logs -f --tail=200
