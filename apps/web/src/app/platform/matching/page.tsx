import Link from "next/link";
import {
  DataTableShell,
  ErrorState,
  FilterBar,
  PageHeader,
} from "@/components/ui";
import { MatchingConfidenceBadge } from "@/components/platform/MatchingConfidenceBadge";
import {
  matchingListQuery,
  platformServerRequest,
  type PlatformMatchCandidate,
} from "@/lib/platform-api";

type SearchValues = Record<string, string | string[] | undefined>;
type MatchingList = {
  candidates: PlatformMatchCandidate[];
  pagination: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
  filters: {
    supplier_account_id: string | null;
    confidence: string | null;
    category_id: string | null;
    age_days: number | null;
    status: string | null;
  };
  filter_options: {
    suppliers: Array<{ id: string; name: string }>;
    categories: Array<{ id: string; name: string }>;
  };
};

function pageHref(values: SearchValues, page: number) {
  return `/platform/matching?${matchingListQuery({ ...values, page: String(page) })}`;
}

export default async function PlatformMatchingPage({
  searchParams,
}: {
  searchParams: Promise<SearchValues>;
}) {
  const values = await searchParams;
  const query = matchingListQuery(values);
  let result: MatchingList;
  try {
    result = await platformServerRequest<MatchingList>(
      `/api/v1/platform/matching/candidates${query ? `?${query}` : ""}`,
    );
  } catch (error) {
    return (
      <ErrorState
        title="Unable to load matching review"
        description={
          error instanceof Error
            ? error.message
            : "The matching review queue is unavailable."
        }
      />
    );
  }
  const returnContext = matchingListQuery(values);

  return (
    <div className="platform-stack">
      <PageHeader
        eyebrow="Platform scope"
        title="Matching review"
        description="Review tenant-private supplier items against the shared canonical catalog. Uncertain candidates are never published as exact matches automatically."
      />
      <form method="get">
        <FilterBar>
          <label className="ui-field">
            <span>Supplier</span>
            <select
              className="ui-select"
              name="supplier_account_id"
              defaultValue={result.filters.supplier_account_id ?? ""}
            >
              <option value="">All suppliers</option>
              {result.filter_options.suppliers.map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplier.name}
                </option>
              ))}
            </select>
          </label>
          <label className="ui-field">
            <span>Match confidence</span>
            <select
              className="ui-select"
              name="confidence"
              defaultValue={result.filters.confidence ?? ""}
            >
              <option value="">All confidence levels</option>
              {["A", "B", "C", "D", "E"].map((confidence) => (
                <option key={confidence} value={confidence}>
                  {confidence}
                </option>
              ))}
            </select>
          </label>
          <label className="ui-field">
            <span>Category</span>
            <select
              className="ui-select"
              name="category_id"
              defaultValue={result.filters.category_id ?? ""}
            >
              <option value="">All categories</option>
              {result.filter_options.categories.map((category) => (
                <option key={category.id} value={category.id}>
                  {category.name}
                </option>
              ))}
            </select>
          </label>
          <label className="ui-field">
            <span>Age at least</span>
            <select
              className="ui-select"
              name="age_days"
              defaultValue={result.filters.age_days?.toString() ?? ""}
            >
              <option value="">Any age</option>
              <option value="1">1 day</option>
              <option value="7">7 days</option>
              <option value="30">30 days</option>
            </select>
          </label>
          <label className="ui-field">
            <span>Status</span>
            <select
              className="ui-select"
              name="status"
              defaultValue={result.filters.status ?? "pending"}
            >
              <option value="pending">Pending review</option>
              <option value="auto_published">Auto-published</option>
              <option value="accepted">Accepted</option>
              <option value="rejected">Rejected</option>
              <option value="superseded">Superseded</option>
            </select>
          </label>
          <button className="ui-button ui-button--primary" type="submit">
            Apply filters
          </button>
          <Link href="/platform/matching">Clear</Link>
        </FilterBar>
      </form>
      <p>
        {result.pagination.total} candidate
        {result.pagination.total === 1 ? "" : "s"}
      </p>
      <DataTableShell
        caption="matching candidates"
        empty={result.candidates.length === 0}
      >
        <table>
          <thead>
            <tr>
              <th>Vendor item</th>
              <th>Supplier</th>
              <th>Candidate</th>
              <th>Confidence</th>
              <th>Evidence</th>
              <th>Status</th>
            </tr>
          </thead>
          <tbody>
            {result.candidates.map((candidate) => (
              <tr key={candidate.id}>
                <td>
                  <Link
                    href={`/platform/matching/${candidate.id}${returnContext ? `?return=${encodeURIComponent(returnContext)}` : ""}`}
                  >
                    {candidate.vendor_item.raw_description}
                  </Link>
                </td>
                <td>{candidate.vendor_item.supplier.display_name}</td>
                <td>
                  {candidate.candidate_product.english_name}
                  <small className="platform-cell-detail">
                    {candidate.candidate_product.category.english_name}
                  </small>
                </td>
                <td>
                  <MatchingConfidenceBadge confidence={candidate.confidence} />
                </td>
                <td>
                  {candidate.evidence_summary.stage?.replaceAll("_", " ") ??
                    "Recorded evidence"}
                  {candidate.evidence_summary.hard_contradictions > 0 && (
                    <small className="platform-cell-detail">
                      {candidate.evidence_summary.hard_contradictions}{" "}
                      contradiction
                      {candidate.evidence_summary.hard_contradictions === 1
                        ? ""
                        : "s"}
                    </small>
                  )}
                </td>
                <td>{candidate.status.replaceAll("_", " ")}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {result.pagination.total_pages > 1 && (
          <nav className="ui-pagination" aria-label="Pagination">
            {result.pagination.page > 1 ? (
              <Link href={pageHref(values, result.pagination.page - 1)}>
                Previous
              </Link>
            ) : (
              <span aria-disabled="true">Previous</span>
            )}
            <span>
              Page {result.pagination.page} of {result.pagination.total_pages}
            </span>
            {result.pagination.page < result.pagination.total_pages ? (
              <Link href={pageHref(values, result.pagination.page + 1)}>
                Next
              </Link>
            ) : (
              <span aria-disabled="true">Next</span>
            )}
          </nav>
        )}
      </DataTableShell>
    </div>
  );
}
