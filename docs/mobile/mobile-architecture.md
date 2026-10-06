# Mobile architecture

## Decision

`apps/mobile` is an Expo managed-workflow React Native application written in strict TypeScript. It is deliberately a buyer-focused foundation, not a second implementation of the web product. Expo Router supplies file-based native navigation; the app uses a small React context rather than a new state-management dependency.

## Shared and platform-specific boundaries

| Shared | Mobile-specific |
| --- | --- |
| OpenAPI-derived schemas from `@cproviderprices/generated-api-client` | Expo Router routes, React Native views, and native interaction patterns |
| EN/ES dictionaries and `Intl` formatting from `@cproviderprices/i18n` | SecureStore adapter and small bearer-aware transport wrapper |
| Framework-neutral colors, spacing, radii, type scale, and touch target from `@cproviderprices/design-tokens` | Loading/error primitives and session context |
| Laravel API, tenant authorization, comparison service, saved-list service, and calculations | Public Expo API-origin configuration |

The app must not import Next.js components, browser cookie/CSRF helpers, web CSS, server session code, or supplier/domain services. It displays API prices, winner labels, freshness and list summaries exactly as returned; it does not rank offers or calculate normalized prices locally.

## Navigation and pilot scope

The shell contains sign-in, organization selector, location selector, dashboard, search, comparison, saved-list index/detail, imports placeholder, and settings/language routes. Tabs replace the desktop sidebar. Search, comparison, price/freshness summaries, and saved-list reading use existing versioned APIs. List editing and mobile imports are intentionally deferred.

Organization and location selection are UX context only. Every request still includes the existing organization route and location parameter, which Laravel authorizes against the authenticated membership and resource ancestry. A device cannot grant itself another organization or location by changing local state.

## Networking, cache, and offline policy

The wrapper adds `Authorization` and `X-Request-ID`, maps the standard API envelope, and performs no automatic retry loop. Network failures are visible and retry is explicit in the screen. There is no persistent tenant-data cache or offline mutation queue in this phase. Current prices remain potentially stale until refreshed by the server, and freshness returned by the API is always shown. A future short-lived in-memory read cache may be added only with explicit expiry, visible stale state, tenant isolation, and no stored private notes or source files.

## Environments and observability

`EXPO_PUBLIC_API_URL` is the sole Expo public runtime setting: it is an API origin, never a secret. Local emulators normally use a host-reachable origin; staging and production use their distinct HTTPS API origins. Public configuration must never contain passwords, tokens, signing material, supplier credentials, or customer data.

The mobile wrapper creates a safe correlation ID for each request and preserves the API response request ID on errors. Future error reporting may record route, safe API error code, request ID, and app version. It must not report bearer tokens, private notes, credentials, raw supplier files, or request bodies. No paid monitoring service is configured.

## Future deep links and releases

The configured `cproviderprices://` scheme is only a future-safe foundation. Future links may target search, comparison, and saved-list detail after authorization; access tokens must never be in a URL. Universal links/app links, notification handling, push infrastructure, and store publication are outside this prompt.

Expo Router and npm-workspace support follow the official [Expo Router introduction](https://docs.expo.dev/router/introduction/) and [Expo monorepo guide](https://docs.expo.dev/guides/monorepos/).
