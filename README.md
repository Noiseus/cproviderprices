# CProviderPrices

CProviderPrices is a commercial, multi-tenant B2B SaaS platform for supplier price comparison, vendor management, and purchasing optimization for restaurants and hospitality businesses. The initial market is North Carolina, with an architecture intended for later US expansion.

## Project status

Prompt 01 established the monorepo and reproducible local-development foundation. Prompt 02 adds repository-wide quality gates, CI, an OpenAPI contract workflow, and request correlation. The repository still contains operational health/readiness behavior only. Authentication, tenants, users, supplier integrations, product data, purchasing workflows, billing, and deployment are not implemented.

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
- Node.js 22 or newer only when running JavaScript quality gates outside Docker.

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

On a fresh Windows clone without GNU Make:

```powershell
powershell -ExecutionPolicy Bypass -File infra/scripts/setup.ps1
docker compose up -d --wait
docker compose exec api php artisan migrate --force
docker compose exec api php artisan db:seed --force
docker compose run --rm --no-deps web npm run smoke
```

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

## Persistent local demo access

Local development has an opt-in, persistent set of five accounts for manual testing. Put unique private values for the following variables in the ignored `apps/api/.env` file; never commit real passwords:

- `LOCAL_PLATFORM_ADMIN_PASSWORD`
- `LOCAL_OWNER_ONE_PASSWORD`
- `LOCAL_OWNER_TWO_PASSWORD`
- `LOCAL_BUYER_PASSWORD`
- `LOCAL_VIEWER_PASSWORD`

With the local stack running, create or reconcile the accounts without resetting the database:

```powershell
docker compose exec api php artisan app:bootstrap-local-demo
```

Log in at [http://localhost:3000/login](http://localhost:3000/login):

| Email | Role / scope |
| --- | --- |
| `platform.admin@local.test` | `platform_admin` only; use `/platform` through the normal platform authorization path. |
| `owner.one@local.test` | `organization_owner` in CProviderPrices Local Demo. |
| `owner.two@local.test` | `organization_owner` in CProviderPrices Local Demo. |
| `buyer@local.test` | `buyer` in CProviderPrices Local Demo. |
| `viewer@local.test` | `viewer` in CProviderPrices Local Demo. |

The command reconciles only these local identities, **CProviderPrices Local Demo**, its active **Charlotte Test Location**, and the existing `growth` plan/subscription needed for the normal entitlement checks. It is idempotent, preserves unrelated local data, creates no catalog/import fixture data, and never prints or stores plaintext passwords. Re-running it updates a demo password only when its private environment value changes.

Staging and production never receive local users, password hashes, sessions, mobile/API tokens, invitation tokens, or database rows. Bootstrap only the first real platform administrator separately, with an operator-provided email and either a hidden prompt or runtime secret:

```shell
php artisan app:bootstrap-platform-admin admin@example.com --confirm
```

For non-interactive operation, supply `PLATFORM_ADMIN_BOOTSTRAP_PASSWORD` through the environment or secret manager and use `--confirm` outside local development. This command is idempotent only for that same first platform identity, writes an audit entry, and creates no organization membership. Afterward, follow the normal platform workflow: create an organization, invite its owner, then have that owner invite buyers and viewers. Equivalent human emails may be used in separate environments, but each environment must create credentials independently.

## Root commands

- `make setup`: create local environment files, validate Compose, and build images.
- `make up`: start services and wait for health checks.
- `make down`: stop services without deleting persistent volumes.
- `make lint`: lint TypeScript, PHP, and Python.
- `make typecheck`: run strict TypeScript, PHP, and Python static checks.
- `make test`: run web, API, and ingestion tests.
- `make contract`: validate OpenAPI and verify generated TypeScript client reproducibility.
- `make e2e`: run isolated Docker Compose Playwright acceptance, including health checks and real browser workflows.
- `make migrate`: run Laravel migrations against PostgreSQL.
- `make seed`: run the intentionally empty Laravel seeder.
- `make logs`: follow recent logs for all local services.

## Readiness behavior

`GET /api/v1/readiness` succeeds only after Laravel can query PostgreSQL, ping Redis, and write/read/delete a short-lived object through Laravel's configured S3 filesystem. Compose uses this endpoint to gate dependent services. Mailpit and the ingestion/web processes have their own health checks.

All API timestamps use ISO 8601 and UTC. Example files contain local-only placeholders; real secrets and local `.env` files must never be committed.

## Documentation

- [Agent contract](AGENTS.md)
- [Architecture](docs/architecture/architecture.md)
- [API guidelines](docs/architecture/api-guidelines.md)
- [Repository structure](docs/architecture/repository-structure.md)
- [Testing strategy](docs/architecture/testing-strategy.md)
- [Product scope](docs/product/product-scope.md)
- [Data principles](docs/data/data-principles.md)
- [Security baseline](docs/security/security-baseline.md)
- [Supplier integration principles](docs/integrations/suppliers.md)
- [Deployment context](docs/deployment/deployment-context.md)
- [Deployment runbook](docs/operations/deployment-runbook.md)
- [Prompt roadmap](docs/roadmap/prompt-sequence.md)

Read `AGENTS.md` and relevant documentation before changing a subsystem. Architectural deviations require an explicit ADR; implementation should not begin later prompts until requested.
