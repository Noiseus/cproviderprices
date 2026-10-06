# Staging and production deployment runbook

This is a provider-neutral, Docker Compose pilot runbook. It prepares a clean
runtime but does not authorize a real deployment, DNS change, infrastructure
purchase, or secret creation in this repository.

## Runtime inventory

| Component | Stateful | Exposure / port | Dependency and restart behavior | Health / readiness |
| --- | --- | --- | --- | --- |
| Next.js web | No | Private `3000`, reached only through Caddy | Needs API; restartable immutable image | `/api/health` liveness |
| Laravel API | No application data | Private `8080`, reached by Caddy and web | Needs PostgreSQL, Redis, MinIO bootstrap, ingestion | `/api/v1/health`; readiness verifies DB/Redis/object storage |
| Queue worker | No | No listener | Separate `queue:work`; graceful stop period exceeds 60-second job timeout | PID liveness plus queue smoke acknowledgement |
| Scheduler | No | No listener | Exactly one `schedule:work`; runs five-minute freshness work | PID liveness and scheduler logs |
| PostgreSQL | Yes | Private `5432` | Persistent named volume; never publish a port | `pg_isready` |
| Redis | Operationally persistent | Private `6379` | AOF enabled; queue/cache/rate limits only, not business truth | authenticated `PING` |
| MinIO | Yes | Private `9000`; console private `9001` | Persistent named volume and one-shot bucket/user bootstrap | authenticated internal readiness |
| Python ingestion | No | Private `8001` | Token-authenticated internal service; restartable | internal `/health` |
| Caddy | Certificate/config state | Public `80`, `443` only | TLS edge, HTTP redirect, app/API routing | process liveness; external HTTPS smoke |
| Migration process | No | No listener | One-shot explicit release profile, after backup | exit status and migration table |
| Build/static assets | No | Embedded in immutable web/API images | Built once per Git SHA | image build and web health |

The public `app` host proxies browser `/api/v1/*` to Laravel. This lets the
browser use a same-origin path while `API_INTERNAL_URL` remains private for
Next server rendering; it also means one web image can be promoted unchanged
from staging to production. `api` remains a separately reachable API hostname
for the documented API boundary and future clients. Caddy forwards WebSocket
upgrades automatically, although the current application does not require one.

## Environment and network separation

Run staging and production on separate runtimes for the pilot. Each has a
separate runtime environment file, PostgreSQL volume/database/user, Redis
password/prefix/data, MinIO volume/bucket/credentials, Laravel key, session
cookie name, ingestion token, SMTP credentials, and Caddy certificate state.
Never copy production values into CI or staging.

Only Caddy joins the public `edge` network. It reaches web/API through the
private `application` network. API reaches PostgreSQL, Redis, MinIO, worker,
scheduler, and ingestion through the separate private `data` network. Caddy's
fixed private address is the sole trusted proxy in the Laravel configuration;
if the subnet/address changes, change both `CADDY_APPLICATION_IP` and
`TRUSTED_PROXIES` in the protected runtime file as one reviewed change.

## Provision a runtime

1. Provision a supported Linux host with Docker Engine, Docker Compose v2,
   outbound registry/ACME access, persistent disk, SSH restricted to approved
   administrators, and firewall rules allowing only TCP 80/443 plus restricted
   SSH. Do not expose Docker, PostgreSQL, Redis, MinIO, or ingestion ports.
2. Install Docker using the provider/OS-approved method. Verify `docker
   --version` and `docker compose version`; enable automatic security updates
   according to the host policy.
3. Create a deployment checkout owned by the limited deployment user. It needs
   `infra/deployment/`, its scripts, and the immutable image references; it
   does not need source bind mounts or local development `.env` files.
4. Create `/etc/cproviderprices/staging.env` or
   `/etc/cproviderprices/production.env` mode `0600`, owned by the deployment
   user. Start from the matching tracked example but generate all `REPLACE`
   values privately. Never use an example file as a runtime file.
5. Configure a private image registry/retrieval policy before first deploy.
   The current CI workflow builds and records Git-SHA release identities but
   intentionally does not publish images or contact a runtime until the owner
   configures a registry and guarded deployment credentials.

## DNS, TLS, and first staging release

Follow [dns-checklist.md](dns-checklist.md). Point only the staging app/API
hostnames to the staging edge, wait for DNS resolution, and ensure Caddy can
answer ACME HTTP-01 traffic on port 80 before asking it to issue certificates.

On the staging host, from the deployment checkout:

```sh
docker compose --env-file /etc/cproviderprices/staging.env \
  -p cproviderprices-staging -f infra/deployment/compose.production.yml config -q

docker compose --env-file /etc/cproviderprices/staging.env \
  -p cproviderprices-staging -f infra/deployment/compose.production.yml \
  up -d postgres redis minio minio-init ingestion

sh infra/deployment/scripts/backup-postgres.sh \
  /etc/cproviderprices/staging.env /srv/cproviderprices/backups

sh infra/deployment/scripts/deploy.sh staging GIT_SHA \
  --backup-id RECORDED_BACKUP_ID --apply-migrations
```

The release script validates Compose, pulls the exact tag, starts only data
dependencies first, runs the one-shot migration container, then starts the
full stack with health waiting. It intentionally refuses to run without an
operator-supplied backup identifier and explicit migration flag.

