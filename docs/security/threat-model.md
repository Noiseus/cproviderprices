# Pilot threat model and operational security controls

This is the implementation-level Prompt 20 review. It records controls that exist in the repository; it does not claim that an unconfigured deployment is production ready. Exact HTTPS hosts, proxy addresses, production secrets, backup/retention and alert routing remain Prompt 21 work.

## Assets and trust boundaries

Protected assets include sessions and CSRF state, invitation/reset tokens, user emails, organization data, supplier pricing and account metadata, private source files and normalized records, canonical mappings, audits, and the Laravel-to-ingestion service credential.

| Boundary | Control in the current implementation | Residual risk and verification |
| --- | --- | --- |
| Browser -> Next.js | Next.js is presentation only; it calls the API for authorization. Web responses have MIME, frame, referrer, permission and conservative CSP headers. | HTTPS serving is deployment work. Web unit/build and browser smoke tests cover navigation. |
| Browser -> Laravel API | Stateful routes use web/session/CSRF middleware, HttpOnly session settings, configurable SameSite, explicit credentialed CORS origins and explicit methods. | Operators must set exact origins, secure cookies and HTTPS. Authentication and security tests cover CSRF/CORS behavior. |
| Next.js -> API | API_INTERNAL_URL is server-only; NEXT_PUBLIC_API_URL is browser reachable. | Private network placement and TLS are deployment work. Examples document the separation. |
| Laravel -> PostgreSQL / Redis | Laravel is the authorization boundary; queue work uses bounded IDs rather than source rows. Redis is not authoritative. | Least privilege and transport encryption are operational configuration. Tenant/job tests cover scoped data and idempotency. |
| Laravel -> object storage | Private S3 disk, generated tenant/import/UUID keys, five-minute relative signed application URLs, authorization on redemption, attachment streaming, nosniff and private/no-store. | Bucket policy and credentials must be configured outside Git. Import tests cover anonymous, cross-tenant, expired and path-like cases. |
| Laravel worker -> Python | Token required before body reading; Python uses constant-time compare, JSON-only 1 MiB bound, no database/storage access. Laravel validates configured URL, disables redirects and verifies correlation/response shape. | Token rotation and private ingress are deployment work. Python/Laravel normalization tests verify the boundary. |
| Member -> tenant API | ResolveTenantContext requires active user, membership and organization. Route organization is authoritative; policies distinguish owner/buyer/viewer and scoped queries begin at the organization. | New tenant endpoints must retain this pattern. Authorization/import/saved-list/search/history tests cover IDOR cases. |
| Platform admin -> platform API | Explicit audited platform.scope; no global policy bypass. Platform query services expose provisioning data, not raw price or import-file surfaces. | No support impersonation exists. Platform tests prove role alone does not grant tenant membership. |
| Import -> parser | CSV/XLSX-only validation, 10 MiB max, path/MIME/extension checks, bounded ZIP/XML/rows/cells, formula/external-reference rejection, private raw evidence and safe parser errors. | No malware scanning in this MVP; unsupported formats are rejected. Import reader/infrastructure tests exercise malformed input. |
| HTTP/job -> logs/audit | Request IDs are returned, persist on imports and propagate to jobs/Python. Structured events contain safe scalar IDs/counts/status/duration. Context/audit redaction blocks secret-named fields. | Log access/retention is deployment work. Focused tests verify redaction and correlation. |

## Threat review

| Threat | Existing control | Remaining risk | Verification |
| --- | --- | --- | --- |
| Cross-tenant access and IDOR | Membership context, policies, scoped lookups, composite ancestry constraints and tenant/location cache keys. | Future routes can bypass the pattern if reviewed poorly. | TenantAuthorization, ImportCenter, SavedList, ProductSearch/Comparison/History tests. |
| Privilege escalation and platform leakage | Explicit platform scope and audits; platform role does not create membership. | Provisioning metadata intentionally spans tenants. | TenantAuthorization and PlatformAdministration tests. |
| CSRF, fixation and unsafe redirect | Laravel CSRF; session regeneration on login/invitation acceptance; logout/reset invalidation; SafeDestination validation. | Deployment cookie/origin values must remain correct. | Authentication and SecurityHardening tests. |
| Brute force and abuse | Named throttles cover login, reset, invitation inspect/accept, verification, team invite/resend, platform mutations and import writes. Keys use token/email hashes where relevant plus IP. | Distributed abuse needs edge controls later. | Rate-limit regression and route inspection. |
| Secret/source-row/signed-URL leakage | No plaintext invitation tokens, short-lived application signatures, generic API exceptions, no body/header log context and central redaction. | Developers must not interpolate secrets into a log message. | Redaction/audit/Python response-log tests and tracked-file review. |
| File enumeration/traversal/parser abuse | Opaque keys, scoped downloads, authorization-gated signatures, bounded readers, path checks and formula neutralization. | Malicious format corpus expansion is later hardening. | ImportCenter and ImportInfrastructure tests. |
| Ingestion replay/SSRF | Idempotent raw record/parser persistence, token/bounded private endpoint, fixed validated configured URL and redirect refusal. | No per-request nonce against a compromised private network. | Normalization/API tests. |
| Log injection and sensitive diagnostics | JSON context is selected scalar data only and redacted; exception response text is generic. | External log collector access requires deployment controls. | SecurityHardening and Python tests. |

