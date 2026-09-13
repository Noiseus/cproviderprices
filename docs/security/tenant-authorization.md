# Tenant context and authorization

## Request pattern

Private tenant APIs use `/api/v1/organizations/{organization}/...`; private web pages use `/o/[organizationSlug]/...`. The API route organization is authoritative. `ResolveTenantContext` requires an authenticated active user, an existing route-bound organization, an active membership in that organization, and the permitted organization lifecycle. It then exposes one request-scoped `TenantContext` containing the user, organization, and membership.

Normal tenant routes deny suspended organizations with `organization_suspended`. The limited organization status endpoint uses the same membership checks but allows the suspended lifecycle so the web app can render a useful state. Closed organizations are unavailable. Suspended or left memberships are denied.

Tenant-owned direct identifiers must be queried through the context organization relationship, as `RestaurantLocationController` does. Future code should start from `$context->organization` or an equivalently constrained query and must not expose an unscoped tenant-record lookup as its default service/repository method. A body or query `organization_id`, localStorage value, slug, role, or membership selection is never authorization evidence.

Entitlement-gated mutations add subscription and entitlement checks only after tenant membership and role authorization. The server derives plan, limit, and current usage from tenant-scoped database records. A denied role must not receive plan or usage details, and frontend visibility never substitutes for this server sequence.

## Policies and roles

Laravel policies cover organizations, memberships, invitations, and restaurant locations. Viewer access is read-only. Buyers receive normal tenant read access but cannot change owner-level membership or organization administration. Organization owners can administer their organization but do not receive platform privilege.

`platform_admin` remains a user platform role and is not a membership role. Platform operations live under an explicit `platform.scope` middleware; there is no global policy bypass. Entering the platform route and denied attempts are audited.

## Switching and auditing

The organization switch endpoint is available only when the user has at least two active memberships in active organizations. It re-runs tenant resolution, stores a navigation preference in the session, validates the destination against the selected slug, and never creates or changes membership. The frontend only renders the switcher for the same multi-active-organization condition; the server remains authoritative.

Audit rows are persisted for organization switches, role changes, denied high-risk mutations, successful explicit platform actions, and denied platform-scope attempts. Metadata contains only route/ability and scalar context—never passwords, session cookies, reset tokens, invitation tokens, or CSRF values.

The standard API envelope and `X-Request-ID` cover authentication, authorization, lifecycle, throttling, and validation failures. Frontends should branch on stable codes including `unauthorized`, `forbidden`, `account_suspended`, `membership_suspended`, `organization_suspended`, and `organization_unavailable`.

## Platform administration workspace

Prompt 09 keeps platform operations under `/platform` in the web application and `/api/v1/platform` in the API. Both are separate from `/o/[organizationSlug]` and tenant-scoped API paths. The web layout rejects users without `users.platform_role = platform_admin`, while every API request independently passes through authentication, active-user enforcement, and `platform.scope`. Platform privilege never creates a membership or grants access through tenant routes.

Platform organization list/detail responses are provisioning-only: identity, lifecycle, locations, members, initial-owner invitation status, plan/subscription/entitlement summaries, and relevant audit history. Supplier pricing, raw files, credentials, and support impersonation are outside the platform response boundary. Mutations use authoritative route UUIDs, validate inputs, preserve organization data on lifecycle changes, and record the existing request correlation ID in audit metadata.
