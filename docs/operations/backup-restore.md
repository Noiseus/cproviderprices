# Backup and restore strategy

## Pilot policy

PostgreSQL is the system of record. Create one encrypted logical PostgreSQL
backup daily and retain at least 14 daily restore points. Keep a copy outside
the application runtime before considering a backup complete. This is a pilot
baseline: it is intentionally smaller than enterprise retention, but covers
operator mistakes and a single-host loss when paired with an off-server copy.

MinIO/self-hosted object storage is durable only while its named volume and
host survive. Mirror the private bucket to encrypted off-server object storage
daily, apply a lifecycle policy that preserves raw import evidence for the
approved product retention period, and test restore of both metadata and a
representative private object. Never make the bucket public as a backup
shortcut.

Runtime environment files and deployment keys are not copied into database
backups. Keep their recovery material in a restricted password manager or
secret manager, with access separate from the backup location.

## Backup procedure

On the runtime host, after the Compose stack is healthy, run:

```sh
sh infra/deployment/scripts/backup-postgres.sh \
  /etc/cproviderprices/production.env /srv/cproviderprices/backups
```

The script uses `pg_dump -Fc --no-owner --no-acl`, writes a SHA-256 sidecar, and fails if the
archive is empty. Restrict the backup directory to the deployment account,
encrypt/mirror the archive and checksum off-host, and record the resulting
backup identifier before a release migration. Use the matching staging file
for staging. The release script deliberately requires a backup identifier; it
does not claim a backup exists merely because one was intended.

For managed PostgreSQL, use the provider's point-in-time/logical backup
features in addition to a periodic portable logical dump. For external
S3-compatible storage, use versioning/lifecycle and cross-location redundancy
instead of the MinIO mirror process.

## Isolated restore test

Do not restore over a live environment. The helper refuses targets not ending
in `_restore`:

```sh
sh infra/deployment/scripts/restore-postgres.sh \
  /etc/cproviderprices/staging.env \
  /srv/cproviderprices/backups/cproviderprices-staging-YYYYMMDDTHHMMSSZ.dump \
  cproviderprices_staging_restore
```

It verifies a stored checksum when present, recreates only the isolated target
database, restores without ownership changes, and queries the `migrations`
table. Follow with a read-only application verification against a separate
temporary API configuration pointing at that database; never point the normal
staging or production application at a restore target. Perform this exercise
at least quarterly and after a material migration or storage change.

The Prompt 21 local validation created a representative `pg_dump -Fc --no-owner
--no-acl` archive from a disposable source, restored it into a separate
disposable PostgreSQL container, and verified the restored schema/data. It did
not touch development or production databases.

## Recovery order

1. Contain the incident and preserve correlation/log evidence according to the
   [threat model](../security/threat-model.md#incident-response-for-the-pilot).
2. Provision an isolated PostgreSQL target and restore the chosen verified
   archive.
3. Restore/mirror the matching private object-storage point before exposing
   imports to users.
4. Configure a separately reviewed application environment, run migrations
   only if the target release requires forward-compatible changes, then perform
   readiness and authenticated read-only smoke checks.
5. Record the chosen recovery point, data-loss window, validation, and any
   credential rotation. Do not treat Redis as a source for business recovery.
