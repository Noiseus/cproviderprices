# CProviderPrices

CProviderPrices is a planned commercial, multi-tenant B2B SaaS platform for supplier price comparison, vendor management, and purchasing optimization for restaurants and hospitality businesses. It will initially focus on North Carolina and is intended to support expansion into other US markets.

## Project status

The repository is currently at **Prompt 00: persistent project contract and documentation**. No application, API, ingestion service, database, authentication, infrastructure, or production deployment has been scaffolded or implemented yet. Implementation begins in later prompts.

## Architecture direction

The approved direction is a monorepo containing:

- a Next.js and TypeScript web application;
- a Laravel backend with a versioned `/api/v1` API;
- PostgreSQL as the primary database;
- Redis for caching, queues, and jobs;
- an internal Python supplier-ingestion and normalization service;
- S3-compatible object storage;
- OpenAPI contracts and generated API clients;
- Docker Compose for local development; and
- a future Expo React Native mobile application.

The architecture prioritizes strict tenant isolation, reusable API/domain contracts, frequently changing supplier prices, auditable ingestion, and an MVP that can evolve without needless complexity.

## Intended repository layout

```text
/
|-- AGENTS.md
|-- README.md
|-- Makefile                       # later prompt
|-- docker-compose.yml             # later prompt
|-- .env.example                   # when configuration exists
|-- apps/
|   |-- web/
|   |-- api/
|   `-- mobile/
|-- services/
|   `-- ingestion/
|-- packages/
|   |-- api-contracts/
|   |-- generated-api-client/
|   |-- design-tokens/
|   |-- domain-types/
|   `-- i18n/
|-- docs/
|-- infra/
|   |-- docker/
|   |-- deployment/
|   `-- scripts/
```

Directories are created only when a prompt needs them; the application directories above are not implementation claims.

## Documentation map

- [Product scope](docs/product/product-scope.md)
- [Architecture](docs/architecture/architecture.md)
- [Repository structure](docs/architecture/repository-structure.md)
- [Data principles](docs/data/data-principles.md)
- [Security baseline](docs/security/security-baseline.md)
- [Supplier integrations](docs/integrations/suppliers.md)
- [UX principles](docs/design/ux-principles.md)
- [Deployment context](docs/deployment/deployment-context.md)
- [Architecture decisions](docs/adr/README.md)
- [Prompt roadmap](docs/roadmap/prompt-sequence.md)

## Development workflow

1. Read `AGENTS.md` first, then the relevant documentation.
2. Confirm the active prompt's scope and reuse recorded decisions.
3. Make the smallest coherent change; avoid unrelated refactors and dependencies.
4. Validate proportionately and update contracts or documentation when decisions change.
5. Record meaningful architectural deviations as ADRs.

Local development is not configured yet. Do not run framework setup or expect application commands to work until the scaffolding prompt establishes them.
