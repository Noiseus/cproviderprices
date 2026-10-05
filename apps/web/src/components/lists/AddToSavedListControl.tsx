"use client";

import { useEffect, useState } from "react";
import type { components } from "@cproviderprices/generated-api-client";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import { Button, Dialog, Input, Select } from "@/components/ui";
import { ApiClientError, apiRequest } from "@/lib/api";

type ListIndexResponse = components["schemas"]["SavedListIndexResponse"];
type ListSummary = ListIndexResponse["saved_lists"][number];

export function AddToSavedListControl({
  canonicalProductId,
  selectedOfferId,
  label = "Add to list",
}: {
  canonicalProductId: string;
  selectedOfferId?: string;
  label?: string;
}) {
  const { organization, locationId } = useWorkspace();
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
          setMessage("Could not load saved lists. Try again.");
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
        setMessage("Could not add the product. Try again.");
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
      setMessage("Could not create a list. Try again.");
      setPending(false);
    }
  }

  return (
    <>
      <Button variant="secondary" size="sm" onClick={() => setOpen(true)}>
        {label}
      </Button>
      <Dialog
        open={open}
        onClose={() => {
          if (!pending) setOpen(false);
        }}
        title="Add to saved purchasing list"
      >
        <p>
          This saves purchasing intent for the canonical product. Current prices
          remain derived from CProviderPrices data.
        </p>
        {loading ? <p>Loading lists…</p> : null}
        {!loading && lists.length > 0 ? (
          <Select
            label="Choose an active list"
            defaultValue=""
            disabled={pending}
            onChange={(event) => {
              const list = lists.find(
                (entry) => entry.id === event.target.value,
              );
              if (list) void addTo(list);
            }}
          >
            <option value="">Select a list</option>
            {lists.map((list) => (
              <option key={list.id} value={list.id}>
                {list.name} · {list.item_count} items
              </option>
            ))}
          </Select>
        ) : null}
        <div className="saved-list-add__create">
          <Input
            label="Or create a new list"
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
            {pending ? "Saving…" : "Create and add"}
          </Button>
        </div>
        {message ? <p role="status">{message}</p> : null}
      </Dialog>
    </>
  );
}
