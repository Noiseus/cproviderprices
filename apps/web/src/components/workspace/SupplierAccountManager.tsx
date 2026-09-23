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

export function SupplierAccountManager({
  locations,
}: {
  locations: Location[];
}) {
  const { organization } = useWorkspace();
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
      setError(message(e));
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
      setError(message(x));
    } finally {
      setBusy(false);
    }
  }
  async function edit(account: SupplierAccount) {
    const display_name = window.prompt("Display name", account.display_name);
    if (!display_name) return;
    setBusy(true);
    try {
      await apiRequest(`${base}/${account.id}`, {
        method: "PATCH",
        body: JSON.stringify({ display_name }),
      });
      await reload();
    } catch (e) {
      setError(message(e));
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="workspace-page">
      <header>
        <p className="eyebrow">Workspace configuration</p>
        <h1>Suppliers</h1>
        <p>
          Account metadata only. Credentials and synchronization are not
          collected here.
        </p>
      </header>
      {error && (
        <Toast
          title={
            error.includes("limit")
              ? "Supplier account limit reached"
              : "Supplier action failed"
          }
          tone="danger"
        >
          {error}
        </Toast>
      )}
      <Select
        label="Location"
        value={locationId}
        onChange={(e) => setLocationId(e.target.value)}
      >
        <option value="">All locations</option>
        {locations.map((x) => (
          <option key={x.id} value={x.id}>
            {x.name}
          </option>
        ))}
      </Select>
      {owner && locationId && (
        <Card>
          <h2>Add supplier account</h2>
          <form className="platform-form" onSubmit={submit}>
            <Select label="Supplier" name="supplier_definition_id" required>
              <option value="">Choose supplier</option>
              {definitions.map((x) => (
                <option key={x.id} value={x.id}>
                  {x.name}
                </option>
              ))}
            </Select>
            <Input label="Display name" name="display_name" required />
            <Input
              label="Masked account reference"
              name="masked_account_reference"
              placeholder="ending 1234"
              hint="Do not enter passwords, tokens, or full credentials."
            />
            <Select
              label="Source method"
              name="source_method"
              defaultValue="manual_import"
            >
              <option value="manual_import">Manual import</option>
              <option value="future_automatic_sync">
                Future automatic sync
              </option>
            </Select>
            <Select
              label="Expected refresh cadence"
              name="expected_refresh_cadence"
              defaultValue="weekly"
            >
              <option value="on_demand">On demand</option>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </Select>
            <Button type="submit" disabled={busy}>
              Add supplier account
            </Button>
          </form>
        </Card>
      )}
      {!owner && (
        <Toast title="Read-only access" tone="neutral">
          Only organization owners can change supplier accounts.
        </Toast>
      )}
      <Card>
        <h2>Supplier accounts</h2>
        {accounts.length === 0 && (
          <p>No supplier accounts configured for this selection.</p>
        )}
        {accounts.map((a) => (
          <div className="ui-list-row" key={a.id}>
            <div>
              <strong>{a.display_name}</strong>
              <p>
                {a.supplier.name} · {a.location.name} ·{" "}
                {a.masked_account_reference ?? "No account reference"}
              </p>
              <small>
                {a.expected_refresh_cadence.replaceAll("_", " ")} · Last
                successful sync: {a.last_successful_sync_at ?? "Not yet synced"}
              </small>
            </div>
            <Badge tone={a.status === "active" ? "success" : "neutral"}>
              {a.status}
            </Badge>
            {owner && (
              <Button
                variant="secondary"
                disabled={busy}
                onClick={() => void edit(a)}
              >
                Edit
              </Button>
            )}
          </div>
        ))}
      </Card>
    </div>
  );
}
function message(error: unknown) {
  return error instanceof ApiClientError
    ? error.message
    : "The action could not be completed.";
}
