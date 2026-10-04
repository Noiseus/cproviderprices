# Import pipeline implementation contract

Prompts 11/12 reuse Laravel for tenant authorization/persistence, Redis jobs, the private S3 disk, and the internal Python service. The approved pipeline is upload, bounded preview, confirmed mapping, queued raw extraction, bounded normalization, and terminal summary. No catalog/matching behavior belongs here.

## Phase A: persistence foundation

One run owns one immutable uploaded file. `import_runs` binds organization, location, supplier account, source SHA-256, creator, correlation ID, mapping snapshot, counters, and typed status. A unique `(organization_id, supplier_account_id, fingerprint)` rejects duplicate bytes in the same account (the account fixes location), regardless of filename or status. An explicit future reprocessing operation may add a parser-version result to existing raw rows; another upload must not circumvent duplicate protection.

`import_files` keeps private object metadata; `import_mapping_templates` holds account-scoped reusable field mappings and source conventions. Templates are snapshotted onto runs; editing a template must not change historical runs. The run/template composite foreign key enforces account and organization scope.

`raw_import_records` preserves JSON source values, mapped values, file/run identity and physical source row. Unique file/row identity makes retries idempotent. `normalized_import_records` links one raw record and parser version to output, warnings and extraction confidence; its unique key permits an additional version without overwriting earlier results. `import_errors` stores safe code/field/message plus optional row and a stable per-run error key for deduplication. No row contents belong in error messages.

Composite foreign keys enforce organization/account/location and run/file/raw ancestry, even if application code misuses an identifier. Restrictive deletion preserves history. UUIDv7 and timezone-aware timestamps follow the access model. JSON values are private internal persistence, never implicit API responses.

## Lifecycle and later phases

Allowed transitions: uploaded -> validating -> needs_mapping -> queued -> processing -> completed/partially_completed/failed. Any nonterminal state may fail or cancel. Completed, partially_completed, failed and canceled are terminal. Validation of mapped rows occurs in bounded work; invalid rows are recorded while good rows remain processable. No generic workflow engine is introduced. The transition service serializes changes on the run row and checks the authoritative previous state; future HTTP handlers must first authorize tenant, role, lifecycle, and entitlement.

Phases B-F must implement policies/routes, private upload and download, bounded file readers, queue orchestration, service authentication, the deterministic Python parser, typed internal and customer contracts, frontend mapping/progress, and two-file E2E validation. Phase A alone exposes no import endpoint or processing job. Proposed initial bounds for those phases: 10 MiB upload, 20 sample rows, 100 columns, 100 records per normalization batch, 1 MiB internal request, and three attempts with backoff. Enforce expanded XLSX limits and reject formulas/external references instead of evaluating them.

Prompt 13 adds a separate `catalog-matching` queue handoff after a normalized
record is persisted. It projects one tenant-private vendor item and generates
bounded, idempotent candidates using immutable normalization evidence. It is not
part of request-time upload/normalization, does not modify raw/normalized rows,
and does not publish uncertain candidate mappings.

Phase A validation: the PostgreSQL persistence suite covers tenant/account/location/file/raw ancestry, source-row and parser-version replay keys, mapping snapshots, lifecycle rejection/cancellation, model evidence immutability, and invalid database values. Model immutability follows the existing audit-model convention; trusted direct query-builder writes can bypass model hooks and must never be used to overwrite evidence. No import HTTP policy, queue retry/resume, object access, or parser behavior is claimed by these model tests.

Phase B now implements tenant-scoped import policies and API contracts, private CSV/XLSX upload, bounded detection, mapping/template confirmation, validation, and authenticated downloads. Lifecycle mutation remains behind the transition service. The fixture command supports `--import-fixtures-only` without generating users. The full combined Prompt 11/12 acceptance matrix remains outstanding until the later phases pass.

Owners and buyers perform purchasing imports; viewers have no mutation permission. All operations use route TenantContext and centralized `manual_imports_enabled`. Downloads recheck tenant membership on signed application routes. Python receives mapped records plus opaque trace identifiers, not database access or storage credentials. See the [credential contract](../security/credential-handling.md) and [unit contract](../data/unit-normalization.md).

## Phase B: backend contract

