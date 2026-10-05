"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import { ApiClientError, apiRequest } from "@/lib/api";
import type {
  Location,
  SupplierAccount,
  SupplierDefinition,
} from "@/lib/customer-api";
import { Badge, Button, Card, Input, Select, Toast } from "@/components/ui";
import { useTranslation } from "@/components/i18n/I18nProvider";

export function SupplierAccountManager({
  locations,
}: {
  locations: Location[];
}) {
  const { organization } = useWorkspace();
  const { formatDateTime, localizeError, t } = useTranslation();
  const owner = organization.role === "organization_owner";
  const base = `/api/v1/organizations/${organization.id}/supplier-accounts`;
  const [locationId, setLocationId] = useState(
    locations.find((x) => x.status === "active")?.id ?? "",
  );
  const [accounts, setAccounts] = useState<SupplierAccount[]>([]);
  const [definitions, setDefinitions] = useState<SupplierDefinition[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  async function reload(id = locationId) {
    try {
      const q = id ? `?location_id=${encodeURIComponent(id)}` : "";
      const r = await apiRequest<{
        supplier_accounts: SupplierAccount[];
        supplier_definitions: SupplierDefinition[];
      }>(base + q);
      setAccounts(r.supplier_accounts);
      setDefinitions(r.supplier_definitions);
      setError(null);
    } catch (e) {
      setError(message(e, localizeError));
    }
  }
  useEffect(() => void reload(), [base, locationId]);
  async function submit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    const f = e.currentTarget,
      d = new FormData(f);
    setBusy(true);
    try {
      await apiRequest(base, {
        method: "POST",
        body: JSON.stringify({
          restaurant_location_id: locationId,
          supplier_definition_id: d.get("supplier_definition_id"),
          display_name: d.get("display_name"),
          masked_account_reference: d.get("masked_account_reference") || null,
          source_method: d.get("source_method"),
          expected_refresh_cadence: d.get("expected_refresh_cadence"),
        }),
      });
      f.reset();
      await reload();
    } catch (x) {
      setError(message(x, localizeError));
    } finally {
      setBusy(false);
    }
  }
  async function edit(account: SupplierAccount) {
    const display_name = window.prompt(
      t("suppliers.displayName"),
      account.display_name,
    );
    if (!display_name) return;
    setBusy(true);
    try {
      await apiRequest(`${base}/${account.id}`, {
        method: "PATCH",
        body: JSON.stringify({ display_name }),
      });
      await reload();
    } catch (e) {
      setError(message(e, localizeError));
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="workspace-page">
      <header>
        <p className="eyebrow">{t("locations.workspaceConfiguration")}</p>
        <h1>{t("suppliers.title")}</h1>
        <p>{t("suppliers.description")}</p>
      </header>
      {error && (
        <Toast
          title={
            error.includes("limit")
              ? t("suppliers.limitReached")
              : t("suppliers.actionFailed")
          }
          tone="danger"
        >
          {error}
        </Toast>
      )}
      <Select
        label={t("suppliers.location")}
        value={locationId}
        onChange={(e) => setLocationId(e.target.value)}
      >
        <option value="">{t("suppliers.allLocations")}</option>
        {locations.map((x) => (
          <option key={x.id} value={x.id}>
            {x.name}
          </option>
        ))}
      </Select>
      {owner && locationId && (
        <Card>
          <h2>{t("suppliers.addAccount")}</h2>
          <form className="platform-form" onSubmit={submit}>
            <Select
              label={t("comparison.supplier")}
              name="supplier_definition_id"
              required
            >
              <option value="">{t("suppliers.chooseSupplier")}</option>
              {definitions.map((x) => (
                <option key={x.id} value={x.id}>
                  {x.name}
                </option>
              ))}
            </Select>
            <Input
              label={t("suppliers.displayName")}
              name="display_name"
              required
            />
            <Input
              label={t("suppliers.maskedReference")}
              name="masked_account_reference"
              placeholder="ending 1234"
              hint={t("suppliers.maskedReferenceHint")}
            />
            <Select
              label={t("suppliers.sourceMethod")}
              name="source_method"
              defaultValue="manual_import"
            >
              <option value="manual_import">
                {t("suppliers.manualImport")}
              </option>
              <option value="future_automatic_sync">
                {t("suppliers.automaticSync")}
              </option>
            </Select>
            <Select
              label={t("suppliers.refreshCadence")}
              name="expected_refresh_cadence"
              defaultValue="weekly"
            >
              <option value="on_demand">{t("suppliers.onDemand")}</option>
              <option value="daily">{t("suppliers.daily")}</option>
              <option value="weekly">{t("suppliers.weekly")}</option>
              <option value="monthly">{t("suppliers.monthly")}</option>
            </Select>
            <Button type="submit" disabled={busy}>
              {t("suppliers.addAccount")}
            </Button>
          </form>
        </Card>
      )}
      {!owner && (
        <Toast title={t("common.readOnlyAccess")} tone="neutral">
          {t("suppliers.readOnlyDescription")}
        </Toast>
      )}
      <Card>
        <h2>{t("suppliers.accounts")}</h2>
        {accounts.length === 0 && <p>{t("suppliers.noAccounts")}</p>}
        {accounts.map((a) => (
          <div className="ui-list-row" key={a.id}>
            <div>
              <strong>{a.display_name}</strong>
              <p>
                {a.supplier.name} · {a.location.name} ·{" "}
                {a.masked_account_reference ?? t("suppliers.noReference")}
              </p>
              <small>
                {a.expected_refresh_cadence.replaceAll("_", " ")} · Last
                successful sync:{" "}
                {a.last_successful_sync_at
                  ? formatDateTime(
                      a.last_successful_sync_at,
                      locations.find(
                        (location) => location.id === a.location.id,
                      )?.timezone ?? "UTC",
                    )
                  : t("suppliers.notYetSynced")}
              </small>
            </div>
            <Badge tone={a.status === "active" ? "success" : "neutral"}>
              {t(`locations.${a.status}`)}
            </Badge>
            {owner && (
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() => void edit(a)}
              >
                {t("common.edit")}
              </Button>
            )}
          </div>
        ))}
      </Card>
    </div>
  );
}
function message(error: unknown, localizeError: (code: string) => string) {
  return error instanceof ApiClientError
    ? localizeError(error.code)
    : localizeError("network_error");
}
