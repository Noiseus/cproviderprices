"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useSearchParams } from "next/navigation";
import type { components } from "@cproviderprices/generated-api-client";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import { useTranslation } from "@/components/i18n/I18nProvider";
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
  const { organization, locationId, locations } = useWorkspace();
  const { formatDate, formatDateTime, formatMoney, localizeError, t } =
    useTranslation();
  const timeZone =
    (locations ?? []).find((location) => location.id === locationId)
      ?.timezone ?? "UTC";
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
          reason instanceof ApiClientError
            ? localizeError(reason.code)
            : localizeError("network_error"),
        );
        return false;
      } finally {
        if (!signal.aborted && current === epoch.current) setLoading(false);
      }
    },
    [filters, localizeError, locationId, organization.id, productId],
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
          title={t("history.chooseProduct")}
          description={t("history.chooseProductDescription")}
        />
      </main>
    );
  }

  return (
    <main className="ui-container price-history">
      <header className="ui-page-header">
        <div>
          <p className="eyebrow">{t("history.title")}</p>
          <h1>
            {response?.canonical_product.english_name ??
              t("history.productHistory")}
          </h1>
          <p>{t("history.description")}</p>
        </div>
        <div className="price-history__actions">
          <Button onClick={() => void refreshNow()} variant="secondary">
            {t("history.refresh")}
          </Button>
          <Button
            onClick={() => setSyncNotice(t("history.syncNotice"))}
            variant="quiet"
          >
            {t("history.syncSupplier")}
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
            label={t("history.from")}
            type="date"
            value={draft.from}
            onChange={(event) =>
              setDraft({ ...draft, from: event.target.value })
            }
          />
          <Input
            label={t("history.to")}
            type="date"
            value={draft.to}
            onChange={(event) => setDraft({ ...draft, to: event.target.value })}
          />
          <Select
            label={t("history.supplierFilter")}
            value={draft.supplier}
            onChange={(event) =>
              setDraft({ ...draft, supplier: event.target.value })
            }
          >
            <option value="">{t("history.allSuppliers")}</option>
            {accounts.map((account) => (
              <option key={account.id} value={account.id}>
                {account.display_name}
              </option>
            ))}
          </Select>
          <Select
            label={t("history.compatibleUnit")}
            value={draft.dimension}
            onChange={(event) =>
              setDraft({ ...draft, dimension: event.target.value })
            }
          >
            <option value="">{t("history.allSafeUnits")}</option>
            <option value="weight">{t("history.weight")}</option>
            <option value="volume">{t("history.volume")}</option>
            <option value="count">{t("history.count")}</option>
          </Select>
          <Button type="submit">{t("history.applyFilters")}</Button>
        </form>
      </Card>
      {syncNotice && <PartialDataWarning>{syncNotice}</PartialDataWarning>}
      {response && (isStale || error) && (
        <PartialDataWarning>
          {t("history.trustedDataNotice")}
        </PartialDataWarning>
      )}
      {loading && !response && (
        <Skeleton lines={8} label={t("history.loading")} />
      )}
      {blocked && (
        <ErrorState
          title={t("history.unavailable")}
          description={t("history.unavailableDescription")}
        />
      )}
      {!loading && error && !blocked && !response && (
        <ErrorState title={t("history.unableToLoad")} description={error} />
      )}
      {response && (
        <HistoryData
          formatDate={formatDate}
          formatDateTime={formatDateTime}
          formatMoney={formatMoney}
          response={response}
          timeZone={timeZone}
        />
      )}
    </main>
  );
}

function HistoryData({
  response,
  timeZone,
  formatDate,
  formatDateTime,
  formatMoney,
}: {
  response: PriceHistoryResponse;
  timeZone: string;
  formatDate: (value: string | Date, timeZone?: string) => string;
  formatDateTime: (value: string | Date, timeZone?: string) => string;
  formatMoney: (value: number | string, currency: string) => string;
}) {
  const { t } = useTranslation();
  const chartSummary = useMemo(() => {
    if (response.series.length === 0) return t("history.noSafePoints");
    return t("history.chartSummary", {
      count: response.series.length,
      suffix: response.series.length === 1 ? "" : "s",
    });
  }, [response.series.length, t]);
  return (
    <>
      <Card className="price-history__summary">
        <strong>{response.location.name}</strong>
        <span>{response.aggregation.method.replaceAll("_", " ")}</span>
        <span>
          {response.aggregation.state === "isolated_incompatible_units"
            ? t("history.isolatedUnits")
            : t("history.aggregatedUnits")}
        </span>
        {response.aggregation.excluded_unsafe_count > 0 && (
          <span>
            {t("history.excludedUnsafe", {
              count: response.aggregation.excluded_unsafe_count,
              suffix:
                response.aggregation.excluded_unsafe_count === 1 ? "" : "s",
            })}
          </span>
        )}
      </Card>
      <Card>
        <h2>{t("history.accessibleChart")}</h2>
        <p id="history-chart-summary">{chartSummary}</p>
        <PriceHistoryChart points={response.series} summary={chartSummary} />
      </Card>
      <Card>
        <h2>{t("history.dailyValues")}</h2>
        {response.series.length === 0 ? (
          <EmptyState
            title={t("history.noChartPoints")}
            description={t("history.noChartPointsDescription")}
          />
        ) : (
          <div className="ui-table-scroll">
            <table>
              <thead>
                <tr>
                  <th>{t("history.date")}</th>
                  <th>{t("history.supplierFilter")}</th>
                  <th>{t("history.normalized")}</th>
                  <th>{t("history.unit")}</th>
                  <th>{t("history.observations")}</th>
                </tr>
              </thead>
              <tbody>
                {response.series.map((point) => (
                  <tr
                    key={`${point.date}-${point.supplier.id}-${point.dimension}`}
                  >
                    <td>{formatDate(`${point.date}T12:00:00Z`, timeZone)}</td>
                    <td>{point.supplier.display_name}</td>
                    <td>
                      {formatMoney(point.normalized_price, point.currency)}
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
        <h2>{t("history.immutableObservations")}</h2>
        {response.observations.length === 0 ? (
          <EmptyState
            title={t("history.noObservations")}
            description={t("history.noObservationsDescription")}
          />
        ) : (
          <div className="ui-table-scroll">
            <table>
              <thead>
                <tr>
                  <th>{t("history.observed")}</th>
                  <th>{t("history.supplierFilter")}</th>
                  <th>{t("history.casePrice")}</th>
                  <th>{t("history.normalized")}</th>
                  <th>{t("comparison.freshness")}</th>
                  <th>{t("history.aggregationStatus")}</th>
                </tr>
              </thead>
              <tbody>
                {response.observations.map((observation) => (
                  <tr key={observation.id}>
                    <td>
                      <time dateTime={observation.observed_at}>
                        {formatDateTime(observation.observed_at, timeZone)}
                      </time>
                    </td>
                    <td>{observation.supplier.display_name}</td>
                    <td>
                      {formatMoney(
                        observation.case_price,
                        observation.currency,
                      )}
                    </td>
                    <td>
                      {observation.normalized_price
                        ? `${formatMoney(observation.normalized_price, observation.currency)}/${observation.normalized_unit}`
                        : t("history.unsafeUnavailable")}
                    </td>
                    <td>
                      <FreshnessBadge
                        state={observation.freshness}
                        timestamp={observation.observed_at}
                        timeZone={timeZone}
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
