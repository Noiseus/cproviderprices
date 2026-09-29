# Import and internal-service credential handling

This supplements the [security baseline](security-baseline.md) and [authentication contract](authentication.md); it does not replace session, CSRF, invitation, or reset-token behavior.

Prompts 11/12 collect no supplier portal passwords, MFA secrets, session cookies, API keys, or speculative connector credentials. There is no supplier credential store. Input validation must reject credential fields and restrict mappings to approved import fields. Uploaded files are untrusted private data; do not copy arbitrary contents into logs or audit metadata.

## Laravel to Python

Use a dedicated environment-managed service token in `Authorization: Bearer ...` on the private Docker network. Python must compare the configured token in constant time before processing normalization requests. Empty/missing configuration must fail closed; no public or development bypass is permitted. The health endpoint may remain unauthenticated and must disclose no configuration or secrets. Do not forward browser cookies or user credentials to Python.

`INGESTION_SERVICE_TOKEN` is the shared runtime variable for API/worker and ingestion, never a `NEXT_PUBLIC_` variable. Generate local values outside Git; example files contain only empty placeholders and setup instructions. Configure Laravel's `INGESTION_SERVICE_URL` as a trusted private origin (local default `http://ingestion:8001`); it is not client-controlled and redirects are disabled. Recreate the services after supplying matching tokens. Production and staging have distinct secret-manager/environment values and service identities. Use private networking and encrypted transport in production, restrict ingress, and rotate secrets through deployment configuration. A token authenticates the service, not a customer tenant: Laravel must still resolve and authorize every record before batching. Python does not query Laravel tables or choose tenants.

Reject oversized requests before buffering unbounded bodies. Log safe error codes and aggregate counts; never tokens, authorization headers, full request bodies, samples, or source rows. Error responses must not echo credentials or validation inputs.

Browser acceptance provisions a fresh process-only token through the isolated Compose runner; it never writes the token to committed configuration or frontend variables. E2E identities are synthetic and stored only in ignored private `auth-e2e-isolated.json`, removed at browser teardown. Retained Playwright failure traces may include synthetic authentication cookies; do not publish those artifacts or raw request logs. See the [isolated browser execution contract](../architecture/testing-strategy.md#isolated-browser-acceptance).

## Source objects and downloads

Reuse Laravel's S3 disk and existing local MinIO. Generate object keys server-side from authorized tenant/import identifiers and random object IDs; filenames are display metadata, never paths. Keep buckets private. Local MinIO credentials are development-only and must not be reused in production. Production object-storage credentials stay environment-managed with least privilege.

The approved download approach is an expiring signed application route (five-minute lifetime), requiring the authenticated session and fresh tenant/resource authorization when redeemed. Laravel streams the private object or safe error report; it does not publish bucket keys or public object URLs. Signatures do not substitute for authorization. Apply safe attachment names, download content types, and `Cache-Control: private, no-store`. Error reports must neutralize spreadsheet formula injection. Supplier files remain private even after cancellation or failure; retention/deletion policy is deferred.
