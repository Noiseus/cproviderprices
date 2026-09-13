# Plans, subscriptions, and entitlements

## Provider-neutral plan definitions

Plans and their entitlement rows are the server-side source of capability and limit values. `EntitlementKey` is the canonical typed key set: `max_locations`, `max_users`, `max_supplier_accounts`, `manual_imports_enabled`, `automatic_sync_enabled`, `price_history_enabled`, and `basket_optimizer_enabled`. Limit values use `{ "limit": integer }`; feature values use `{ "enabled": boolean }`. Authorization must never branch on a plan code or display name.

The stable `starter` and `growth` definitions are seeded without prices, customers, subscriptions, or payment-provider data. Changing an organization's effective plan changes the database-backed authorization result on the next request; entitlement state is cached only within one request.

## Central evaluation

`EntitlementService` selects the organization's latest subscription deterministically, resolves its plan entitlements, evaluates lifecycle access, reports feature flags, computes implemented usage, and raises standard API problems for denied actions. Policies and tenant context run first. The authorization sequence is authenticated identity, tenant membership and role, organization lifecycle, subscription lifecycle, entitlement, then the use case.

Restaurant-location creation enforces `max_locations`. Invitation acceptance enforces `max_users` when an invited membership becomes active. Location usage counts all retained locations; user usage counts active memberships. `max_supplier_accounts` is defined but its usage remains `null` until the approved supplier-account domain exists.

The server is authoritative. The web status page uses the same response to explain or hide unavailable functions, but client state never grants access.

## Trial and subscription lifecycle

A trial is stored as `trialing` with explicit `starts_at` and `trial_ends_at` timestamps. At or after `trial_ends_at`, the effective status is deterministically `expired` even if a maintenance job has not rewritten the stored row. Active and past-due subscriptions grant entitlement access; past-due is a visible grace state. Suspended, canceled, expired, missing, or not-yet-started subscriptions do not grant entitlement-gated actions.

Subscription status is distinct from organization status. Trial expiry, suspension, cancellation, and restoration do not change or delete the organization, memberships, locations, or audit history.

## Platform administration

The explicit audited platform route supports `start_trial`, `extend_trial`, `activate`, `mark_past_due`, `suspend`, `cancel`, and `restore`. Starting a replacement trial closes the preceding current subscription row and creates a new history row. Other actions update the selected current row. Plan input is a server-resolved active plan code; clients cannot supply entitlement values. Organization owners cannot use this route.

## Error and frontend behavior

Limit conflicts use HTTP 409 with code `limit_reached` and safe details containing `entitlement_key`, `limit`, and `current_usage`. Disabled plan capabilities use HTTP 403 and code `feature_disabled`. Subscription suspension uses HTTP 423 and code `subscription_suspended`. Every response uses the standard error envelope and correlation ID.

The private `/o/[organizationSlug]/subscription` page shows plan, effective lifecycle, trial end, capabilities, and implemented usage. It includes reusable limit, unavailable, suspended, and contact states. It intentionally contains no checkout, invoice, payment method, or provider-specific behavior. A future payment integration should translate external events into these existing provider-neutral lifecycle operations rather than move authorization into a billing SDK.

Prompt 09 exposes the approved lifecycle actions through the separate platform workspace. Transitions are implemented by `PlatformSubscriptionService`, not duplicated in controllers or frontend code. Suspension and cancellation require a reason and UI confirmation; the server response is authoritative. Each transition records actor, organization, subscription, previous/next status, plan code, reason when supplied, and request ID. Cancellation or suspension never deletes the organization or customer records.
