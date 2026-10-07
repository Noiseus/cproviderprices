# Launch 02A - staging runtime selection and first-provisioning plan

Date: 2026-10-06

Scope: select a low-cost, single-host **staging** runtime and prepare its
operator runbook. This does not authorize a purchase, host provisioning, DNS
change, secret generation, external deployment, production work, or customer
data use. It complements [Launch 01 readiness](staging-readiness.md) and the
canonical [deployment runbook](deployment-runbook.md); it does not alter the
approved topology.

## Verified requirements and acceptance matrix

Compose defines nine long-running services: Caddy, web, API, worker,
scheduler, ingestion, PostgreSQL, Redis, and MinIO. `minio-init` is a one-shot
bucket bootstrap and `migrate` a one-shot release service. Five named volumes
retain PostgreSQL, Redis AOF, MinIO, and Caddy data/config. Caddy alone
publishes host ports 80 and 443; all other service ports remain private.

| Requirement | Classification | Evidence required before selection |
| --- | --- | --- |
| x86_64 Linux; root/equivalent administrator and recovery-console access | Hard | Supported OS image and documented console. ARM waits for multi-architecture image verification. |
| Docker Engine and Compose v2 allowed | Hard | Operator can create Docker networks, volumes, and Caddy; shared web hosting is excluded. |
| 2 vCPU, 4 GiB RAM, 60 GiB persistent SSD/NVMe | Hard minimum | Plan documents durable resources, not temporary local storage. |
| Resize path to at least 8 GiB RAM | Hard | Documented resize path and pricing. |
| Stable public IPv4/equivalent endpoint, firewall, inbound 80/443 and restricted SSH | Hard | No HTTP-01 block and documented firewall/security-group controls. |
| Persistent Docker data root and an external backup path | Hard | Volumes survive normal image replacement and host egress can reach a separately authenticated target. |
| Egress for updates, registry, ACME, SMTP, and backups | Hard | Provider policy and any port restrictions are known. |
| Predictable monthly price and transfer/egress policy | Hard | Base, disk, IPv4, snapshots, and overage costs are known. No numeric transfer quota is invented because none is evidenced. |
| Region near initial North Carolina users | Selection requirement | Prefer US East or similarly nearby documented region; record it. |
| Provider snapshots, IPv6, larger resources, private networking | Nice to have | Snapshots supplement, never replace, tested logical/object backups. |
| Managed data services, Kubernetes, HA/load balancing, multiple nodes, multi-region | Defer | The supplied single-host Compose pilot already meets first-staging needs. |

The Launch 01 floor remains **2 vCPU / 4 GiB / 60 GiB**, plus at least
**20 GiB** of separate initial backup capacity. The preferred first staging
target is **4 vCPU / 8 GiB / 80 GiB SSD/NVMe**. This is a recommendation, not a
silent requirement increase: local Next development compilation peaked at
about 3.1 CPU and 2.23 GiB RAM, while long-running Compose ceilings add to
5.5 vCPU and about 4.375 GiB RAM before host/Docker overhead. Those are not a
simultaneous production working-set measurement, but 4 GiB is only a monitored
pilot minimum, not comfortable headroom. Reassess after 30 days of staging
images, logs, imports, database/object growth, and restore evidence.

## Network, firewall, and persistence layout

```text
Internet
  |
  +-- TCP 80/443 --> Caddy
                         +-- Next.js web :3000       [private application network]
                         +-- Laravel API :8080       [private application/data]
                                  +-- PostgreSQL :5432, Redis :6379
                                  +-- MinIO :9000/9001, ingestion :8001
                                  +-- worker and scheduler (no listener)
```

| Direction | Rule |
| --- | --- |
| Inbound | Allow TCP 80 and 443 from the Internet, to Caddy only. |
| Inbound | Allow TCP 22 only from approved fixed operator/CI administration CIDRs; use the provider console for recovery. |
| Inbound | Deny every other port. Never publish Docker, web, API, PostgreSQL, Redis, MinIO/console, or ingestion. |
| Outbound | Permit package updates, image registry, ACME, SMTP submission, and the backup endpoint. Default-allow pilot egress is acceptable only when granular egress cannot be safely maintained; record destinations and review it later. |

