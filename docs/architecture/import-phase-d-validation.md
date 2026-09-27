# Phase D delivery contract and validation

Continues `993faa0` on `feat/11-12-import-ingestion`. Phase A persistence and Phase C parser/contract are unchanged. No migration, dependency, frontend, catalog, matching, commit or push is included.

## Delivery and bounds

`AwaitImportNormalization` remains the compatible Phase B queue entry. It checks persisted organization/account/location/file ancestry, transitions queued to processing through the lifecycle service, and selects remaining raw IDs in source-row order. `NormalizeImportBatch` carries only organization/run and at most 25 raw UUIDs. Every attempt reloads ancestry/state and excludes permanently failed rows and results already present for **1.0.0**. Other parser versions remain immutable and distinguishable. There is no public reprocessing operation for terminal imports.

The configurable default/hard Laravel ceiling is 25 rows, below Python's 100. Selection also budgets 900,000 serialized bytes including per-record overhead; the client enforces the exact 1 MiB request ceiling. No whole-import materialization occurs. Mapped strings stay unchanged, with explicit nullable defaults and source conventions. The adapter converts only Phase B's accepted boolean forms (true/false, 0/1, "0"/"1") into Python booleans without rewriting stored evidence.

Laravel's HTTP facade sends one POST to the configured private ingestion origin with bearer auth, JSON, and the original run request ID in header/body. Local default: `http://ingestion:8001`. API/worker and ingestion require the same privately provisioned `INGESTION_SERVICE_TOKEN`; blank fails closed. Redirects are disabled. Connection timeout is 3 seconds; total timeout defaults to 20 seconds, clamped to 1–30. Production requires private ingress and encrypted transport. There is no HTTP retry loop.

Before persistence the client validates version, counts, echoed header/body correlation, unique expected raw IDs, exact typed originals, nested measurement/pack/warning shape and confidence. Untrusted HTTP bodies and exception chains never enter failure messages. Full typed results, originals and trace IDs remain private import output, not public persistence-model serialization.

## Failures and retries

Connection/timeout failures and HTTP 429/502/503/504 are transient. Authentication/configuration, malformed local input, unexpected statuses (including 500), and invalid success responses are permanent. Fixed-ID deliveries get three queue attempts and the established 5/15/30-second backoff schedule; ordinarily only the first two delays are used. `retry_count` increments under the run lock for each transient service failure eligible for another attempt, excluding the final exhausted attempt. It measures observed retry-eligible service attempts, not exactly-once delivery. Unexpected persistence failures are sanitized and use the bounded queue policy but do not count as service retries.

Permanent/exhausted delivery errors are deduplicated per raw/version in `import_errors`, with physical source row, safe code and a message referencing the run request ID. Python rejects a structurally invalid batch atomically, so a rejected delivery fails its unresolved rows while earlier successful batches remain intact. The existing failed-job table holds ID-only payloads and sanitized exceptions. A dispatcher/broker failure leaves a nonterminal run resumable rather than inventing failed row outcomes.

## Idempotency, cancellation and resume

One transaction holds the run lock for each bounded HTTP/persistence operation, deliberately serializing duplicates and cancellation as validation already does. Cancellation may wait for the in-flight operation (HTTP timeout plus database work) and takes effect at the next batch boundary. It cannot reverse a terminal transition. After cancellation, handoff, delivery, retries and failed callbacks perform no further work; all history remains. The 60-second job timeout is below Redis's 90-second reservation interval. Compose consumes `default,import-normalization`.

Validated successes use the existing unique raw/parser key and immutable normalized model. `normalized_values` retains the complete typed result/original/correlation; parser version, warnings and confidence also populate their existing columns. Raw evidence never changes; only status advances. Warning-bearing and low-confidence outputs are successes, not import errors.

Start accepts queued or processing redelivery under the same tenant/role/entitlement checks. This recovers interruptions, including a database commit followed by failed broker dispatch. There is no exactly-once DB/broker guarantee, lease table, schema migration, evidence rebuild or terminal restart. Operators must investigate failed dispatcher jobs and resume nonterminal imports through the authorized Start route.

## Progress and observability

Counts are recomputed from persisted outcomes at batch boundaries: successful current-version rows, warning-bearing rows, and distinct failed source rows across validation and normalization. OpenAPI **0.11.0** and its generated client add `normalization_failed_count`, `remaining_count` and `retry_count`. `valid_rows` is the eligible raw total. Remaining = eligible − current-version successes − permanently failed raw rows. Validation failures contribute to total failed count but never subtract from eligible raw rows.

Zero remaining yields completed, partially_completed or failed via the existing lifecycle service; canceled stays canceled. Dispatch alone cannot complete an import. Aggregate progress/retry logs contain counts, safe codes and trace IDs, not rows, filenames, object keys, responses, tokens or credentials. Errors retain source rows and resolve ancestry/correlation through the run.

## Verification

All Laravel tests use explicit `APP_ENV=testing` and `DB_DATABASE=cproviderprices_test`. Import test guards run before database refresh. Infrastructure tests use a random Redis prefix and synthetic private objects; no shared Redis flush or development-database migration is used.

