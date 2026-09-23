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

Phase A validation: the PostgreSQL persistence suite covers tenant/account/location/file/raw ancestry, source-row and parser-version replay keys, mapping snapshots, lifecycle rejection/cancellation, model evidence immutability, and invalid database values. Model immutability follows the existing audit-model convention; trusted direct query-builder writes can bypass model hooks and must never be used to overwrite evidence. No import HTTP policy, queue retry/resume, object access, or parser behavior is claimed by these model tests.

Next continuation point: Phase B. Add tenant-scoped import policies and API contracts first, then private CSV/XLSX upload, bounded detection, mapping/template confirmation, validation, and authenticated downloads. Keep lifecycle mutation behind the transition service and snapshot mapping/conventions before queueing. Add appropriate audit events at the authorized action boundary. Extend the existing fixture command rather than creating a parallel fixture system. The complete Prompt 11/12 acceptance matrix remains outstanding until Phases B-F pass.

Owners and buyers perform purchasing imports; viewers have no mutation permission. All operations use route TenantContext and centralized `manual_imports_enabled`. Downloads recheck tenant membership on signed application routes. Python receives mapped records plus opaque trace identifiers, not database access or storage credentials. See the [credential contract](../security/credential-handling.md) and [unit contract](../data/unit-normalization.md).
