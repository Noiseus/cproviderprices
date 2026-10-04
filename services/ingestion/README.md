# CProviderPrices Ingestion

Internal Python HTTP service. Phase C adds a pure deterministic normalization engine and authenticated bounded batch API. Supplier connectors, Laravel delivery, database persistence and matching are not implemented here.

The local Docker stack publishes port `8001` for developer smoke testing. The service remains internal in the intended production topology.

## Configuration and API

Keep `INGESTION_ENV` and `INGESTION_SERVICE_NAME` as before. Set a privately generated `INGESTION_SERVICE_TOKEN` in the ignored local environment file or deployment secret manager. Use different secrets in staging and production; no committed default token exists. Missing, empty or whitespace-only configuration fails closed on normalization, while `GET /health` remains unauthenticated. Authentication compares the complete `Bearer <token>` value in constant time before reading request bodies. Tokens are excluded from settings repr, responses and application logs. Production requires private ingress and encrypted transport as documented in the credential contract.

`POST /internal/v1/normalize` accepts `application/json`, maximum **1 MiB of actual streamed bytes**, and **1–100 records**. Compressed bodies are unsupported. Content-Length is checked early but cannot override the actual-byte limit. Parsing/typing failures return safe `invalid_request` errors without echoing Pydantic input values. All models reject unknown fields and type coercion. Text fields are at most 4,096 characters; identifiers are bounded safe opaque strings. Duplicate record references and blank SKU/description are rejected. There is no customer endpoint or database connection.

Example body (credentials belong only in the Authorization header):

```json
{
  "correlation_id": "import-request-1",
  "records": [{
    "record_id": "raw-record-1",
    "correlation_id": "original-request-1",
    "mapped": {
      "supplier_sku": "00012",
      "description": "OIL CANOLA 6/1 GAL",
      "pack_size_text": "6/1 GAL"
    },
    "source_conventions": {"volume_system": "us_liquid"}
  }]
}
```

The success response echoes the batch correlation ID in `X-Request-ID` and the body; each result retains its record reference/correlation and entire typed original record. On invalid requests, an optional valid `X-Request-ID` request header provides error correlation; otherwise `unassigned` is used. The body correlation is authoritative on success. Request IDs/row IDs are not metric labels or normal log content.

`processed` counts returned results, `warning` counts results with any warnings, and `failed` is zero for a structurally valid parsed batch. Low confidence is uncertainty, not an exception. Invalid batches are rejected atomically before parsing and do not claim partial processing. Aggregate INFO events contain only these counts. No retry counter exists in Python: Phase D Laravel owns retry, tenant authorization, ancestry and persistence.

## Parser contract

Parser version is the explicit literal **1.0.0**. Follow [unit-normalization.md](../../docs/data/unit-normalization.md), [canonical-product-model.md](../../docs/data/canonical-product-model.md) and [matching-rules.md](../../docs/data/matching-rules.md). No supplier-name-specific rules, network lookup, LLM, embeddings, product categorization or matching exists.

`RawRecord` contains an opaque `record_id`, optional record correlation, `MappedValues` and `SourceConventions`. The mapped keys mirror the import mapping: supplier_sku, description, brand, pack_size_text, quantity, weight, volume, unit, price, currency, observed_at, availability, gtin. Only explicit supplied brand/identifiers/prices/timestamps/statuses are preserved; none is inferred or priced. Unit/source-convention metadata is separate from raw strings. Tenant/account/location/file ancestry stays authoritative in Laravel and is resolved through the record reference in Phase D.

`NormalizedResult` contains original input, parser version, typed measurements, pack evidence, separate case_quantity, size_code, variable_weight, warnings and confidence. Measurements retain source field, original expression/value/unit lexemes, dimension, parsed decimal string and nullable canonical value/unit. Packs retain original count/expression, pack_count, unit_size and nullable canonical case_total. Multiple identical descriptions/pack columns remain separate evidence, not precedence choices. Conflicting evidence stays visible with derived values suppressed.

