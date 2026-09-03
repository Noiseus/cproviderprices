# CProviderPrices Agent Contract

## Start here

Read this file before making changes. Then read the documentation relevant to the subsystem you will touch. The repository is the canonical source of project decisions: reuse existing decisions and abstractions instead of inventing parallel ones. Keep work scoped to the active task, avoid unrelated refactors and boilerplate, and do not change the approved architecture silently. Record any meaningful architectural deviation in an ADR.

## Project identity

CProviderPrices (`cproviderprices.com`) is a commercial, multi-tenant B2B SaaS platform for restaurants and hospitality businesses. It helps customers compare supplier prices, manage vendors, analyze purchasing options, and make purchasing decisions. The initial market is North Carolina, but domain and technical decisions must support later US expansion.

## Approved architecture

- Monorepo.
- Customer web application: Next.js with TypeScript.
- Backend: Laravel, exposing a versioned API under `/api/v1`.
- Primary database: PostgreSQL.
- Cache, queues, and background jobs: Redis.
- Supplier ingestion and normalization: internal Python service.
- File/object storage: S3-compatible storage.
- API source of truth: OpenAPI; generated clients derive from the contract.
- Local development: Docker Compose.
- Future mobile client: Expo React Native, reusing API and domain contracts.

Do not substitute another core framework, database, backend, or architecture without an explicit ADR and approval. In particular, do not silently replace Laravel, PostgreSQL, Next.js, or the approved service boundaries with Express/NestJS, MongoDB, Firebase/Supabase as the primary architecture, or unrelated microservices.

## Intended repository structure

- `apps/web`: Next.js customer and web experiences.
- `apps/api`: Laravel API.
- `apps/mobile`: future Expo application.
- `services/ingestion`: Python supplier ingestion and normalization.
- `packages/api-contracts`: OpenAPI specifications.
- `packages/generated-api-client`: contract-generated client code.
- `packages/design-tokens`: shared visual tokens.
- `packages/domain-types`: reusable domain contracts where appropriate.
- `packages/i18n`: shared localization resources.
- `docs`: product, architecture, data, design, security, integration, deployment, roadmap, and ADR documentation.
- `infra`: Docker, deployment, and operational scripts.

See `docs/architecture/repository-structure.md` before changing these boundaries. Prompt 01 established the web, API, ingestion, shared-package, and local infrastructure foundations. `apps/mobile` remains intentionally absent until the mobile-readiness phase.

## Engineering rules

- Build the MVP without architectural dead ends; prefer clear, maintainable contracts over clever abstractions.
- Optimize navigation and perceived performance. Avoid unnecessary reloads and data fetching.
- Keep APIs and domain contracts reusable for future mobile clients.
- Do not hard-code North Carolina-specific business rules unless explicitly required.
- Modify an existing abstraction before creating a duplicate.
- Add dependencies only when the active requirement needs them; explain non-obvious additions.
- Never commit secrets. Document configuration through safe example files such as `.env.example`.
- Keep production and staging configuration separate.

## Tenancy and security

- Every customer-owned record and data-access path must enforce tenant isolation.
- Never trust a tenant or company identifier supplied by a client; resolve and authorize tenant context server-side.
- Authentication establishes identity; authorization separately controls access to every protected resource and action.
- Apply least privilege where practical, validate and sanitize external data, and log sensitive operational actions.
- Supplier ingestion must be auditable, including provenance, timestamps, failures, and actor/job context where applicable.
- Review `docs/security/security-baseline.md` before changing authentication, authorization, data access, ingestion, storage, or operations.

## API and data principles

- Place public application API endpoints under `/api/v1`; use explicit versioning and backward-compatible evolution where practical.
- Treat OpenAPI as the API contract. Update the specification and generated clients together once those assets exist.
- Do not expose persistence models as accidental public contracts.
- Supplier formats vary. Isolate supplier behavior behind adapters/connectors rather than spreading supplier-specific conditions across the application.
- Preserve source provenance, ingestion time, supplier SKU, units/case sizes, customer-specific pricing context, price history, and errors.
- Never match equivalent products by product name alone. Normalization and matching require stronger evidence and may include human review.
- Do not finalize a detailed schema without the dedicated data-model task and its decisions.

## Documentation, testing, and validation

- Read the relevant files under `docs/` before modifying a subsystem.
- Update documentation when behavior, contracts, deployment assumptions, or architecture change.
- Create an ADR for meaningful architectural changes; do not use an ADR for routine implementation detail.
- Add or update tests proportionate to implementation risk when code exists. Tenant-boundary and authorization behavior require explicit tests.
- Run the smallest deterministic validation that covers the change. Do not scan or rewrite unrelated files.
- Do not claim commands passed unless they were run, and do not claim functionality that is not implemented.

## Completion report

Keep handoffs concise:

1. Files or behavior changed.
2. Validation performed and result.
3. Genuine unresolved decisions or risks.
4. Recommended next step, without beginning it unless requested.