All routes are under `/api/v1/organizations/{organization}`. They reuse session/CSRF, active-user and tenant middleware, policies, the centralized entitlement service, error envelope and request IDs. Existing middleware checks organization status before entering the controller; no alternate tenant-resolution path was added. Owners/buyers can upload, map, validate, start and cancel. Active viewers can list/read and download. Historical reads and cancellation remain available after the feature is disabled; upload, mapping, validation and Start require subscription access plus `manual_imports_enabled`. Body/query `organization_id` and credential fields are prohibited. Account/location, run, file and template lookups are scoped before use, backed by Phase A composite constraints.

| Route | Behavior |
| --- | --- |
| GET /imports | Tenant list with shared `page/per_page/total/total_pages` pagination; default 20, maximum 100 |
| POST /imports | Multipart file, restaurant_location_id, supplier_account_id; private upload plus bounded preview |
| GET /imports/{import} | Status, mapping snapshot, file headers, at most 20 sample rows and first 100 errors |
| PUT /imports/{import}/mapping | Confirm/update mapping, optionally save/update template_name, or select template_id |
| GET /import-mapping-templates | Required supplier_account_id; 20 account-scoped templates per page |
| POST /imports/{import}/validate | Asynchronous mapped-row validation; retry is safe |
| POST /imports/{import}/start | Require finished validation and at least one valid row, then queue normalization handoff |
| POST /imports/{import}/cancel | Locked lifecycle cancellation; canceled replay is a no-op |
| POST /imports/{import}/downloads | Issue relative file/error download links expiring in five minutes |
| GET /imports/{import}/files/{file}/download | Current authenticated tenant/ancestry authorization plus valid signature |
| GET /imports/{import}/errors/download | Same authorization; safe streamed CSV with formula neutralization |

### File bounds and evidence

Uploads use the existing private `s3` disk (MinIO locally), random server-generated keys, safe original-filename metadata, server-detected MIME and extension checks, and SHA-256 over original bytes. `IMPORT_MAX_KIB` may lower the 10 MiB hard ceiling; PHP admits 10 MiB files and 12 MiB multipart requests via `infra/docker/php-imports.ini`. Invalid filenames cannot select paths. No public URL or object key is exposed. Download responses are attachments with `private, no-store` and `nosniff`; original bytes remain private even after failure/cancellation. Retention/deletion remains deferred.

CSV supports UTF-8 (optional BOM), comma/semicolon/tab delimiter detection, quoted fields and escaped quotes. Multiline quoted records are explicitly unsupported so source row numbers remain physical lines. Headers must be nonempty/unique. XLSX supports a single standard `xl/worksheets/sheet1.xml` linked by workbook metadata. It reads literal string/numeric cell lexemes without evaluating formulas, formatting numeric SKUs, or normalizing units. Omitted XLSX cells are preserved as blank strings, including optional trailing columns. Export SKUs as text when leading zeroes matter. Formulas, external references, macros, DTDs/entities, malformed XML, invalid row/cell addresses, ambiguous multiple worksheets and unsafe ZIP paths are rejected.

Limits: 100 ZIP entries; 8 MiB expanded per entry, 32 MiB total; 64 KiB per metadata XML, 1 MiB shared-string XML/10,000 strings, 8 MiB worksheet XML; 100 columns, 4,096 bytes per cell, 64 KiB per row, 10,000 data rows. Inspection parses at most 20 data rows, not the entire worksheet. ZIP metadata and bounded shared strings are read before preview; expanded worksheet data is bounded in memory. Late structural defects are caught during asynchronous validation and fail the run safely. Bounds are not relaxed for fixtures.

### Mapping, validation and retry

Mappings select exact source headers into supplier_sku, description, brand, pack_size_text, quantity, weight, volume, unit, price, currency, observed_at, availability and gtin. SKU/description are required, columns cannot be selected twice, and all values remain strings. `observed_at` is an explicit source-observed timestamp for a later immutable price observation; it is never replaced with processing time. Availability remains source evidence and unrecognized values stay unknown. Source conventions are explicitly confirmed (`us_liquid`, produce-size-code, pound-sign weight), never inferred. Account-scoped templates require identical ordered headers; names/filenames do not establish format identity. The run gets a version-1 snapshot. Once validation starts, that snapshot is locked even if the template changes.

The additive Phase B progress migration supplies `validation_cursor`, `validation_started_at` and `validation_completed_at`. It fills the need for resumable mapped validation while retaining the approved Phase A lifecycle. Upload traverses uploaded -> validating -> needs_mapping after preview. Mapped validation stays needs_mapping and is distinguished by progress timestamps; explicit Start alone transitions to queued. This is not a Phase A schema redesign.

