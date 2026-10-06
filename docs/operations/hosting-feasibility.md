# Hosting feasibility and pilot topology

## Evidence and scope

The existing subscription is **Hostinger Business Web Hosting**, not Hostinger
VPS. This assessment does not infer capabilities from its name. Hostinger's
current support articles state that Node.js requires root access and is not
available on Web/WordPress/Cloud hosting; PostgreSQL, Redis, and Python are
also directed to VPS because the necessary resources, permissions, or runtime
control are unavailable on Web/Cloud plans. Hostinger lists Business Web
Hosting at 2 CPU cores and 1.5 GiB RAM, which is not a safe envelope for this
multi-process stack.

Sources reviewed on 2026-10-05:

- [Node.js support](https://support.hostinger.com/en/articles/1583661-is-node-js-supported-at-hostinger)
- [PostgreSQL support](https://support.hostinger.com/en/articles/1583659-is-postgresql-supported-at-hostinger)
- [Redis support](https://support.hostinger.com/en/articles/9581774-is-redis-supported-at-hostinger)
- [Python support](https://support.hostinger.com/en/articles/3648030-is-python-supported-at-hostinger)
- [current Web Hosting limits](https://support.hostinger.com/en/articles/6976044-parameters-and-limits-of-hosting-plans)

Provider documentation can change. Before purchasing or changing plans, the
operator should recheck these links and the specific account's hPanel limits.

## Component assessment

| Component | Existing Business Web Hosting | Reason / verification needed |
| --- | --- | --- |
| Next.js runtime | Unsuitable | Hostinger states Node.js needs root/VPS. A static export is not applicable to this authenticated SSR app. |
| Laravel request runtime | Conditionally suitable alone | Shared PHP may run Laravel, but cannot safely supply this app's complete Redis/PostgreSQL/worker/object-storage topology. |
| Long-running queue worker | Unknown / requires provider verification | Shared hosting cron does not establish supervised long-running processes or graceful restart behavior. |
| Scheduler | Conditionally suitable | hPanel supports cron, but this scheduler depends on the private Redis-backed application stack and must not be split without an operational design. |
| PostgreSQL | Unsuitable | Hostinger directs PostgreSQL use on Web/Cloud plans to VPS. |
| Redis | Unsuitable | Hostinger directs Redis use on Web/Cloud plans to VPS. |
| Python ingestion HTTP service | Unsuitable | Hostinger states Python projects/libraries need VPS/root. |
| Docker / Compose | Unknown / effectively unsuitable | The required root-level container, network, volume, and reverse-proxy control is not established for Business Web Hosting. |
| Durable private S3-compatible storage | Unknown | Shared file storage is not evidence of private S3 semantics, lifecycle, app credentials, or durable import replay storage. |
| Private internal networks / Caddy | Unsuitable for this topology | The plan does not provide the required isolated Docker networks and edge control. |
| DNS, domain registration, email, future `www` static site | Suitable | Keep the domain registered and DNS-managed at Hostinger; these do not require moving the domain. |

## Lowest-cost technically sound pilot

Use the already-paid Hostinger account for domain/DNS management, optional
email, and a future public `www` site. Run the application on Docker-capable
Linux runtime(s) with SSH, persistent volumes, firewall control, and ports
80/443. This can be a Hostinger VPS or another provider; Prompt 21 does not
purchase or select one.

The recommended minimum is one production runtime plus one smaller isolated
staging runtime. This keeps database, Redis, MinIO buckets, session keys,
ingestion tokens, mail credentials, and blast radius separate. The supplied
Compose file uses a fixed private proxy address, so its staging and production
instances are designed for separate hosts. Do not co-locate them merely to save
cost without redesigning the shared TLS edge/network boundary and reviewing the
resulting blast radius.

For the initial pilot, self-hosted PostgreSQL, Redis, and MinIO on each
runtime are acceptable only with persistent volumes, host patching, encrypted
off-server backups, and restore tests. Managed PostgreSQL/Redis/S3-compatible
services are an optional later upgrade when operational burden, storage
durability, or availability needs justify it.

## Resource envelope

These are starting points, not load-test capacity claims. The local measured
stack recorded Next development compilation peaks around 3.1 CPU and 2.23 GiB
in the systemic Playwright investigation; service-level product timing data is
recorded in [performance results](../architecture/performance-results.md).

| Environment | vCPU | RAM | fast persistent disk | backup storage |
| --- | ---: | ---: | ---: | ---: |
| Minimum staging | 2 | 4 GiB | 60 GiB | 20 GiB separate location |
| Minimum pilot production | 4 | 8 GiB | 120 GiB | at least 120 GiB separate location |
| Comfortable pilot production | 4 | 16 GiB | 200 GiB | at least 200 GiB separate location |

The disk allocation includes PostgreSQL, private source imports, container
images, logs, and room for a restore. Revisit it after the first 30 days of
actual import volume. CPU/RAM limits in the Compose file prevent one process
from taking the whole host; they are not a substitute for host monitoring.
