# Deployment secret inventory

Generate all values outside Git and store staging and production separately in
the host's protected runtime environment file or an approved secret manager.
Never put real values in `infra/deployment/env/*.example`, GitHub logs, Docker
image layers, browser variables, or `NEXT_PUBLIC_*` variables.

| Secret / value | Purpose and component | Generation / source | Rotation expectation | Restart required |
| --- | --- | --- | --- | --- |
| `APP_KEY` | Laravel encryption, signed state; API, worker, scheduler | `php artisan key:generate --show` in a secure terminal | On compromise through an incident plan; retain `APP_PREVIOUS_KEYS` only under reviewed migration | API, worker, scheduler |
| `POSTGRES_ADMIN_PASSWORD` | PostgreSQL administrative backup/restore access | Password manager generator | At setup and after suspected host/admin compromise | PostgreSQL and admin clients |
| `APP_DB_PASSWORD` | Non-superuser Laravel database role | Password manager generator | At setup and after app/DB credential exposure | API, worker, scheduler |
| `REDIS_PASSWORD` | Private queue/cache/rate-limit access | Password manager generator | At setup and after service/host exposure | Redis plus API, worker, scheduler |
| `MINIO_ROOT_PASSWORD` | MinIO administration and bucket bootstrap | Password manager generator | At setup and after operator compromise | MinIO and `minio-init` |
| `AWS_SECRET_ACCESS_KEY` | Least-privilege app access to its private S3/MinIO bucket | Create as environment-specific MinIO/S3 application credential | At setup, periodically, and after storage exposure | API, worker, scheduler |
| `INGESTION_SERVICE_TOKEN` | Laravel/worker to private Python service bearer boundary | `openssl rand -hex 32` or password-manager generator | At setup, routinely, and after network/service compromise | API, worker, scheduler, ingestion together |
| `MAIL_PASSWORD` | SMTP authenticated delivery | Selected mail provider | Per provider policy and after compromise | API, worker, scheduler |
| `DEPLOY_SSH_PRIVATE_KEY` / registry credential | CI runner or operator retrieves immutable images/releases | Deploy-key or workload identity, limited to one runtime/registry | At offboarding, host rebuild, or compromise | CI/deployer only |
| `SMOKE_EMAIL` / `SMOKE_PASSWORD` | Dedicated, read-only staging/production smoke account | Create through approved invitation flow; store only in deployment secret store | Per release cycle or on exposure | No; rotates the account session/password |

`SESSION_COOKIE`, `CACHE_PREFIX`, `REDIS_PREFIX`, bucket names, and image tags
are not secret, but must be distinct by environment. `SESSION_DOMAIN`, trusted
hosts, origins, proxy addresses, and `MOBILE_ACCESS_TOKEN_TTL_DAYS` are
security-sensitive configuration, not credentials; review them with the same
release change control. `EXPO_PUBLIC_API_URL` is public build configuration
only and may contain only the HTTPS API origin.

Rotation sequence for the ingestion token is coordinated: deploy API/worker
with support for the new value only once the Python service receives the same
new value in the same controlled release window. The current single-token
boundary intentionally fails closed rather than accepting an unbounded overlap.