The bootstrap creates a non-superuser Laravel role. It has only the database
and schema privileges required by the current migrations, including database
`CREATE` for the reviewed `pg_trgm` extension migration; it is not a PostgreSQL
superuser and does not receive administrative/cluster access.

Do not run destructive migrations automatically. Prefer expand/contract,
backward-compatible migrations: deploy schema additions first, deploy code
that supports both versions, backfill asynchronously where needed, and remove
old columns only in a later reviewed release. A failed migration stops the
release; do not issue an automatic down migration. See
[rollback.md](rollback.md).

## Session, CSRF, CORS, and proxy configuration

Production templates configure:

```dotenv
APP_URL=https://api.cproviderprices.com
FRONTEND_URL=https://app.cproviderprices.com
SESSION_COOKIE=cproviderprices_production_session
SESSION_DOMAIN=.cproviderprices.com
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
CORS_ALLOWED_ORIGINS=https://app.cproviderprices.com
TRUSTED_PROXIES=172.29.0.2
SECURITY_HSTS_ENABLED=true
```

Staging uses its own hosts, Laravel key, database, credentials and distinct
cookie name. The sibling staging hosts require the same parent cookie scope;
the different cookie names and secrets prevent one environment from accepting
the other's session. CORS never uses `*` with credentials. Caddy terminates
TLS, redirects HTTP, forwards standard client/protocol headers, and only the
known private Caddy address is trusted by Laravel. `NEXT_PUBLIC_*` never holds
a server secret; the production web image uses the same-origin Caddy API path.

## Storage choices

The supplied pilot Compose stack uses MinIO with a persistent volume, a private
bucket, a non-root application credential scoped to that bucket, and no public
API or console port. Raw imports remain private and Laravel continues to issue
authorized application download routes rather than bucket URLs.

An external S3-compatible service is acceptable later when its credentials,
private bucket policy, lifecycle, regional durability, and backup/recovery
properties are reviewed. In that case remove self-hosted MinIO from the
deployment profile through a reviewed provider overlay; keep `FILESYSTEM_DISK`
as `s3`, use a separate bucket/credential per environment, and do not expose
browser credentials. Do not use an application container filesystem for imports.

## Worker, scheduler, and operational checks

The worker consumes `default`, `import-normalization`, `catalog-matching`, and
`freshness` queues with three tries and a 60-second timeout. It is one
container at pilot scale; scale only after measuring queue latency and ensuring
idempotency. The scheduler is exactly one `schedule:work` container. It retains
Prompt 16's five-minute, lock-protected freshness recalculation.

Check processes and logs without printing runtime environment files:

```sh
docker compose --env-file /etc/cproviderprices/staging.env \
  -p cproviderprices-staging -f infra/deployment/compose.production.yml ps
docker compose --env-file /etc/cproviderprices/staging.env \
  -p cproviderprices-staging -f infra/deployment/compose.production.yml logs --tail=200 worker scheduler ingestion
```

Container logs are the pilot's central operational stream: API/worker/scheduler
emit structured JSON to stderr; Next, ingestion and Caddy emit container logs.
Configure Docker's local log rotation or the host's approved collector before
go-live, retain enough history for incident investigation, and restrict log
access. Do not introduce a paid observability vendor solely for this prompt.

## Post-deploy smoke and release completion

Create a dedicated read-only synthetic smoke account through the normal
invitation process. Store `SMOKE_EMAIL`, `SMOKE_PASSWORD`, and a non-mutating
`SMOKE_READONLY_URL` only in the runtime secret environment, not the tracked
deployment file. Then run:

```sh
sh infra/deployment/scripts/smoke.sh /etc/cproviderprices/staging.env
```

It checks web liveness, API liveness, API readiness (database, Redis and
object-storage write/read/delete), CSRF/login, a read-only authenticated route,
and an actual non-destructive Redis worker acknowledgement. Review the
scheduler container log after its five-minute cadence and record the image tag,
backup ID, smoke result, request IDs on any failure, and operator in the
release record.

## Upgrade and rollback

Build and validate a Git-SHA image in CI, deploy it to staging, complete smoke
checks, and retain the exact tag. Production requires manual GitHub Environment
approval and an operator command with `DEPLOY_PRODUCTION_APPROVED=1`; it is
never auto-promoted from `main`. Use the same already-validated tag and follow
[rollback.md](rollback.md) if any readiness/smoke or business verification
fails.

## Common failures

| Symptom | Check / safe response |
| --- | --- |
| Caddy cannot issue TLS | Verify authoritative DNS, public 80/443 firewall, ACME email, and no competing proxy. Do not disable HTTPS/cookie security. |
| API fails configuration validation | Correct the protected runtime file; do not add defaults or use production values in staging. |
| Readiness says object storage failed | Check MinIO/S3 private credential, bucket policy and endpoint. Do not make a bucket public. |
| Queue smoke times out | Check worker liveness, Redis authentication, queue logs and failed jobs; do not enqueue duplicate imports to test it. |
| Scheduler has no freshness run | Confirm exactly one scheduler, its Redis connection and lock/log output. |
| Browser session/CSRF fails | Verify HTTPS, exact cookie domain/name, trusted Caddy address, CORS origin and app/API hostnames. |
| Migration fails | Stop the release, preserve logs, keep old code running only if schema-compatible, and follow backup/rollback guidance. |
