# CProviderPrices Web

Next.js and TypeScript application for CProviderPrices. It provides the functional invite-only authentication routes and protected organization placeholder; business dashboards and the Prompt 07 design system remain deferred.

Use the repository root commands for setup and validation.

`NEXT_PUBLIC_API_URL` is the browser-reachable API origin. Server-rendered
requests use `API_INTERNAL_URL` when set; Docker Compose points it at the API
service while direct local development falls back to the public URL.
