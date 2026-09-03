# Repository Structure

The intended monorepo layout is shown below. Prompt 00 creates only documentation and repository-policy files; implementation directories should be created when their scoped prompt begins.

```text
/
|-- AGENTS.md
|-- README.md
|-- Makefile
|-- docker-compose.yml
|-- .env.example
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
|   |-- product/
|   |-- architecture/
|   |-- data/
|   |-- design/
|   |-- security/
|   |-- integrations/
|   |-- deployment/
|   |-- roadmap/
|   `-- adr/
`-- infra/
    |-- docker/
    |-- deployment/
    `-- scripts/
```

## Responsibilities

- `apps/web`: Next.js and TypeScript web application, including public and authenticated browser experiences.
- `apps/api`: Laravel application and versioned `/api/v1` endpoints.
- `apps/mobile`: reserved for a future Expo React Native client; not part of initial scaffolding unless requested.
- `services/ingestion`: internal Python ingestion, validation, normalization, and supplier adapters.
- `packages/api-contracts`: OpenAPI source contracts and related contract documentation.
- `packages/generated-api-client`: generated consumers of OpenAPI; generated files must not be hand-maintained.
- `packages/design-tokens`: framework-neutral shared design values.
- `packages/domain-types`: intentionally shared domain types that do not bypass API boundaries.
- `packages/i18n`: shared localization resources and conventions.
- `docs`: persistent product and engineering decisions. Architecture changes belong here and, when meaningful, in an ADR.
- `infra/docker`: container definitions and supporting assets.
- `infra/deployment`: environment/deployment configuration that is safe to version.
- `infra/scripts`: deterministic operational or development scripts.
- Root files: cross-repository workflow, local orchestration, configuration examples, and contributor guidance.

Avoid duplicating business logic across apps and packages. Shared placement must reflect genuine reuse, not a default dumping ground.
