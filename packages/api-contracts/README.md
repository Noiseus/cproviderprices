# API Contracts

OpenAPI 3.1 is the source of truth for CProviderPrices application API contracts. The specification includes health/readiness, invite-only browser authentication, tenant context, scoped access surfaces, and shared error/request-ID conventions.

Run `npm run contract:lint` from the repository root to validate the contract. Run `npm run generate:api` to regenerate the tracked TypeScript schema types. Run `npm run check:api-client` to compare the tracked client with a fresh temporary generation and fail on drift.
