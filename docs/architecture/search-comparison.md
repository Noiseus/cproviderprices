# Search and comparison

Prompt 14 and Prompt 15 add the first customer search-to-comparison flow. It
uses PostgreSQL, Laravel and the existing customer shell; it does not introduce
an external search service, vector store, embedding model, or LLM.

## Private offer facts

`supplier_offers` is the tenant-private logical identity for one
organization, location, supplier account and vendor item. It deliberately does
not duplicate a canonical product or treat a price as identity. A
`price_observations` row is an immutable source fact attached to that offer.
It retains the import run, normalized import row, original price/currency/time
mapping, pack evidence, safely normalized quantity, availability, source match
snapshot, source-update/import timestamps and the stable
`price-comparison-1.0.0` formula version.

An observation is only created when the mapped source has a strict decimal
price, ISO-8601 source-observed timestamp, three-letter currency, and an
unambiguous safe Prompt 12 quantity when a normalized price is possible.
Processing/import time never substitutes for an absent source timestamp.
Variable, ambiguous, unsupported or conflicting quantities stay traceable but
have no fabricated denominator. PostgreSQL composite foreign keys enforce that
the current-observation pointer belongs to the same offer and organization.

The current observation is the newest observed timestamp; UUID is its stable
tie-breaker. Earlier observations are neither updated nor deleted. A completed
projection that creates an observation or changes this pointer invalidates the
organization/location search version and the affected product-scoped
comparison/history version after transaction commit.

## Server calculation policy

`OfferComparisonService` is the sole calculation authority. It uses Brick
decimal arithmetic; prices are API decimal strings and browser code does not
recalculate them. Weight is displayed per lb from stored grams using the exact
453.59237 factor, volume per gal from stored mL using 3785.411784, and count
per ea. Different dimensions are never converted through inferred density.

Prompt 13 mapping semantics remain authoritative:

| Prompt 13 mapping                    | Comparison class        | Direct winner eligible |
| ------------------------------------ | ----------------------- | ---------------------- |
| `exact_match`                        | `exact`                 | yes                    |
| `equivalent_product`                 | `equivalent`            | yes                    |
| `comparable_alternative`             | `alternative`           | no                     |
| `related_product` / `not_comparable` | `not_safely_comparable` | no                     |

The service independently labels lowest case price, best normalized price, and
best available option. Direct exact/equivalent offers must share currency for
a winner; normalized winners additionally require one compatible dimension.
Best available requires `available` and a non-stale observation. Decimal price,
then newest observation timestamp, then offer UUID is the deterministic
tie-breaker. Alternatives and unsafe offers stay visible with explicit
warnings, but never receive a direct-comparison normalized winner.

The current freshness and historical-read contract is documented in
[price history and freshness](price-history-freshness.md). The API never calls
an observation live. Saved purchasing lists reuse this service for current
offer summaries; see [saved purchasing lists](saved-purchasing-lists.md). They
store product intent and optional offer IDs only, never copied prices or
supplier facts.

## Search and privacy

`ProductSearchService` parses a bounded normalized query deterministically. It
recognizes size codes such as `5x6` as a string attribute, never arithmetic,
and recognizes only documented weight, volume and count units. Unknown tokens
remain text. Ranking is stable: exact canonical name, exact Spanish/English
alias, canonical/variant token match, scoped private vendor/brand evidence,
then a low-scoring trigram typo candidate; name and UUID break ties. A known
incompatible product size code is excluded rather than promoted by text
similarity.

The global catalog is searched read-only. Supplier counts, price/freshness
summaries, private vendor text, supplier filters and price sorts are computed
only after server-side organization and active location authorization, from
active tenant/location supplier accounts, current mappings and current
observations. Canonical products may therefore have zero offers without
leaking another tenant's supplier presence or price. Request telemetry records
only organization/location IDs, latency, a zero-result flag and a result-count
bucket—not query text or prices.

Search and comparison responses use a 30-second Laravel cache; price-history
responses use a five-minute cache. Every key includes the server-authorized
organization and location plus a scoped version (and the canonical product for
comparison/history), so cache hits cannot cross a tenant/location boundary.
Offer projection and accepted import completion invalidate only affected scopes
after transaction commit.

The browser keeps, per organization, at most eight recent query strings as a
local convenience. It never persists results, offers, supplier identities or
prices, and selecting a recent query reconstructs normal URL state.

## PostgreSQL plan evidence

Plans were measured on 2026-10-04 in `cproviderprices_test` only after seeding
the base catalog plus 5,000 deterministic tomato-family canonical rows, 5,000
Spanish aliases and 2,500 variants (5,003 / 5,008 / 2,500 total). The
development database was not accessed. Measurements use PostgreSQL 17.6,
`EXPLAIN (ANALYZE, BUFFERS)` and a warm local container; they are query-plan
evidence, not an HTTP latency claim.

| Search shape                                       | Before migration 000015               | After migration 000015                                                           | Decision                                                                    |
| -------------------------------------------------- | ------------------------------------- | -------------------------------------------------------------------------------- | --------------------------------------------------------------------------- |
| Selective typo `beefstak` on active canonical keys | sequential scan, 5.035 ms, 87 buffers | `canonical_products_normalized_key_trgm_index` bitmap scan, 0.080 ms, 20 buffers | retain active canonical trigram GIN index                                   |
| Bilingual structured alias `beef` + `tomato`       | sequential scan, 0.429 ms, 72 buffers | `product_aliases_normalized_alias_trgm_index` bitmap scan, 0.115 ms, 17 buffers  | retain alias trigram GIN index                                              |
| Broad `tomato` with ordered 200-result cap         | ordered primary-key scan, 0.280 ms    | planner correctly retained ordered primary-key scan, 0.119 ms                    | do not force trigram for broad matches                                      |
| Broad Spanish `tomate` with ordered cap            | ordered alias-target scan, 0.258 ms   | planner correctly retained ordered alias-target scan, 0.106 ms                   | do not add another broad alias index                                        |
| Broad typo `tomto`                                 | sequential scan, 4.665 ms             | GIN is selected but rechecks all 5,003 intentionally similar rows, 5.248 ms      | retain the index for selective typos; do not promise a broad-needle speedup |

No variant trigram index was retained: its equivalent plan stayed sequential at
this pilot size. The offer identity unique index already begins with the
organization/location comparison scope; history has a supplier-offer/observed
time index. No speculative vendor-description or offer index was added.

## Local performance guard

`SearchComparisonPerformanceTest` recreates this 5,003-product / 5,008-alias
/ 2,500-variant catalog plus six deterministic Beefsteak offers. On
2026-10-04 it ran ten uncached and cached passes of each required search query
(40 samples per cache mode) and 20 comparison passes in the isolated test
database. Measured p95 values were 33.095 ms uncached search, 0.124 ms cached
search and 5.978 ms comparison. The test enforces the requested local service
targets of 1.5 s, 800 ms and 1.2 s respectively.

These are Laravel service/cache measurements in the test environment (array
cache), not a statistically representative production HTTP/Redis benchmark.
They demonstrate the query/data path and guard local regressions; deployment
monitoring should measure real network and Redis latency separately.
