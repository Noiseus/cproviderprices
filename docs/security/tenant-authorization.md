# Tenant context and authorization

## Request pattern

Private tenant APIs use `/api/v1/organizations/{organization}/...`; private web pages use `/o/[organizationSlug]/...`. The API route organization is authoritative. `ResolveTenantContext` requires an authenticated active user, an existing route-bound organization, an active membership in that organization, and the permitted organization lifecycle. It then exposes one request-scoped `TenantContext` containing the user, organization, and membership.

Normal tenant routes deny suspended organizations with `organization_suspended`. The limited organization status endpoint uses the same membership checks but allows the suspended lifecycle so the web app can render a useful state. Closed organizations are unavailable. Suspended or left memberships are denied.

Tenant-owned direct identifiers must be queried through the context organization relationship, as `RestaurantLocationController` does. Future code should start from `$context->organization` or an equivalently constrained query and must not expose an unscoped tenant-record lookup as its default service/repository method. A body or query `organization_id`, localStorage value, slug, role, or membership selection is never authorization evidence.

## Policies and roles

Laravel policies cover organizations, memberships, invitations, and restaurant locations. Viewer access is read-only. Buyers receive normal tenant read access but cannot change owner-level membership or organization administration. Organization owners can administer their organization but do not receive platform privilege.

`platform_admin` remains a user platform role and is not a membership role. Platform operations live under an explicit `platform.scope` middleware; there is no global policy bypass. Entering the platform route and denied attempts are audited.

## Switching and auditing

The organization switch endpoint is available only when the user has at least two active memberships in active organizations. It re-runs tenant resolution, stores a navigation preference in the session, validates the destination against the selected slug, and never creates or changes membership. The frontend only renders the switcher for the same multi-active-organization condition; the server remains authoritative.

Audit rows are persisted for organization switches, role changes, denied high-risk mutations, successful explicit platform actions, and denied platform-scope attempts. Metadata contains only route/ability and scalar context—never passwords, session cookies, reset tokens, invitation tokens, or CSRF values.

The standard API envelope and `X-Request-ID` cover authentication, authorization, lifecycle, throttling, and validation failures. Frontends should branch on stable codes including `unauthorized`, `forbidden`, `account_suspended`, `membership_suspended`, `organization_suspended`, and `organization_unavailable`.