`ValidateImportBatch` uses Redis and the default worker. Each delivery authorizes persisted organization/account/location/run/file ancestry, locks the run, rechecks cancellation, verifies stored bytes/size/fingerprint, and persists at most 100 rows. Source prefix rescans are bounded by the 10,000-row/file ceilings. Temporary worker files are removed in `finally`; the S3 object remains authoritative. Valid rows retain all source cells, physical row, mapped strings and ancestry through the run snapshot. Invalid rows retain original evidence in the file and get stable row/field errors; `failed_count` counts rows, not individual field errors. Structural failures preserve already-written evidence but cannot start normalization.

Row/file uniqueness, error keys, run locking and the cursor prevent duplicate evidence on replay. Validation can be redispatched while incomplete; finished validation replay is a no-op. Three attempts, 5/15/30-second backoff and a 60-second job timeout are bounded below Redis's 90-second retry interval. Exhaustion records only a safe file-level error. A broker failure after database commit can be recovered by retrying Validate or Start; no exactly-once broker-delivery claim is made.

Phase B also supplies the missing standard `failed_jobs` table required by the existing `database-uuids` failed-job driver. This is operational queue persistence, not a customer-domain table. Validation wraps unexpected SQL/storage exceptions in a safe message without retaining the original exception, because original messages may contain raw row bindings or private object paths. The infrastructure test exercises real exhaustion and verifies safe failed-job payload/error persistence. No customer API exposes that operational table.

Start queues an organization/run pointer, never full rows or secrets, on **import-normalization**. At the Phase B checkpoint the worker intentionally did not consume that queue and `AwaitImportNormalization` was a no-op. Phase D now implements that handoff; see the [delivery contract and validation record](import-phase-d-validation.md). Cancellation serializes with bounded work, preserves history, and blocks canceled jobs from starting more work.

Audit events: import.created, import.mapping_confirmed, import.validation_started, import.queued, import.canceled. Metadata includes correlation and small aggregates, not source rows, filenames, storage keys or credentials. Raw rows are not individually audited.

## Phase B validation and continuation

`ImportCenterTest` covers the customer API, malformed/oversized inputs, ancestry/roles, entitlements, preview bounds, mapping/template isolation, exact raw preservation, partial errors, replay, queue boundary, cancellation, signed downloads and audit safety. `ImportInfrastructureTest` is opt-in: actual private MinIO objects and anonymous-access denial, Redis enqueue and actual Laravel worker delivery for both fixtures, plus the explicit normalization boundary. It uses a dedicated test database and a random Redis prefix; it deletes only its own synthetic objects/queue entries. No full Playwright or Python suite is required for Phase B.

Run against an initialized dedicated PostgreSQL test database, never development data:

```powershell
docker compose exec -T postgres createdb -U cproviderprices cproviderprices_test
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=cproviderprices_test api php artisan test --compact --filter=ImportCenterTest
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=cproviderprices_test -e RUN_IMPORT_INFRASTRUCTURE=1 api php artisan test --compact --filter=ImportInfrastructureTest
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=cproviderprices_test api php artisan test --compact --filter='ImportPersistenceTest|CustomerConfigurationTest|TenantAuthorizationTest|EntitlementSubscriptionTest'
```

Create the test database only once. The import suites guard against an incorrect database. The infrastructure test applies/rolls back migrations in the disposable test database. Other existing suites still require the explicit environment overrides above. `APP_ENV=testing` must be set before Artisan bootstraps, not just inside PHPUnit.

Fixtures: `apps/api/tests/Fixtures/import-supplier-a.csv` yields three valid rows and one missing-description error at row 5; `import-supplier-b.xlsx` has a different column order/naming and yields three valid rows. Both queue successfully, with no normalized records. `php artisan app:prepare-auth-e2e --import-fixtures-only` deterministically regenerates XLSX from synthetic values; CSV is tracked text. No customer data is included.

**Phase C is complete:** the deterministic Python normalizer and bounded authenticated internal API are verified in the [Phase C validation record](import-phase-c-validation.md). Original mapped strings and explicit source conventions are preserved; parser version 1.0.0 returns safe typed results without database access or matching. Phase D now implements delivery and persistence; see its [contract and validation record](import-phase-d-validation.md). Import Center frontend remains Phase E and is not started automatically. Vendor items, matching, catalog records and Prompt 13 remain outside this pipeline implementation.
