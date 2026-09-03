# Architecture Decision Records

Architecture Decision Records (ADRs) capture meaningful decisions that change or clarify the project's durable technical direction. Use an ADR when choosing between consequential alternatives, changing an approved technology or service boundary, or accepting a significant cross-cutting tradeoff. Do not create ADRs for routine implementation details.

## Process

1. Copy the template below into `docs/adr/NNNN-short-title.md` using the next sequential number.
2. Set the status to `Proposed` while discussion or approval is pending.
3. Describe context and realistic alternatives concisely.
4. Change the status to `Accepted`, `Rejected`, or `Superseded` when decided.
5. Link superseding and superseded ADRs; do not rewrite accepted history to hide a change.
6. Update affected project documentation with the accepted outcome.

## Lightweight template

```markdown
# ADR NNNN: Decision title

- Status: Proposed
- Date: YYYY-MM-DD
- Owners: TBD

## Context

What problem, constraints, and existing decisions shape this choice?

## Decision

What will we do?

## Alternatives considered

What credible options were considered and why were they not selected?

## Consequences

What benefits, costs, risks, migrations, and follow-up work result?
```
