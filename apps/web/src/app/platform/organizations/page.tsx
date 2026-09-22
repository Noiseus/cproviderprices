import Link from "next/link";
import {
  Badge,
  DataTableShell,
  ErrorState,
  FilterBar,
  PageHeader,
} from "@/components/ui";
import {
  platformListQuery,
  platformServerRequest,
  type PlatformOrganizationSummary,
} from "@/lib/platform-api";

type SearchValues = Record<string, string | string[] | undefined>;
type ListResponse = {
  organizations: PlatformOrganizationSummary[];
  pagination: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
  filters: {
    search: string | null;
    status: string | null;
    subscription_status: string | null;
  };
};

function pageHref(values: SearchValues, page: number): string {
  return `/platform/organizations?${platformListQuery({ ...values, page: String(page) })}`;
}

export default async function PlatformOrganizationsPage({
  searchParams,
}: {
  searchParams: Promise<SearchValues>;
}) {
  const values = await searchParams;
  const query = platformListQuery(values);
  let result: ListResponse;
  try {
    result = await platformServerRequest<ListResponse>(
      `/api/v1/platform/organizations${query ? `?${query}` : ""}`,
    );
  } catch (error) {
    return (
      <ErrorState
        title="Unable to load organizations"
        description={
          error instanceof Error
            ? error.message
            : "The organization list is unavailable."
        }
      />
    );
  }

  const returnContext = platformListQuery(values);
  return (
    <div className="platform-stack">
      <PageHeader
        eyebrow="Platform scope"
        title="Organizations"
        description="Search and administer customer provisioning without entering tenant context."
        actions={
          <Link
            className="platform-link-button"
            href="/platform/organizations/new"
          >
            Create organization
          </Link>
        }
      />
      <form method="get">
        <FilterBar>
          <label className="ui-field">
            <span>Search</span>
            <input
              className="ui-input"
              type="search"
              name="search"
              defaultValue={result.filters.search ?? ""}
              maxLength={100}
              placeholder="Organization name or slug"
            />
          </label>
          <label className="ui-field">
            <span>Organization status</span>
            <select
              className="ui-select"
              name="status"
              defaultValue={result.filters.status ?? ""}
            >
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="suspended">Suspended</option>
              <option value="closed">Closed</option>
            </select>
          </label>
          <label className="ui-field">
            <span>Trial/subscription</span>
            <select
              className="ui-select"
              name="subscription_status"
              defaultValue={result.filters.subscription_status ?? ""}
            >
              <option value="">All subscriptions</option>
              <option value="trialing">Trialing</option>
              <option value="active">Active</option>
              <option value="past_due">Past due</option>
              <option value="suspended">Suspended</option>
              <option value="canceled">Canceled</option>
              <option value="expired">Expired</option>
            </select>
          </label>
          <button className="ui-button ui-button--primary" type="submit">
            Apply filters
          </button>
          <Link href="/platform/organizations">Clear</Link>
        </FilterBar>
      </form>
      <p>
        {result.pagination.total} organization
        {result.pagination.total === 1 ? "" : "s"}
      </p>
      <DataTableShell
        caption="organizations"
        empty={result.organizations.length === 0}
      >
        <table>
          <thead>
            <tr>
              <th>Organization</th>
              <th>Organization status</th>
              <th>Plan</th>
              <th>Subscription</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            {result.organizations.map((organization) => (
              <tr key={organization.id}>
                <td>
                  <Link
                    href={`/platform/organizations/${organization.id}${returnContext ? `?return=${encodeURIComponent(returnContext)}` : ""}`}
                  >
                    {organization.name}
                  </Link>
                  <small className="platform-cell-detail">
                    {organization.slug}
                  </small>
                </td>
                <td>
                  <Badge
                    tone={
                      organization.status === "active"
                        ? "success"
                        : organization.status === "suspended"
                          ? "warning"
                          : "neutral"
                    }
                  >
                    {organization.status}
                  </Badge>
                </td>
                <td>
                  {organization.subscription?.plan_code ?? "Not configured"}
                </td>
                <td>
                  <Badge
                    tone={
                      organization.subscription?.status === "active" ||
                      organization.subscription?.status === "trialing"
                        ? "success"
                        : organization.subscription?.status === "suspended"
                          ? "danger"
                          : "neutral"
                    }
                  >
                    {organization.subscription?.status.replaceAll("_", " ") ??
                      "none"}
                  </Badge>
                </td>
                <td>
                  {new Date(organization.created_at).toLocaleDateString(
                    "en-US",
                    { timeZone: "UTC" },
                  )}
                </td>
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
