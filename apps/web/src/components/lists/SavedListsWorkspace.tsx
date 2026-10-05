"use client";

import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import type { components } from "@cproviderprices/generated-api-client";
import { useWorkspace } from "@/components/app-shell/AppShellContext";
import {
  Button,
  Card,
  ConfirmationDialog,
  Dialog,
  EmptyState,
  ErrorState,
  Input,
  PermissionState,
  Skeleton,
} from "@/components/ui";
import { ApiClientError, apiRequest } from "@/lib/api";

type ListIndexResponse = components["schemas"]["SavedListIndexResponse"];
type ListSummary = ListIndexResponse["saved_lists"][number];
type Status = "active" | "archived";
type EditorMode = "create" | "rename" | "duplicate" | null;

export function SavedListsWorkspace() {
  const { organization, locationId } = useWorkspace();
  const canMutate = organization.role !== "viewer";
  const [status, setStatus] = useState<Status>("active");
  const [data, setData] = useState<ListIndexResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [conflict, setConflict] = useState<string | null>(null);
  const [editor, setEditor] = useState<EditorMode>(null);
  const [target, setTarget] = useState<ListSummary | null>(null);
  const [name, setName] = useState("");
  const [pending, setPending] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<ListSummary | null>(null);

  const load = useCallback(async () => {
    if (!locationId) {
      setData(null);
      setLoading(false);
      return;
    }
    setLoading(true);
    try {
      const payload = await apiRequest<ListIndexResponse>(
        `/api/v1/organizations/${organization.id}/saved-lists?location_id=${encodeURIComponent(locationId)}&status=${status}`,
      );
      setData(payload);
      setError(null);
    } catch (reason) {
      setError(
        reason instanceof Error ? reason.message : "Lists are unavailable.",
      );
    } finally {
      setLoading(false);
    }
  }, [locationId, organization.id, status]);

  useEffect(() => {
    void load();
  }, [load]);

  function openEditor(mode: Exclude<EditorMode, null>, list?: ListSummary) {
    setEditor(mode);
    setTarget(list ?? null);
    setName(mode === "rename" ? (list?.name ?? "") : "");
    setConflict(null);
  }

  function replaceList(next: ListSummary) {
    setData((current) =>
      current
        ? {
            ...current,
            saved_lists: current.saved_lists.map((entry) =>
              entry.id === next.id ? next : entry,
            ),
          }
        : current,
    );
  }

  async function saveEditor() {
    if (!editor || !locationId || (editor !== "duplicate" && !name.trim()))
      return;
    setPending(true);
    try {
      if (editor === "create") {
        const response = await apiRequest<
          components["schemas"]["SavedListIndexSingleResponse"]
        >(`/api/v1/organizations/${organization.id}/saved-lists`, {
          method: "POST",
          body: JSON.stringify({ name: name.trim(), location_id: locationId }),
        });
        setData((current) =>
          current
            ? {
                ...current,
                saved_lists: [response.saved_list, ...current.saved_lists],
              }
            : current,
        );
      } else if (editor === "rename" && target) {
        const response = await apiRequest<
          components["schemas"]["SavedListIndexSingleResponse"]
        >(`/api/v1/organizations/${organization.id}/saved-lists/${target.id}`, {
          method: "PATCH",
          body: JSON.stringify({
            name: name.trim(),
            expected_version: target.version,
          }),
        });
        replaceList(response.saved_list);
      } else if (editor === "duplicate" && target) {
        const response = await apiRequest<
          components["schemas"]["SavedListIndexSingleResponse"]
        >(
          `/api/v1/organizations/${organization.id}/saved-lists/${target.id}/duplicate`,
          {
            method: "POST",
            body: JSON.stringify({
              name: name.trim() || undefined,
              expected_version: target.version,
            }),
          },
        );
        if (status === "active")
          setData((current) =>
            current
              ? {
                  ...current,
                  saved_lists: [response.saved_list, ...current.saved_lists],
                }
              : current,
          );
      }
      setEditor(null);
    } catch (reason) {
      if (reason instanceof ApiClientError && reason.code === "conflict") {
        setConflict("Changed elsewhere — reload the list before trying again.");
      } else {
        setConflict("Could not save the list. Try again.");
      }
    } finally {
      setPending(false);
    }
  }

  async function changeStatus(list: ListSummary, next: Status) {
    setPending(true);
    try {
      const response = await apiRequest<
        components["schemas"]["SavedListIndexSingleResponse"]
      >(
        `/api/v1/organizations/${organization.id}/saved-lists/${list.id}/${next === "archived" ? "archive" : "restore"}`,
        {
          method: "POST",
          body: JSON.stringify({ expected_version: list.version }),
        },
      );
      if (status === next) replaceList(response.saved_list);
      else
        setData((current) =>
          current
            ? {
                ...current,
                saved_lists: current.saved_lists.filter(
                  (entry) => entry.id !== list.id,
                ),
              }
            : current,
        );
    } catch (reason) {
      setConflict(
        reason instanceof ApiClientError && reason.code === "conflict"
          ? "Changed elsewhere — reload the list before trying again."
          : "Could not update the list.",
      );
    } finally {
      setPending(false);
    }
  }

  async function deleteList() {
    if (!deleteTarget) return;
    setPending(true);
    try {
      await apiRequest(
        `/api/v1/organizations/${organization.id}/saved-lists/${deleteTarget.id}`,
        {
          method: "DELETE",
          body: JSON.stringify({ expected_version: deleteTarget.version }),
        },
      );
      setData((current) =>
        current
          ? {
              ...current,
              saved_lists: current.saved_lists.filter(
                (entry) => entry.id !== deleteTarget.id,
              ),
            }
          : current,
      );
      setDeleteTarget(null);
    } catch (reason) {
      setConflict(
        reason instanceof ApiClientError && reason.code === "conflict"
          ? "Changed elsewhere — reload the list before deleting."
          : "Could not delete the list.",
      );
    } finally {
      setPending(false);
    }
  }

  return (
    <main className="ui-container saved-lists">
      <header className="ui-page-header saved-lists__header">
        <div>
          <p className="eyebrow">Purchasing intent</p>
          <h1>Saved purchasing lists</h1>
          <p>
            Lists keep products and quantities durable while current price and
            availability evidence continues to change.
          </p>
        </div>
        {canMutate ? (
          <Button onClick={() => openEditor("create")}>Create list</Button>
        ) : null}
      </header>
      <div
        className="saved-lists__tabs"
        role="tablist"
        aria-label="List status"
      >
        {(["active", "archived"] as const).map((value) => (
          <Button
            key={value}
            variant={status === value ? "primary" : "secondary"}
            aria-pressed={status === value}
            onClick={() => setStatus(value)}
          >
            {value === "active" ? "Active" : "Archived"}
          </Button>
        ))}
      </div>
      {conflict ? (
        <ErrorState
          title="List changed elsewhere"
          description={conflict}
          action={
            <Button variant="secondary" onClick={() => void load()}>
              Reload lists
            </Button>
          }
        />
      ) : null}
      {loading ? <Skeleton lines={5} label="Loading saved lists" /> : null}
      {!loading && error ? (
        <ErrorState title="Unable to load lists" description={error} />
      ) : null}
      {!loading && !error && !locationId ? (
        <EmptyState
          title="Choose a location"
          description="Select an active location to work with saved purchasing lists."
        />
      ) : null}
      {!loading && !error && locationId && data?.saved_lists.length === 0 ? (
        <EmptyState
          title={
            status === "active" ? "No saved lists yet" : "No archived lists"
          }
          description={
            status === "active"
              ? "Create a list from here, search, or a comparison."
              : "Archived lists remain here as purchasing-intent history."
          }
          action={
            canMutate && status === "active" ? (
              <Button onClick={() => openEditor("create")}>Create list</Button>
            ) : undefined
          }
        />
      ) : null}
      <section className="saved-lists__grid" aria-live="polite">
        {data?.saved_lists.map((list) => (
          <Card
            key={list.id}
            className="saved-list-card"
            aria-labelledby={`saved-list-${list.id}`}
          >
            <div>
              <p className="eyebrow">{list.location.name}</p>
              <h2 id={`saved-list-${list.id}`}>
                <Link href={`/o/${organization.slug}/lists/${list.id}`}>
                  {list.name}
                </Link>
              </h2>
              <p>
                {list.item_count} purchasing-intent{" "}
                {list.item_count === 1 ? "item" : "items"}
              </p>
              <small>
                Updated {new Date(list.updated_at).toLocaleString()}
              </small>
            </div>
            {canMutate ? (
              <div className="saved-list-card__actions">
                <Button
                  variant="quiet"
                  size="sm"
                  onClick={() => openEditor("rename", list)}
                >
                  Rename
                </Button>
                <Button
                  variant="quiet"
                  size="sm"
                  onClick={() => openEditor("duplicate", list)}
                >
                  Duplicate
                </Button>
                <Button
                  variant="quiet"
                  size="sm"
                  disabled={pending}
                  onClick={() =>
                    void changeStatus(
                      list,
                      status === "active" ? "archived" : "active",
                    )
                  }
                >
                  {status === "active" ? "Archive" : "Restore"}
                </Button>
                <Button
                  variant="danger"
                  size="sm"
                  onClick={() => setDeleteTarget(list)}
                >
                  Delete
                </Button>
              </div>
            ) : (
              <PermissionState
                title="Read-only"
                description="Viewers can review saved purchasing intent but cannot change it."
              />
            )}
          </Card>
        ))}
      </section>
      <Dialog
        open={editor !== null}
        onClose={() => !pending && setEditor(null)}
        title={
          editor === "rename"
            ? "Rename list"
            : editor === "duplicate"
              ? "Duplicate list"
              : "Create saved list"
        }
      >
        <Input
          label={
            editor === "duplicate" ? "New list name (optional)" : "List name"
          }
          value={name}
          maxLength={120}
          disabled={pending}
          onChange={(event) => setName(event.target.value)}
        />
        <div className="ui-dialog__actions">
          <Button
            variant="secondary"
            disabled={pending}
            onClick={() => setEditor(null)}
          >
            Cancel
          </Button>
          <Button
            disabled={pending || (editor !== "duplicate" && !name.trim())}
            onClick={() => void saveEditor()}
          >
            {pending
              ? "Saving…"
              : editor === "duplicate"
                ? "Duplicate"
                : "Save"}
          </Button>
        </div>
      </Dialog>
      <ConfirmationDialog
        open={deleteTarget !== null}
        onClose={() => !pending && setDeleteTarget(null)}
        onConfirm={() => void deleteList()}
        title="Delete saved list"
        confirmLabel="Delete list"
        pending={pending}
      >
        <p>
          Delete “{deleteTarget?.name}” and its items? This is explicit removal;
          immutable audit context remains.
        </p>
      </ConfirmationDialog>
    </main>
  );
}
