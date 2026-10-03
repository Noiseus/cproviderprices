# Phase C completion and validation

Resumed from `04a3de9` on `feat/11-12-import-ingestion`. Phase A (`dbfcfdc`) and Phase B (`d0d45e6`) were not restarted. No Laravel implementation, database, migration, frontend, delivery/orchestration or Prompt 13 work was performed. No commit or push was made.

## Checkpoint assessment and changes

The checkpoint already contained strict typed request/response models, parser version 1.0.0, exact deterministic normalization, source preservation, warnings/confidence, authenticated bounded ASGI ingress, internal OpenAPI with drift checking, README documentation and 88 passing tests from the preceding run. The earlier 56-test progress note was an intermediate milestone, not the final checkpoint count. Prior in-container health/authenticated HTTP checks and Python gates had also run successfully.

Remaining work was final-state verification, a formal acceptance record, and an explicit invalid-token container smoke assertion. Review added a focused regression for malformed competing numeric evidence. Both `4/10 LB OR 4/0 LB` and `10 LB OR 0 LB` reproduced a defect: the invalid candidate was warned about but another value in that dimension remained normalized. The smallest fix now blocks derived values for the known affected dimension; unknown dimensions fail conservatively. An independent valid volume remains available when only weight evidence is invalid. Three regression cases now pass. No authoritative unit rule was changed.

A fourth test compares exact serialized output from fresh Python processes with different hash seeds. Existing tests already varied decimal precision/exponent limits and repeated serialization. The container smoke now checks invalid credentials as well as absent credentials.

Files changed after the checkpoint:

- `services/ingestion/src/cproviderprices_ingestion/normalization.py`: malformed-evidence suppression.
- `services/ingestion/tests/test_normalization.py`: three numeric-evidence regressions and cross-process determinism.
- `services/ingestion/tests/container_smoke.py`: invalid-token rejection.
- `services/ingestion/README.md`: completion-record link.
- `docs/architecture/import-pipeline.md`: Phase C completion/Phase D continuation pointer.
- This validation record.

## Verified contract and behavior

The internal [OpenAPI 3.1 contract](../../services/ingestion/openapi.json) and [service README](../../services/ingestion/README.md) describe the complete interface. Customer OpenAPI and generated TypeScript clients remain unchanged.

- `BatchRequest`: correlation_id and 1–100 uniquely referenced `RawRecord` inputs, with record correlation, Phase B mapped fields and explicit source conventions. Unknown fields, type coercion, blank SKU/description and oversized strings are rejected.
- `BatchResponse`: parser version, batch correlation, typed results and processed/warning/failed counts. Each result preserves its full typed original input and trace, measurements and original numeric/unit lexemes, pack evidence, case quantity, size code, variable-weight flag, stable warnings and extraction confidence.
- Parser version **1.0.0** is an explicit stable literal in every result and batch, independent of time/deployment state. There is no database/table coupling, clock lookup, external lookup, LLM or probabilistic inference.
- Arithmetic is exact Decimal with an isolated precision/exponent context and bounded numeric operands. Weight normalizes to g; volume to mL; count to ea. All output numbers are plain decimal strings. Original strings/leading-zero SKUs remain intact.
- Supported weight units: g, kg, avoirdupois oz/lb and documented aliases. Supported volume units: mL/L and aliases, plus exact US liquid gal/qt/pt/fl oz only with an explicit source convention or supported explicit US qualifier. Imperial/dry/troy remain unsupported. No density or weight-to-volume conversion exists.
- Isolated `N/S UNIT` keeps pack count, unit size and case total separate. A standalone measurement never invents pack count or case total. A mapped case quantity never multiplies the package total. `5X6` is never multiplication; interpreted size code requires produce-size semantics. `#` requires documented weight context.
- Conflicts retain source evidence and suppress derived values; compatible canonical equivalents do not conflict. Malformed/ambiguous expressions do not create a preferred winner. Independent safe dimensions can survive.
- CW/CATCH WEIGHT/VARIABLE WEIGHT/RANDOM WEIGHT suppress fixed normalized weight and case totals and produce low confidence.
- Warning objects are sorted/deduplicated `{code, field, message}` with constant safe messages. The exact existing vocabulary is retained: missing_quantity, ambiguous_quantity, ambiguous_unit, ambiguous_pack, ambiguous_size_code, unit_system_required, unsupported_unit, unsafe_conversion, conflicting_values, variable_weight. Incomplete packs and malformed numbers reuse ambiguity codes.
- Confidence: high = safe quantitative extraction without warnings; medium = safe extraction with non-conflicting warnings; low = no safe quantitative extraction, conflict, unsafe conversion or variable weight. This is not product-match confidence.

