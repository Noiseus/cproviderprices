# Deployment Context

## Current facts

- Repository: private GitHub repository `Noiseus/cproviderprices`.
- Primary domain: `cproviderprices.com`.
- Current hosting provider and plan: Hostinger Business Web Hosting.
- Hostinger supports Web Apps and GitHub deployment, but deployment for this project is **not configured yet**.

## Intended domain direction

- `www.cproviderprices.com`: public/marketing website.
- `app.cproviderprices.com`: authenticated customer web application.
- `api.cproviderprices.com`: production API.
- `staging.cproviderprices.com`: staging web application.
- `api-staging.cproviderprices.com`: staging API.

This is a direction, not a claim that DNS, certificates, applications, or deployments already exist. Production and staging configuration, credentials, data, and access must remain separated.

## Decision boundary

Deployment architecture, CI/CD, environment topology, runtime availability, and Hostinger compatibility will be verified and finalized in a later deployment prompt. Do not introduce temporary application implementations, collapse approved service boundaries, or add architectural hacks solely to satisfy a Hostinger onboarding wizard. If verified hosting constraints require a durable architectural change, evaluate alternatives and record the decision in an ADR.
