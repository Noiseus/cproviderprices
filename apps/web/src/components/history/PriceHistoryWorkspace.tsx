"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import type { components } from "@cproviderprices/generated-api-client";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import { useVisibleStaleRefresh } from "@/components/refresh/useVisibleRefresh";
import {
  Button,
  Card,
  EmptyState,
  ErrorState,
  FreshnessBadge,
  Input,
  PartialDataWarning,
  Select,
  Skeleton,
} from "@/components/ui";
import { ApiClientError, apiRequest } from "@/lib/api";
import type { SupplierAccount } from "@/lib/customer-api";

type PriceHistoryResponse = components["schemas"]["PriceHistoryResponse"];

function defaultRange() {
  const to = new Date();
  const from = new Date(to);
  from.setDate(from.getDate() - 89);
  return {
    from: from.toISOString().slice(0, 10),
    to: to.toISOString().slice(0, 10),
    supplier: "",
    dimension: "",
  };
}

export function PriceHistoryWorkspace() {
  const { organization, locationId } = useWorkspace();
  const searchParams = useSearchParams();
  const productId = searchParams.get("product") ?? "";
  const [draft, setDraft] = useState(defaultRange);
  const [filters, setFilters] = useState(defaultRange);
  const [response, setResponse] = useState<PriceHistoryResponse | null>(null);
  const responseRef = useRef<PriceHistoryResponse | null>(null);
  const [accounts, setAccounts] = useState<SupplierAccount[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [blocked, setBlocked] = useState(false);
  const [syncNotice, setSyncNotice] = useState<string | null>(null);
  const epoch = useRef(0);

  useEffect(() => {
    setResponse(null);
    responseRef.current = null;
  }, [productId]);

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

  const load = useCallback(
    async (signal: AbortSignal): Promise<boolean> => {
      if (!locationId || !productId) return false;
      const current = ++epoch.current;
      const parameters = new URLSearchParams({
        location_id: locationId,
        from: filters.from,
        to: filters.to,
      });
      if (filters.supplier)
        parameters.append("supplier_id[]", filters.supplier);
      if (filters.dimension) parameters.set("dimension", filters.dimension);
      setLoading(true);
      setBlocked(false);
      try {
        const payload = await apiRequest<PriceHistoryResponse>(
          `/api/v1/organizations/${organization.id}/price-history/${encodeURIComponent(productId)}?${parameters.toString()}`,
          { signal },
        );
        if (signal.aborted || current !== epoch.current) return false;
        setResponse(payload);
        responseRef.current = payload;
        setError(null);
        return true;
      } catch (reason: unknown) {
        if (signal.aborted || current !== epoch.current) return false;
        if (responseRef.current === null) setResponse(null);
        if (
          reason instanceof ApiClientError &&
          [401, 403, 404].includes(reason.status)
        )
          setBlocked(true);
        setError(
          reason instanceof Error
            ? reason.message
            : "Price history is unavailable.",
        );
        return false;
      } finally {
        if (!signal.aborted && current === epoch.current) setLoading(false);
      }
    },
    [filters, locationId, organization.id, productId],
  );

  const { isStale, markFresh, refreshNow } = useVisibleStaleRefresh(
    300_000,
    load,
    Boolean(locationId && productId),
  );

  useEffect(() => {
    if (!productId) return;
    const controller = new AbortController();
    void load(controller.signal).then((succeeded) => {
      if (succeeded && !controller.signal.aborted) markFresh();
    });
    return () => controller.abort();
  }, [load, markFresh, productId]);

  if (!productId) {
    return (
      <main className="ui-container">
        <EmptyState
          title="Choose a product to view history"
          description="Open Price history from a product comparison so CProviderPrices can keep the canonical product and your authorized location in context."
        />
      </main>
    );
  }

  return (
    <main className="ui-container price-history">
      <header className="ui-page-header">
        <div>
          <p className="eyebrow">Price history</p>
          <h1>
            {response?.canonical_product.english_name ?? "Product history"}
          </h1>
          <p>
            Daily averages are calculated server-side with exact decimals and
            only within the same supplier, currency, and compatible unit.
          </p>
        </div>
        <div className="price-history__actions">
          <Button onClick={() => void refreshNow()} variant="secondary">
            Refresh CProviderPrices data
          </Button>
          <Button
            onClick={() =>
              setSyncNotice(
                "Supplier Sync is a future connector action. Refresh does not contact supplier systems.",
              )
            }
            variant="quiet"
          >
            Sync supplier
          </Button>
        </div>
      </header>
      <Card>
        <form
          className="price-history__filters"
          onSubmit={(event) => {
            event.preventDefault();
            setFilters(draft);
          }}
        >
          <Input
            label="From"
            type="date"
            value={draft.from}
            onChange={(event) =>
              setDraft({ ...draft, from: event.target.value })
            }
          />
          <Input
            label="To"
            type="date"
            value={draft.to}
            onChange={(event) => setDraft({ ...draft, to: event.target.value })}
          />
          <Select
            label="Supplier"
            value={draft.supplier}
            onChange={(event) =>
              setDraft({ ...draft, supplier: event.target.value })
            }
          >
            <option value="">All suppliers</option>
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.display_name}
              </option>
            ))}
          </Select>
          <Select
            label="Compatible unit"
            value={draft.dimension}
            onChange={(event) =>
              setDraft({ ...draft, dimension: event.target.value })
            }
          >
            <option value="">All safe units (isolated)</option>
            <option value="weight">Weight</option>
            <option value="volume">Volume</option>
            <option value="count">Count</option>
          </Select>
          <Button type="submit">Apply filters</Button>
        </form>
      </Card>
      {syncNotice && <PartialDataWarning>{syncNotice}</PartialDataWarning>}
      {response && (isStale || error) && (
        <PartialDataWarning>
          Showing the last trusted history data. Refresh checks only the
          CProviderPrices records already available to this workspace.
        </PartialDataWarning>
      )}
      {loading && !response && (
        <Skeleton lines={8} label="Loading price history" />
      )}
      {blocked && (
        <ErrorState
          title="Price history is unavailable"
          description="Your current workspace context cannot access this product or location."
        />
      )}
      {!loading && error && !blocked && !response && (
        <ErrorState title="Unable to load price history" description={error} />
      )}
      {response && <HistoryData response={response} />}
    </main>
  );
}

