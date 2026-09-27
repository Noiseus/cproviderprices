# Testing Strategy

## Quality gates

Repository quality gates are deterministic and runnable locally and in CI:

- **Formatting and linting:** Prettier and ESLint for TypeScript, Pint for PHP, and Ruff for Python.
- **Static analysis:** strict TypeScript, Larastan/PHPStan for Laravel, and mypy for Python.
- **Unit tests:** Vitest for web/shared TypeScript, PHPUnit for Laravel, and pytest for ingestion.
- **Contract validation:** Redocly validates OpenAPI 3.1 and generated-client drift detection verifies reproducibility.
- **Smoke tests:** Playwright's HTTP client verifies the running web, API health/readiness, ingestion health, and Mailpit UI.

## Test boundaries

- **Unit tests** cover isolated behavior without network services.
- **Integration tests** cover framework behavior and real adapters where their dependencies matter.
- **Policy and authorization tests** prove server-side access decisions and tenant boundaries independently of UI behavior. The PostgreSQL feature suite includes reusable tenant setup plus adversarial UUID, direct-ID, role, lifecycle, platform-scope, and audit checks.
- **Contract tests** verify public API shapes and deterministic generation without inventing domain behavior.
- **End-to-end tests** prove that composed services can start and communicate. Initial smoke coverage stays intentionally small and does not replace feature-level tests.
- **Authentication end-to-end tests** generate short-lived credentials and invitation tokens into ignored private storage at runtime. CI never prints those values; Playwright removes the fixture after the flow.

## Local execution

Docker is the canonical runtime. From an initialized checkout, run:

Create a dedicated local PostgreSQL test database once with `docker compose exec -T postgres createdb -U cproviderprices cproviderprices_test`. Laravel test commands must start with `APP_ENV=testing` and `DB_DATABASE=cproviderprices_test`; `RefreshDatabase` resets its target database. Never point test commands at the development database. `make test` supplies these overrides. For direct PowerShell invocation, use `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=cproviderprices_test api php artisan test`. See [Import Center validation](import-pipeline.md#phase-b-validation-and-continuation) for the opt-in real MinIO/Redis test.

```shell
make lint
make typecheck
make test
make contract
make e2e
```

On Windows without GNU Make, use the equivalent `docker compose run --rm ...` commands in the root `Makefile`. The PowerShell setup command and Compose remain the supported bootstrap path.

Tests must not depend on execution order, committed secrets, or pre-existing business data. Add the smallest test at the lowest boundary that proves a behavior, then use broader integration or end-to-end coverage only where service interaction is material.

Phase D normalization: run `ImportNormalizationTest` under the explicit test environment/database above. For real MinIO/Redis/Laravel-to-Python/PostgreSQL validation, run `powershell -NoProfile -ExecutionPolicy Bypass -File infra/scripts/test-import-normalization.ps1` with dependencies running. It supplies a process-only shared token to an isolated ingestion container on the private Compose network, runs `ImportInfrastructureTest` against the disposable test database, and cleans up its own temporary resources. See [Phase D](import-phase-d-validation.md) for bounds and operational caveats.