Use `/srv/cproviderprices/release` (`cproviderdeploy`, `0750`) for reviewed
release tooling, `/etc/cproviderprices/staging.env` (`cproviderdeploy`, `0600`)
for the untracked environment file, and `/srv/cproviderprices/backups/staging`
(`cproviderdeploy`, `0700`) only as a short-lived export area. Docker's data
root (normally `/var/lib/docker`) must be on the persistent disk. With
`-p cproviderprices-staging`, retain `_postgres-data`, `_redis-data`,
`_minio-data`, `_caddy-data`, and `_caddy-config` named volumes across image
replacement. Compose already uses Docker `local` log rotation (10 MiB by 5
files). Never run `docker compose down -v` or `docker system prune --volumes`.

## Reference fresh-host runbook

This reviewable reference is for Ubuntu 24.04 LTS x86_64. Another maintained
LTS is acceptable only with verified equivalent OS, SSH, firewall, clock, and
Docker steps. Recheck vendor instructions at execution time. Do not execute
these commands until a host is selected.

1. Patch the base host and set the clock from the provider console.

   ```sh
   sudo apt-get update
   sudo apt-get -y full-upgrade
   sudo apt-get install -y ca-certificates curl gnupg ufw chrony unattended-upgrades
   sudo timedatectl set-timezone UTC
   sudo timedatectl set-ntp true
   timedatectl status
   ```

2. Create separate human-operations and deployment users. Add public keys with
   `sudoedit`; do not create passwords.

   ```sh
   sudo adduser --disabled-password --gecos "" cproviderops
   sudo usermod -aG sudo cproviderops
   sudo adduser --disabled-password --gecos "" cproviderdeploy
   sudo install -d -o cproviderops -g cproviderops -m 0700 /home/cproviderops/.ssh
   sudo install -d -o cproviderdeploy -g cproviderdeploy -m 0700 /home/cproviderdeploy/.ssh
   sudoedit /home/cproviderops/.ssh/authorized_keys
   sudoedit /home/cproviderdeploy/.ssh/authorized_keys
   sudo chown cproviderops:cproviderops /home/cproviderops/.ssh/authorized_keys
   sudo chown cproviderdeploy:cproviderdeploy /home/cproviderdeploy/.ssh/authorized_keys
   sudo chmod 0600 /home/cproviderops/.ssh/authorized_keys /home/cproviderdeploy/.ssh/authorized_keys
   ```

3. Retain the provider console and a second key-authenticated SSH session.
   Only after both accounts can log in, review
   `/etc/ssh/sshd_config.d/99-cproviderprices.conf`:

   ```text
   PermitRootLogin no
   PasswordAuthentication no
   KbdInteractiveAuthentication no
   PubkeyAuthentication yes
   AllowUsers cproviderops cproviderdeploy
   ```

   Validate/reload and test a new key login before closing either session.

   ```sh
   sudo sshd -t
   sudo systemctl reload ssh
   ```

4. Add Docker's signed repository; this deliberately avoids `curl | sh`.

   ```sh
   sudo install -m 0755 -d /etc/apt/keyrings
   sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
   sudo chmod a+r /etc/apt/keyrings/docker.asc
   arch="$(dpkg --print-architecture)"
   . /etc/os-release
   printf '%s\n' "deb [arch=$arch signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $VERSION_CODENAME stable" | sudo tee /etc/apt/sources.list.d/docker.list >/dev/null
   sudo apt-get update
   apt-cache policy docker-ce
   sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
   sudo systemctl enable --now docker
   docker --version
   docker compose version
   ```

5. Docker socket access is root-equivalent. Add only the deployment identity to
   the Docker group; it receives no broad password-based `sudo` grant.

   ```sh
   sudo usermod -aG docker cproviderdeploy
   ```

   Re-authenticate as `cproviderdeploy` before testing `docker info`.

6. Before containers exist, set Docker's default log rotation with
   `sudoedit /etc/docker/daemon.json`:

   ```json
   { "log-driver": "local", "log-opts": { "max-size": "10m", "max-file": "5" } }
   ```

   ```sh
   sudo python3 -m json.tool /etc/docker/daemon.json >/dev/null
   sudo systemctl restart docker
   sudo systemctl is-active docker
   ```

7. Only after console recovery and a second SSH session work, replace
   `ADMIN_CIDR` with the approved fixed network. If it is unknown, stop rather
   than opening SSH to the Internet.

   ```sh
   sudo ufw default deny incoming
   sudo ufw default allow outgoing
   sudo ufw allow from ADMIN_CIDR to any port 22 proto tcp
   sudo ufw allow 80/tcp
   sudo ufw allow 443/tcp
   sudo ufw enable
   sudo ufw status verbose
   ```

