import Link from "next/link";
import {
  Badge,
  Card,
  DataTableShell,
  ErrorState,
  PageHeader,
  StatCard,
} from "@/components/ui";
import {
  platformServerRequest,
  type PlatformOverview,
} from "@/lib/platform-api";

export default async function PlatformOverviewPage() {
  let overview: PlatformOverview;
  try {
    const response = await platformServerRequest<{
      platform_overview: PlatformOverview;
    }>("/api/v1/platform/overview");
    overview = response.platform_overview;
  } catch (error) {
    return (
      <ErrorState
        title="Unable to load platform overview"
        description={
          error instanceof Error
            ? error.message
            : "The overview is unavailable."
        }
      />
    );
  }

  return (
    <div className="platform-stack">
      <PageHeader
        eyebrow="Platform scope"
        title="Overview"
        description="Safe provisioning and lifecycle status across customer organizations."
      />
      <div className="platform-stats">
        <StatCard
          label="Organizations"
          value={String(overview.organization_counts.total)}
          detail={`${overview.organization_counts.active} active`}
        />
        <StatCard
          label="Suspended"
          value={String(overview.organization_counts.suspended)}
        />
        <StatCard
          label="Active trials"
          value={String(overview.trialing_subscriptions)}
        />
        <StatCard
          label="Pending invitations"
          value={String(overview.pending_invitations)}
        />
      </div>
      <section>
        <h2>Recently created organizations</h2>
        <DataTableShell
          caption="recent organizations"
          empty={overview.recent_organizations.length === 0}
        >
          <table>
            <thead>
              <tr>
                <th>Name</th>
                <th>Status</th>
                <th>Created</th>
              </tr>
            </thead>
            <tbody>
              {overview.recent_organizations.map((organization) => (
                <tr key={organization.id}>
                  <td>
                    <Link href={`/platform/organizations/${organization.id}`}>
                      {organization.name}
                    </Link>
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
                    {new Date(organization.created_at).toLocaleDateString(
                      "en-US",
                      { timeZone: "UTC" },
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </DataTableShell>
      </section>
      <Card>
        <h2>Recent platform audit activity</h2>
        {overview.recent_audit_activity.length === 0 ? (
          <p>No recent platform activity.</p>
        ) : (
          <ul className="platform-audit-list">
            {overview.recent_audit_activity.map((audit) => (
              <li key={audit.id}>
                <strong>{audit.action.replaceAll("_", " ")}</strong>
                <span>
                  {audit.actor_label ?? "System"} ·{" "}
                  {new Date(audit.occurred_at).toLocaleString("en-US", {
                    timeZone: "UTC",
                  })}{" "}
                  UTC
                </span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
