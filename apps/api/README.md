# CProviderPrices API

Laravel application for the versioned CProviderPrices API. The current scope contains only operational health and readiness endpoints. Authentication and domain entities are intentionally deferred.

- `GET /api/v1/health`: process liveness.
- `GET /api/v1/readiness`: PostgreSQL, Redis, and S3-compatible storage verification.

Use the repository root commands for setup and validation.
