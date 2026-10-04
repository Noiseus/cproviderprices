"use client";

import Link from "next/link";
import { useEffect, useState, type FormEvent } from "react";
import { useRouter } from "next/navigation";
import { ApiClientError, apiRequest } from "@/lib/api";
import type { PlatformMatchCandidateDetail } from "@/lib/platform-api";
import {
  Badge,
  Button,
  Card,
  ConfirmationDialog,
  Input,
  PageHeader,
  Select,
  Toast,
} from "@/components/ui";
import { MatchingConfidenceBadge } from "./MatchingConfidenceBadge";

type CanonicalChoice = {
  id: string;
  english_name: string;
  variants: Array<{ id: string; code: string | null }>;
};

function entries(value: unknown): Array<[string, string]> {
  if (!value || typeof value !== "object" || Array.isArray(value)) return [];
  return Object.entries(value as Record<string, unknown>).map(
    ([key, entry]) => [
      key.replaceAll("_", " "),
      Array.isArray(entry) ? entry.join(", ") : String(entry ?? "not supplied"),
    ],
  );
}

export function MatchingReviewView({
  initial,
  returnHref,
}: {
  initial: PlatformMatchCandidateDetail;
  returnHref: string;
}) {
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [message, setMessage] = useState<{
    tone: "success" | "danger";
    text: string;
  } | null>(null);
  const [choices, setChoices] = useState<CanonicalChoice[]>([]);
  const [confirmCreate, setConfirmCreate] = useState<Record<
    string,
    unknown
  > | null>(null);

  useEffect(() => {
    void apiRequest<{ canonical_products: CanonicalChoice[] }>(
      `/api/v1/platform/matching/canonical-products?category_id=${encodeURIComponent(initial.candidate_product.category.id)}`,
    )
      .then((response) => setChoices(response.canonical_products))
      .catch(() => setChoices([]));
  }, [initial.candidate_product.category.id]);

  async function submit(body: Record<string, unknown>) {
    if (pending) return;
    setPending(true);
    setMessage(null);
    try {
      await apiRequest<{ match_candidate: PlatformMatchCandidateDetail }>(
        `/api/v1/platform/matching/candidates/${initial.id}/review`,
        { method: "POST", body: JSON.stringify(body) },
      );
      setMessage({ tone: "success", text: "Review action recorded." });
      router.push(returnHref);
      router.refresh();
    } catch (error) {
      setMessage({
        tone: "danger",
        text:
          error instanceof ApiClientError
            ? error.message
            : "The review action could not be completed.",
      });
    } finally {
      setPending(false);
      setConfirmCreate(null);
    }
  }

  function reject(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const reason = String(
      new FormData(event.currentTarget).get("reason") ?? "",
    );
    void submit({ action: "reject", reason });
  }

  function chooseExisting(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const values = new FormData(event.currentTarget);
    void submit({
      action: "choose_existing",
      canonical_product_id: String(values.get("canonical_product_id")),
      product_variant_id:
        String(values.get("product_variant_id") || "") || undefined,
      match_class: String(values.get("match_class")),
      confidence: String(values.get("confidence")),
    });
  }

  function createCanonical(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const values = new FormData(event.currentTarget);
    setConfirmCreate({
      action: "create_canonical",
      new_canonical: {
        english_name: String(values.get("english_name")),
        category_id: initial.candidate_product.category.id,
        aliases: values.get("spanish_alias")
          ? [{ locale: "es", alias: String(values.get("spanish_alias")) }]
          : [],
      },
    });
  }

  const evidence = initial.evidence;
  const differences = Array.isArray(evidence.attribute_differences)
    ? evidence.attribute_differences
    : [];
  const matches = Array.isArray(evidence.attribute_matches)
    ? evidence.attribute_matches
    : [];

  return (
    <div className="platform-stack">
      <Link href={returnHref}>← Back to matching review</Link>
      <PageHeader
        eyebrow="Platform matching review"
        title={initial.candidate_product.english_name}
        description={`Candidate from ${initial.vendor_item.supplier.display_name}. Matcher ${initial.matcher_version}.`}
        actions={<MatchingConfidenceBadge confidence={initial.confidence} />}
      />
      {message && (
        <Toast
          title={
            message.tone === "success" ? "Action completed" : "Action failed"
          }
          tone={message.tone}
        >
          {message.text}
        </Toast>
      )}
      <div className="platform-detail-grid">
        <Card>
          <h2>Vendor item</h2>
          <p>
            <strong>{initial.vendor_item.raw_description}</strong>
          </p>
          <dl className="platform-audit-list">
            <div>
              <dt>Supplier</dt>
              <dd>{initial.vendor_item.supplier.display_name}</dd>
            </div>
            <div>
              <dt>Supplier item identifier</dt>
              <dd>
                {initial.vendor_item.supplier_item_identifier ?? "Not supplied"}
              </dd>
            </div>
            <div>
              <dt>Normalized description</dt>
              <dd>{initial.vendor_item.normalized_description}</dd>
            </div>
            <div>
              <dt>Parser version</dt>
              <dd>{initial.vendor_item.parser_version}</dd>
            </div>
            {entries(initial.vendor_item.normalized_attributes).map(
              ([key, value]) => (
                <div key={key}>
                  <dt>{key}</dt>
                  <dd>{value}</dd>
                </div>
              ),
            )}
          </dl>
        </Card>
        <Card>
          <h2>Canonical candidate</h2>
          <p>
            <strong>{initial.candidate_product.english_name}</strong>
          </p>
          <p>{initial.candidate_product.category.english_name}</p>
          {initial.candidate_product.variant && (
            <p>Variant: {initial.candidate_product.variant.code}</p>
          )}
          <dl className="platform-audit-list">
            {entries(initial.candidate_product.attributes).map(
              ([key, value]) => (
                <div key={key}>
                  <dt>{key}</dt>
                  <dd>{value}</dd>
                </div>
              ),
            )}
          </dl>
          <p>
            Aliases:{" "}
            {initial.candidate_product.aliases
              .map((alias) => `${alias.alias} (${alias.locale})`)
              .join(", ") || "None"}
          </p>
        </Card>
      </div>
      <Card>
        <h2>Evidence and differences</h2>
        <p>
          Proposed class:{" "}
          <Badge>{initial.proposed_match_class.replaceAll("_", " ")}</Badge>
          {" · "}Score {initial.score}
        </p>
        <ul>
          <li>
            Stage:{" "}
            {String(evidence.stage ?? "recorded evidence").replaceAll("_", " ")}
          </li>
          {matches.map((match, index) => (
            <li key={`match-${index}`}>
              Matching attribute:{" "}
              {String(
                (match as Record<string, unknown>).attribute ?? "recorded",
              )}
            </li>
          ))}
          {differences.map((difference, index) => (
            <li key={`difference-${index}`}>
              Difference:{" "}
              {String(
                (difference as Record<string, unknown>).attribute ?? "recorded",
              )}{" "}
              — vendor{" "}
              {String(
                (difference as Record<string, unknown>).vendor_value ??
                  "not supplied",
              )}
              , candidate{" "}
              {String(
                (difference as Record<string, unknown>).candidate_value ??
                  "not supplied",
              )}
            </li>
          ))}
        </ul>
      </Card>
      {initial.status === "pending" ? (
        <div className="platform-detail-grid">
          <Card>
            <h2>Accept candidate</h2>
            <p>
              Accepting records a manual, auditable mapping. It does not erase
              earlier mappings.
            </p>
            <Button
              disabled={pending}
              onClick={() => void submit({ action: "accept" })}
            >
              {pending ? "Saving…" : "Accept"}
            </Button>
          </Card>
          <Card>
            <h2>Reject candidate</h2>
            <form className="platform-form" onSubmit={reject}>
              <Input
                label="Rejection reason"
                name="reason"
                required
                minLength={3}
                maxLength={1000}
              />
              <Button variant="danger" type="submit" disabled={pending}>
                {pending ? "Saving…" : "Reject"}
              </Button>
            </form>
          </Card>
          <Card>
            <h2>Choose existing canonical product</h2>
            <form className="platform-form" onSubmit={chooseExisting}>
              <Select
                label="Choose canonical product"
                name="canonical_product_id"
                required
              >
                <option value="">Select a canonical product</option>
                {choices.map((choice) => (
                  <option key={choice.id} value={choice.id}>
                    {choice.english_name}
                  </option>
                ))}
              </Select>
              <Select
                label="Match class"
                name="match_class"
                defaultValue="equivalent_product"
              >
                <option value="exact_match">Exact match</option>
                <option value="equivalent_product">Equivalent product</option>
                <option value="comparable_alternative">
                  Comparable alternative
                </option>
                <option value="related_product">Related product</option>
                <option value="not_comparable">Not comparable</option>
              </Select>
              <Select
                label="Match confidence"
                name="confidence"
                defaultValue="B"
              >
                {["A", "B", "C", "D", "E"].map((confidence) => (
                  <option key={confidence} value={confidence}>
                    {confidence}
                  </option>
                ))}
              </Select>
              <Button type="submit" disabled={pending}>
                {pending ? "Saving…" : "Choose existing"}
              </Button>
            </form>
          </Card>
          <Card>
            <h2>Create canonical product</h2>
            <p>
              Use an English source-of-truth name. The supplier description is
              not copied automatically.
            </p>
            <form className="platform-form" onSubmit={createCanonical}>
              <Input
                label="Canonical English name"
                name="english_name"
                required
                minLength={2}
                maxLength={255}
              />
              <Input
                label="Spanish alias (optional)"
                name="spanish_alias"
                maxLength={255}
              />
              <Button type="submit" disabled={pending}>
                {pending ? "Saving…" : "Create canonical product"}
              </Button>
            </form>
          </Card>
        </div>
      ) : (
        <Card>
          <h2>Review status</h2>
          <p>
            This candidate is {initial.status.replaceAll("_", " ")}. Its review
            and mapping history remain available below.
          </p>
        </Card>
      )}
      <Card>
        <h2>Mapping and review history</h2>
        <ul className="platform-audit-list">
          {initial.mapping_history.map((mapping) => (
            <li key={mapping.id}>
              {mapping.canonical_name} ·{" "}
              {mapping.match_class.replaceAll("_", " ")} ·{" "}
              {mapping.is_current ? "current" : "historical"}
            </li>
          ))}
          {initial.review_history.map((review) => (
            <li key={review.id}>
              {review.action.replaceAll("_", " ")} by {review.actor}
              {review.reason ? ` · ${review.reason}` : ""}
            </li>
          ))}
        </ul>
      </Card>
      <ConfirmationDialog
        open={confirmCreate !== null}
        onClose={() => !pending && setConfirmCreate(null)}
        onConfirm={() => confirmCreate && void submit(confirmCreate)}
        title="Create canonical product"
        confirmLabel="Create and map"
        pending={pending}
      >
        <p>
          This creates a shared catalog record and a manual mapping. Duplicate
          protection is checked again by the server.
        </p>
      </ConfirmationDialog>
    </div>
  );
}
