"use client";

import Link from "next/link";
import { useCallback, useEffect, useRef, useState } from "react";
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
  PermissionState,
  Select,
  Skeleton,
} from "@/components/ui";
import { ApiClientError, apiRequest } from "@/lib/api";
import { AddToSavedListControl } from "./AddToSavedListControl";
import { useTranslation } from "@/components/i18n/I18nProvider";

type DetailResponse = components["schemas"]["SavedListDetailResponse"];
type Detail = DetailResponse["saved_list"];
type Item = Detail["items"][number];
type Offer = components["schemas"]["ProductComparisonOffer"];
type ItemMutation = components["schemas"]["SavedListItemMutationResponse"];
type SaveResult = "saved" | "failed" | "conflict";

export function SavedListDetailWorkspace({ listId }: { listId: string }) {
  const { organization } = useWorkspace();
  const { localizeError, t } = useTranslation();
  const canMutate = organization.role !== "viewer";
  const [detail, setDetail] = useState<Detail | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [blocked, setBlocked] = useState(false);
  const epoch = useRef(0);

  const load = useCallback(
    async (signal: AbortSignal): Promise<boolean> => {
      const request = ++epoch.current;
      setLoading(true);
      try {
        const payload = await apiRequest<DetailResponse>(
          `/api/v1/organizations/${organization.id}/saved-lists/${listId}`,
          { signal },
        );
        if (signal.aborted || request !== epoch.current) return false;
        setDetail(payload.saved_list);
        setError(null);
        setBlocked(false);
        return true;
      } catch (reason) {
        if (signal.aborted || request !== epoch.current) return false;
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
        if (!signal.aborted && request === epoch.current) setLoading(false);
      }
    },
    [listId, organization.id],
  );

  const { isStale, markFresh, refreshNow } = useVisibleStaleRefresh(
    60_000,
    load,
    true,
  );

  useEffect(() => {
    const controller = new AbortController();
    void load(controller.signal).then((success) => {
      if (success && !controller.signal.aborted) markFresh();
    });
    return () => controller.abort();
  }, [load, markFresh]);

  function applyMutation(payload: ItemMutation) {
    setDetail((current) =>
      current
        ? {
            ...current,
            version: payload.saved_list.version,
            updated_at: payload.item.updated_at,
            items: current.items.map((item) =>
              item.id === payload.item.id ? payload.item : item,
            ),
          }
        : current,
    );
  }

  async function updateItem(
    item: Item,
    changes: Record<string, unknown>,
  ): Promise<SaveResult> {
    try {
      const payload = await apiRequest<ItemMutation>(
        `/api/v1/organizations/${organization.id}/saved-lists/${listId}/items/${item.id}`,
        {
          method: "PATCH",
          body: JSON.stringify({ ...changes, expected_version: item.version }),
        },
      );
      applyMutation(payload);
      return "saved";
    } catch (reason) {
      return reason instanceof ApiClientError && reason.code === "conflict"
        ? "conflict"
        : "failed";
    }
  }

  async function selectOffer(
    item: Item,
    selectedOfferId: string,
  ): Promise<SaveResult> {
    try {
      const payload = await apiRequest<ItemMutation>(
        `/api/v1/organizations/${organization.id}/saved-lists/${listId}/items/${item.id}/selected-offer`,
        {
          method: "PUT",
          body: JSON.stringify({
            selected_offer_id: selectedOfferId || null,
            expected_version: item.version,
          }),
        },
      );
      applyMutation(payload);
      return "saved";
    } catch (reason) {
      return reason instanceof ApiClientError && reason.code === "conflict"
        ? "conflict"
        : "failed";
    }
  }

  async function removeItem(item: Item): Promise<SaveResult> {
    try {
      const payload = await apiRequest<
        components["schemas"]["RemoveSavedListItemResponse"]
      >(
        `/api/v1/organizations/${organization.id}/saved-lists/${listId}/items/${item.id}`,
        {
          method: "DELETE",
          body: JSON.stringify({ expected_version: item.version }),
        },
      );
      setDetail((current) =>
        current
          ? {
              ...current,
              version: payload.saved_list.version,
              items: current.items.filter(
                (entry) => entry.id !== payload.removed_item_id,
              ),
            }
          : current,
      );
      return "saved";
    } catch (reason) {
      return reason instanceof ApiClientError && reason.code === "conflict"
        ? "conflict"
        : "failed";
    }
  }

  async function moveItem(item: Item, direction: -1 | 1) {
    if (!detail) return;
    const items = [...detail.items].sort(
      (left, right) => left.sort_order - right.sort_order,
    );
    const index = items.findIndex((entry) => entry.id === item.id);
    const target = items[index + direction];
    if (!target) return;
    try {
      const payload = await apiRequest<
        components["schemas"]["ReorderSavedListResponse"]
      >(
        `/api/v1/organizations/${organization.id}/saved-lists/${listId}/reorder`,
        {
          method: "PUT",
          body: JSON.stringify({
            item_ids: items.map((entry, currentIndex) =>
              currentIndex === index
                ? target.id
                : currentIndex === index + direction
                  ? item.id
                  : entry.id,
            ),
            expected_list_version: detail.version,
          }),
        },
      );
      const ordering = new Map(payload.items.map((entry) => [entry.id, entry]));
      setDetail((current) =>
        current
          ? {
              ...current,
              version: payload.saved_list.version,
              items: current.items
                .map((entry) => {
                  const next = ordering.get(entry.id);
                  return next
                    ? {
                        ...entry,
                        sort_order: next.sort_order,
                        version: next.version,
                      }
                    : entry;
                })
                .sort((left, right) => left.sort_order - right.sort_order),
            }
          : current,
      );
    } catch {
      setError("Changed elsewhere — reload the list before reordering.");
    }
  }

  return (
    <main className="ui-container saved-list-detail">
      <p>
        <Link href={`/o/${organization.slug}/lists`}>
          ← Back to saved lists
        </Link>
      </p>
      {loading && !detail ? (
        <Skeleton lines={8} label="Loading saved list" />
      ) : null}
      {blocked ? (
        <PermissionState
          title="List unavailable"
          description="Your workspace cannot access this saved list."
        />
      ) : null}
      {!blocked && error && !detail ? (
        <ErrorState title="Unable to load list" description={error} />
      ) : null}
      {detail ? (
        <>
          <header className="ui-page-header saved-list-detail__header">
            <div>
              <p className="eyebrow">
                {detail.location.name} · {detail.status}
              </p>
              <h1>{detail.name}</h1>
              <p>
                Version {detail.version}. Product intent remains even when
                current supplier prices change.
              </p>
            </div>
            <Button variant="secondary" onClick={() => void refreshNow()}>
              {t("common.refresh")}
            </Button>
          </header>
          {isStale || error ? (
            <PartialDataWarning>
              Showing the last loaded list detail. Refresh reloads current
              internal price records; it does not sync a supplier.
            </PartialDataWarning>
          ) : null}
          {detail.status === "archived" ? (
            <PartialDataWarning>
              This list is archived. Its purchasing-intent history remains
              readable, but it must be restored before items can change.
            </PartialDataWarning>
          ) : null}
          {!canMutate ? (
            <PermissionState
              title="Read-only saved list"
              description="Viewers can review intent and current price evidence but cannot change quantities, notes, offers, or order."
            />
          ) : null}
          {detail.items.length === 0 ? (
            <EmptyState
              title="No purchasing-intent items"
              description="Add a canonical product from Search or Compare, or use the product control below when you have a product in context."
            />
          ) : (
            <section className="saved-list-detail__items" aria-live="polite">
              {detail.items.map((item, index) => (
                <SavedListItemCard
                  key={item.id}
                  item={item}
                  canMutate={canMutate && detail.status === "active"}
                  first={index === 0}
                  last={index === detail.items.length - 1}
                  onUpdate={updateItem}
                  onSelectOffer={selectOffer}
                  onRemove={removeItem}
                  onMove={moveItem}
                />
              ))}
            </section>
          )}
        </>
      ) : null}
    </main>
  );
}