8. Create protected paths and inspect capacity before pulls. Repeat the checks
   before releases and after backup/restore exercises.

   ```sh
   sudo install -d -o cproviderdeploy -g cproviderdeploy -m 0750 /srv/cproviderprices/release
   sudo install -d -o cproviderdeploy -g cproviderdeploy -m 0700 /srv/cproviderprices/backups/staging
   sudo install -d -o cproviderdeploy -g cproviderdeploy -m 0700 /etc/cproviderprices
   df -h / /var/lib/docker /srv
   docker system df -v
   docker volume ls
   ```

`unattended-upgrades` needs a maintenance/reboot policy; it does not authorize
unattended releases. Audit host access, firewall/SSH changes, Docker restarts,
and release operators without logging environment values.

## Deployment identity and environment checklist

`cproviderops` is the audited human/break-glass administrator.
`cproviderdeploy` owns the checkout, runtime file, backups, and Compose
operations. For later GitHub automation, use one dedicated SSH key for
`cproviderdeploy`, held only as a protected GitHub staging Environment secret
with named reviewers, plus non-secret `DEPLOY_HOST` and `DEPLOY_USER`. Limit
it to this host and rotate it on offboarding, host replacement, or compromise.
The workflow stays a guarded skeleton until an immutable registry and reviewed
remote command wrapper exist. Do not use a personal password.

Create the real untracked `/etc/cproviderprices/staging.env` from the tracked
example. “Generate locally” means in a secure password/secret manager or
controlled terminal, never this repository or CI output. Every template value
is classified here:

| Source / classification | Variables |
| --- | --- |
| Public hostname/configuration | `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_MAINTENANCE_DRIVER`, `LOG_LEVEL`, `CADDY_IMAGE`, `APP_HOST`, `API_HOST`, `WEB_URL`, `APP_URL`, `FRONTEND_URL`, `CORS_ALLOWED_ORIGINS`, `SESSION_COOKIE`, `SESSION_DOMAIN`, `TRUSTED_HOSTS`, `APPLICATION_NETWORK_SUBNET`, `CADDY_APPLICATION_IP`, `POSTGRES_DB`, `APP_DB_USERNAME`, `DB_SSLMODE`, `REDIS_PREFIX`, `CACHE_PREFIX`, `MINIO_ROOT_USER`, `MINIO_BUCKET`, `MOBILE_ACCESS_TOKEN_TTL_DAYS`. |
| Selected runtime/registry provider or release process; public deployment configuration | `IMAGE_TAG`, `WEB_IMAGE`, `API_IMAGE`, `INGESTION_IMAGE`, `POSTGRES_IMAGE`, `ACME_EMAIL`. Use the validated immutable Git SHA, never `latest`. |
| Generate locally - application secret/credential | `APP_KEY`, `POSTGRES_ADMIN_PASSWORD`, `APP_DB_PASSWORD`, `REDIS_PASSWORD`, `MINIO_ROOT_PASSWORD`, `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `INGESTION_SERVICE_TOKEN`. Use staging-only values. |
| Obtain from SMTP provider | `MAIL_SCHEME`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME`. The password/token is a secret. |
| Create through staging application flow; smoke-only secret/configuration | `SMOKE_EMAIL`, `SMOKE_PASSWORD`, `SMOKE_READONLY_URL`, added only to the protected runtime file. The account is synthetic, read-only, and non-mutating. |

Before use, verify no `REPLACE_...` value remains; the host/cookie/key/database/
prefixes/bucket are staging-only; CORS is not `*`; and the Caddy address
matches `TRUSTED_PROXIES`. Apply mode `0600` and validate without printing it:

```sh
docker compose --env-file /etc/cproviderprices/staging.env \
  -p cproviderprices-staging -f infra/deployment/compose.production.yml config -q
```

## Backup, SMTP, DNS, and first deploy

The backup target is separately authenticated from staging, encrypted in
transit/at rest, and stores daily PostgreSQL
`pg_dump -Fc --no-owner --no-acl` archives plus SHA-256 and a daily private
MinIO object mirror. It needs at least 20 GiB initially, 14 daily points, and
retrieval for an isolated `_restore` database and representative private object.
Keep recovery/encryption material separate; do not back up the runtime file or
deployment keys. Snapshots cannot replace it.

Obtain SMTP host, port, username, password/token, TLS mode, verified FROM
address, and FROM name. Use authenticated TLS submission (normally 587 with
STARTTLS unless the chosen provider says otherwise), a staging sender where
practical, and only required SPF/DKIM/verification DNS records. Mailpit is
development-only.

