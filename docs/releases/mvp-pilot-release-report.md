# MVP pilot release report

Date: 2026-10-06  
Release candidate: Prompt 22 on `test/22-pilot-e2e-release`  
Classification: **READY WITH CONDITIONS**

## Tested scope and synthetic environment

The release candidate was exercised only with deterministic, non-sensitive data in the isolated `cproviderprices_e2e` database. `Pilot Bistro Group` has an owner, buyer, Spanish-preference viewer, two locations, and four supplier accounts: manual CSV, fresh automatic XLSX, stale feed, and failed connection. `Pilot Isolation Tenant` contains separate private offer/list data for IDOR checks.

The pilot holds 142 representative private vendor items across produce, poultry, oils, dairy/cheese, pantry, and beverages. The shared tomato taxonomy has Beefsteak, Roma, Grape, Cherry, and Vine-ripe canonical products with English and Spanish aliases; Spanish names do not create duplicate canonical products. It includes the required tomato, chicken breast, canola oil, and whole-milk mozzarella examples.

Beefsteak pricing deliberately distinguishes winner semantics: the $0.50/lb best-normalized offer is unavailable, while a different fresh offer is best available. Stale, manual-import, connection-failed, alternative, unavailable, and unsafe catch-weight records remain visible with their proper warnings; unsafe conversion has no fabricated normalized value. Twenty-two immutable observations support history and freshness checks. One ambiguous `TOM BEEFSTEAK 25#` item remains pending until the platform-admin review E2E accepts it; after review, the buyer's comparison contains the newly mapped offer and the scoped cache is invalidated.

## Commercial journey evidence

| Journey | Result |
| --- | --- |
| Platform provisioning | Passed in `platform.spec.ts`: creation, trial/subscription updates, owner invitation/resend, audit-backed platform actions, and non-platform denial. |
| Owner onboarding | Passed in auth/workspace coverage: invitation acceptance, secure session, tenant/location context, locations, suppliers, team roles, limits, and viewer restrictions. |
| Buyer imports | Passed in `imports.spec.ts` plus `app:verify-import-e2e`: real CSV and differently shaped XLSX, mapping/template reuse, queue/Python path, partial outcomes, downloads, leading-zero SKU, parser version, and correlations. |
| Matching review | Targeted matching plus pilot sequence: 4/4 passed; full composed suite passed the review and buyer-visible downstream mapping. |
| Search/comparison/history | Targeted search: 3/3 passed. It asserts English/Spanish/structured query flow, API response readiness after filters, source evidence, normalized/available winners, stale/failed warnings, history chart/table, and no unintended full reload. |
| Saved lists | Full suite passed selected offer, best-normalized option, best-available option, persistence, unavailable selection, alternatives, direct-list denial, and client navigation. |
| Viewer and tenant attack paths | Full suite passed read-only viewer behavior and direct URL/API tenant denials; pilot test confirms Organization B receives 403 and no Organization A offer source. |
| EN/ES, responsive, accessibility | Full suite passed Spanish route/filter/context retention, mobile drawer keyboard behavior, focus restoration, labels, dialogs, history fallback, and desktop/tablet/mobile overflow checks. |

The final composed browser run was **43 passed in 3.6 minutes**, followed by successful real-import persistence verification. No arbitrary sleeps or global timeout increases were used. A scoped E2E synchronization now waits for the precise `product-search` response after changing availability, then still asserts the compare link, canonical URL, and same-document navigation.

## Quality, security, and performance evidence

- Laravel: Pint passed 227 files; PHPStan analyzed 163 files with no errors; full suite passed 251 tests with 2 skipped and 1,500 assertions. Relevant product/matching/search/list/history/isolation coverage passed 36 tests with 255 assertions.
- Python: Ruff format/lint, strict mypy (12 source files), typed internal OpenAPI check, and pytest all passed; pytest reported 93 passed with two dependency deprecation warnings.
- Frontend after the final E2E synchronization: `npm run lint`, strict workspace TypeScript, Vitest (13 files, 58 tests), and production Next build all passed.
- Contract: public Redocly lint and generated-client drift checks passed.
- Performance: local pilot p95 values were search/comparison uncached 38.010 ms, cached 0.127 ms, comparison 5.305 ms; history uncached 7.498 ms with six queries. These are local guardrails, not production capacity claims.
- Runtime security: production npm audit has zero findings; Composer audit has zero advisories; Python dependency validation is clean. Tracked secret signatures, tracked runtime credential files, and representative E2E sensitive-log-pattern matches were all zero.

Full npm audit still reports development-only findings: two critical records through Vitest/Tinypool, four high records through the Next ESLint plugin's fast-glob chain, and one moderate Vitest mocker record. The production web image copies Next's standalone runtime rather than build `node_modules`, so those tools are absent from the customer runtime. CI uses read-only pull-request permissions and an ephemeral isolated E2E credential. Treat a compatible Vitest/ESLint toolchain upgrade as a release-engineering follow-up before running untrusted test/build inputs; do not apply the audit's incompatible major downgrade blindly.

## Data recovery and deployment evidence

An isolated PostgreSQL backup restored successfully into a disposable database and verified 18 migrations, the pilot organization, and 142 vendor items. The disposable restore database and in-container dump were deleted. Current isolated-db audit also confirms 22 price observations and indexes/constraints across import, offer, price, list-item, and vendor-item tables.

Normal Compose was healthy; isolated Compose executed the full browser journey. Production and staging Compose configuration both validate with their safe example environment files. Local production images were built. The deployment guard refuses a production invocation without explicit `DEPLOY_PRODUCTION_APPROVED=1`, before trying to read a runtime environment file or contact deployment services. A real deployment or rollback was intentionally not performed.

## Conditions and next operational step

This candidate is ready for a controlled pilot only after operators provision the runtime/registry, exact production and staging secrets, SMTP, DNS/TLS, managed off-host database and object-storage backup retention with a real restore drill, monitoring/alert routing, and GitHub Environment reviewers. Those conditions are outside Prompt 22 and are why this report is not `READY`.

The next operational action is to satisfy and evidence those deployment conditions, then use the documented immutable-tag, backup-id, migration, smoke, and rollback procedure. Do not begin mobile work as part of this release audit.
