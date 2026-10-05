"use client";

import Link from "next/link";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import type { components } from "@cproviderprices/generated-api-client";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import {
  Badge,
  Button,
  Card,
  Drawer,
  EmptyState,
  ErrorState,
  FilterBar,
  FreshnessBadge,
  Input,
  PartialDataWarning,
  PermissionState,
  SearchInput,
  Select,
  Skeleton,
} from "@/components/ui";
import { ApiClientError, apiRequest } from "@/lib/api";
import type { SupplierAccount } from "@/lib/customer-api";
import { AddToSavedListControl } from "@/components/lists/AddToSavedListControl";
import { useTranslation } from "@/components/i18n/I18nProvider";

type SearchResponse = components["schemas"]["ProductSearchResponse"];

type SearchFilter =
  | "category"
  | "supplier"
  | "brand"
  | "unit"
  | "availability"
  | "freshness"
  | "match_class"
  | "sort";

const RECENT_LIMIT = 8;
const resultSorts = [
  ["relevance", "Relevance"],
  ["lowest_case_price", "Lowest case price"],
  ["lowest_normalized_price", "Lowest normalized price"],
  ["newest_observation", "Newest observation"],
] as const;

function price(result: SearchResponse["results"][number]) {
  const summary = result.price_summary;
  if (!summary.lowest_case_price || !summary.currency)
    return "Price unavailable";
  const normalized = summary.lowest_normalized_price
    ? ` · ${summary.lowest_normalized_price}/${summary.normalized_unit}`
    : "";
  return `${summary.currency} ${summary.lowest_case_price}/case${normalized}`;
}

