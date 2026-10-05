# Local performance results

This record accompanies Prompts 16 and 18. It is a reproducible local guard,
not a production capacity claim. The repository did not contain
`docs/performance-budget.md`; therefore no undocumented product budget is
invented here.

## Environment and pilot data

Measurements ran on 2026-10-04 using the Docker Compose PostgreSQL 17.6 test
database, Laravel's isolated test environment and its array cache. The
existing search pilot has 5,003 catalog products, 5,008 aliases, 2,500 variants
and six Beefsteak offers. The history pilot has 24 immutable observations for
one authorized product/location/supplier. These are service/cache timings, not
HTTP p50/p95 measurements and not Redis cache measurements.

| Path | Before Prompt 16/18 | After Prompt 16/18 | Local guard |
| --- | ---: | ---: | ---: |
| Search, uncached p95 | 32.526 ms | 31.854 ms | < 1,500 ms |
| Search, cached p95 | 0.123 ms | 0.107 ms | < 800 ms |
| Comparison p95 | 5.741 ms | 5.118 ms | < 1,200 ms |
| History, uncached p95 | n/a | 7.586 ms | < 1,500 ms |
| History query count | n/a | 6 | <= 6 |

The after figures are produced by `SearchComparisonPerformanceTest` and
`PriceHistoryPerformanceTest` on the described isolated data. The history test
also verifies that two requests in one authorized cache scope resolve once and
that a different organization/location gets a distinct key.

## Interpretation and remaining measurement work

The cache improvement is structural rather than a capacity claim: comparison
and history now invalidate product-scoped versions, while search remains a
location scope. Visible-only browser revalidation prevents duplicate request
loops and avoids background refresh in hidden tabs. No heavyweight chart or
visualization dependency was introduced.

`next build` completed for the web workspace, but its Turbopack output did not
report per-route JavaScript byte sizes. A CI bundle analyzer is required before
claiming a route-byte budget. The existing database index plan is retained; no
new index was added because this small history pilot did not provide persistent
query-plan evidence for one. Production-like HTTP/Redis load testing and an
approved dashboard/history budget remain required before release capacity can
be asserted.
