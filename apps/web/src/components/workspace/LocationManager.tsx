"use client";

import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import { ApiClientError, apiRequest } from "@/lib/api";
import type { Location } from "@/lib/customer-api";
import { Badge, Button, Card, Input, Toast } from "@/components/ui";
import { useTranslation } from "@/components/i18n/I18nProvider";

export function LocationManager() {
  const { organization } = useWorkspace();
  const { localizeError, t } = useTranslation();
  const router = useRouter();
  const owner = organization.role === "organization_owner";
  const [locations, setLocations] = useState<Location[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const path = `/api/v1/organizations/${organization.id}/restaurant-locations`;
  async function reload() {
    try {
      setLocations(
        (await apiRequest<{ restaurant_locations: Location[] }>(path))
          .restaurant_locations,
      );
      setError(null);
    } catch (e) {
      setError(message(e, localizeError));
    }
  }
  useEffect(() => void reload(), [path]);
  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const d = new FormData(form);
    setBusy(true);
    try {
      await apiRequest(path, {
        method: "POST",
        body: JSON.stringify({
          name: d.get("name"),
          timezone: d.get("timezone"),
          address_line_1: d.get("address_line_1") || null,
          city: d.get("city") || null,
          region: d.get("region") || null,
          postal_code: d.get("postal_code") || null,
        }),
      });
      form.reset();
      await reload();
      router.refresh();
    } catch (e) {
      setError(message(e, localizeError));
    } finally {
      setBusy(false);
    }
  }
  async function patch(location: Location, values: Record<string, string>) {
    setBusy(true);
    try {
      await apiRequest(`${path}/${location.id}`, {
        method: "PATCH",
        body: JSON.stringify(values),
      });
      await reload();
      router.refresh();
    } catch (e) {
      setError(message(e, localizeError));
    } finally {
      setBusy(false);
    }
  }
  async function select(location: Location) {
    setBusy(true);
    try {
      await apiRequest(`${path}/${location.id}/select`, { method: "POST" });
      sessionStorage.setItem(
        `selected-location:${organization.id}`,
        location.id,
      );
      window.dispatchEvent(
        new CustomEvent("workspace:location-changed", {
          detail: { organizationId: organization.id, locationId: location.id },
        }),
      );
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
        <h1>{t("locations.title")}</h1>
        <p>{t("locations.description")}</p>
      </header>
      {error && (
        <Toast
          title={
            error.includes("limit")
              ? t("locations.limitReached")
              : t("locations.actionFailed")
          }
          tone="danger"
        >
          {error}
        </Toast>
      )}
      {owner ? (
        <Card>
          <h2>{t("locations.add")}</h2>
          <form className="platform-form" onSubmit={create}>
            <Input label={t("common.name")} name="name" required />
            <Input
              label={t("locations.timezone")}
              name="timezone"
              defaultValue="America/New_York"
              required
            />
            <Input label={t("locations.streetAddress")} name="address_line_1" />
            <Input label={t("locations.city")} name="city" />
            <Input label={t("locations.region")} name="region" />
            <Input label={t("locations.postalCode")} name="postal_code" />
            <Button type="submit" disabled={busy}>
              {t("locations.createAction")}
            </Button>
          </form>
        </Card>
      ) : (
        <Toast title={t("common.readOnlyAccess")} tone="neutral">
          {t("locations.readOnlyDescription")}
        </Toast>
      )}
      <Card>
        <h2>{t("locations.title")}</h2>
        {locations.length === 0 && <p>{t("locations.noneConfigured")}</p>}
        {locations.map((location) => (
          <div className="ui-list-row" key={location.id}>
            <div>
              <strong>{location.name}</strong>
              <p>
                {[location.city, location.region].filter(Boolean).join(", ") ||
                  location.timezone}
              </p>
            </div>
            <Badge tone={location.status === "active" ? "success" : "neutral"}>
              {t(`locations.${location.status}`)}
            </Badge>
            <Button
              variant="secondary"
              disabled={busy || location.status !== "active"}
              onClick={() => void select(location)}
            >
              {t("locations.select")}
            </Button>
            {owner && (
              <>
                <Button
                  variant="secondary"
                  disabled={busy}
                  onClick={() => {
                    const name = window.prompt(
                      t("locations.locationName"),
                      location.name,
                    );
                    if (name) void patch(location, { name });
                  }}
                >
                  {t("locations.edit")}
                </Button>
                <Button
                  variant={
                    location.status === "active" ? "danger" : "secondary"
                  }
                  disabled={busy}
                  onClick={() =>
                    void patch(location, {
                      status:
                        location.status === "active" ? "inactive" : "active",
                    })
                  }
                >
                  {location.status === "active"
                    ? t("locations.deactivate")
                    : t("locations.activate")}
                </Button>
              </>
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