## Private route authorization inventory

| Route family | Server-side class and enforcement | Explicitly excluded scope |
| --- | --- | --- |
| Auth session, preferences, logout, verification | Authenticated active user; CSRF for mutation. | Authentication alone does not select an organization. |
| Organization status/subscription | Active membership with the limited suspended-status exception. | No client organization field is authority. |
| Locations, supplier accounts, team and memberships | Tenant context plus resource-scoped lookup; owner-only mutations where policy requires it. | Foreign location/account/member UUIDs resolve as absent. |
| Imports and downloads | Tenant context; read for active members, owner/buyer mutation policy, entitlement after role check; signature plus fresh authorization on download. | Raw object keys, bucket URLs and other-tenant files. |
| Search, comparison and price history | Tenant context plus authorized active location/account filters; server-scoped cache keys. | Other organizations' offers, supplier counts and prices. |
| Saved lists | Tenant context; active members read, owner/buyer mutations; list/item/location/offer lookup is organization-scoped. | Browser-forged organization/location and foreign IDs. |
| Matching and platform provisioning | Authenticated active platform_admin with platform.scope and audit. | Raw customer supplier pricing/files and implicit tenant membership. |

## Operational controls

### Sessions, CORS, proxies and headers

The API session defaults to database storage and HttpOnly cookies. Production requires SESSION_SECURE_COOKIE=true, HTTPS, an appropriate SESSION_DOMAIN and exact CORS_ALLOWED_ORIGINS. Credentialed wildcard CORS is never valid. CORS permits GET, POST, PUT, PATCH, DELETE and OPTIONS only. TRUSTED_PROXIES is empty by default and must list only known reverse proxies. Laravel host protection derives from APP_URL and can be extended with regular-expression TRUSTED_HOSTS entries.

API responses use nosniff, no-referrer, deny-frame, disabled camera/geolocation/microphone, private/no-store and a non-rendering CSP. The web application has compatible web headers. SECURITY_HSTS_ENABLED=true emits HSTS only for secure requests; it must stay disabled for localhost HTTP.

### Rate limits

| Surface | Limit | Key |
| --- | ---: | --- |
| Login | 5/min | SHA-256 normalized email + IP |
| Forgot/reset password | 5/min | SHA-256 normalized email + IP |
| Invitation inspection/acceptance | 10/min | SHA-256 token + IP |
| Verification | 6/min | Authenticated user ID |
| Team invitation/resend | 10/min | User + organization + IP |
| Platform mutation | 20/min | User + route/global scope + IP |
| Import mutation/upload | 10/min | User + organization + IP |

Throttle responses use the standard error envelope with too_many_requests, a correlation ID and Laravel retry headers. Limiter keys are never logged.

### Correlation, logs, metrics and health

X-Request-ID accepts only a safe 1-128-character value; invalid or absent values become UUIDs. It is returned on every API response/error. Import runs persist it as request_id, jobs log it as correlation_id and Laravel supplies it to Python in both header and batch payload.

Laravel defaults to newline-delimited JSON logs (LOG_FORMAT=json) with a processor that redacts secret-named context. Important events include rejected HTTP responses, import validation/normalization dispatch/progress/retry/failure and bounded freshness outcomes. Python emits JSON aggregate events for accepted batches and safe rejections. Existing logs therefore provide request/error, import success/failure, ingestion duration, queue failure and freshness outcome counts without a new monitoring platform.

/api/v1/health is process liveness. /api/v1/readiness performs the existing minimal database, Redis and object-storage write/read/delete check and returns only dependency states. Ingestion health returns only status, service and timestamp.

### Incident response for the pilot

1. Identify the affected correlation ID, import, user and organization from safe logs/audits and preserve that evidence.
2. Contain narrowly: suspend the affected organization/account or stop the relevant integration/job path.
3. Revoke or rotate the affected application, storage or ingestion secret through deployment configuration; increment auth_version when a user credential is affected.
4. Determine tenant scope from authoritative database/audit relationships, not client IDs or log guesses.
5. Restore only after safe configuration, then verify readiness and the affected authorized path.
6. Record timeline, impact, corrective action and a regression test or follow-up.

## Dependency and repository review

Prompt 20 validation on 2026-10-05 found and fixed one production npm advisory
by updating Next.js from 16.3.4 to 16.3.8; production `npm audit --omit=dev`
then reported zero findings. There are six development-only findings: the
Next ESLint plugin's `fast-glob`/`micromatch`/`braces` chain (four high records)
and Vitest/@vitest-mocker (two moderate records). Their proposed remediation is
a breaking downgrade of the plugin to 14.x or a Vitest 5 migration, so neither
was applied blindly; they are not bundled into the customer runtime and require
a compatible toolchain upgrade follow-up.

Composer initially reported two `league/commonmark` advisories (medium raw HTML
bypass and high table-scan DoS). A targeted compatible lock update moved it from
2.10.0 to 2.10.3 (and its required polyfill); Composer audit then reported no
advisories. `pip check` and an ephemeral `pip-audit -r requirements.lock` both
reported no Python findings. Tracked-file scanning found no private-key, GitHub
token, or AWS access-key signature; `.env` and E2E credential artifacts remain
ignored. Do not print real environment values while repeating this review.
