"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type FormEvent,
} from "react";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import {
  Badge,
  Button,
  Card,
  ConfirmationDialog,
  DataTableShell,
  ErrorState,
  Input,
  PartialDataWarning,
  PermissionState,
  Select,
  Skeleton,
  Toast,
} from "@/components/ui";
import { apiBaseUrl, apiRequest, ApiClientError } from "@/lib/api";
import type { SupplierAccount } from "@/lib/customer-api";
import {
  importError,
  importFieldErrors,
  shouldPoll,
  terminal,
  type ImportDetail,
  type ImportDownloads,
  type ImportList,
  type ImportSummary,
  type ImportTemplates,
  type MappingRequest,
} from "@/lib/imports";
import { ImportMappingForm } from "./ImportMappingForm";
import { useImportPolling } from "./useImportPolling";

export function ImportCenter({ importId }: { importId?: string }) {
  const { organization } = useWorkspace();
  return (
    <ImportWorkspace
      key={`${organization.id}:${importId ?? "history"}`}
      importId={importId}
    />
  );
}

function ImportWorkspace({ importId }: { importId?: string }) {
  const {
    organization,
    subscription,
    locations,
    locationId: contextLocation,
  } = useWorkspace();
  const router = useRouter();
  const root = `/o/${organization.slug}/imports`;
  const tenant = `/api/v1/organizations/${organization.id}`;
  const base = `${tenant}/imports`;
  const canMutate = organization.role !== "viewer";
  const enabled =
    subscription?.capabilities.manual_imports_enabled === true &&
    subscription.subscription?.access_available === true;
  const [locationId, setLocationId] = useState(contextLocation);
  const [accounts, setAccounts] = useState<SupplierAccount[]>([]);
  const [accountsLoaded, setAccountsLoaded] = useState(false);
  const [supplierId, setSupplierId] = useState("");
  const [page, setPage] = useState(1);
  const [history, setHistory] = useState<ImportList | null>(null);
  const [detail, setDetail] = useState<ImportDetail | null>(null);
  const [templates, setTemplates] = useState<ImportTemplates["templates"]>([]);
  const [templatePage, setTemplatePage] = useState(1);
  const [templatePages, setTemplatePages] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);
  const [blocked, setBlocked] = useState(false);
  const [cancelOpen, setCancelOpen] = useState(false);
  const [notice, setNotice] = useState("");
  const busyRef = useRef(false);
  const epoch = useRef(0);
  const mounted = useRef(true);
  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);
  useEffect(() => {
    setLocationId(contextLocation);
    setSupplierId("");
  }, [contextLocation]);

  const refresh = useCallback(
    async (signal: AbortSignal) => {
      const current = epoch.current;
      try {
        if (importId) {
          const result = await apiRequest<ImportDetail>(
            `${base}/${encodeURIComponent(importId)}`,
            { signal },
          );
          if (!signal.aborted && current === epoch.current) {
            setDetail(result);
            if (terminal(result.import.status)) setNotice("");
            setError(null);
          }
        } else {
          const result = await apiRequest<ImportList>(`${base}?page=${page}`, {
            signal,
          });
          if (!signal.aborted && current === epoch.current) {
            setHistory(result);
            setError(null);
          }
        }
      } catch (e) {
        if (!signal.aborted && current === epoch.current) {
          setError(importError(e));
          if (
            e instanceof ApiClientError &&
            [401, 403, 404].includes(e.status)
          ) {
            setBlocked(true);
            setDetail(null);
            setHistory(null);
          }
        }
      }
    },
    [base, importId, page],
  );
  useEffect(() => {
    const controller = new AbortController();
    void refresh(controller.signal);
    return () => controller.abort();
  }, [refresh]);
  useEffect(() => {
    const controller = new AbortController();
    void apiRequest<{ supplier_accounts: SupplierAccount[] }>(
      `${tenant}/supplier-accounts`,
      { signal: controller.signal },
    )
      .then((result) => {
        if (!controller.signal.aborted) {
          setAccounts(result.supplier_accounts);
          setAccountsLoaded(true);
        }
      })
      .catch((e: unknown) => {
        if (!controller.signal.aborted) setError(importError(e));
      });
    return () => controller.abort();
  }, [tenant]);
  const editable =
    detail?.import.status === "needs_mapping" &&
    !detail.import.validation_started_at;
  const accountId = detail?.import.supplier_account_id;
  useEffect(() => {
    if (!editable || !accountId || !canMutate || !enabled) return;
    const controller = new AbortController();
    void apiRequest<ImportTemplates>(
      `${tenant}/import-mapping-templates?supplier_account_id=${accountId}&page=${templatePage}`,
      { signal: controller.signal },
    )
      .then((result) => {
        if (!controller.signal.aborted) {
          setTemplates(result.templates);
          setTemplatePages(result.pagination.total_pages);
        }
      })
      .catch((e: unknown) => {
        if (!controller.signal.aborted) setError(importError(e));
      });
    return () => controller.abort();
  }, [tenant, editable, accountId, templatePage, canMutate, enabled]);
  const active = detail
    ? shouldPoll(detail.import)
    : history?.imports.some(shouldPoll) === true;
  useImportPolling(active && !busy && !blocked, refresh);

  async function action(
    path: string,
    init: RequestInit,
    success: string,
  ): Promise<boolean> {
    if (busyRef.current) return false;
    busyRef.current = true;
    epoch.current++;
    setBusy(true);
    setError(null);
    setFields({});
    setNotice("");
    try {
      const result = await apiRequest<ImportDetail>(path, init);
      if (!mounted.current) return true;
      if (!importId) router.push(`${root}/${result.import.id}`);
      else {
        setDetail(result);
        setNotice(success);
      }
      return true;
    } catch (e) {
      if (mounted.current) {
        setError(importError(e));
        setFields(importFieldErrors(e));
      }
      return false;
    } finally {
      busyRef.current = false;
      if (mounted.current) setBusy(false);
    }
  }
  async function upload(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const body = new FormData(event.currentTarget);
    const file = body.get("file");
    if (
      !(file instanceof File) ||
      !file.size ||
      file.size > 10485760 ||
      !/\.(csv|xlsx)$/i.test(file.name)
    ) {
      setFields({
        file: "Choose a nonempty CSV or XLSX file, at most 10 MiB.",
      });
      return;
    }
    body.set("restaurant_location_id", locationId);
    body.set("supplier_account_id", supplierId);
    await action(base, { method: "POST", body }, "File uploaded.");
  }
  async function saveMapping(request: MappingRequest) {
    return action(
      `${base}/${importId}/mapping`,
      { method: "PUT", body: JSON.stringify(request) },
      "Mapping saved. Ready to validate.",
    );
  }
  async function download(kind: "file" | "errors") {
    if (!detail || busyRef.current) return;
    busyRef.current = true;
    setBusy(true);
    setError(null);
    try {
      const links = await apiRequest<ImportDownloads>(
        `${base}/${importId}/downloads`,
        { method: "POST" },
      );
      const url = new URL(
        kind === "file" ? links.file_url : links.errors_url,
        apiBaseUrl,
      );
      if (
        url.origin !== new URL(apiBaseUrl).origin ||
        !url.pathname.startsWith(`${base}/${importId}/`)
      )
        throw new Error("Invalid download reference");
      const response = await fetch(url, {
        credentials: "include",
        headers: { Accept: "application/json" },
        redirect: "error",
      });
      if (!response.ok) throw new Error("Download unavailable");
      const blob = await response.blob();
      const objectUrl = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = objectUrl;
      link.download =
        kind === "file" ? detail.file.original_filename : "import-errors.csv";
      document.body.append(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(objectUrl), 0);
      if (mounted.current) setNotice("Download ready.");
    } catch {
      if (mounted.current)
        setError(
          "Download unavailable or authorization expired. Try again to request a fresh link, or sign in again.",
        );
    } finally {
      busyRef.current = false;
      if (mounted.current) setBusy(false);
    }
  }
  const accountName = (id: string) =>
    accounts.find((a) => a.id === id)?.display_name ??
    "Supplier account unavailable";
  const locationName = (id: string) =>
    locations.find((l) => l.id === id)?.name ?? "Location unavailable";
  const activeLocations = locations.filter((l) => l.status === "active");
  const selectableAccounts = accounts.filter(
    (a) => a.location.id === locationId && a.status === "active",
  );
  return (
    <div className="workspace-page import-center">
      <header>
        <p className="eyebrow">Purchasing workspace</p>
        <h1>Import Center</h1>
        <p>
          Upload supplier files, confirm their columns, and follow each import
          through validation and normalization.
        </p>
      </header>
      {importId && <Link href={root}>Back to import history</Link>}
      {error && (
        <ErrorState
          title="Import action unavailable"
          description={error}
          action={
            <Button
              variant="secondary"
              disabled={busy}
              onClick={() => {
                setBlocked(false);
                void refresh(new AbortController().signal);
              }}
            >
              Retry loading
            </Button>
          }
        />
      )}
      {notice && <Toast title={notice} />}
      {!canMutate && (
        <PermissionState
          title="Read-only access"
          description="You can review import history and download authorized files. An owner or buyer must upload, map, start or cancel imports."
        />
      )}
      {!enabled && (
        <PermissionState
          title="Manual imports unavailable"
          description="Your current subscription does not enable manual imports. History and authorized downloads remain available. Contact your administrator."
          action={
            <Link href={`/o/${organization.slug}/subscription`}>
              View subscription
            </Link>
          }
        />
      )}
      {!blocked && !importId && (
        <>
          {canMutate && enabled && (
            <Card>
              <h2>New import</h2>
              {activeLocations.length === 0 ? (
                <p>
                  No active location.{" "}
                  <Link href={`/o/${organization.slug}/locations`}>
                    Configure locations
                  </Link>{" "}
                  or ask your owner.
                </p>
              ) : (
                <form className="import-form" onSubmit={(e) => void upload(e)}>
                  <Select
                    label="Import location"
                    value={locationId}
                    required
                    disabled={busy}
                    error={fields.restaurant_location_id}
                    onChange={(e) => {
                      setLocationId(e.target.value);
                      setSupplierId("");
                    }}
                  >
                    <option value="">Choose location</option>
                    {activeLocations.map((l) => (
                      <option key={l.id} value={l.id}>
                        {l.name}
                      </option>
                    ))}
                  </Select>
                  <Select
                    label="Supplier account"
                    value={supplierId}
                    required
                    disabled={busy || !locationId}
                    error={fields.supplier_account_id}
                    onChange={(e) => setSupplierId(e.target.value)}
                  >
                    <option value="">Choose supplier account</option>
                    {selectableAccounts.map((a) => (
                      <option key={a.id} value={a.id}>
                        {a.display_name}
                      </option>
                    ))}
                  </Select>
                  {accountsLoaded &&
                    locationId &&
                    selectableAccounts.length === 0 && (
                      <p>
                        No active supplier account for this location.{" "}
                        <Link href={`/o/${organization.slug}/suppliers`}>
                          Configure supplier accounts
                        </Link>{" "}
                        or ask your owner.
                      </p>
                    )}
                  <Input
                    label="Supplier file"
                    name="file"
                    type="file"
                    accept=".csv,.xlsx"
                    required
                    disabled={busy}
                    hint="CSV or XLSX · maximum 10 MiB. Files stay private. Do not include credentials."
                    error={fields.file}
                  />
                  <Button
                    type="submit"
                    disabled={
                      busy ||
                      !supplierId ||
                      !selectableAccounts.some((a) => a.id === supplierId)
                    }
                  >
                    {busy ? "Uploading…" : "Upload file"}
                  </Button>
                </form>
              )}
            </Card>
          )}
          <Card>
            <h2>Import history</h2>
            <DataTableShell
              caption="import history"
              loading={!history && !error}
              empty={history?.imports.length === 0}
            >
              <table>
                <caption>Recent imports, newest first</caption>
                <thead>
                  <tr>
                    <th>Created</th>
                    <th>Location</th>
                    <th>Supplier</th>
                    <th>Status</th>
                    <th>Processed</th>
                    <th>Review</th>
                  </tr>
                </thead>
                <tbody>
                  {history?.imports.map((run) => (
                    <tr key={run.id}>
                      <td>{date(run.created_at)}</td>
                      <td>{locationName(run.restaurant_location_id)}</td>
                      <td>{accountName(run.supplier_account_id)}</td>
                      <td>
                        <ImportStatusBadge run={run} />
                      </td>
                      <td>
                        {run.processed_count} / {run.valid_rows}
                      </td>
                      <td>
                        <Link href={`${root}/${run.id}`}>View import</Link>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </DataTableShell>
            <div className="import-actions">
              <Button
                variant="secondary"
                disabled={page <= 1}
                onClick={() => setPage(page - 1)}
              >
                Previous imports
              </Button>
              <span>
                Page {page} of{" "}
                {Math.max(1, history?.pagination.total_pages ?? 1)}
              </span>
              <Button
                variant="secondary"
                disabled={!history || page >= history.pagination.total_pages}
                onClick={() => setPage(page + 1)}
              >
                Next imports
              </Button>
            </div>
          </Card>
        </>
      )}
      {!blocked && importId && !detail && !error && (
        <Skeleton label="Loading import" />
      )}
      {!blocked && detail && (
        <>
          <Card>
            <h2>
              {terminal(detail.import.status)
                ? "Import summary"
                : "Import progress"}
            </h2>
            <div role="status" aria-live="polite" aria-atomic="true">
              <ImportStatusBadge run={detail.import} />
            </div>
            <dl>
              <div>
                <dt>Source file</dt>
                <dd>{detail.file.original_filename}</dd>
              </div>
              <div>
                <dt>Location</dt>
                <dd>{locationName(detail.import.restaurant_location_id)}</dd>
              </div>
              <div>
                <dt>Supplier account</dt>
                <dd>{accountName(detail.import.supplier_account_id)}</dd>
              </div>
              <div>
                <dt>Created</dt>
                <dd>{date(detail.import.created_at)}</dd>
              </div>
              <div>
                <dt>Finished</dt>
                <dd>
                  {detail.import.finished_at
                    ? date(detail.import.finished_at)
                    : "Not finished"}
                </dd>
              </div>
            </dl>
            <dl className="import-counts">
              {(
                [
                  [
                    "Total rows",
                    detail.import.valid_rows +
                      detail.import.failed_count -
                      detail.import.normalization_failed_count,
                  ],
                  ["Eligible rows", detail.import.valid_rows],
                  ["Processed", detail.import.processed_count],
                  ["Warnings", detail.import.warning_count],
                  ["Failed rows", detail.import.failed_count],
                  ["Remaining", detail.import.remaining_count],
                ] as const
              ).map(([label, count]) => (
                <div key={label}>
                  <dt>{label}</dt>
                  <dd
                    data-testid={`import-${label.toLowerCase().replaceAll(" ", "-")}`}
                  >
                    {count}
                  </dd>
                </div>
              ))}
            </dl>
            {detail.import.status === "processing" && (
              <progress
                aria-label="Normalization progress"
                max={Math.max(1, detail.import.valid_rows)}
                value={
                  detail.import.processed_count +
                  detail.import.normalization_failed_count
                }
              />
            )}
            {detail.import.failed_count > 0 && (
              <PartialDataWarning>
                {terminal(detail.import.status)
                  ? "This import ended with failed rows. Successful results are retained; review or download the error report."
                  : detail.import.valid_rows > 0
                    ? "Some source rows failed. Valid rows can proceed after validation; review the errors below."
                    : "No eligible rows are available. Review the errors and prepare a corrected file."}
              </PartialDataWarning>
            )}
            {detail.import.warning_count > 0 && (
              <p>
                Warnings preserve uncertain values for review. They are not
                failed rows.
              </p>
            )}
            {detail.import.status === "canceled" && (
              <p>
                Canceled. Previously processed rows and source history are
                retained.
              </p>
            )}
            <div className="import-actions">
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() => void download("file")}
              >
                Download source file
              </Button>
              {detail.errors.length > 0 && (
                <Button
                  variant="secondary"
                  disabled={busy}
                  onClick={() => void download("errors")}
                >
                  Download error report
                </Button>
              )}
              {canMutate && !terminal(detail.import.status) && (
                <Button
                  variant="danger"
                  disabled={busy}
                  onClick={() => setCancelOpen(true)}
                >
                  Cancel import
                </Button>
              )}
            </div>
          </Card>
          <Card>
            <h2>Source preview</h2>
            <p>
              {detail.file.format.toUpperCase()} ·{" "}
              {detail.file.size_bytes.toLocaleString()} bytes · first{" "}
              {detail.file.sample_rows.length} rows only. Original strings are
              preserved.
            </p>
            <DataTableShell caption="source preview">
              <table className="import-preview">
                <caption>Detected headers and bounded sample rows</caption>
                <thead>
                  <tr>
                    <th>Source row</th>
                    {detail.file.headers.map((header) => (
                      <th key={header}>{header}</th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {detail.file.sample_rows.map((row) => (
                    <tr key={row.source_row}>
                      <th scope="row">{row.source_row}</th>
                      {row.values.map((value, i) => (
                        <td key={i}>
                          {value || <span aria-label="Empty value">—</span>}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </DataTableShell>
          </Card>
          {editable && canMutate && enabled && (
            <Card>
              <h2>Column mapping</h2>
              <ImportMappingForm
                key={JSON.stringify(detail.import.mapping_snapshot)}
                detail={detail}
                templates={templates}
                pending={busy}
                onSave={saveMapping}
                serverErrors={fields}
              />
              {templatePages > 1 && (
                <div className="import-actions">
                  <Button
                    variant="secondary"
                    disabled={templatePage <= 1}
                    onClick={() => setTemplatePage(templatePage - 1)}
                  >
                    Previous templates
                  </Button>
                  <Button
                    variant="secondary"
                    disabled={templatePage >= templatePages}
                    onClick={() => setTemplatePage(templatePage + 1)}
                  >
                    More templates
                  </Button>
                </div>
              )}
            </Card>
          )}
          {detail.import.mapping_snapshot && !editable && (
            <Card>
              <h2>Confirmed mapping</h2>
              <dl>
                {Object.entries(detail.import.mapping_snapshot.mapping).map(
                  ([field, column]) => (
                    <div key={field}>
                      <dt>{field.replaceAll("_", " ")}</dt>
                      <dd>{column}</dd>
                    </div>
                  ),
                )}
              </dl>
              <p>The mapping is locked after validation begins.</p>
            </Card>
          )}
          {detail.import.status === "needs_mapping" && (
            <Card>
              <h2>Validation</h2>
              <p role="status">
                {detail.import.validation_completed_at
                  ? "Validation complete."
                  : detail.import.validation_started_at
                    ? "Validating source rows."
                    : "Save a mapping, then validate the source rows."}
              </p>
              {canMutate && enabled && (
                <div className="import-actions">
                  {!detail.import.validation_completed_at && (
                    <Button
                      disabled={busy || !detail.import.mapping_snapshot}
                      onClick={() =>
                        void action(
                          `${base}/${importId}/validate`,
                          { method: "POST" },
                          "Validation queued.",
                        )
                      }
                    >
                      {busy
                        ? "Working…"
                        : detail.import.validation_started_at
                          ? "Resume validation"
                          : "Validate rows"}
                    </Button>
                  )}
                  {detail.import.validation_completed_at &&
                    detail.import.valid_rows > 0 && (
                      <Button
                        disabled={busy}
                        onClick={() =>
                          void action(
                            `${base}/${importId}/start`,
                            { method: "POST" },
                            "Import queued. Processing continues if you navigate away.",
                          )
                        }
                      >
                        {busy ? "Starting…" : "Start import"}
                      </Button>
                    )}
                </div>
              )}
            </Card>
          )}
          {canMutate &&
            enabled &&
            ["queued", "processing"].includes(detail.import.status) && (
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() =>
                  void action(
                    `${base}/${importId}/start`,
                    { method: "POST" },
                    "Remaining work requeued safely.",
                  )
                }
              >
                Resume processing
              </Button>
            )}
          {detail.errors.length > 0 && (
            <Card>
              <h2>Import errors</h2>
              <p>
                Showing up to 100 errors. Download the error report for all
                rows.
              </p>
              <DataTableShell caption="import errors">
                <table>
                  <caption>Source row and field errors</caption>
                  <thead>
                    <tr>
                      <th>Source row</th>
                      <th>Field</th>
                      <th>Issue</th>
                    </tr>
                  </thead>
                  <tbody>
                    {detail.errors.map((issue, i) => (
                      <tr key={i}>
                        <td>{issue.source_row ?? "File"}</td>
                        <td>{issue.field ?? "—"}</td>
                        <td>{issue.message}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </DataTableShell>
            </Card>
          )}
          <ConfirmationDialog
            open={cancelOpen}
            onClose={() => !busy && setCancelOpen(false)}
            title="Cancel this import?"
            confirmLabel="Confirm cancellation"
            pending={busy}
            onConfirm={() =>
              void action(
                `${base}/${importId}/cancel`,
                { method: "POST" },
                "Import canceled. History is retained.",
              ).then((ok) => {
                if (ok) setCancelOpen(false);
              })
            }
          >
            <p>
              Future batches will stop at a safe boundary. Source files and
              completed results will not be deleted.
            </p>
          </ConfirmationDialog>
        </>
      )}
    </div>
  );
}

function date(value: string) {
  return new Date(value).toLocaleString();
}
export function ImportStatusBadge({ run }: { run: ImportSummary }) {
  const label =
    run.status === "needs_mapping" && run.validation_started_at
      ? run.validation_completed_at
        ? "Validated"
        : "Validating"
      : run.status.replaceAll("_", " ");
  return (
    <Badge
      tone={
        run.status === "completed"
          ? "success"
          : run.status === "failed"
            ? "danger"
            : run.status === "partially_completed"
              ? "warning"
              : "neutral"
      }
    >
      {label}
    </Badge>
  );
}
