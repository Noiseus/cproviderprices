# Launch 01 — real staging readiness

Date: 2026-10-06
Scope: first externally provisioned **staging** runtime only. This record does
not authorize a purchase, DNS change, external deployment, credential
creation, production deployment, or customer-data use.

This is the preflight companion to the canonical
[deployment runbook](deployment-runbook.md), not a second deployment
architecture. Once every external prerequisite below is supplied, follow that
runbook's commands in order.

## Repository readiness inventory

| Area | Status | Evidence / disposition |
| --- | --- | --- |
| Immutable application images | READY | Production Dockerfiles exist for web, API, ingestion, and PostgreSQL. The web Dockerfile includes every npm workspace manifest required by the lockfile. |
| Runtime topology | READY | `infra/deployment/compose.production.yml` defines Caddy, web, API, worker, scheduler, ingestion, PostgreSQL, Redis, MinIO, a private bucket bootstrap, and one-shot migration. |
| Edge and TLS | READY | `infra/deployment/Caddyfile` exposes only 80/443, redirects HTTP, and routes app/API hosts. Actual ACME issuance requires DNS and public ports. |
| Runtime configuration | READY | Separate tracked staging/production inventories exist; real `/etc/cproviderprices/*.env` files remain external. Mobile token TTL is now explicit in the inventory and Compose API environment. |
| Database migration | READY | The release-only `migrate` service runs `migrate --force --isolated` after the data dependencies are healthy. |
| Backup and restore tooling | READY | Scripts create a compressed checksum-backed dump and refuse restores except to a database ending `_restore`. The backup script reads only `POSTGRES_DB` from the protected Compose environment before using its database name. |
| Health, readiness and smoke | READY | Web/API health, API dependency readiness, authenticated read-only, CSRF/login, and queue acknowledgement checks are present. |
| Worker, scheduler and ingestion | READY | Each is a distinct private service with health/lifecycle settings; Python has no public listener and is token-authenticated. |
| Release workflow | NEEDS EXTERNAL ACTION | GitHub builds immutable local images and retains a tag artifact. Publishing, registry retrieval, deployment credentials and protected environment approval are deliberately not configured. |
| Off-host recovery | NEEDS EXTERNAL ACTION | No backup destination, encryption key custody, mirror job or real staging restore drill exists yet. |
| Staging runtime, DNS and SMTP | NEEDS EXTERNAL ACTION | No Docker-capable host, DNS target, TLS certificate, SMTP credentials, staging identity, or monitoring/alert routing has been supplied. |

## Mobile impact from Prompt 23

The native client adds no service to the staging host. Expo development or
build tooling stays off the runtime. A staging device/build may use only the
public, non-secret value:

```text
EXPO_PUBLIC_API_URL=https://api-staging.cproviderprices.com/api/v1
```

The existing public API edge supplies TLS. CORS remains an explicit browser
policy for `https://app-staging.cproviderprices.com`; native HTTP clients do
not obtain browser CORS privileges. Mobile login shares the existing login
rate limiter. Laravel stores hashed, revocable, expiring tokens in staging's
own PostgreSQL volume; token data never crosses environments. The API accepts
only scoped mobile reads plus session preference/logout, keeps organization and
location authorization server-side, and returns stable token errors. Staging
validation must exercise login, expiry/revocation, a permitted read, and a
forbidden mutation on a controlled synthetic identity.

## Minimum practical staging runtime

