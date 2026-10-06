# MVP pilot release checklist

Date: 2026-10-06  
Scope: Prompt 22 synthetic-pilot release candidate. No customer data, external deployment, commit, or push was performed.

| Gate | Status | Evidence |
| --- | --- | --- |
| Deterministic isolated fixture | PASS | `app:prepare-auth-e2e` is guarded by `E2E_ISOLATED=1` and an exact E2E database name. `app:verify-pilot-e2e` verified 2 locations, 4 supplier accounts, 142 private vendor items, safe Beefsteak winners, a pending review, and a separate tenant. |
| Authentication and invitations | PASS | Full Laravel suite: 251 passed, 2 skipped, 1,500 assertions. Playwright auth coverage passed in the 43/43 composed run. |
| Tenant isolation, IDOR, and cache scope | PASS | Tenant authorization/import/saved-list/search tests passed. Pilot browser test received 403 for an Organization B comparison and no private source text. Cache invalidation is scoped to authorized organization, location, and canonical product. |
| Roles, entitlements, and platform scope | PASS | Owner/buyer/viewer and non-platform platform-route coverage passed; viewer mutation denials are asserted in browser and backend tests. |
| Decimal prices, units, and safe normalization | PASS | Pilot assertions prove `$0.50/lb` normalized winner, a distinct fresh best-available winner, and `null` normalization for variable/catch-weight data. |
| Raw-to-normalized provenance and imports | PASS | Full composed Playwright passed and `app:verify-import-e2e` confirmed two real UI fixtures persisted three parser `1.0.0` results with correlations and terminal states. |
| Catalog matching and human review | PASS | Pilot data includes high-confidence, ambiguous, alternative, and unsafe candidate cases. Platform review is audited and its accepted mapping invalidates the scoped comparison cache. |
| Search, comparison, history, and freshness | PASS | Search 3/3 and full browser 43/43 cover bilingual aliases, structured packs, comparison winners, formula/source evidence, stale/failed states, history, and refresh semantics. |
| Saved Lists | PASS | Browser coverage preserves canonical intent and selected offer while showing current best normalized and best available options, including unavailable selected-offer alternatives. |
| English and Spanish | PASS | `i18n.spec.ts` in full browser coverage preserves session, organization, location, route, filters, and client navigation. Pilot taxonomy uses shared canonical products with English and Spanish aliases. |
| Navigation, loading, and client refresh | PASS | E2E uses exact URLs and API/RSC responses rather than sleeps. It asserts same-document markers, dynamic deep links, refresh/back state, active import polling, and terminal polling stop. |
| Responsive and representative accessibility | PASS | Desktop/tablet/mobile overflow, accessible mobile drawer, keyboard cancellation/focus restoration, labelled forms, dialogs, headings, and accessible history fallback passed. This is not a formal accessibility certification. |
| Local performance budget | PASS | Measured Laravel local pilot p95: search/comparison uncached 38.010 ms, cached 0.127 ms, comparison 5.305 ms; history uncached 7.498 ms with 6 queries. Browser production capacity was not measured and is not claimed. |
| Database/query audit | PASS | Isolated `cproviderprices_e2e`: 18 migrations, 1 pilot organization, 142 vendor items, 22 observations; critical-table indexes and constraints were enumerated. No development database was reset. |
| Backup and restore | PASS | An isolated compressed dump (179,160 bytes, SHA-256 `D8C25C53D8356B68CE77F61F94B2D650C8B91144B63F1BDE5D7223ADABADB9A7`) restored into a disposable database and verified 18 migrations, 1 pilot organization, and 142 items. The disposable restore database and container copy were removed. |
| Rollback guard and runbook | PASS | Deployment script rejects a production invocation before configuration access without `DEPLOY_PRODUCTION_APPROVED=1`; immutable tag and backup-id checks were reviewed. No real rollback was performed. |
| Logs, credentials, and secret review | PASS | Tracked secret-signature matches: 0; tracked runtime credential files: 0; representative E2E API/worker/ingestion sensitive-log matches: 0. Fixture identities and credentials remain ignored/private. |
| Runtime dependency audit | PASS | `npm audit --omit=dev --json`: 0 production vulnerabilities; Composer audit: 0 advisories; Python dependency check/audit completed clean. |
| Development-tool dependency follow-up | PASS | Full npm audit has 2 critical Vitest/Tinypool, 4 high ESLint/fast-glob chain, and 1 moderate Vitest advisory. They are development-only and excluded from the final standalone web runtime; a compatible toolchain upgrade remains required before accepting untrusted build/test inputs. |
| Contract and generated client | PASS | Public OpenAPI lint, generated-client drift, and ingestion internal typed-contract checks passed. |
| Build and deployment assets | PASS | Normal Compose health, isolated E2E Compose, staging and production Compose configuration validation, and all production images were built locally. The web image was rebuilt after the production audit lockfile update. |
| External production readiness | BLOCKED | Before a pilot deployment, provide managed runtime/registry, separate real secrets, SMTP, DNS/TLS, off-host database and object-storage backup/restore retention, monitoring/alert routing, and GitHub Environment reviewers. |

## Classification

**READY WITH CONDITIONS.** All repository, synthetic-data, authorization, quality, and local recovery gates above pass. The final row is intentionally blocked because Prompt 22 does not provision or operate real production infrastructure.
