# Phase E — Import Center frontend validation

Continuation of checkpoint `cef59d9` on `feat/11-12-import-ingestion`. Phases A–D, their schema/service boundaries and parser 1.0.0 remain intact. No Prompt 13 work, commit or push.

## Reused implementation and scoped corrections

The checkpoint already contained real history/detail routes, native CSV/XLSX multipart upload, bounded preview, mapping/templates, validation, start/resume, summary, authorized downloads, cancellation, and scoped polling. This continuation completes validation rather than replacing those components.

- Fixed React Strict Mode effect replay leaving mutation state permanently pending.
- Fixed an aborted hidden-document poll scheduling a duplicate timer after visibility resumed.
- Added total-row summary, accurate terminal partial-failure wording and non-noisy validation announcements.
- Added focused component/polling and real browser acceptance tests; expanded the existing synthetic fixture command and persisted-result verifier.
- Completed isolated Compose environment retention, health addressing, local runner cleanup and CI/Makefile integration. No development database reset or destructive preparation.
- Kept the E2E-only Next compiled output ephemeral and ran its development server with Webpack so a persisted Turbopack route manifest cannot make a host-mounted dynamic detail route appear absent. This does not change the normal development or production server.
- Made the preview and error-table source-row headers preserve a readable minimum width and internal horizontal scrolling at narrow viewports; the shared table shell still prevents document-level overflow.
- Kept the explicit Laravel configuration-backed E2E isolation guard and added PHPStan-compatible iterable typing to its unsafe-target data provider. Its runtime cases continue to fail closed before a database connection can be used.
- Applied repository formatters; no additional frontend dependencies, shell, component library, query cache or backend business behavior.

## UI and API boundaries

`/o/[organizationSlug]/imports` and `/imports/[importId]` reuse the authenticated AppShell and its tenant/location/subscription context. `ImportCenter` holds scoped remote history/detail state; `ImportMappingForm` manages unconfirmed field choices; `useImportPolling` owns one abortable timer/request. API aliases derive from the generated contract. Server authorization remains authoritative.

Only active tenant locations/accounts can be selected for a new upload; changing location resets its account. Empty states link to Locations/Suppliers. Detail routes remain pinned to persisted ancestry. The standard labeled file picker exposes filename/type/10-MiB guidance; no full file is parsed in the browser. Pending refs and disabled controls prevent duplicate mutations.

Preview uses backend headers/sample rows and existing internally scrollable tables, preserving strings such as `00012`. Required/duplicate/unknown mappings are checked locally and again by the server; templates require identical ordered headers. Explicit source conventions are sent unchanged; normalization remains in Python.

Validation separates eligible rows from failed source rows and displays safe row/field errors, bounded to 100 with an authorized report download. Start is available after completed validation with eligible rows and confirmed server capability. Cancel uses the existing confirmation dialog and retains history. Summary includes status, counts, file, tenant location/account and timestamps.

Polling occurs approximately two seconds after each completed request, only for active runs. Hidden documents abort/pause; visible documents resume; terminal states/unmount stop. Polls fetch import data only, never the full shell/session. The shareable detail URL reconstructs persisted state after navigation, with no document reload in the tested workflow.

Owners/buyers can mutate under existing policy; viewers retain authorized history/downloads. Disabled manual-import capability hides upload/mapping/start, while history/download/cancel policies remain server-defined. Cross-tenant and anonymous access produce useful states without source preview. Downloads request fresh backend-signed application routes, validate their origin/path and never construct object-store URLs; expiry has an actionable error state.

## Validation record

- Frontend unit/component suite: **36 passed**, including **20 Import Center tests** (mapping, safe errors, role/capability/empty states, duplicate mutation, Strict Mode, terminal/hidden/visible polling and race regression).
- Strict TypeScript across workspaces: passed.
- ESLint and repository Prettier check: passed.
- Focused `test-browser.ps1 -Spec imports.spec.ts`: **6 passed (36.8 seconds)**, followed by successful persisted-result verification.
- CSV: real upload → mapping → async validation → queue → Python → persistence; **partially_completed**, three normalized/processed rows, one missing-description error at source row 5, total four, remaining zero.
- XLSX: different ordered column names/layout; real end-to-end **completed**, three normalized/processed rows, zero failed rows.
- Both fixtures persisted parser **1.0.0** with matching run correlation; no Python/lifecycle response fake. Expired-download coverage modifies only the signed download request expiry and exercises actual backend denial.
- Focused `E2EIsolationTest`: **4 passed, 8 assertions** for unsafe database/flag combinations; the guard requires both database `cproviderprices_e2e` and `E2E_ISOLATED=1` before either fixture command can run.
- Full composed Playwright: **32 passed**. The latest Playwright result record is passed; real fixture verification ran after the suite before the isolated project stopped.
- Final responsive inspection: desktop, tablet and 390-pixel mobile captures passed. Preview/error-table “Source row” headers remain readable; any extra table width scrolls only inside the shared table region and the document has no horizontal overflow.
- PHPStan/Larastan: passed with the project configuration.
- Final frontend lint/format/type/unit gates, `docker compose config --quiet`, and `git diff --check`: passed.

Initial browser failures were selector and isolated test-network issues, not backend business defects. Native select assertions now use accessible combobox roles; expired-link testing stays in the browser network rather than Node's localhost. Tests use condition-based waits rather than fixed sleeps.

## Boundaries

Local test volumes intentionally remain for diagnostics; synthetic fixtures are fresh per run. Production shared-token provisioning, transport and source retention remain deployment responsibilities. Cancellation takes effect at a batch boundary. No vendor/catalog/matching records are created.

Phase E is formally complete. Phase F has not begun; begin its end-to-end fixture processing and broad quality gates only after explicit authorization.