export function SearchWorkspace() {
  const { organization, locationId } = useWorkspace();
  const { localizeError, t } = useTranslation();
  const pathname = usePathname();
  const router = useRouter();
  const searchParams = useSearchParams();
  const query = searchParams.get("q") ?? "";
  const [draftQuery, setDraftQuery] = useState(query);
  const [response, setResponse] = useState<SearchResponse | null>(null);
  const [accounts, setAccounts] = useState<SupplierAccount[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [blocked, setBlocked] = useState(false);
  const [filterOpen, setFilterOpen] = useState(false);
  const [recent, setRecent] = useState<string[]>([]);
  const epoch = useRef(0);
  const resultsRef = useRef<HTMLDivElement>(null);
  const recentKey = `cproviderprices-recent-searches:${organization.id}`;

  const filters = useMemo(
    () => ({
      category: searchParams.get("category") ?? "",
      supplier: searchParams.get("supplier") ?? "",
      brand: searchParams.get("brand") ?? "",
      unit: searchParams.get("unit") ?? "",
      availability: searchParams.get("availability") ?? "",
      freshness: searchParams.get("freshness") ?? "",
      match_class: searchParams.get("match_class") ?? "",
      sort: searchParams.get("sort") ?? "relevance",
      page: Number(searchParams.get("page") ?? "1") || 1,
    }),
    [searchParams],
  );

  const setParams = useCallback(
    (changes: Record<string, string | null>) => {
      const next = new URLSearchParams(searchParams.toString());
      for (const [key, value] of Object.entries(changes)) {
        if (value === null || value === "") next.delete(key);
        else next.set(key, value);
      }
      const text = next.toString();
      router.replace(text ? `${pathname}?${text}` : pathname, {
        scroll: false,
      });
    },
    [pathname, router, searchParams],
  );

  useEffect(() => setDraftQuery(query), [query]);

  useEffect(() => {
    try {
      const saved = JSON.parse(localStorage.getItem(recentKey) ?? "[]");
      setRecent(
        Array.isArray(saved)
          ? saved
              .filter((item): item is string => typeof item === "string")
              .slice(0, RECENT_LIMIT)
          : [],
      );
    } catch {
      setRecent([]);
    }
  }, [recentKey]);

  useEffect(() => {
    const normalized = draftQuery.trim();
    if (normalized === query) return;
    const timeout = window.setTimeout(() => {
      setParams({ q: normalized || null, page: null });
    }, 250);
    return () => window.clearTimeout(timeout);
  }, [draftQuery, query, setParams]);

  useEffect(() => {
    if (!locationId) return;
    const controller = new AbortController();
    void apiRequest<{ supplier_accounts: SupplierAccount[] }>(
      `/api/v1/organizations/${organization.id}/supplier-accounts?location_id=${encodeURIComponent(locationId)}`,
      { signal: controller.signal },
    )
      .then((payload) => {
        if (!controller.signal.aborted) setAccounts(payload.supplier_accounts);
      })
      .catch(() => {
        if (!controller.signal.aborted) setAccounts([]);
      });
    return () => controller.abort();
  }, [locationId, organization.id]);

  useEffect(() => {
    const normalized = query.trim();
    if (!normalized || !locationId) {
      setResponse(null);
      setError(null);
      setLoading(false);
      return;
    }
    const controller = new AbortController();
    const requestEpoch = ++epoch.current;
    const parameters = new URLSearchParams({
      q: normalized,
      location_id: locationId,
    });
    const requestFilters: Array<[string, string, string]> = [
      ["category", "category_id", filters.category],
      ["supplier", "supplier_id[]", filters.supplier],
      ["brand", "brand[]", filters.brand],
      ["unit", "unit[]", filters.unit],
      ["availability", "availability[]", filters.availability],
      ["freshness", "freshness[]", filters.freshness],
      ["match_class", "match_class[]", filters.match_class],
      ["sort", "sort", filters.sort],
    ];
    for (const [, name, value] of requestFilters)
      if (value) parameters.append(name, value);
    if (filters.page > 1) parameters.set("page", String(filters.page));
    setLoading(true);
    setBlocked(false);
    void apiRequest<SearchResponse>(
      `/api/v1/organizations/${organization.id}/product-search?${parameters.toString()}`,
      { signal: controller.signal },
    )
      .then((payload) => {
        if (controller.signal.aborted || requestEpoch !== epoch.current) return;
        setResponse(payload);
        setError(null);
        try {
          const existing = JSON.parse(
            localStorage.getItem(recentKey) ?? "[]",
          ) as string[];
          const next = [
            normalized,
            ...existing.filter((item) => item !== normalized),
          ].slice(0, RECENT_LIMIT);
          localStorage.setItem(recentKey, JSON.stringify(next));
          setRecent(next);
        } catch {
          // Local convenience history is optional and never stores results.
        }
      })
      .catch((reason: unknown) => {
        if (controller.signal.aborted || requestEpoch !== epoch.current) return;
        setResponse(null);
        if (
          reason instanceof ApiClientError &&
          [401, 403, 404].includes(reason.status)
        ) {
          setBlocked(true);
        }
        setError(
          reason instanceof ApiClientError
            ? localizeError(reason.code)
            : localizeError("network_error"),
        );
      })
      .finally(() => {
        if (!controller.signal.aborted && requestEpoch === epoch.current)
          setLoading(false);
      });
    return () => controller.abort();
  }, [
    filters.availability,
    filters.brand,
    filters.category,
    filters.freshness,
    filters.match_class,
    filters.page,
    filters.sort,
    filters.supplier,
    filters.unit,
    locationId,
    organization.id,
    query,
    recentKey,
  ]);

  const categories = useMemo(() => {
    const current =
      response?.results.map((result) => result.canonical_product.category) ??
      [];
    return Array.from(
      new Map(current.map((category) => [category.id, category])).values(),
    );
  }, [response]);
  const updateFilter = (filter: SearchFilter, value: string) =>
    setParams({ [filter]: value || null, page: null });
  const movePage = (page: number) =>
    setParams({ page: page > 1 ? String(page) : null });

  const filterFields = (
    <div className="search-comparison__filters">
      <Select
        label={t("search.sorting")}
        value={filters.sort}
        onChange={(event) => updateFilter("sort", event.target.value)}
      >
        {resultSorts.map(([value, text]) => (
          <option key={value} value={value}>
            {text}
          </option>
        ))}
      </Select>
      <Select
        label={t("search.category")}
        value={filters.category}
        onChange={(event) => updateFilter("category", event.target.value)}
      >
        <option value="">{t("search.anyCategory")}</option>
        {categories.map((category) => (
          <option key={category.id} value={category.id}>
            {category.english_name}
          </option>
        ))}
      </Select>
      <Select
        label={t("search.supplier")}
        value={filters.supplier}
        onChange={(event) => updateFilter("supplier", event.target.value)}
      >
        <option value="">{t("search.anySupplier")}</option>
        {accounts.map((account) => (
          <option key={account.id} value={account.id}>
            {account.display_name}
          </option>
        ))}
      </Select>
      <Input
        label={t("search.brand")}
        value={filters.brand}
        onChange={(event) => updateFilter("brand", event.target.value)}
        placeholder={t("search.anyBrand")}
      />
      <Select
        label={t("search.pack")}
        value={filters.unit}
        onChange={(event) => updateFilter("unit", event.target.value)}
      >
        <option value="">{t("common.all")}</option>
        <option value="case">Case</option>
        <option value="weight">Weight</option>
        <option value="volume">Volume</option>
        <option value="count">Count</option>
      </Select>
      <Select
        label={t("search.availability")}
        value={filters.availability}
        onChange={(event) => updateFilter("availability", event.target.value)}
      >
        <option value="">{t("search.anyAvailability")}</option>
        <option value="available">{t("availability.available")}</option>
        <option value="unavailable">{t("availability.unavailable")}</option>
        <option value="unknown">{t("availability.unknown")}</option>
      </Select>
      <Select
        label={t("search.freshness")}
        value={filters.freshness}
        onChange={(event) => updateFilter("freshness", event.target.value)}
      >
        <option value="">{t("search.anyFreshness")}</option>
        <option value="fresh">{t("freshness.fresh")}</option>
        <option value="aging">{t("freshness.aging")}</option>
        <option value="stale">{t("freshness.stale")}</option>
        <option value="connection_failed">
          {t("freshness.connection_failed")}
        </option>
        <option value="manual_import">{t("freshness.manual_import")}</option>
      </Select>
      <Select
        label={t("search.matchClass")}
        value={filters.match_class}
        onChange={(event) => updateFilter("match_class", event.target.value)}
      >
        <option value="">{t("search.anyMatchClass")}</option>
        <option value="exact">{t("match.exact")}</option>
        <option value="equivalent">{t("match.equivalent")}</option>
        <option value="alternative">{t("match.alternative")}</option>
        <option value="not_safely_comparable">
          {t("match.not_safely_comparable")}
        </option>
      </Select>
    </div>
  );

  return (
    <main className="ui-container search-comparison">
      <header className="ui-page-header">
        <div>
          <p className="eyebrow">{t("nav.workspace")}</p>
          <h1>{t("search.title")}</h1>
          <p>
            Find canonical products first, then compare only offers available to
            this location.
          </p>
        </div>
      </header>
      <FilterBar>
        <div className="search-comparison__input-row">
          <SearchInput
            label={t("search.title")}
            value={draftQuery}
            placeholder="Try tomato, tomate, or beef tomato 5x6 25 lb"
            onChange={(event) => setDraftQuery(event.target.value)}
            onKeyDown={(event) => {
              if (event.key === "ArrowDown")
                resultsRef.current
                  ?.querySelector<HTMLAnchorElement>("a")
                  ?.focus();
            }}
          />
          {draftQuery && (
            <Button variant="secondary" onClick={() => setDraftQuery("")}>
              {t("common.clear")}
            </Button>
          )}
          <Button variant="secondary" onClick={() => setFilterOpen(true)}>
            {t("search.filters")}
          </Button>
        </div>
        <div className="search-comparison__desktop-filters">{filterFields}</div>
      </FilterBar>
      <Drawer
        open={filterOpen}
        onClose={() => setFilterOpen(false)}
        title={t("search.searchFilters")}
        side="right"
      >
        {filterFields}
      </Drawer>
      {!query.trim() && (
        <>
          <EmptyState
            title="Start with a product or pack"
            description="Search supports English and Spanish catalog aliases, size codes, and safe pack evidence."
          />
          {recent.length > 0 && (
            <Card className="search-comparison__recent">
              <h2>{t("search.recentSearches")}</h2>
              <div>
                {recent.map((item) => (
                  <Button
                    key={item}
                    variant="secondary"
                    onClick={() => {
                      setDraftQuery(item);
                      setParams({ q: item, page: null });
                    }}
                  >
                    {item}
                  </Button>
                ))}
              </div>
            </Card>
          )}
        </>
      )}
      {loading && <Skeleton lines={6} label={t("common.loading")} />}
      {blocked && (
        <PermissionState
          title={t("common.notAvailable")}
          description="Your current workspace context cannot access this location or search data."
        />
      )}
      {!loading && error && !blocked && (
        <ErrorState title={t("search.title")} description={error} />
      )}
      {response?.partial.supplier_accounts.length ? (
        <PartialDataWarning>
          Some supplier data is incomplete; shown results include only safe
          available evidence.
        </PartialDataWarning>
      ) : null}
      {response && !loading && response.results.length === 0 && (
        <EmptyState
          title={t("search.noResults")}
          description="Try a broader product family, remove a filter, or check the spelling."
        />
      )}
      {response && !loading && response.results.length > 0 && (
        <div
          className="search-comparison__results"
          ref={resultsRef}
          aria-live="polite"
        >
          {response.results.map((result) => (
            <Card
              key={result.canonical_product.id}
              className="search-result-card"
            >
              <div>
                <p className="eyebrow">
                  {result.canonical_product.category.english_name}
                </p>
                <h2>{result.canonical_product.english_name}</h2>
                <p>
                  {result.variants
                    .map((variant) => variant.code)
                    .filter(Boolean)
                    .join(", ") || "Canonical product"}
                </p>
              </div>
              <div className="search-result-card__summary">
                <strong>
                  {result.offer_count}{" "}
                  {t("availability.available").toLocaleLowerCase()}
                </strong>
                <span>{price(result)}</span>
                <span>
                  {result.supplier_count
                    ? result.suppliers
                        .map((supplier) => supplier.display_name)
                        .join(", ")
                    : "No private supplier offers"}
                </span>
              </div>
              <div className="search-result-card__badges">
                {(
                  [
                    "fresh",
                    "aging",
                    "stale",
                    "connection_failed",
                    "manual_import",
                  ] as const
                )
                  .filter((state) => result.freshness_summary[state] > 0)
                  .map((state) => (
                    <FreshnessBadge key={state} state={state} />
                  ))}
                {result.match_quality_summary.alternative > 0 && (
                  <Badge tone="warning">Alternatives included</Badge>
                )}
                {result.match_quality_summary.not_safely_comparable > 0 && (
                  <Badge tone="warning">Some offers need review</Badge>
                )}
              </div>
              <div className="search-result-card__actions">
                <Link
                  className="ui-button ui-button--primary"
                  href={`/o/${organization.slug}/compare/${result.canonical_product.id}`}
                >
                  {t("comparison.title")}
                </Link>
                <AddToSavedListControl
                  canonicalProductId={result.canonical_product.id}
                />
              </div>
            </Card>
          ))}
          {response.pagination.total_pages > 1 && (
            <nav className="ui-pagination" aria-label={t("search.pagination")}>
              <Button
                variant="secondary"
                disabled={response.pagination.page <= 1}
                onClick={() => movePage(response.pagination.page - 1)}
              >
                {t("common.back")}
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
                {t("common.next")}
              </Button>
            </nav>
          )}
        </div>
      )}
    </main>
  );
}
