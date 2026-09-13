# Prompt Sequence

- **Prompt 00 — Persistent project contract/documentation:** established repository instructions, product and architecture direction, data/security/integration/UX principles, deployment context, ADR process, and roadmap.
- **Prompt 01 — Initial monorepo/application scaffolding and local development foundation:** established the approved initial applications and reproducible local development orchestration.
- **Prompt 02 — Repository quality gates and CI:** established formatting, static analysis, unit/contract/smoke validation, API request correlation, and continuous integration.
- **Prompt 03 — Access data model:** established tenant identities, memberships, invitations, locations, provider-neutral subscription records, and immutable audit history.
- **Prompts 04 and 05 — Authentication and tenant authorization:** implement invite-only cookie authentication, tenant context, policies, organization switching, explicit platform scope, and adversarial boundary tests. This is the current stage.
- **Prompt 06 — Entitlements and subscription lifecycle:** established provider-neutral plans, central entitlement evaluation, and audited platform subscription transitions.
- **Prompts 07 and 08 — Design system and authenticated app shell:** established reusable visual primitives and the tenant organization workspace.
- **Prompt 09 — Platform administration workspace:** adds the separate audited platform scope for customer organization provisioning, lifecycle administration, and secure initial-owner invitations.
- **Later prompts:** supplier ingestion, comparison workflows, observability, security hardening, deployment, and subsequent MVP capabilities.

Later items are themes only. Their detailed requirements and order remain unresolved until their prompts are provided; do not preemptively implement or invent them.
