# DNS and domain cutover checklist

The domain stays registered at Hostinger. No transfer is required: hPanel can
continue to manage DNS while records point to the selected Docker-capable
runtime. Do not change nameservers or records until the staging checks below
are complete.

## Suggested records

| Hostname | Purpose | Record before cutover | Target |
| --- | --- | --- | --- |
| `www.cproviderprices.com` | Future public/marketing site or temporary redirect | `A`/`AAAA` for a fixed runtime IP, or provider `CNAME` | Public-site/edge target |
| `app.cproviderprices.com` | Authenticated production web | `A`/`AAAA` | Production Caddy edge |
| `api.cproviderprices.com` | Production Laravel API | `A`/`AAAA` | Same production Caddy edge |
| `app-staging.cproviderprices.com` | Authenticated staging web | `A`/`AAAA` | Staging Caddy edge |
| `api-staging.cproviderprices.com` | Staging Laravel API | `A`/`AAAA` | Staging Caddy edge |

Use `A` for IPv4 and `AAAA` only after IPv6 firewall/routing is tested. Use a
`CNAME` only when the selected runtime/provider gives a stable hostname and
does not require an apex record. Do not create public records for PostgreSQL,
Redis, MinIO, ingestion, Mailpit, or Docker service names.

## Before and during cutover

1. Lower the existing relevant TTL to 300 seconds at least one normal TTL
   period before a planned cutover. Do not change unrelated MX/TXT records.
2. Verify the runtime firewall permits only TCP 80/443 and SSH from approved
   administration sources. Confirm Caddy can obtain a certificate on staging.
3. Populate the runtime's protected environment file and start the staging
   stack. Run the post-deploy smoke script over HTTPS.
4. Check `dig` or `nslookup` against authoritative DNS, certificate hostnames,
   HTTPS redirect, `app` health, and `api` health/readiness from an external
   network.
5. Create production records only after explicit production approval. Keep the
   prior endpoint and its configuration available through the rollback window.
6. Raise the TTL after stability is established. Record the old and new target,
   change time, certificate status, deploy image tag, and operator in the
   release record.

## Rollback

If the new edge cannot serve TLS or smoke checks fail, restore the previous
known-good A/AAAA/CNAME target and leave the previous runtime running. DNS
rollback is not a substitute for image/configuration rollback; follow
[rollback.md](rollback.md) as well. Wait for the prior TTL plus propagation,
then verify the former endpoint over HTTPS.

There is no meaningful public `www` marketing application in the repository
today. Reserve the host or configure a simple edge redirect only after a public
site is approved; Prompt 21 does not create marketing content.
