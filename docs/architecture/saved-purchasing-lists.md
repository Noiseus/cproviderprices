# Saved purchasing lists

Prompt 17 introduces customer-owned saved purchasing lists. A list is durable
purchasing intent for exactly one organization and restaurant location; it is
not an order, quote, basket optimizer, inventory allocation, or supplier sync.
It stores only the user's list/item intent: canonical product, optional product
variant, quantity target, preferred display unit, private note, explicit item
order, optional selected offer ID, status, actor IDs, timestamps, and optimistic
versions. Current price, normalized value, availability, freshness, supplier
details, and alternative offers are all derived at read time.

## Isolation, permissions, and lifecycle

Every list and item redundantly carries organization and location keys. Scoped
foreign keys require the list location to belong to its organization, every item
to belong to its list's organization/location, an optional variant to belong to
its canonical product, and a selected offer to belong to the same
organization/location. Controllers derive context from the authorized route and
reject client-supplied organization or location IDs. A direct foreign UUID is
reported as `404`, without confirming its existence.

Active owner and buyer memberships may create, rename, duplicate, archive,
restore, delete, and mutate lists. Active viewers may read only. Archived lists
remain readable but cannot be changed until restored. Deletion removes the list
and its items while preserving immutable audit rows that contain only safe IDs,
field names, and lifecycle metadata.

List and item updates require their current versions. The service obtains a
row lock before changing the durable record, increments its version, and
returns `409 conflict` with the current version on stale requests. Reorder is a
list-level operation requiring the exact item ID set and expected list version.
The database permits one canonical product without a variant and one of each
canonical/variant pair per active list via partial unique indexes; duplicate
adds return a typed conflict instead of creating another intent row.

## Read model and price behavior

Saved-list detail calls `OfferComparisonService` once for all list products,
using the same approved comparison calculation path as Search and Compare.
This batched read has no per-item offer query. It shows selected, best
normalized, and best available options separately, with source observation time
and freshness. A preferred unit is accepted only when a known current offer for
that product has the compatible dimension; the API never converts across weight,
volume, and count.

Offer selection is an explicit, current, active, tenant/location-scoped mapping
to the intended canonical product (and variant where supplied). It must be
available when chosen. If a selected offer later becomes inactive, unavailable,
or loses its mapping, its stored ID is retained but it is not silently replaced;
detail returns an unavailable-selection warning and current eligible
alternatives. Price changes and freshness projections naturally appear on the
next list read without changing intent or generating list audit events.

## API and browser behavior

Routes live below the existing versioned organization API as
`/api/v1/organizations/{organization}/saved-lists`. The OpenAPI contract defines
list lifecycle, item, selection, and reorder requests and returns the same
version tokens used by the browser. Generated client types are regenerated from
that contract; no handwritten duplicate client model exists.

The Lists workspace has active and archived tabs with explicit create, rename,
duplicate, archive/restore, and delete-confirmation actions. Search and Compare
can create a list or add canonical product intent, optionally carrying an
available selected offer. Detail uses explicit save, selection, remove, and
move controls, local mutation responses rather than a document reload, a
60-second visible-only stale refresh, and responsive cards. Notes render as
ordinary React text, so stored markup is not interpreted. The phrase “Refresh
CProviderPrices data” rereads authorized internal records only; it does not
contact a supplier.

## Validation scope

Feature tests cover role boundaries, direct foreign IDs, forged tenant input,
list lifecycle/audit, duplicate canonical intent, selected-offer validation and
retention, stale versions, preferred-unit compatibility, and bounded query
count for an eight-item detail. Unit/component tests cover explicit save,
conflict/error states, viewer read-only behavior, freshness/warning rendering,
and client-side navigation. Isolated Playwright data includes an offer that is
selected and then becomes inactive, so the browser verifies intent retention
and visible alternatives without a development-database mutation.
