# Design system and authenticated app shell

Prompt 07 establishes the shared visual foundation, and Prompt 08 applies it to
the authenticated organization workspace. This document records the intended
reuse boundaries; feature prompts should extend these foundations instead of
creating parallel controls or navigation shells.

## Tokens and themes

- `packages/design-tokens/tokens.json` is the framework-neutral source for
  semantic color, spacing, type, radius, shadow, breakpoint, and touch-target
  values. Future mobile clients may map the same semantics to native styles.
- `apps/web/src/app/globals.css` maps those semantics to web CSS variables and
  defines light and dark themes. Components consume semantic variables rather
  than fixed product colors.
- Theme preference is explicit (`system`, `light`, or `dark`) and stored locally.
  The root layout applies a small pre-render bootstrap so a saved preference is
  present before the page is painted.
- Motion is reduced when the user's `prefers-reduced-motion` setting requests it.

## Component boundary

Reusable controls, surfaces, feedback states, and layout helpers live under
`apps/web/src/components/ui`. Use native semantics where possible, keep labels
programmatically associated with controls, preserve visible focus treatment,
and retain the shared minimum touch target. The `/design-system` route is a
development-only visual and interaction reference; production returns not
found for that route.

## Organization workspace

`/o/[organizationSlug]/layout.tsx` resolves the authenticated session,
authoritative organization membership, and subscription context once at the
tenant layout boundary. It renders explicit unauthenticated, forbidden,
suspended, offline, and unexpected-error states rather than blank pages.

The persistent shell provides desktop, compact, and mobile navigation,
organization and safe development location context, route state, breadcrumbs,
theme control, and the account menu. Normal tenant links use Next.js client
navigation so route content changes without rebuilding the document. Route
content remains server-rendered and is passed through the interactive shell
boundary.

Navigation filtering is a usability affordance only. The API remains
authoritative for tenant membership, roles, entitlements, organization
switching, and protected actions. Unavailable entitlement destinations are
rendered as non-focusable explanatory states, not active links. Selective
prefetch metadata is defined with navigation policy; links are not blindly
prefetched.

Feature routes created in Prompt 08 are structural placeholders. They must not
be treated as implemented dashboard, supplier, import, search, list, or pricing
functionality.

## Platform workspace

Prompt 09 adds a visibly distinct `/platform` shell for explicit platform administrators. It reuses the shared cards, tables, badges, forms, dialogs, loading states, and theme controls, while labeling the current security scope and never selecting a tenant implicitly. The organization list keeps search, lifecycle, subscription, page, and page-size state in the URL; detail links carry only that filtered-list return context. Platform mutations show pending state, prevent duplicate submission, wait for the API response, and update only the affected detail section.