- Exact `Decimal` arithmetic uses an isolated precision/exponent context. Numeric tokens are capped at 32 characters; no binary floats or exponent-form outputs are used. No rounding is needed within the operand bounds.
- Weight: g, kg, avoirdupois oz/lb and documented word aliases -> g.
- Volume: mL/L and aliases -> mL; gal/qt/pt/fl oz use exact US factors only with `us_liquid` or an explicit leading US qualifier. Imperial/dry/troy units are unsupported, including suffix qualifiers.
- Count: ea/each/count/ct -> ea. An unqualified mapped quantity or CS/CASE quantity is a separate case count, never units-per-case or a multiplier for the pack total.
- Isolated positive integer `N/S UNIT` yields pack count and per-unit size; standalone `S UNIT` never invents a pack count or case total. `CS/CASE` is packaging. Numeric ranges, locale commas, exponents, mixed fractions, nested/incomplete packs and spaced multiplication forms are not guessed.
- `5X6` is never multiplied. Only `produce_size_code` authorizes interpreted size-code output. `#` requires `pound_sign_is_weight` or an explicit mapped weight field.
- Explicit numeric fields and pack/description evidence are compared in the same dimension; equivalent canonical values do not conflict. Same-unit disagreements remain conflicts even without a volume convention. No weight/volume conversion exists. A standalone unit column supplies missing units; it does not reinterpret an explicitly measured independent dimension.
- CW/CATCH WEIGHT/VARIABLE WEIGHT/RANDOM WEIGHT flags suppress fixed normalized weight and all fixed case totals. Independent safe fields can remain extracted, but confidence is low.
- Conservative unresolved expressions may suppress additional derived values rather than choose one interpretation. Originals always remain available for future review.

Warnings are sorted/deduplicated `{code, field, message}` objects with safe constant messages. The Phase A vocabulary is unchanged: missing_quantity, ambiguous_quantity, ambiguous_unit, ambiguous_pack, ambiguous_size_code, unit_system_required, unsupported_unit, unsafe_conversion, conflicting_values, variable_weight. Malformed numbers use ambiguous_quantity; incomplete packs use ambiguous_pack. No redundant malformed_numeric_value/incomplete_pack codes were added.

Confidence is high with safe quantitative extraction and no warnings; medium with safe extraction plus non-conflicting warnings; low without safe quantitative extraction or with conflict, unsafe conversion or variable weight. It is extraction confidence, not Prompt 13 match confidence.

## Internal OpenAPI and verification

The reviewed `openapi.json` is the internal OpenAPI 3.1 contract for Phase D consumers. Typed models and this contract must change together. `python -m cproviderprices_ingestion.export_contract` reproducibly generates the artifact; `--check` and pytest detect drift. No public Laravel contract or generated customer client is changed. No runtime Swagger/schema route is exposed.

```powershell
docker compose exec -T ingestion ruff format --check src tests
docker compose exec -T ingestion ruff check src tests
docker compose exec -T ingestion mypy src tests
docker compose exec -T ingestion pytest -q -p no:cacheprovider
docker compose exec -T ingestion python -m cproviderprices_ingestion.export_contract --check
npx redocly lint services/ingestion/openapi.json --extends=minimal
docker compose exec -T ingestion python tests/container_smoke.py
```

Parser tests deny socket calls. API tests use an in-process client. The separate container smoke launches a temporary loopback Uvicorn process inside the ingestion container with a random process-only token, verifies real HTTP health/auth/normalization, and terminates only that process. It does not modify `.env`, print secrets or contact Laravel. Normal service configuration remains fail-closed until a token is supplied. Existing pinned test dependencies emit two deprecation warnings; no dependencies were added/upgraded for Phase C.

No database commands are needed. Preserve all Phase B test-database safeguards. Stop after Phase C; Phase D will authorize and batch existing pending raw records, call this contract with service credentials, persist versioned results idempotently, and finalize lifecycle counts. Do not start frontend or Prompt 13 automatically.

Phase C completion, fixture outcomes, final checks and remaining boundaries are recorded in [the validation record](../../docs/architecture/import-phase-c-validation.md).
