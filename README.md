# CProviderPrices

CProviderPrices is a commercial, multi-tenant B2B SaaS platform for supplier price comparison, vendor management, and purchasing optimization for restaurants and hospitality businesses. The initial market is North Carolina, with an architecture intended for later US expansion.

## Project status

Prompt 01 establishes the monorepo and reproducible local-development foundation. The repository currently contains operational health/readiness behavior only. Authentication, tenants, users, supplier integrations, product data, purchasing workflows, billing, and deployment are not implemented.

## Architecture

- `apps/web`: Next.js 16 with strict TypeScript.
- `apps/api`: Laravel 12 with versioned routes under `/api/v1`.
- `services/ingestion`: internal Python/FastAPI service with typed schemas.
- `packages/api-contracts`: OpenAPI source contract.
- `packages/generated-api-client`: generated TypeScript API schema types.
- `packages/design-tokens`, `packages/domain-types`, `packages/i18n`: shared package boundaries.
- PostgreSQL, Redis, MinIO (S3-compatible storage), and Mailpit orchestrated by Docker Compose.

`apps/mobile` is intentionally absent until the mobile-readiness phase.

## Prerequisites

- Docker Desktop or another Docker Engine with Docker Compose v2.
- GNU Make for the documented root commands.
- Node.js 22 or newer only when running the smoke script outside Docker.

Windows users without GNU Make can run `powershell -ExecutionPolicy Bypass -File infra/scripts/setup.ps1` for setup, then use the corresponding `docker compose` commands from the Makefile. Docker is still required.

## Fresh-clone setup

```shell
make setup
make up
make migrate
make seed
make e2e
```

`make setup` copies safe example environment files when local files are missing, validates Compose configuration, builds pinned application images from lockfiles, and generates a local Laravel application key. It never overwrites existing local environment files.

## Local URLs

| Service | URL |
| --- | --- |
| Web | http://localhost:3000 |
| Web health | http://localhost:3000/api/health |
| API health | http://localhost:8080/api/v1/health |
| API readiness | http://localhost:8080/api/v1/readiness |
| Ingestion health (local only) | http://localhost:8001/health |
| Mailpit inbox | http://localhost:8025 |
| MinIO console | http://localhost:9001 |

PostgreSQL listens on `localhost:5432`, Redis on `localhost:6379`, Mailpit SMTP on `localhost:1025`, and the MinIO S3 endpoint on `localhost:9000` by default. Ports can be changed in the root `.env`.

The ingestion port is published only for local smoke testing. The intended production topology keeps the Python service internal.

## Root commands

- `make setup`: create local environment files, validate Compose, and build images.
- `make up`: start services and wait for health checks.
- `make down`: stop services without deleting persistent volumes.
- `make lint`: lint TypeScript, PHP, and Python.
- `make typecheck`: run strict TypeScript and Python type checks.
- `make test`: run web, API, and ingestion tests.
- `make e2e`: smoke-test all HTTP endpoints and the Mailpit UI.
- `make migrate`: run Laravel migrations against PostgreSQL.
- `make seed`: run the intentionally empty Laravel seeder.
- `make logs`: follow recent logs for all local services.

## Readiness behavior

`GET /api/v1/readiness` succeeds only after Laravel can query PostgreSQL, ping Redis, and write/read/delete a short-lived object through Laravel's configured S3 filesystem. Compose uses this endpoint to gate dependent services. Mailpit and the ingestion/web processes have their own health checks.

All API timestamps use ISO 8601 and UTC. Example files contain local-only placeholders; real secrets and local `.env` files must never be committed.

## Documentation

- [Agent contract](AGENTS.md)
- [Architecture](docs/architecture/architecture.md)
- [Repository structure](docs/architecture/repository-structure.md)
- [Product scope](docs/product/product-scope.md)
- [Data principles](docs/data/data-principles.md)
- [Security baseline](docs/security/security-baseline.md)
- [Supplier integration principles](docs/integrations/suppliers.md)
- [Deployment context](docs/deployment/deployment-context.md)
- [Prompt roadmap](docs/roadmap/prompt-sequence.md)

Read `AGENTS.md` and relevant documentation before changing a subsystem. Architectural deviations require an explicit ADR; implementation should not begin Prompt 02 until requested.