function SavedListItemCard({
  item,
  canMutate,
  first,
  last,
  onUpdate,
  onSelectOffer,
  onRemove,
  onMove,
}: {
  item: Item;
  canMutate: boolean;
  first: boolean;
  last: boolean;
  onUpdate: (
    item: Item,
    changes: Record<string, unknown>,
  ) => Promise<SaveResult>;
  onSelectOffer: (item: Item, selectedOfferId: string) => Promise<SaveResult>;
  onRemove: (item: Item) => Promise<SaveResult>;
  onMove: (item: Item, direction: -1 | 1) => Promise<void>;
}) {
  const { t } = useTranslation();
  const [quantity, setQuantity] = useState(item.quantity_target);
  const [unit, setUnit] = useState(item.preferred_unit ?? "");
  const [notes, setNotes] = useState(item.notes ?? "");
  const [state, setState] = useState<"idle" | "saving" | SaveResult>("idle");

  useEffect(() => {
    setQuantity(item.quantity_target);
    setUnit(item.preferred_unit ?? "");
    setNotes(item.notes ?? "");
  }, [item.notes, item.preferred_unit, item.quantity_target, item.version]);

  async function save() {
    setState("saving");
    setState(
      await onUpdate(item, {
        quantity_target: quantity,
        preferred_unit: unit || null,
        notes: notes || null,
      }),
    );
  }

  async function changeOffer(value: string) {
    setState("saving");
    setState(await onSelectOffer(item, value));
  }

  async function remove() {
    if (
      !window.confirm(
        `Remove ${item.canonical_product.english_name} from this list?`,
      )
    )
      return;
    setState("saving");
    setState(await onRemove(item));
  }

  return (
    <Card className="saved-list-item">
      <header className="saved-list-item__header">
        <div>
          <p className="eyebrow">
            {item.canonical_product.category.english_name}
          </p>
          <h2>{item.canonical_product.english_name}</h2>
          {item.product_variant ? <p>{item.product_variant.code}</p> : null}
        </div>
        <div
          className="saved-list-item__order"
          aria-label={`Order controls for ${item.canonical_product.english_name}`}
        >
          <Button
            variant="quiet"
            size="sm"
            disabled={!canMutate || first}
            onClick={() => void onMove(item, -1)}
          >
            Move up
          </Button>
          <Button
            variant="quiet"
            size="sm"
            disabled={!canMutate || last}
            onClick={() => void onMove(item, 1)}
          >
            Move down
          </Button>
        </div>
      </header>
      {item.selected_offer_unavailable ? (
        <p className="saved-list-item__warning">
          Selected offer is unavailable. Your selection is retained; choose an
          alternative only if you want to change it.
        </p>
      ) : null}
      <div className="saved-list-item__summary">
        <OfferSummary
          title={t("lists.selectedOption")}
          offer={item.selected_offer}
          empty={
            item.selected_offer_id
              ? "Selected offer is no longer eligible."
              : "No supplier offer selected."
          }
        />
        <OfferSummary
          title={t("lists.bestNormalizedOption")}
          offer={item.best_normalized_option}
          empty="No safely comparable normalized winner."
        />
        <OfferSummary
          title={t("lists.bestAvailableOption")}
          offer={item.best_available_option}
          empty="No current available winner."
        />
      </div>
      <div className="saved-list-item__fields">
        <Input
          label={t("lists.quantity")}
          inputMode="decimal"
          value={quantity}
          disabled={!canMutate || state === "saving"}
          onChange={(event) => setQuantity(event.target.value)}
        />
        <Select
          label={t("lists.preferredUnit")}
          value={unit}
          disabled={!canMutate || state === "saving"}
          onChange={(event) => setUnit(event.target.value)}
        >
          <option value="">{t("common.none")}</option>
          <option value="lb">lb</option>
          <option value="gal">gal</option>
          <option value="ea">ea</option>
        </Select>
        <label className="ui-field">
          <span>{t("lists.notes")}</span>
          <textarea
            className="ui-input"
            value={notes}
            maxLength={2000}
            disabled={!canMutate || state === "saving"}
            onChange={(event) => setNotes(event.target.value)}
          />
        </label>
      </div>
      {item.notes ? (
        <p className="saved-list-item__note">
          <strong>Saved note:</strong> {item.notes}
        </p>
      ) : null}
      <div className="saved-list-item__actions">
        {canMutate ? (
          <Button
            variant="secondary"
            size="sm"
            disabled={state === "saving"}
            onClick={() => void save()}
          >
            {state === "saving" ? t("common.saving") : t("lists.saveItem")}
          </Button>
        ) : null}
        {canMutate ? (
          <Select
            label={t("lists.selectedOffer")}
            value={item.selected_offer_id ?? ""}
            disabled={state === "saving"}
            onChange={(event) => void changeOffer(event.target.value)}
          >
            <option value="">{t("common.none")}</option>
            {item.offer_choices.map((offer) => (
              <option
                key={offer.id}
                value={offer.id}
                disabled={
                  offer.availability !== "available" &&
                  offer.id !== item.selected_offer_id
                }
              >
                {offer.supplier.display_name} · {offer.currency}{" "}
                {offer.case_price} · {offer.availability}
              </option>
            ))}
          </Select>
        ) : null}
        {canMutate ? (
          <Button
            variant="danger"
            size="sm"
            disabled={state === "saving"}
            onClick={() => void remove()}
          >
            {t("common.remove")}
          </Button>
        ) : null}
        <AddToSavedListControl
          canonicalProductId={item.canonical_product.id}
          label={t("lists.addToList")}
        />
        <span role="status">
          {state === "saved"
            ? t("common.saved")
            : state === "failed"
              ? t("errors.request_failed")
              : state === "conflict"
                ? t("lists.conflict")
                : ""}
        </span>
      </div>
      <details className="saved-list-item__alternatives">
        <summary>
          {item.alternative_offer_count} eligible current offer
          {item.alternative_offer_count === 1 ? "" : "s"}
        </summary>
        {item.offer_choices.map((offer) => (
          <OfferSummary
            key={offer.id}
            title={offer.supplier.display_name}
            offer={offer}
            empty=""
          />
        ))}
      </details>
    </Card>
  );
}

function OfferSummary({
  title,
  offer,
  empty,
}: {
  title: string;
  offer: Offer | null;
  empty: string;
}) {
  return (
    <section className="saved-list-offer">
      <strong>{title}</strong>
      {offer ? (
        <>
          <span>{offer.supplier.display_name}</span>
          <span>
            {offer.currency} {offer.case_price}/case
            {offer.normalized_price
              ? ` · ${offer.normalized_price}/${offer.normalized_unit}`
              : " · no safe normalized price"}
          </span>
          <span>
            {offer.availability} · {offer.comparison_class}
          </span>
          <FreshnessBadge
            state={offer.freshness}
            timestamp={offer.observed_at}
          />
        </>
      ) : (
        <span>{empty}</span>
      )}
    </section>
  );
}
