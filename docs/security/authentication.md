# Invite-only browser authentication

## Lifecycle

CProviderPrices has no public registration endpoint. A pending invitation is the only account-activation path. Login, logout, current session, email verification, forgot password, reset password, and invitation acceptance are exposed below `/api/v1/auth`; the corresponding Next.js routes live at `/login`, `/forgot-password`, `/reset-password`, `/accept-invitation`, and `/verify-email`.

Invitation acceptance looks up the keyed HMAC-SHA-256 digest of the bearer token and locks the invitation during the transaction. It checks expiration, single-use status, organization lifecycle, intended normalized email, intended role, and any existing user's lifecycle and password. Organization and role values in a client payload are ignored as authority. A new account receives the invitation's email and membership role, the invitation becomes accepted, and the browser is authenticated only after the transaction commits. Plaintext invitation tokens are never stored or written to audit metadata.

Forgot-password acknowledgements are identical for known and unknown accounts. Invalid reset requests also use one response regardless of whether the email exists. Login, password, verification, and invitation endpoints are rate limited. Password resets increment `users.auth_version`; every private request compares this version with the session so all earlier sessions become invalid. The same middleware refreshes the user and denies a newly suspended account.

## Cookie and CSRF model

The API uses Laravel's `web` session guard. The session cookie is HttpOnly. Before a state-changing request, the web client requests `GET /api/v1/auth/csrf-cookie`, reads Laravel's separate `XSRF-TOKEN` cookie, and returns it in `X-XSRF-TOKEN`. Laravel's CSRF middleware remains enabled on all stateful API routes. CORS allows credentials only from explicitly configured web origins.

The web application keeps browser and server-side routing explicit:
`API_INTERNAL_URL` points server-rendered requests at a private service address.
Local development uses `NEXT_PUBLIC_API_URL` as the browser-reachable API
origin. The production Caddy edge instead proxies browser `/api/v1` calls from
the authenticated app host to Laravel, so the same immutable Next image can be
promoted between environments without baking an environment-specific public
origin into JavaScript. The public API hostname remains protected by the same
cookie/CSRF/CORS controls for future clients. Docker Compose uses
`http://api:8080` internally without exposing that hostname to browser code.

Safe local settings are:

```dotenv
APP_URL=http://localhost:8080
FRONTEND_URL=http://localhost:3000
SESSION_DRIVER=database
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CORS_ALLOWED_ORIGINS=http://localhost:3000
```

Production for the intended sibling subdomains uses:

```dotenv
APP_URL=https://api.cproviderprices.com
FRONTEND_URL=https://app.cproviderprices.com
SESSION_DRIVER=database
SESSION_DOMAIN=.cproviderprices.com
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CORS_ALLOWED_ORIGINS=https://app.cproviderprices.com
```

Production and staging must use distinct keys, databases, sessions, origins,
cookie names, and cookies. HTTPS is required. Do not use wildcard credentialed
CORS origins. The shared parent-domain cookie configuration is required so
Next.js server rendering and browser CSRF handling can receive the API-issued
cookies across intended sibling subdomains. Staging's distinct cookie name and
Laravel key prevent its parent-scoped cookie from becoming a production session.

Prompt 20 keeps CORS methods explicit (`GET`, `POST`, `PUT`, `PATCH`,
`DELETE`, and `OPTIONS`) and permits only the documented request headers.
Sensitive authentication routes are rate limited without placing raw email
addresses or tokens in limiter keys: login/reset use normalized-email hash plus
IP, and invitation inspection/acceptance uses token hash plus IP. A throttled
response uses the normal error envelope, `too_many_requests`, and safe retry
headers. See the [threat model](threat-model.md#rate-limits).

## Redirects

The API accepts only internal paths and, after authentication, only a destination below an organization slug backed by the user's active membership. Invitation acceptance always returns the invited organization's dashboard. Organization switching accepts only a destination under the selected organization's slug. Absolute URLs, protocol-relative URLs, backslashes, and control characters fall back to a server-selected safe dashboard.
