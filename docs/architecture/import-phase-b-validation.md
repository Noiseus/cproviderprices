# Phase B completion record

Resumed from `8e63eff` on `feat/11-12-import-ingestion`, following Phase A checkpoint `dbfcfdc`. No commit/push, frontend, Python normalizer, catalog or Prompt 13 work was performed.

## Checkpoint assessment

The checkpoint already contained the Import Center controller/routes, role policy, scoped resource access, private S3 upload/download, CSV/XLSX readers, mapping/template snapshots, validation and normalization-boundary jobs, progress migration, synthetic fixtures, Docker ZIP/upload configuration and 40 test cases. These were an implementation checkpoint, not a verified completion claim. OpenAPI/client coverage, backend operational documentation, real MinIO/Redis evidence, formatting and successful static/test validation remained incomplete.

## Targeted fixes

- Upload `min:1` measured KiB, incorrectly rejecting small valid CSV files. Empty-byte rejection now happens explicitly; maximum size bounds are unchanged.
- Explicit SKU/description validation rules replaced wildcard header rules, admitting nonexistent columns. Both required fields now enforce the detected-header whitelist.
- XLSX accepted an unrelated worksheet root, invalid numeric row text and a workbook pointing to another file. Root, row address and workbook relationship checks now reject these structures. New adversarial tests demonstrated each failure before the fix.
- XLSX legitimately omits empty trailing cells. These are now represented as blank strings, rather than rejecting an otherwise valid row. A failing sparse-cell regression test drove this fix.
- Import list/template pagination now uses the existing shared Pagination contract. Credential inputs are explicitly prohibited.
- The configured `database-uuids` failed-job driver had no `failed_jobs` table. Added its standard operational migration; a real worker exhaustion test verifies safe failure persistence. Unexpected validation exceptions are sanitized before operational failure logging so SQL bindings/private object paths are not retained.
- Added model PHPDoc for existing JSON/date casts to resolve Larastan errors without changing Phase A persistence or lifecycle rules. No Phase A functional defect or architecture deviation was found.
- CI explicitly provides ZIP/XML extensions and testing environment. The Makefile selects the dedicated test database. Import suites fail before refresh when pointed at the wrong database.

## Files changed after the checkpoint

- `.github/workflows/ci.yml`, `Makefile`: required extensions and safer test environment.
- `apps/api/app/Http/Controllers/Api/V1/ImportController.php`: upload, pagination, prohibited-input fixes and formatting.
- `apps/api/app/Services/ImportFileReader.php`, `ImportMapping.php`: targeted reader/mapping fixes.
- `apps/api/app/Jobs/ValidateImportBatch.php`: safe operational errors and formatting.
- `apps/api/app/Jobs/AwaitImportNormalization.php`: formatting only; boundary behavior unchanged.
- `apps/api/app/Models/ImportRun.php`, `RawImportRecord.php`: cast annotations.
- `apps/api/database/migrations/2026_09_25_000011_create_failed_jobs_table.php`: operational failed-job storage.
- `apps/api/routes/api.php`, `routes/console.php`: formatting only; checkpoint endpoints/fixture command retained.
- `apps/api/tests/Feature/ImportCenterTest.php`: fixes/expanded coverage and test-database guard.
- `apps/api/tests/Feature/ImportInfrastructureTest.php`: real private storage, Redis worker and exhaustion coverage.
- `packages/api-contracts/openapi.yaml`: OpenAPI 3.1 Import Center coverage; version 0.10.0.
- `packages/generated-api-client/src/schema.d.ts`: deterministically generated, never manually edited.
- `docs/architecture/import-pipeline.md`, `testing-strategy.md`, this record: behavior, limits, commands, outcomes and continuation.

## Acceptance coverage

All 39 requirements in the continuation request are covered. API behavior and documented format restrictions are described in [the pipeline contract](import-pipeline.md).

| Requirement numbers | Coverage |
| --- | --- |
| 1–2 | Both fixture uploads accepted; different headers mapped; each reaches queued |
| 3–8 | Malformed CSV/XLSX, empty/header-only, MIME/extension, size, traversal, ZIP/XML/formula bounds and sparse cells |
| 9–13 | Private object visibility, anonymous MinIO access denied, no public URL, signed authenticated downloads, expired/tampered signatures, cross-tenant and cross-import file denial |
| 14–18 | Foreign account/location/import, body/query organization forgery, role denial, suspended/revoked access and centralized entitlement denial |
| 19–24 | Headers and 20-row samples, approved distinct mappings, immutable snapshots, structural template reuse, cross-tenant/account template denial |
| 25–30 | Stable row/field errors, valid rows alongside invalid, unchanged raw strings/whitespace/prices/pack text, leading-zero SKU, physical row numbers |
| 31–32 | SHA-256 duplicate identity independent of filename; account-scoped acceptance; replay with no duplicate raw records/errors |
| 33–37 | Legal/illegal transitions, real Redis worker delivery, bounded batches, queued boundary without normalized results, idempotent cancellation and no further canceled work |
| 38–39 | Meaningful audit actions, request correlation, no row content or object-key metadata; failed-job error safety also tested |

