# Access Model

## Purpose

This access model establishes the private, multi-tenant account foundation for CProviderPrices. It represents identities, tenant organizations, organization-scoped access, invitations, restaurant locations, provider-neutral commercial plans and subscriptions, and immutable audit history. Prompt 03 established these entities; Prompts 04 and 05 added invite-only authentication, tenant context, and authorization policies; Prompt 06 adds provider-neutral entitlement and subscription lifecycle enforcement while supplier, product, and pricing data remain deferred.

## Entities and responsibilities

- `users` holds a person's application identity, active/suspended lifecycle, and session invalidation version. A nullable `platform_role` may contain only `platform_admin`; platform privilege is not an organization membership role.
- `organizations` is the tenant/business account and owns an explicit lifecycle status.
- `organization_memberships` is the sole user-to-organization access relationship. It records one current membership lifecycle per user and organization.
- `restaurant_locations` represents an organization's restaurant or business locations.
- `invitations` records an intended organization role and normalized email, but only a keyed SHA-256 token hash.
- `plans` and `plan_entitlements` describe provider-neutral commercial definitions and their typed JSON capability/limit values.
- `subscriptions` records an organization's plan history and subscription lifecycle without controlling organization lifecycle.
- `audit_logs` stores immutable event context using durable scalar identifiers and snapshots rather than foreign keys that could erase history.

## ER-style diagram

```text
users 1 ---< organization_memberships >--- 1 organizations
  |                    |                         |
  |                    | invited_by              +---< restaurant_locations
  |                    v                         +---< invitations
  +------------------ users                      +---< subscriptions >--- 1 plans
  |                                                                          |
  +---< invitations.invited_by                                               +---< plan_entitlements

audit_logs -- optional scalar organization/actor/subject context (no cascading FK)
```

## Users, organizations, and memberships

A user can hold memberships in any number of organizations. The database prevents more than one membership record for the same `(organization_id, user_id)` pair. Lifecycle changes update that record instead of creating ambiguous duplicate access paths. Tenant context must be derived and authorized server-side from the authenticated user and this relationship; a client-supplied organization ID is never proof of access.

The membership lifecycle is:

```text
invited -> active -> suspended -> active
                    |              |
                    +-----> left <-+
```

An `invited` membership has no `joined_at`; all other membership states require it. `invited_by` is optional so imports or system-created access can be represented. Leaving or suspension is explicit and does not delete access history.

## Roles

The stable role set is exactly:

- `platform_admin`: application-wide operational privilege, stored only in `users.platform_role` and conceptually separate from tenant roles.
- `organization_owner`: organization-level ownership and administration.
- `buyer`: organization purchasing work.
- `viewer`: organization read-only access.

Only the three organization-level values are accepted by memberships and invitations. This prompt intentionally does not introduce dynamic permissions.

## Invitation lifecycle and token handling

Invitations use `pending`, `accepted`, `expired`, and `revoked` statuses. A pending invitation is active only before `expires_at`; the model reports an overdue pending record as effectively expired even before a maintenance process persists the `expired` state. Acceptance and revocation timestamps preserve the corresponding transition.

Invitation emails are trimmed and lowercased by the model and enforced as normalized by PostgreSQL. A partial unique index prevents two pending invitations for the same normalized email and organization while allowing historical accepted, expired, or revoked invitations.

Plaintext invitation tokens are never persisted. The stored lookup value is an HMAC-SHA-256 digest keyed by the application key. Factory helpers accept a plaintext token only as a transient method argument and place only its digest in model state. Token hashes are globally unique and hidden from model serialization. Tokens and credentials must never appear in audit metadata.

## Organization and subscription lifecycles

Organization lifecycle values are `active`, `suspended`, and `closed`. Subscription lifecycle values are `trialing`, `active`, `past_due`, `suspended`, `canceled`, and `expired`. They are independent columns on independent entities: changing or ending a subscription never changes or deletes the organization. Multiple subscriptions may be retained as history; the entitlement service selects the latest by start and creation time instead of relying on an ambiguous convenience relationship. An elapsed trial is treated as effectively expired from its explicit timestamp without coupling that transition to organization lifecycle.

## Identifier and time strategy

All new domain primary keys use non-incrementing UUIDv7 identifiers, generated by Laravel's `HasUuids` concern. UUIDv7 is time-ordered, PostgreSQL-compatible, and suitable for distributed API and future mobile clients without exposing integer sequences. Foreign key columns use PostgreSQL `uuid` consistently; no second domain ID strategy is introduced.

Application time and containers use UTC. Database instants use timezone-aware columns (`timestamp with time zone` through Laravel's `timestampTz`). Location timezone is descriptive business context only and does not change the UTC storage rule.

## Integrity and lookup rules

- User email, organization slug, plan code, and invitation token hash are unique.
- Membership `(organization_id, user_id)` is unique and role/status values are database constrained.
- Membership indexes support active memberships by user and by organization.
- Location code is unique within an organization; organization/status supports tenant location lists.
- Only one pending invitation may exist per organization and normalized intended email. Status/expiration and organization/email lookups are indexed.
- Entitlement key is unique within a plan.
- Subscription indexes support organization/status and plan/status lookups while preserving subscription history.
- Foreign keys use deliberate restrictive deletion for tenant and billing history. Optional inviters use `SET NULL`.
- Soft deletion is not used; meaningful lifecycle state is explicit.

## Audit intent

Audit rows record an event name, UTC occurrence time, optional organization and actor identity, optional subject type/identifier, an actor label snapshot, and secret-free JSON metadata. The Eloquent model rejects updates and deletes. Organization, actor, and subject references are deliberately scalar and have no cascading foreign keys so deleting or changing a mutable domain record cannot silently erase historical context.

## Tenant isolation considerations

Customer-owned records have an explicit organization owner or an unambiguous ownership chain. Membership is the authorization join and must be checked server-side for every tenant operation. Platform administrators are represented separately from organization memberships and require explicit privileged paths and auditing. No model exposes a cross-tenant convenience relationship, and audit entries carry organization context where applicable. Policies and request-level authorization use the pattern documented in `docs/security/tenant-authorization.md`.
