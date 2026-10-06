# Release rollback

Every release uses immutable Git-SHA image tags. `latest` is never sufficient
for deployment or rollback identity. Keep the prior validated tag, runtime
environment version, backup identifier, and migration status in the release
record.

## Application-only rollback

If the database migration is backward compatible, deploy the previous web,
API, worker, scheduler, ingestion, and PostgreSQL-image tag only when the
PostgreSQL image itself was intentionally changed. Use the same protected
environment file, record the rollback reason, wait for services to become
healthy, and run the smoke script. The worker must be rolled back with the API
so queued job payloads remain compatible.

```sh
DEPLOY_PRODUCTION_APPROVED=1 sh infra/deployment/scripts/deploy.sh \
  production PREVIOUS_GIT_SHA --backup-id PREVIOUS_BACKUP_ID --apply-migrations
```

This command is explicit because it may execute migrations; first determine
whether the old code is compatible with the current schema. Do not blindly run
down migrations.

## Failed or irreversible migration

Stop application/worker writes, preserve logs and the failed migration output,
and do not automatically attempt `migrate:rollback`. If the migration was not
backward compatible, restore a verified database backup into an isolated
database, validate it, restore matching private object storage where necessary,
then perform a controlled cutback as described in
[backup-restore.md](backup-restore.md). Schema/data repair requires an explicit
reviewed follow-up release.

## Configuration, TLS, and service rollback

- **Web/API/worker/scheduler/ingestion:** restore the prior immutable tag and
  the prior reviewed runtime environment file; never copy a current secret into
  an old file without reviewing cookie/session compatibility.
- **Caddy/TLS:** restore the prior Caddy configuration/image and verify 80→443
  redirects, certificate hosts, forwarded headers, and HSTS before reopening
  traffic.
- **PostgreSQL/Redis/MinIO:** do not roll their persisted volumes back merely
  because an application image failed. Restore only under the backup plan.
- **DNS:** return records to the known-good edge only when the previous runtime
  is still healthy. DNS rollback and application rollback are independently
  recorded actions.

After any rollback, run web/API/readiness, queue, authenticated read-only and
private-object smoke checks. Review failed jobs, nonterminal imports, freshness
schedule operation, and the relevant request IDs before declaring recovery.
