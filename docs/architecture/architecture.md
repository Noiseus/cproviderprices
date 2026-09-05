# Architecture

## Strategy

CProviderPrices uses a monorepo so applications, internal services, contracts, shared packages, documentation, and infrastructure changes can evolve together with explicit ownership. Monorepo placement does not erase service boundaries: each component keeps its own runtime responsibilities and may be deployed independently.

Prompt 01 implements this foundation with Docker Compose for local development. It provides operational health contracts only; authentication and product/domain behavior remain deferred.

## Components

- **Web:** Next.js with TypeScript provides the public and authenticated browser experiences.
- **API:** Laravel owns application use cases, authentication/authorization enforcement, tenant-aware access, and persistence through a versioned `/api/v1` interface.
- **Database:** PostgreSQL is the system of record for relational application and tenant-aware business data.
- **Redis:** supports caching, queues, and background jobs; it is not the primary system of record.
- **Ingestion:** an internal Python service retrieves, parses, validates, and normalizes heterogeneous supplier data through supplier adapters/connectors.
- **Object storage:** S3-compatible storage holds source files or other objects that should not live directly in the relational database.
- **Contracts:** OpenAPI defines the API contract and supports generated clients.
- **Mobile:** a future Expo React Native client consumes the same stable API contracts; it does not dictate the initial MVP UI.

The mobile application is intentionally not present in the repository yet.

## High-level flow

```text
Browser / future mobile
        |
        | HTTPS + authenticated /api/v1 requests
        v
Next.js web ---------> Laravel API ---------> PostgreSQL
                           |   |
                           |   `-----------> Redis (cache, queue, jobs)
                           |
Python ingestion ----------+---------------> S3-compatible storage
  | adapters/connectors     |
  `-- supplier sources -----`---------------> validated tenant-aware records
```

The Laravel API is the authorization boundary for customer-facing data. The ingestion service performs specialized acquisition and normalization, while durable business state remains governed by application contracts and PostgreSQL. Raw source objects may be retained in S3-compatible storage for provenance and replay. Redis accelerates or schedules work without becoming authoritative storage.

## Separation of concerns

- Web code owns presentation, interaction, and client-side orchestration, not authorization decisions.
- Laravel owns business rules, tenant enforcement, protected resource access, and the external application API.
- Python owns supplier-format handling and normalization pipelines, not customer-facing APIs.
- OpenAPI separates durable interface contracts from framework internals.
- Supplier-specific behavior stays inside connectors/adapters.
- Deployment configuration remains separate for staging and production.

## Hosting context

Hostinger Business Web Hosting and its GitHub deployment support are current operational context, not a reason to collapse the system into a single hosting-wizard-shaped application. The chosen components have different runtime and operational needs, and preserving their boundaries keeps contracts testable, the ingestion workload isolated, and future hosting options open. Any deployment constraint that requires changing these boundaries must be evaluated explicitly and recorded in an ADR.