function HistoryData({ response }: { response: PriceHistoryResponse }) {
  const chartSummary = useMemo(() => {
    if (response.series.length === 0)
      return "No safely normalized price points are available for the selected filters.";
    return `${response.series.length} daily compatible-unit price point${response.series.length === 1 ? "" : "s"} are shown below with an equivalent data table.`;
  }, [response.series.length]);
  return (
    <>
      <Card className="price-history__summary">
        <strong>{response.location.name}</strong>
        <span>{response.aggregation.method.replaceAll("_", " ")}</span>
        <span>
          {response.aggregation.state === "isolated_incompatible_units"
            ? "Incompatible units are isolated; no cross-unit value was calculated."
            : "Compatible units are aggregated separately by supplier."}
        </span>
        {response.aggregation.excluded_unsafe_count > 0 && (
          <span>
            {response.aggregation.excluded_unsafe_count} unsafe observation
            {response.aggregation.excluded_unsafe_count === 1
              ? " was"
              : "s were"}{" "}
            retained in the table but excluded from the chart.
          </span>
        )}
      </Card>
      <Card>
        <h2>Accessible price chart</h2>
        <p id="history-chart-summary">{chartSummary}</p>
        <PriceHistoryChart points={response.series} summary={chartSummary} />
      </Card>
      <Card>
        <h2>Daily compatible-unit values</h2>
        {response.series.length === 0 ? (
          <EmptyState
            title="No safe chart points"
            description="The raw observation table remains available below so unavailable, variable-weight, and incompatible evidence is never hidden."
          />
        ) : (
          <div className="ui-table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Supplier</th>
                  <th>Normalized price</th>
                  <th>Unit</th>
                  <th>Observations</th>
                </tr>
              </thead>
              <tbody>
                {response.series.map((point) => (
                  <tr
                    key={`${point.date}-${point.supplier.id}-${point.dimension}`}
                  >
                    <td>{point.date}</td>
                    <td>{point.supplier.display_name}</td>
                    <td>
                      {point.currency} {point.normalized_price}
                    </td>
                    <td>{point.normalized_unit}</td>
                    <td>{point.observation_count}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
      <Card>
        <h2>Immutable source observations</h2>
        {response.observations.length === 0 ? (
          <EmptyState
            title="No price observations"
            description="Try a wider date range or another supplier."
          />
        ) : (
          <div className="ui-table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Observed</th>
                  <th>Supplier</th>
                  <th>Case price</th>
                  <th>Normalized price</th>
                  <th>Freshness</th>
                  <th>Aggregation status</th>
                </tr>
              </thead>
              <tbody>
                {response.observations.map((observation) => (
                  <tr key={observation.id}>
                    <td>
                      <time dateTime={observation.observed_at}>
                        {new Date(observation.observed_at).toLocaleString()}
                      </time>
                    </td>
                    <td>{observation.supplier.display_name}</td>
                    <td>
                      {observation.currency} {observation.case_price}
                    </td>
                    <td>
                      {observation.normalized_price
                        ? `${observation.currency} ${observation.normalized_price}/${observation.normalized_unit}`
                        : "Not safely available"}
                    </td>
                    <td>
                      <FreshnessBadge
                        state={observation.freshness}
                        timestamp={observation.observed_at}
                      />
                    </td>
                    <td>
                      {observation.aggregation_state.replaceAll("_", " ")}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </>
  );
}

function PriceHistoryChart({
  points,
  summary,
}: {
  points: PriceHistoryResponse["series"];
  summary: string;
}) {
  const width = 640;
  const height = 220;
  const padding = 28;
  const groups = useMemo(() => {
    const bySupplier = new Map<string, typeof points>();
    for (const point of points) {
      const key = `${point.supplier.id}-${point.dimension}-${point.currency}`;
      bySupplier.set(key, [...(bySupplier.get(key) ?? []), point]);
    }
    return [...bySupplier.values()];
  }, [points]);
  const values = points
    .map((point) => Number(point.normalized_price))
    .filter(Number.isFinite);
  if (values.length === 0) return null;
  const minimum = Math.min(...values);
  const maximum = Math.max(...values);
  const dateValues = points
    .map((point) => Date.parse(point.date))
    .filter(Number.isFinite);
  const firstDate = Math.min(...dateValues);
  const lastDate = Math.max(...dateValues);
  const x = (date: string) => {
    const value = Date.parse(date);
    const ratio =
      lastDate === firstDate
        ? 0.5
        : (value - firstDate) / (lastDate - firstDate);
    return padding + ratio * (width - padding * 2);
  };
  const y = (value: string) => {
    const numeric = Number(value);
    const ratio =
      maximum === minimum ? 0.5 : (numeric - minimum) / (maximum - minimum);
    return height - padding - ratio * (height - padding * 2);
  };
  return (
    <svg
      className="price-history__chart"
      viewBox={`0 0 ${width} ${height}`}
      role="img"
      tabIndex={0}
      aria-describedby="history-chart-summary"
      aria-label={summary}
    >
      <line x1={padding} x2={padding} y1={padding} y2={height - padding} />
      <line
        x1={padding}
        x2={width - padding}
        y1={height - padding}
        y2={height - padding}
      />
      {groups.map((group, index) => (
        <g key={`${group[0]?.supplier.id}-${index}`}>
          <polyline
            fill="none"
            points={group
              .map((point) => `${x(point.date)},${y(point.normalized_price)}`)
              .join(" ")}
            className={`price-history__line price-history__line--${index % 4}`}
          />
          {group.map((point) => (
            <circle
              key={`${point.date}-${point.supplier.id}`}
              cx={x(point.date)}
              cy={y(point.normalized_price)}
              r="4"
              className={`price-history__point price-history__line--${index % 4}`}
            >
              <title>{`${point.date}: ${point.supplier.display_name}, ${point.currency} ${point.normalized_price}/${point.normalized_unit}`}</title>
            </circle>
          ))}
        </g>
      ))}
    </svg>
  );
}
