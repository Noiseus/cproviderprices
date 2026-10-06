# Provider-neutral deployment assets

These assets run the same production topology in staging and production without
development bind mounts, Mailpit, test fixtures, published database ports, or
published ingestion/object-storage administration ports.

- `compose.production.yml` is the production-style Compose topology. Select the
  environment with an ignored runtime file under `/etc/cproviderprices/`.
- `Caddyfile` is the only public edge: it terminates TLS, redirects HTTP, routes
  app-host `/api/v1` requests to Laravel, and keeps web/API server-to-server
  traffic private.
- `env/*.example` are inventories, not deployable credentials.
- `scripts/` contains explicit backup, restore, release, and smoke commands.

Build the four immutable application images from the root with the matching
production Dockerfiles. Do not use `docker compose up` against this file until
the runbook's DNS, host firewall, secrets, backup target, and protected release
approval requirements have been completed.

See [the deployment runbook](../../docs/operations/deployment-runbook.md).
