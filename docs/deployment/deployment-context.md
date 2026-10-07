# Deployment Context

## Current facts

- Repository: private GitHub repository `Noiseus/cproviderprices`.
- Primary domain: `cproviderprices.com`.
- Current hosting provider and plan: Hostinger Business Web Hosting.
- Hostinger supports Web Apps and GitHub deployment, but its existing Business
  Web Hosting plan is not sufficient for this repository's Node.js,
  PostgreSQL, Redis and Python runtime requirements. Prompt 21 provides a
  provider-neutral Docker deployment path; no external runtime is configured
  or deployed yet.

## Intended domain direction

- `www.cproviderprices.com`: public/marketing website.
- `app.cproviderprices.com`: authenticated customer web application.
- `api.cproviderprices.com`: production API.
- `app-staging.cproviderprices.com`: staging web application.
- `api-staging.cproviderprices.com`: staging API.

This is a direction, not a claim that DNS, certificates, applications, or deployments already exist. Production and staging configuration, credentials, data, and access must remain separated.

## Decision boundary

Prompt 21 records the approved pilot direction in
[operations documentation](../operations/deployment-runbook.md): keep the
domain at Hostinger if desired, but run staging and production on isolated
Docker-capable runtimes. Do not introduce temporary application
implementations, collapse approved service boundaries, or add architectural
hacks solely to satisfy a hosting onboarding wizard. If a later verified
constraint requires a durable architectural change, evaluate alternatives and
record the decision in an ADR.
