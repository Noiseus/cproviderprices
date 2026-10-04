"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useRef, useState } from "react";
import type { components } from "@cproviderprices/generated-api-client";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import {
  Badge,
  Button,
  Card,
  EmptyState,
  ErrorState,
  FreshnessBadge,
  PartialDataWarning,
  PermissionState,
  Select,
  Skeleton,
} from "@/components/ui";
import { ApiClientError, apiRequest } from "@/lib/api";

type ComparisonResponse = components["schemas"]["ProductComparisonResponse"];
type Offer = ComparisonResponse["offers"][number];

function title(value: string) {
  return value
    .replaceAll("_", " ")
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function pack(offer: Offer) {
  const packs = offer.pack.packs;
  if (Array.isArray(packs)) {
    const visible = packs
      .map((entry) =>
        typeof entry === "object" && entry
          ? (entry as { original_text?: unknown }).original_text
          : null,
      )
      .filter((entry): entry is string => typeof entry === "string");
    if (visible.length) return visible.join(" · ");
  }
  return "Source pack evidence available";
}

function tone(value: Offer["availability"] | Offer["comparison_class"]) {
  return value === "available" || value === "exact"
    ? "success"
    : value === "unavailable"
      ? "danger"
      : "warning";
}

export function ComparisonWorkspace({
  canonicalProductId,
}: {
  canonicalProductId: string;
}) {
  const { organization, locationId } = useWorkspace();
  const pathname = usePathname();
  const router = useRouter();
  const searchParams = useSearchParams();
  const [response, setResponse] = useState<ComparisonResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [blocked, setBlocked] = useState(false);
  const epoch = useRef(0);
  const filters = {
    availability: searchParams.get("availability") ?? "",
    freshness: searchParams.get("freshness") ?? "",
    matchClass: searchParams.get("match_class") ?? "",
    sort: searchParams.get("sort") ?? "supplier",
    page: Number(searchParams.get("page") ?? "1") || 1,
  };

  const setParams = useCallback(
    (changes: Record<string, string | null>) => {
      const next = new URLSearchParams(searchParams.toString());
      for (const [key, value] of Object.entries(changes)) {
        if (!value) next.delete(key);
        else next.set(key, value);
      }
      const query = next.toString();
      router.replace(query ? `${pathname}?${query}` : pathname, {
        scroll: false,
      });
    },
    [pathname, router, searchParams],
  );

  useEffect(() => {
    if (!locationId) {
      setResponse(null);
      return;
    }
    const controller = new AbortController();
    const current = ++epoch.current;
    const parameters = new URLSearchParams({
      location_id: locationId,
      sort: filters.sort,
    });
    if (filters.availability)
      parameters.append("availability[]", filters.availability);
    if (filters.freshness) parameters.append("freshness[]", filters.freshness);
    if (filters.matchClass)
      parameters.append("match_class[]", filters.matchClass);
    if (filters.page > 1) parameters.set("page", String(filters.page));
    setLoading(true);
    setBlocked(false);
    void apiRequest<ComparisonResponse>(
      `/api/v1/organizations/${organization.id}/comparisons/${encodeURIComponent(canonicalProductId)}?${parameters.toString()}`,
      { signal: controller.signal },
    )
      .then((payload) => {
        if (controller.signal.aborted || current !== epoch.current) return;
        setResponse(payload);
        setError(null);
      })
      .catch((reason: unknown) => {
        if (controller.signal.aborted || current !== epoch.current) return;
        setResponse(null);
        if (
          reason instanceof ApiClientError &&
          [401, 403, 404].includes(reason.status)
        )
          setBlocked(true);
        setError(
          reason instanceof Error
            ? reason.message
            : "Comparison is unavailable.",
        );
      })
      .finally(() => {
        if (!controller.signal.aborted && current === epoch.current)
          setLoading(false);
      });
    return () => controller.abort();
  }, [
    canonicalProductId,
    filters.availability,
    filters.freshness,
    filters.matchClass,
    filters.page,
    filters.sort,
    locationId,
    organization.id,
  ]);

  const setFilter = (
    key: "availability" | "freshness" | "match_class" | "sort",
    value: string,
  ) => setParams({ [key]: value || null, page: null });
  const movePage = (page: number) =>
    setParams({ page: page > 1 ? String(page) : null });

  return (
    <main className="ui-container search-comparison">
      <p>
        <Link href={`/o/${organization.slug}/search`}>← Back to search</Link>
      </p>
      {response && (
        <header className="ui-page-header">
          <div>
            <p className="eyebrow">{response.location.name}</p>
            <h1>{response.canonical_product.english_name}</h1>
            <p>
              Formula {response.formula_version}. Values are source-observed,
              not live quotes.
            </p>
          </div>
        </header>
      )}
      <div
        className="search-comparison__comparison-filters"
        aria-label="Comparison filters"
      >
        <Select
          label="Sort offers"
          value={filters.sort}
          onChange={(event) => setFilter("sort", event.target.value)}
        >
          <option value="supplier">Supplier</option>
          <option value="lowest_case_price">Lowest case price</option>
          <option value="lowest_normalized_price">
            Lowest normalized price
          </option>
          <option value="newest_observation">Newest observation</option>
        </Select>
        <Select
          label="Availability"
          value={filters.availability}
          onChange={(event) => setFilter("availability", event.target.value)}
        >
          <option value="">Any availability</option>
          <option value="available">Available</option>
          <option value="unavailable">Unavailable</option>
          <option value="unknown">Unknown</option>
        </Select>
        <Select
          label="Freshness"
          value={filters.freshness}
          onChange={(event) => setFilter("freshness", event.target.value)}
        >
          <option value="">Any freshness</option>
          <option value="fresh">Fresh</option>
          <option value="aging">Aging</option>
          <option value="stale">Stale</option>
          <option value="unknown">Unknown</option>
        </Select>
        <Select
          label="Match quality"
          value={filters.matchClass}
          onChange={(event) => setFilter("match_class", event.target.value)}
        >
          <option value="">Any mapping</option>
          <option value="exact">Exact</option>
          <option value="equivalent">Equivalent</option>
          <option value="alternative">Alternative</option>
          <option value="not_safely_comparable">Not safely comparable</option>
        </Select>
      </div>
      {loading && <Skeleton lines={7} label="Loading offer comparison" />}
      {blocked && (
        <PermissionState
          title="Comparison is unavailable"
          description="Your current workspace context cannot access this product or location."
        />
      )}
      {!loading && error && !blocked && (
        <ErrorState title="Unable to load comparison" description={error} />
      )}
      {response?.partial.supplier_accounts.length ? (
        <PartialDataWarning>
          Some supplier data is incomplete. This view only includes safe
          available evidence.
        </PartialDataWarning>
      ) : null}
      {response?.warnings.length ? (
        <Card className="search-comparison__warning">
          <strong>Comparison caution</strong>
          <p>{response.warnings.map(title).join(". ")}</p>
        </Card>
      ) : null}
      {response && !loading && response.offers.length === 0 && (
        <EmptyState
          title="No eligible offers"
          description="Try removing a filter or return to search for another product."
        />
      )}
      {response && !loading && response.offers.length > 0 && (
        <section className="search-comparison__offers" aria-live="polite">
          <div className="search-comparison__freshness">
            <strong>Price freshness</strong>
            {(["fresh", "aging", "stale"] as const)
              .filter((state) => response.freshness_summary[state] > 0)
              .map((state) => (
                <span key={state}>
                  <FreshnessBadge state={state} />{" "}
                  {response.freshness_summary[state]}
                </span>
              ))}
          </div>
          {response.offers.map((offer) => (
            <OfferCard key={offer.id} offer={offer} />
          ))}
          {response.pagination.total_pages > 1 && (
            <nav className="ui-pagination" aria-label="Comparison pages">
              <Button
                variant="secondary"
                disabled={response.pagination.page <= 1}
                onClick={() => movePage(response.pagination.page - 1)}
              >
                Previous
              </Button>
              <span>
                Page {response.pagination.page} of{" "}
                {response.pagination.total_pages}
              </span>
              <Button
                variant="secondary"
                disabled={
                  response.pagination.page >= response.pagination.total_pages
                }
                onClick={() => movePage(response.pagination.page + 1)}
              >
                Next
              </Button>
            </nav>
          )}
        </section>
      )}
    </main>
  );
}

function OfferCard({ offer }: { offer: Offer }) {
  const unsafe =
    offer.comparison_class === "alternative" ||
    offer.comparison_class === "not_safely_comparable" ||
    offer.normalized_price === null;
  return (
    <Card className="comparison-offer">
      <div className="comparison-offer__head">
        <div>
          <h2>{offer.supplier.display_name}</h2>
          <p>{offer.raw_supplier_name}</p>
        </div>
        <div className="comparison-offer__badges">
          {offer.winner_labels.map((winner) => (
            <Badge key={winner} tone="accent">
              {title(winner)}
            </Badge>
          ))}
          <Badge tone={tone(offer.availability)}>
            {title(offer.availability)}
          </Badge>
          <Badge tone={tone(offer.comparison_class)}>
            {title(offer.comparison_class)}
          </Badge>
          {offer.freshness === "fresh" ||
          offer.freshness === "aging" ||
          offer.freshness === "stale" ? (
            <FreshnessBadge state={offer.freshness} />
          ) : (
            <Badge tone="warning">Freshness unknown</Badge>
          )}
        </div>
      </div>
      <dl className="comparison-offer__facts">
        <div>
          <dt>Pack</dt>
          <dd>{pack(offer)}</dd>
        </div>
        <div>
          <dt>Case price</dt>
          <dd>
            {offer.currency} {offer.case_price}
          </dd>
        </div>
        <div>
          <dt>Normalized price</dt>
          <dd>
            {offer.normalized_price
              ? `${offer.currency} ${offer.normalized_price}/${offer.normalized_unit}`
              : "Not safely available"}
          </dd>
        </div>
        <div>
          <dt>Observed</dt>
          <dd>{new Date(offer.observed_at).toLocaleString()}</dd>
        </div>
      </dl>
      {unsafe && (
        <p className="comparison-offer__warning">
          {offer.comparison_class === "alternative"
            ? "Alternative offer: it is not directly interchangeable with the canonical product."
            : "Not safely comparable: a normalized winner is intentionally not shown without safe quantity and unit evidence."}
        </p>
      )}
      <details className="comparison-offer__details">
        <summary>Calculation details and source evidence</summary>
        <dl className="comparison-offer__facts">
          <div>
            <dt>Source case price</dt>
            <dd>
              {offer.calculation.currency} {offer.calculation.source_case_price}
            </dd>
          </div>
          <div>
            <dt>Normalized denominator</dt>
            <dd>
              {offer.calculation.normalized_denominator
                ? `${offer.calculation.normalized_denominator.value} ${offer.calculation.normalized_denominator.unit}`
                : "Unavailable"}
            </dd>
          </div>
          <div>
            <dt>Formula</dt>
            <dd>
              {offer.calculation.formula ?? "No safe normalization formula"}
            </dd>
          </div>
          <div>
            <dt>Formula version</dt>
            <dd>{offer.calculation.formula_version}</dd>
          </div>
          <div>
            <dt>Observation</dt>
            <dd>{offer.calculation.observation.observed_at}</dd>
          </div>
          <div>
            <dt>Mapping</dt>
            <dd>
              {offer.calculation.match.match_class} · confidence{" "}
              {offer.calculation.match.confidence}
            </dd>
          </div>
          <div>
            <dt>Matcher version</dt>
            <dd>{offer.calculation.match.matcher_version}</dd>
          </div>
        </dl>
        <pre>
          {JSON.stringify(
            {
              pack: offer.calculation.source_pack_evidence,
              price: offer.calculation.source_price_evidence,
              match: offer.calculation.match.evidence,
            },
            null,
            2,
          )}
        </pre>
      </details>
    </Card>
  );
}
