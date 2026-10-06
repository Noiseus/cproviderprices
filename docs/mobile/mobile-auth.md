# Mobile authentication

## Boundary

Browser authentication remains the existing HttpOnly Laravel session plus CSRF cookie/header protection. Native clients do not reuse browser cookies. `POST /api/v1/mobile/auth/login` validates the same account credentials under the existing login rate limit and returns a native bearer token once, along with the existing server-authoritative session profile.

The raw token is high-entropy and is never logged or stored in the database. The database stores a SHA-256 hash, device label, issued user auth version, expiry, revocation timestamp, and last-use timestamp. Tokens expire after `MOBILE_ACCESS_TOKEN_TTL_DAYS` (30 by default), replacing the same device label revokes its prior active token, and logout (`DELETE /auth/session` with bearer auth) revokes the current token. A password-reset auth-version change or account suspension denies and revokes a current token.

There is deliberately no long-lived refresh token in this foundation: expiration requires a fresh credentialed mobile login. This keeps rotation and revocation explicit while avoiding an OAuth-provider or refresh-token subsystem before it is justified.

## Authorization and CSRF

The `auth.api` middleware accepts either the existing web session or a valid mobile bearer token and resolves the same `User` instance. A native token is deliberately scoped to session/preferences/logout plus read-only organization context, locations, search, comparison/history, and saved-list endpoints. It cannot call imports, platform administration, or mobile mutations. It runs before `active.user`, tenant context, policy, entitlement, organization lifecycle, and location ancestry checks. The bearer token never contains organization, role, or location authority.

`VerifyApiCsrfToken` bypasses CSRF only when a bearer credential is present on `/api/v1/*`; every such request must subsequently pass the bearer middleware. Cookie-authenticated state changes still require CSRF exactly as before. An invalid bearer does not fall back to a supplied browser cookie.

Stable native token errors are `mobile_token_invalid` and `mobile_token_expired`, both localized by the shared i18n package. Standard validation, permission, conflict, throttle, and network errors retain their existing stable API codes.

## Device storage and lifecycle

The app stores only the bearer token and expiry through `expo-secure-store`, behind a testable `MobileAuthStorage` interface. It does not use AsyncStorage for credentials and does not persist private tenant payloads, notes, or source files. On bootstrap, an unexpired token is checked using the shared session endpoint; invalid/expired tokens are cleared. Logout clears secure storage even if its network request fails.

The Expo SecureStore implementation follows the official [Expo SecureStore documentation](https://docs.expo.dev/versions/v55.0.0/sdk/securestore/). The configured accessibility class is device-only while unlocked; platform backup and device-loss behavior must be reviewed during real release signing.