The real-network runner is:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File infra/scripts/test-import-normalization.ps1
```

API/PostgreSQL/Redis/MinIO must already be running and the dedicated test database must exist. The runner launches the unchanged ingestion image/source as an isolated service on the Compose network, generates a random runtime-only shared credential, and overrides the test API process's private service origin. It never writes a token or modifies `.env`. Finally it stops/removes its own container; the normal ingestion service remains untouched and fail-closed without separately provisioned credentials.

The real path verifies private MinIO access, Redis worker delivery, HTTP authentication, PostgreSQL versioned results, correlation, cancellation, exact mozzarella case total **9071.8474 g**, and safe actual three-attempt failure persistence. Python parser and internal API source/contract remain unchanged, so no parser-suite rerun is required by this change; internal contract drift was nevertheless checked successfully.

### Files changed

- Laravel: `app/Jobs/AwaitImportNormalization.php`, new `app/Jobs/NormalizeImportBatch.php`, new `app/Services/ImportNormalizer.php`, new `app/Services/ImportNormalizationProgress.php`, new `app/Support/NormalizationFailure.php`, `app/Models/NormalizedImportRecord.php` (cast annotations), `app/Http/Controllers/Api/V1/ImportController.php`, `config/imports.php`, `config/services.php`, `.env.example` (all under `apps/api`).
- Tests: `apps/api/tests/Feature/ImportNormalizationTest.php`, `ImportCenterTest.php`, `ImportInfrastructureTest.php`, and `apps/api/tests/Concerns/BuildsNormalizationImport.php`.
- Contracts: `packages/api-contracts/openapi.yaml`, generated `packages/generated-api-client/src/schema.d.ts`.
- Operations: `docker-compose.yml`, `infra/scripts/test-import-normalization.ps1`.
- Documentation: this record, `docs/architecture/import-pipeline.md`, `docs/architecture/testing-strategy.md`, `docs/security/credential-handling.md`.

### Commands and results

- `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=cproviderprices_test api php artisan test --compact --filter=ImportNormalizationTest`: **44 passed, 321 assertions**. Includes 5 transient variants, 8 permanent HTTP variants, 13 malformed-response variants, successful/replayed/versioned output, byte/row bounds, retry exhaustion/recovery, cancellation/history, resume, progress, tenant attacks and log/payload safety.
- Final combined `--filter='ImportNormalizationTest|ImportCenterTest|ImportPersistenceTest|CustomerConfigurationTest|TenantAuthorizationTest|EntitlementSubscriptionTest'` with the same explicit environment/database: **146 passed, 887 assertions**. This includes Phase B import, Phase A persistence, customer configuration, tenant authorization and entitlement regressions.
- `powershell -NoProfile -ExecutionPolicy Bypass -File infra/scripts/test-import-normalization.ps1`: **2 passed, 77 assertions**, actual isolated Docker-network integration plus real Redis three-attempt exhaustion. Run twice successfully after correcting the startup readiness poll. Temporary containers were removed by the runner.
- Pint `--test` on all 13 changed PHP files: passed. Final client-only recheck after URL-validation hardening also passed.
- `docker compose exec -T api vendor/bin/phpstan analyse --no-progress --memory-limit=512M`: no errors.
- `npm run generate:api`, `npm run contract:lint`, `npm run check:api-client`: generated successfully, valid OpenAPI and no generated-client drift.
- `docker compose exec -T ingestion python -m cproviderprices_ingestion.export_contract --check`: internal contract matches unchanged typed models.
- `docker compose config --quiet`: exit 0. `docker compose top worker` confirms the live process consumes both queues with timeout 60. The normal normalization queue was checked empty before worker recreation. `curl` from API to `http://ingestion:8001/health` returned status ok; Compose reports ingestion healthy.
- No full Playwright, unrelated Python suite, frontend work, development-database migration or commit/push was performed.
- `git diff --check`: exit 0; only local Git LF/CRLF notices. No temporary Phase D ingestion containers remain.

Intermediate failures were not counted as passes: the initial mixed-outcome test retained its earlier HTTP fake (corrected to route responses by raw ID), PHPStan findings were resolved without suppressions, and Windows PowerShell's native-stderr readiness behavior was corrected before integration passed. No Phase A schema incompatibility or Phase C behavior change was required.

## Remaining boundaries and continuation

All Phase D acceptance criteria are satisfied by the implementation and checks above. No schema or Phase A–C compatibility blocker was found; no earlier phase was restarted.

Local normal-service use requires provisioning the shared token in both ignored environment files and recreating API/worker/ingestion; the isolated test intentionally does not persist credentials. Production secret rotation, private encrypted networking and retention remain deployment responsibilities. Cancellation is a batch-boundary operation, not an interrupt of an HTTP request already in flight. Retries are bounded per queued delivery, while explicit authorized resume can redeliver nonterminal work.

Stop here after Phase D validation. On explicit request, Phase E starts with Import Center frontend using existing upload/preview, confirmed mapping/templates, asynchronous validation, Start/resume, progress/error summary, cancellation and authorized downloads from the generated contract. Do not implement Prompt 13 or create vendor/catalog/matching records.
