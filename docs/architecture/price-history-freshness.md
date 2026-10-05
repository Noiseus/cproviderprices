# Price history and freshness contract

Prompt 16 extends the existing comparison read model without changing its
service boundary. Laravel remains the API/calculation authority, PostgreSQL
retains source facts, Redis backs queues and response cache, and the browser
does not calculate prices or contact suppliers.

## Immutable evidence and history

`price_observations` are append-only source facts. A correction creates a new
observation and may move an offer's current-observation pointer; it never
rewrites the prior observation. Each new observation records the nullable
supplier-provided `source_updated_at`, required source `observed_at`, separate
system `imported_at`, formula version, import/normalized-record provenance and
the match-evidence snapshot current when it was created. Legacy rows receive
their existing `created_at` only as `imported_at`; no source-update timestamp
is fabricated.

Price history resolves new observations from their immutable match snapshot.
Legacy observations without one use the current mapping only as a compatibility
fallback. This prevents a later remapping from silently assigning a new match
to a snapshotted historical price.

The history endpoint is a tenant- and active-location-scoped GET route under
`/api/v1`. It accepts bounded dates (default 90 days, maximum 366), authorized
supplier filters and a dimension filter. It returns raw immutable observation
evidence and a daily series. The server calculates a daily mean only within the
same supplier, currency and compatible dimension, using exact decimals. It
never aggregates variable-weight, ambiguous, invalid or incompatible units;
those rows remain visible with an aggregation-state reason. No name-based
matching or invented normalization is used.

## Freshness states

Freshness is a dynamically derived state, stored as an audited current-offer
projection for operational visibility. It never changes the immutable price
fact or claims that a supplier was contacted.

| Condition | State |
| --- | --- |
| Manual-import source | `manual_import` |
| Automatic source whose latest recorded sync failure is not older than its last successful sync | `connection_failed` |
| Automatic daily source at most 24 hours old / at most 48 hours old | `fresh` / `aging` |
| Automatic weekly source at most 7 days old / at most 14 days old | `fresh` / `aging` |
| Automatic monthly source at most 31 days old / at most 62 days old | `fresh` / `aging` |
| Automatic source past its aging boundary, or on-demand cadence | `stale` |

Age uses `source_updated_at` when supplied, otherwise `observed_at`; a future
source timestamp is treated as zero age. A manual source takes precedence over
failure metadata. `next_expected_sync_at`, last-success and safe failure code
are operational account metadata, not client-authoritative fields. Last trusted
prices stay visible after `stale` or `connection_failed`, but those states are
excluded from the best-available winner.

The Laravel scheduler queues a bounded `freshness` recalculation every five
minutes. The job uses a Redis lock, processes at most 100 overdue offer
projections per run and invalidates only changed organization/location/product
cache versions. Docker Compose runs it through Laravel `schedule:work` and the
existing Redis worker; it adds no scheduler or broker.

## Cache, import signals and browser behavior

Search cache keys carry authorized organization/location and a location scope.
Comparison and history keys also carry the canonical product and its scoped
version. Cache entries do not contain global tenant data, and invalidation
never uses a global flush. A successful or partially successful import emits an
IDs-only internal completion event after commit. Its listener server-scopes the
run/account, updates safe sync metadata and invalidates the relevant location
and known product versions. Later projection also invalidates a product scope
when it creates the actual observation.

Dashboard refreshes its existing aggregate context every 60 seconds only while
visible. Comparison marks its local view stale after 60 seconds and history
after five minutes; either view refreshes once when it becomes visible again.
There is no global browser polling loop, cross-tab price cache or data stored in
local storage. Import progress keeps its existing polling contract. “Refresh
CProviderPrices data” rereads authorized internal records; “Sync supplier” is
explicitly a future connector action and performs no supplier call.

Saved purchasing-list detail follows the same derived-read rule. It refreshes
visible stale data after 60 seconds, retains an old selected-offer ID if it
later becomes unavailable, and presents current alternatives without modifying
durable purchasing intent. See [saved purchasing lists](saved-purchasing-lists.md).
