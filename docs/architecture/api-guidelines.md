# API Guidelines

## Contract and versioning

The public application API is versioned under `/api/v1`. The OpenAPI 3.1 document at `packages/api-contracts/openapi.yaml` is the source of truth for public request and response shapes. Update the contract and regenerate `packages/generated-api-client/src/schema.d.ts` in the same change.

Health responses use endpoint-specific success schemas. Future resource endpoints should likewise return explicit, documented success shapes rather than exposing persistence models.

## Error envelope

API errors use this JSON structure:

```json
{
  "error": {
    "code": "not_found",
    "message": "The requested resource was not found.",
    "request_id": "c32bfb55-5a24-4dce-ab9d-d92d159bab86",
    "details": {}
  }
}
```

- `code` is a stable machine-readable identifier.
- `message` is safe for clients and must not disclose secrets or internals.
- `request_id` matches the response `X-Request-ID` header.
- `details` is optional structured context, such as validation errors.

Unhandled exceptions must be rendered with a generic message outside local debug output. Authentication and authorization errors will follow this envelope when those capabilities are introduced.

## Request correlation

Every `/api/*` response includes `X-Request-ID`. A caller-provided value is reused only when it is 1–128 characters and contains letters, digits, dots, underscores, colons, or hyphens. Missing or invalid values are replaced with a UUID. API response bodies include the same value where their schema defines `request_id`.

Application logs should include this identifier when request-aware logging is introduced. A request ID is diagnostic metadata, not an authentication or authorization credential.

## Validation workflow

Run `make contract` to lint the OpenAPI document, regenerate the TypeScript schema, and fail if regeneration changes the tracked client. Direct host equivalents are documented in `packages/api-contracts/README.md`.
