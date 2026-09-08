# CProviderPrices API

Laravel application for the versioned CProviderPrices API, including invite-only cookie authentication and the shared organization tenant context.

- `GET /api/v1/health`: process liveness.
- `GET /api/v1/readiness`: PostgreSQL, Redis, and S3-compatible storage verification.
- `/api/v1/auth/*`: cookie session, password, verification, and invitation lifecycle.
- `/api/v1/organizations/{organization}/*`: server-authorized tenant context and scoped resources.
- `/api/v1/platform/*`: explicit audited platform scope.

Use the repository root commands for setup and validation.