## Required examples

| Input | Verified result with required explicit context |
| --- | --- |
| TOM BEEF 5X6 1/25 LB CS | Pack 1; size 25 lb; case total 11339.80925 g; size code 5X6 only with produce_size_code |
| TOM BEEFSTEAK 25# | Size 25 lb = 11339.80925 g with pound-sign convention; no invented pack/case total |
| CHIX BRST BNLS SKNLS 4/10 LB | Pack 4; size 10 lb; total 18143.6948 g |
| OIL CANOLA 6/1 GAL | Pack 6; size 1 gal; total 22712.470704 mL with us_liquid |
| MOZZ WHOLE MILK 4/5 LB | Pack 4; size 5 lb; total 9071.8474 g |
| 12 CT | Count 12 ea, no pack total |
| 1 L | 1000 mL, no invented pack count |

Without the required conventions, pound/volume/size-code ambiguity is preserved and warned about. Additional tests cover malformed/incomplete/multiple packs, zero/negative/locale/exponent/range inputs, unsupported units, catch weight, explicit-field conflicts and forbidden cross-dimension conversion.

## Service boundary and observability

`POST /internal/v1/normalize` is internal only. Environment-managed `INGESTION_SERVICE_TOKEN` is checked using constant-time comparison before reading the body. Missing/invalid credentials and empty configuration fail closed. `/health` remains unauthenticated. No token is committed, returned, printed by the smoke check or included in normal logs.

ASGI ingress enforces the **1 MiB actual-byte limit** even with missing/false Content-Length; the streaming test proves oversized input is rejected before the downstream parser runs. Unsupported encoding/media, pathological JSON, invalid structure, oversized batches and duplicate references produce stable safe errors without input echoes.

Success echoes body correlation_id in the response/header and retains record-level traces. Error responses use a valid supplied X-Request-ID or `unassigned`. Aggregate counters/events contain processed result count, warning-bearing result count and failed=0 for a structurally valid parsed batch. Invalid batches are rejected before parsing rather than claiming partial processing. Uncertain extraction is a warning, not a fabricated successful normalization. Full rows/tokens are absent from normal logs, and no sensitive high-cardinality labels or Python retry counters were introduced.

## Final commands actually run

| Command | Result |
| --- | --- |
| `docker compose exec -T ingestion ruff format --check src tests` | 11 files already formatted |
| `docker compose exec -T ingestion ruff check src tests` | All checks passed |
| `docker compose exec -T ingestion mypy src tests` | Strict mode: no issues in 11 source files |
| `docker compose exec -T ingestion pytest -q -p no:cacheprovider` | **92 passed**, 2 dependency deprecation warnings, 1.42s |
| `docker compose exec -T ingestion python -m cproviderprices_ingestion.export_contract --check` | Internal contract matches typed models |
| `npx redocly lint services/ingestion/openapi.json --extends=minimal` | Valid; no contract warnings/errors |
| `docker compose exec -T ingestion python tests/container_smoke.py` | Real HTTP: health 200, authenticated normalization 200, missing token 401, invalid token 401 |
| `docker compose config --quiet` | Exit 0 |
| `docker compose ps ingestion` | Running and healthy |
| `git diff --check` | Exit 0 |

The complete Python suite includes parser, internal endpoint and both existing health/configuration tests. Parser tests forbid socket calls. Serialization matches under changed decimal context and across fresh processes/hash seeds. Intermediate regression failures and one smoke-script formatting issue were corrected, not counted as passing checks.

Only ingestion was started/restarted; no dependency or image rebuild was needed. The HTTP smoke uses a temporary loopback Uvicorn process inside the running ingestion container with an ephemeral random credential, then terminates its own process. The normal service remains fail-closed until an environment token is configured. No Laravel call or database command was run.

## Acceptance and remaining boundaries

All Phase C acceptance criteria are satisfied. No Phase A/B compatibility issue was found; their implementation and test-database safeguards remain untouched. The final review fixed one Phase C numeric-ambiguity defect without redesigning the parser or changing the authoritative rules.

Non-blocking risks: the pinned test stack emits Starlette/httpx and AnyIO deprecation notices. Production must provide separate environment secrets, private ingress and encrypted transport. Conservative unresolved inputs intentionally retain null derived values and warnings. No production-readiness claim is made for the unimplemented delivery path.

Next task, **only on request**, is Phase D: authorize tenant/import ancestry in Laravel, read bounded pending raw batches, call this internal contract with service authentication, preserve correlation, handle retries/cancellation, persist parser-versioned results idempotently, and finalize counters/lifecycle correctly. Do not start frontend Phase E or Prompt 13 automatically.