The provider-neutral baseline in
[hosting feasibility](hosting-feasibility.md#resource-envelope) is retained:

| Requirement | First staging minimum | Required now | Optional later |
| --- | --- | --- | --- |
| Architecture / OS | x86_64 Linux; a currently supported LTS distribution with a maintained Docker Engine package | Yes | ARM only after every production image is verified multi-architecture |
| CPU | 2 vCPU | Yes | 4 vCPU when queue/import latency or host metrics require it |
| RAM | 4 GiB | Yes | 8 GiB for more operating headroom or measured contention |
| Persistent disk | 60 GiB fast SSD/NVMe | Yes | Increase after 30 days of images, logs, imports, database and restore measurements |
| Docker | Docker Engine plus Docker Compose v2 | Yes | Registry cache, host orchestration or managed services |
| Inbound network | TCP 80/443; SSH only from approved administrator networks | Yes | IPv6 only after routing and firewall are tested |
| Outbound network | Image registry, OS security updates, ACME, selected SMTP submission and the off-host backup endpoint | Yes | External monitoring/alert collector |
| Backup storage | Separate encrypted off-host location with at least 20 GiB initial capacity | Yes | Larger retention, provider snapshots and cross-region replication |

These are a pilot floor, not a production-capacity claim. Compose service
limits constrain processes but do not replace host monitoring or a sizing
review after real staging import volume is known.

## Network and service topology

```text
Internet
  └── Caddy (staging host; public TCP 80/443)
        ├── web :3000              [private application network]
        └── api :8080              [private application + data networks]
              ├── PostgreSQL :5432 [private data network, persistent volume]
              ├── Redis :6379      [private data network, AOF volume]
              ├── MinIO :9000/9001 [private data network, persistent volume]
              ├── worker           [private, no listener]
              ├── scheduler        [private, exactly one]
              └── ingestion :8001  [private, token-authenticated]
```

`app-staging` and `api-staging` both resolve to the same Caddy edge. The API
container itself is never Internet-exposed. PostgreSQL, Redis, MinIO API,
MinIO console, ingestion, Docker daemon and Compose networks are private-only.
The staging host, volumes, backup target, app key, cookies, database, prefixes,
bucket/credentials, ingestion token, SMTP credentials and mobile-token rows
are all independent from development and production.

## Existing Hostinger Business Web Hosting

The existing paid plan remains useful, but is not the runtime for this stack.
The assessment is based on the repository topology and the provider references
recorded in [hosting feasibility](hosting-feasibility.md), which operators must
recheck against their account before any purchase.

| Component | Current Business Web Hosting usable? | Why | Alternative requirement |
| --- | --- | --- | --- |
| Domain registration and DNS | Yes | No Docker/process control is needed. | Keep DNS at Hostinger; point only future staging records to the new edge. |
| Future `www` / redirect | Yes, when separately approved | Static marketing/redirect hosting does not run the application topology. | A separately approved public-site deployment. |
| Email-related DNS | Yes | DNS may host SPF/DKIM/DMARC records selected by the SMTP provider. | Configure the provider-approved records without altering unrelated MX/TXT. |
| Next.js SSR web | No | Needs the private API topology and long-running Node runtime. | Docker-capable Linux host. |
| Laravel API, worker and scheduler | No | Requires supervised private services, Redis and PostgreSQL. | Docker-capable Linux host. |
| PostgreSQL, Redis, MinIO, Python ingestion | No | Need persistent volumes, private networking, process control and least-privilege credentials. | Docker-capable Linux host with private Compose networks. |
| Caddy/TLS edge | No | Needs ports 80/443 and the private upstream network. | Docker-capable Linux host. |

No cancellation, replacement, DNS modification or Hostinger VPS purchase is
part of this readiness work.

## Staging configuration inventory

Create `/etc/cproviderprices/staging.env` mode `0600`, owned by the deployment
user, from `infra/deployment/env/staging.env.example`. Do not copy it into the
repository, an image, CI output, Expo config or a shell transcript.

| Classification | Values |
| --- | --- |
| Public configuration | `APP_NAME`, `APP_ENV`, `APP_DEBUG=false`, immutable image names/tag, app/API hosts and URLs, CORS origin, trusted-host regex, network subnet/Caddy address, database name/user, Redis/cache prefixes, bucket name, mail host/port/from address, log level, `MOBILE_ACCESS_TOKEN_TTL_DAYS`, and Expo's staging API origin. |
| Secret generated for staging | `APP_KEY`, PostgreSQL admin/app passwords, Redis password, MinIO root password, MinIO application secret, ingestion token, and the smoke-account password. Generate these in a password/secret manager or secure terminal; do not generate them in this repository. |
| External credential | Registry pull/deploy identity, SMTP username/password, off-host backup destination credential and any operator SSH key. |
| Security-sensitive non-secret configuration | `SESSION_COOKIE`, `SESSION_DOMAIN`, `TRUSTED_HOSTS`, `TRUSTED_PROXIES`, `CORS_ALLOWED_ORIGINS`, `MAIL_FROM_*`, `ACME_EMAIL`, and `MOBILE_ACCESS_TOKEN_TTL_DAYS`. Review them with the release change. |

The staging mobile token lifetime is currently 30 positive whole days. It is
not a secret. No raw device token is put into this file; issued tokens remain
hashed in the staging database and are revocable by the API.

## Future DNS and SMTP actions

Do not create these records until a staging edge has a verified public IP or
provider hostname.

| Record | Hostname | Target | TTL before cutover | Proxy / TLS notes |
| --- | --- | --- | --- | --- |
| `A` | `app-staging.cproviderprices.com` | Staging Caddy IPv4, not yet supplied | 300 seconds | DNS-only for the first launch so Caddy can complete HTTP-01; Caddy terminates TLS. |
| `AAAA` | `app-staging.cproviderprices.com` | Staging IPv6 only after firewall/routing tests | 300 seconds | Optional; do not publish merely because a host has IPv6. |
| `A` | `api-staging.cproviderprices.com` | Same staging Caddy IPv4 | 300 seconds | DNS-only for the first launch; Caddy terminates TLS. |
| `AAAA` | `api-staging.cproviderprices.com` | Same tested staging IPv6 | 300 seconds | Optional. |
| Provider-required TXT/CNAME | Staging sending domain/subdomain, only if selected SMTP provider requires it | Provider-supplied target | Provider recommendation | Required for sender verification/DKIM; do not alter unrelated MX, SPF or DMARC records. |

An external proxy is not appropriate for the initial launch unless its TLS,
HTTP method/header behavior, origin address rules and ACME flow are explicitly
verified. No DNS entry may expose a private service.

Staging SMTP requires authenticated TLS submission (normally port 587 unless
the chosen provider documents another port), a verified staging sender/domain,
an address that can receive invitation, verification and reset messages, and
safe delivery/log review. Mailpit is development-only. No provider is selected
here; use a provider with credentials and DNS records that can remain separate
from production.

## Stateful services and recovery

Private Dockerized PostgreSQL, Redis and MinIO are appropriate for the first
staging pilot because the repository already supplies persistent volumes,
private networks and health checks. They are a single-host availability
boundary, not managed-service equivalents.

| Service | Staging decision | Required safeguards |
| --- | --- | --- |
| PostgreSQL | Use the private persistent Compose service. | Non-superuser app role; no public port; encrypted daily logical dump, checksum, off-host copy; isolated restore at least quarterly and after a material migration. |
| Redis | Use the private persistent Compose service. | Password, AOF, distinct prefixes and volume; no public port. Redis is never business-data recovery. |
| MinIO | Use the private persistent Compose service and private bucket. | No public API/console; least-privilege app identity; encrypted daily off-host object mirror; restore a representative private object with matching metadata. |

The backup target must be outside the host and separately authenticated. It
must retain at least 14 daily PostgreSQL restore points and corresponding
private-object mirrors, protect archives/mirrors with encryption, retain
checksums, and support an isolated restore without repointing live staging.
Host snapshots are supplementary only until a documented restore drill proves
otherwise.

## First staging deployment sequence

1. Provision the approved Linux runtime; record its provider, region, IPv4 and
   release operator outside Git.
2. Harden access: deployment user, SSH keys, restricted SSH firewall, OS
   updates, Docker log rotation and no public Docker socket.
3. Install and verify Docker Engine and Compose v2; create the protected
   deployment checkout and `/etc/cproviderprices` directory.
4. Configure a private immutable-image registry/retrieval identity and obtain
   the validated Git-SHA release images; do not use `latest`.
5. Generate and store separate staging secrets; create the `0600` runtime
   environment file from the tracked inventory.
6. Configure the persistent host volumes and the encrypted off-host backup
   destination, then test destination access without printing credentials.
7. Validate `docker compose --env-file /etc/cproviderprices/staging.env -p
   cproviderprices-staging -f infra/deployment/compose.production.yml config -q`.
8. Create only the two approved staging DNS records after the host firewall is
   ready; confirm authoritative resolution before Caddy is asked to complete
   ACME HTTP-01 validation.
9. Start PostgreSQL, Redis, MinIO, its bootstrap, and ingestion as specified
   by the runbook; confirm only private listeners exist.
10. Run the backup script and record its identifier after the off-host copy and
   checksum are confirmed.
11. Run `deploy.sh staging GIT_SHA --backup-id RECORDED_BACKUP_ID
    --apply-migrations`; preserve migration output and stop on failure.
12. Confirm Compose health, `/api/health`, `/api/v1/health` and
    `/api/v1/readiness` before public cutover.
13. Verify HTTPS, redirect, trusted host/cookie behavior and exact certificate
    names from an external network.
14. Create a controlled synthetic read-only smoke identity through the normal
    invitation flow, store its credentials outside Git, and run `smoke.sh`.
15. Create a separate controlled staging admin/pilot identity only after smoke
    passes; never use restaurant/customer data.
16. Verify invitation, email verification and password reset through the real
    staging SMTP path.
17. Execute a controlled synthetic CSV/XLSX import and verify worker,
    ingestion, private object access, correlation and scheduler logs.
18. Verify one worker, one scheduler and their five-minute freshness cadence;
    investigate failed jobs before declaring staging healthy.
19. On a controlled device/emulator, build/run with the staging public API
    origin; verify mobile login, scoped read, token revoke/expiry and denied
    mutation without placing a token in a URL.
20. Record image tag, commit, backup ID, DNS target, certificate result,
    smoke/request IDs, mobile check and operator in the release record.

## Failure and rollback status

Existing rollback guidance is sufficient for a first staging launch when the
operator supplies a verified prior tag and backup. It must be followed without
automatic destructive actions:

| Failure | Safe first response |
| --- | --- |
| Image, configuration or readiness failure | Stop release progression, preserve safe logs/request IDs, correct the protected configuration or return to the prior immutable tag only after schema compatibility review. |
| Migration failure | Do not run an automatic down migration. Keep old code only when compatible; otherwise restore a verified backup into an isolated `_restore` database and review the cutback. |
| Worker/scheduler failure | Stop duplicate workers/schedulers, inspect Redis/failed jobs/logs, then restart the single intended service. Do not enqueue duplicate imports as a test. |
| Ingestion failure | Keep it private, inspect token/config/network health, rotate only through a coordinated API/worker/ingestion release, and retry only idempotent documented work. |
| Database or object loss | Contain access, restore database and matching object point into isolation, validate read-only paths, then perform a controlled recovery. Redis is not a recovery source. |
| DNS/TLS failure | Leave the prior healthy edge available, return only approved app/API records to it, wait for TTL propagation, and independently verify application rollback. |

## Provider comparison inputs

Before a future provider decision, compare only evidence for: monthly price,
vCPU/RAM, persistent disk and bandwidth, included/extra IPv4, backup and
snapshot cost, data-center location, Docker/root support, upgrade path,
reliability/support, registry access, firewall controls, and documented ease
of isolated restore. Do not select a provider in this phase.

## Launch checklist

| Item | Status |
| --- | --- |
| Compose topology, private networks, Caddy, health/readiness and lifecycle assets | READY |
| Immutable Dockerfiles including Prompt 23 workspace compatibility | READY |
| Staging/production environment inventories and mobile TTL wiring | READY |
| Backup/restore safeguards and documented off-host policy | READY |
| No tracked runtime secrets, signing keys or deployment credentials | READY |
| Docker-capable isolated staging runtime with required CPU/RAM/disk | NEEDS EXTERNAL ACTION |
| Immutable image registry/retrieval path and staging deployment identity | NEEDS EXTERNAL ACTION |
| Generated separate staging secrets and protected runtime file | NEEDS EXTERNAL ACTION |
| Encrypted off-host database/object backup target and first restore drill | NEEDS EXTERNAL ACTION |
| SMTP account, verified staging sender and necessary DNS records | NEEDS EXTERNAL ACTION |
| Staging app/API DNS records, firewall and externally verified TLS | NEEDS EXTERNAL ACTION |
| GitHub Environment reviewers/deployment credentials and alert routing | NEEDS EXTERNAL ACTION |
| Synthetic smoke/admin/mobile test identities | NEEDS EXTERNAL ACTION |
| External deployment, DNS change, paid account or provider selection | BLOCKED — intentionally outside Launch 01 readiness |

## Decision

The repository is **READY FOR REAL STAGING PROVISIONING**, not for deployment:
its only corrected deployment-asset defects are covered above, while all
runtime, credential, DNS, SMTP, backup and release-approval work remains
external. The next operational milestone is **Launch 02 — provision the
selected isolated staging runtime and execute this checklist through the first
controlled smoke/restore evidence**. Do not start it without explicit approval.
