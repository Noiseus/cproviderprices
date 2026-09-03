# Security Baseline

These rules are mandatory for all future implementation.

## Tenant isolation and access control

- Enforce strict tenant isolation in queries, commands, jobs, caches, object keys, exports, logs, and administrative tools.
- Never trust tenant/company IDs supplied by clients without server-side authorization. Derive authorized tenant context from the authenticated identity and server-controlled membership or privilege records.
- Apply server-side authorization to every protected resource and action, including indirect object references and bulk operations.
- Treat authentication and authorization separately: identity alone does not grant access to a tenant or resource.
- Test tenant-boundary failures, not only successful access.

## Secrets and environments

- Never commit credentials, tokens, private keys, connection strings, production data, or other secrets.
- Document required variables in safe example files with inert values; `.env.example` may be committed, real `.env` files may not.
- Separate staging and production accounts, secrets, configuration, data, and access wherever practical.
- Rotate exposed secrets and remove them from history through an approved incident process; deletion from the latest file alone is insufficient.

## Application and operations

- Use least privilege for database users, service identities, storage, deployment credentials, and human access.
- Validate type, shape, size, and allowed values at trust boundaries; safely encode output and sanitize content where its use requires it.
- Protect sensitive actions against common web threats appropriate to the chosen authentication mechanism.
- Log sensitive operational actions such as privilege changes, tenant administration, imports, exports, connector changes, and destructive operations.
- Avoid secrets and unnecessary personal or commercially sensitive data in logs and errors.
- Keep dependencies supported and review security impact before introducing them.

## Supplier ingestion

All supplier input is untrusted. Validate files and payloads, constrain resource consumption, isolate connector credentials, and prevent source content from controlling paths, queries, commands, or rendered output. Preserve source provenance, ingestion timestamps, result status, validation failures, and actor/job context sufficient for audit. Customer-specific supplier prices must remain tenant-isolated through ingestion, processing, storage, and export.

Security-sensitive architectural exceptions require explicit review and an ADR where they alter the baseline.
