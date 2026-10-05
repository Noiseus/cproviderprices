"use client";

import { useEffect, useState } from "react";
import type { components } from "@cproviderprices/generated-api-client";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import { Button, Dialog, Input, Select } from "@/components/ui";
import { ApiClientError, apiRequest } from "@/lib/api";
import { useTranslation } from "@/components/i18n/I18nProvider";

type ListIndexResponse = components["schemas"]["SavedListIndexResponse"];
type ListSummary = ListIndexResponse["saved_lists"][number];

export function AddToSavedListControl({
  canonicalProductId,
  selectedOfferId,
  label,
}: {
  canonicalProductId: string;
  selectedOfferId?: string;
  label?: string;
}) {
  const { organization, locationId } = useWorkspace();
  const { localizeError, t } = useTranslation();
  const canMutate = organization.role !== "viewer";
  const [open, setOpen] = useState(false);
  const [lists, setLists] = useState<ListSummary[]>([]);
  const [loading, setLoading] = useState(false);
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [newName, setNewName] = useState("");

  useEffect(() => {
    if (!open || !locationId) return;
    const controller = new AbortController();
    setLoading(true);
    void apiRequest<ListIndexResponse>(
      `/api/v1/organizations/${organization.id}/saved-lists?location_id=${encodeURIComponent(locationId)}&status=active`,
      { signal: controller.signal },
    )
      .then((payload) => {
        if (!controller.signal.aborted) setLists(payload.saved_lists);
      })
      .catch(() => {
        if (!controller.signal.aborted)
          setMessage(localizeError("request_failed"));
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [locationId, open, organization.id]);

  if (!canMutate) return null;

  async function addTo(list: ListSummary) {
    setPending(true);
    setMessage(null);
    try {
      await apiRequest(
        `/api/v1/organizations/${organization.id}/saved-lists/${list.id}/items`,
        {
          method: "POST",
          body: JSON.stringify({
            canonical_product_id: canonicalProductId,
            selected_offer_id: selectedOfferId ?? null,
            expected_list_version: list.version,
          }),
        },
      );
      setMessage(`Added to ${list.name}.`);
      setLists((current) =>
        current.map((entry) =>
          entry.id === list.id
            ? {
                ...entry,
                item_count: entry.item_count + 1,
                version: entry.version + 1,
              }
            : entry,
        ),
      );
    } catch (reason) {
      if (reason instanceof ApiClientError && reason.code === "conflict") {
        setMessage("That list changed elsewhere — reload it before adding.");
      } else if (
        reason instanceof ApiClientError &&
        reason.code === "duplicate_list_item"
      ) {
        setMessage("This product is already on that list.");
      } else {
        setMessage(localizeError("request_failed"));
      }
    } finally {
      setPending(false);
    }
  }

  async function createAndAdd() {
    if (!locationId || !newName.trim()) return;
    setPending(true);
    setMessage(null);
    try {
      const created = await apiRequest<
        components["schemas"]["SavedListIndexSingleResponse"]
      >(`/api/v1/organizations/${organization.id}/saved-lists`, {
        method: "POST",
        body: JSON.stringify({ name: newName.trim(), location_id: locationId }),
      });
      await addTo(created.saved_list);
      setNewName("");
    } catch {
      setMessage(localizeError("request_failed"));
      setPending(false);
    }
  }

  return (
    <>
      <Button variant="secondary" size="sm" onClick={() => setOpen(true)}>
        {label ?? t("lists.addToList")}
      </Button>
      <Dialog
        open={open}
        onClose={() => {
          if (!pending) setOpen(false);
        }}
        title={t("lists.addToSavedPurchasingList")}
      >
        <p>
          This saves purchasing intent for the canonical product. Current prices
          remain derived from CProviderPrices data.
        </p>
        {loading ? <p>{t("common.loading")}</p> : null}
        {!loading && lists.length > 0 ? (
          <Select
            label={t("lists.chooseActiveList")}
            defaultValue=""
            disabled={pending}
            onChange={(event) => {
              const list = lists.find(
                (entry) => entry.id === event.target.value,
              );
              if (list) void addTo(list);
            }}
          >
            <option value="">{t("common.select")}</option>
            {lists.map((list) => (
              <option key={list.id} value={list.id}>
                {list.name} · {list.item_count} items
              </option>
            ))}
          </Select>
        ) : null}
        <div className="saved-list-add__create">
          <Input
            label={t("lists.orCreateNewList")}
            value={newName}
            maxLength={120}
            disabled={pending || !locationId}
            onChange={(event) => setNewName(event.target.value)}
          />
          <Button
            variant="secondary"
            disabled={pending || !newName.trim() || !locationId}
            onClick={() => void createAndAdd()}
          >
            {pending ? t("common.saving") : t("lists.createAndAdd")}
          </Button>
        </div>
        {message ? <p role="status">{message}</p> : null}
      </Dialog>
    </>
  );
}