CSV fixture result: 3 valid raw rows; missing_description error on source row 5; Start reaches queued. XLSX fixture result: 3 valid raw rows, no validation errors; Start reaches queued. Normalized record count stays zero. XLSX regeneration is byte-identical to the tracked fixture.

## Commands and final results

All Laravel tests below ran with PostgreSQL `cproviderprices_test`. The infrastructure test additionally used real MinIO and Redis, not fakes, with an isolated random Redis prefix and synthetic object cleanup.

```powershell
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=cproviderprices_test api php artisan test --compact --filter=ImportCenterTest
# 50 passed, 371 assertions

docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=cproviderprices_test api php artisan test --compact --filter='ImportPersistenceTest|CustomerConfigurationTest|TenantAuthorizationTest|EntitlementSubscriptionTest'
# 52 passed, 190 assertions

docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=cproviderprices_test api php artisan test --compact --filter='ImportCenterTest|ImportPersistenceTest|CustomerConfigurationTest|TenantAuthorizationTest|EntitlementSubscriptionTest'
# Final combined run: 102 passed, 561 assertions

docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=cproviderprices_test -e RUN_IMPORT_INFRASTRUCTURE=1 api php artisan test --compact --filter=ImportInfrastructureTest
# 1 passed, 49 assertions, including real worker failure exhaustion

docker compose exec -T api vendor/bin/phpstan analyse --no-progress --memory-limit=512M
# No errors

$phaseBPhp = @(git diff --name-only dbfcfdc -- apps/api | Where-Object { $_ -like '*.php' } | ForEach-Object { $_ -replace '^apps/api/', '' })
$phaseBPhp += @('tests/Feature/ImportInfrastructureTest.php', 'database/migrations/2026_09_25_000011_create_failed_jobs_table.php')
docker compose exec -T api vendor/bin/pint --test @phaseBPhp
# 17 files passed (includes checkpoint Phase B PHP files)

npm run contract:lint
# Valid OpenAPI, no errors
npm run generate:api
# Schema types generated successfully
npm run check:api-client
# Generated client matches the contract

docker compose config --quiet
# Exit 0
git diff --check
# Exit 0; only local Git LF/CRLF notices

docker compose build api worker
# Both images built successfully, once during this continuation
docker compose up -d --no-deps api worker
# Both services started using the built images
docker compose exec -T api php artisan migrate --force
# Pending 2026_09_25_000011 migration applied successfully
docker compose exec -T api php artisan app:prepare-auth-e2e --import-fixtures-only
# Successful; SHA-256 before/after regeneration matched
```

The infrastructure suite's migration lifecycle also exercised apply/rollback in the disposable test database; the subsequent combined suite reapplied successfully. PHP runtime inspection confirmed ZIP support, `upload_max_filesize=10M` and `post_max_size=12M`. No full Playwright suite or Python/frontend implementation ran. Remote CI was configured but not executed in this session.

Intermediate failing tests were used to identify the defects listed above; they were not treated as passing validation. Initial invocation without explicit environment overrides returned 40 CSRF failures. After fixing the environment, the small-CSV and header-mapping defects were reproduced and corrected. New adversarial XLSX tests failed before their targeted fixes. An added operational assertion then exposed the missing failed-job table; the final real-worker test passes with the table present.

## Operational incident and remaining boundaries

The initial test invocation mistakenly used the local development database, and `RefreshDatabase` reset it. Prior local development data was not restored; no recoverable backup was established in this session. This is an unresolved operational consequence, not a passing test claim. All subsequent tests were isolated in `cproviderprices_test`. The Makefile, import-suite guards and documentation now prevent that same invocation path from silently targeting development data.

No Phase B implementation blocker remains. Format restrictions and fixed size/row limits are intentional and documented. Redis delivery is at-least-once; API retries recover dispatch interruptions. Production retention/cleanup policy and later Python delivery remain deferred; queued does not imply normalized or completed.

Next authorized task is **Phase C**, on a new explicit request: the deterministic Python parser and its tests under `services/ingestion`, following unit-normalization, canonical-product-model and matching boundaries. Use the preserved mapped strings and explicit source conventions. Laravel service delivery is Phase D; frontend is Phase E. Do not begin Prompt 13.