Do not point DNS at an unvalidated host. Order: server hardening/private health;
external HTTP `--resolve` to the selected IPv4 to test Caddy host routing/redirect
(not TLS); lower TTL to 300 one normal TTL before cutover; create only `A`
records for `app-staging.cproviderprices.com` and
`api-staging.cproviderprices.com` to the same IPv4; confirm authoritative DNS
and public 80/443; let Caddy complete ACME HTTP-01; run HTTPS smoke. Add `AAAA`
only after IPv6 firewall/routing tests.

After all external prerequisites are ready, execute this release order as
`cproviderdeploy`; do not execute it during Launch 02A:

1. Establish immutable image retrieval. `deploy.sh` calls `compose pull`, while
   CI builds but deliberately does not publish images. A paid separate registry
   can wait only if an included registry meets this requirement.
2. Retrieve reviewed release tooling via read-only repository deploy key or
   reviewed archive, pin it to the image commit, and enter the checkout.

   ```sh
   git clone git@github.com:Noiseus/cproviderprices.git /srv/cproviderprices/release
   git -C /srv/cproviderprices/release checkout --detach GIT_SHA
   test "$(git -C /srv/cproviderprices/release rev-parse HEAD)" = "GIT_SHA"
   cd /srv/cproviderprices/release
   ```

3. Complete and validate the protected environment file, pull immutable images,
   start PostgreSQL, then create and copy the initial pre-migration backup
   off-host. Record `BACKUP_ID`.

   ```sh
   docker compose --env-file /etc/cproviderprices/staging.env -p cproviderprices-staging -f infra/deployment/compose.production.yml pull
   docker compose --env-file /etc/cproviderprices/staging.env -p cproviderprices-staging -f infra/deployment/compose.production.yml up -d --wait postgres
   sh infra/deployment/scripts/backup-postgres.sh /etc/cproviderprices/staging.env /srv/cproviderprices/backups/staging
   # Encrypt/copy the emitted archive and .sha256 off-host; record BACKUP_ID.
   ```

4. Start private dependencies, run isolated migrations, and wait for the full
   stack through the supplied release script.

   ```sh
   sh infra/deployment/scripts/deploy.sh staging GIT_SHA --backup-id BACKUP_ID --apply-migrations
   docker compose --env-file /etc/cproviderprices/staging.env -p cproviderprices-staging -f infra/deployment/compose.production.yml ps
   ```

5. After DNS/TLS, run `sh infra/deployment/scripts/smoke.sh
   /etc/cproviderprices/staging.env`; inspect worker/scheduler/ingestion/Caddy
   logs; then validate SMTP, synthetic CSV/XLSX import, private object access,
   one worker/scheduler freshness cadence, and controlled mobile
   login/read/revoke-or-expiry/denied-mutation. Record tag, commit, backup ID,
   DNS target, certificate, smoke result, and operator.
6. On smoke failure, stop and preserve safe logs/request IDs. Return to a prior
   immutable tag only after schema-compatibility review; never delete volumes
   or run automatic down migrations. Use the isolated restore/rollback runbooks
   for an incompatible migration.

| External item | Needed before first staging? |
| --- | --- |
| Compliant Docker server and selected region | Required |
| Immutable image retrieval path | Required |
| SMTP account/sender and DNS verification records | Required |
| Encrypted off-host backup target and restore plan | Required |
| Two staging DNS `A` records after host validation | Required for public HTTPS smoke |
| GitHub Environment credentials/reviewers | Required for automated deployment; a controlled manual deployment may precede it using the same dedicated identity |
| Provider snapshots, IPv6, paid monitoring/log platform | Can wait |
| Managed data services, Kubernetes, HA, load balancer, extra nodes, multi-region | Can wait |

Existing Hostinger Business Web Hosting remains useful for domain ownership,
DNS, email-related records, and a future `www` surface. It is not the runtime
for this Docker stack; do not cancel it, move the domain, or buy a Hostinger VPS
as part of this planning work.

## Stopping point

The repository is ready to **select** a provider with this matrix. It is not
ready to provision or deploy until the owner chooses the server, image path,
SMTP, backup target, DNS change window, and credentials. The exact next action
is to compare candidate plans against the hard requirements and choose a
4 vCPU / 8 GiB / 80 GiB preferred host (or document use of the
2 vCPU / 4 GiB / 60 GiB floor) before creating any external resource.
