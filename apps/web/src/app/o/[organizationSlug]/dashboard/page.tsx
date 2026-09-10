import { redirect } from "next/navigation";
import Link from "next/link";
import { LogoutButton } from "@/components/LogoutButton";
import { OrganizationSwitcher } from "@/components/OrganizationSwitcher";
import { currentSession } from "@/lib/server-session";

export default async function DashboardPage({
  params,
}: {
  params: Promise<{ organizationSlug: string }>;
}) {
  const { organizationSlug } = await params;
  const session = await currentSession();
  if (!session)
    redirect(
      `/login?next=${encodeURIComponent(`/o/${organizationSlug}/dashboard`)}`,
    );

  const organization = session.session.organizations.find(
    (item) => item.slug === organizationSlug,
  );
  if (!organization)
    return (
      <main>
        <h1>Organization unavailable</h1>
        <p>You do not have access to this organization.</p>
        <LogoutButton />
      </main>
    );
  if (organization.status === "closed")
    return (
      <main>
        <h1>Organization unavailable</h1>
        <p>
          This organization is closed and private functionality is unavailable.
        </p>
        <LogoutButton />
      </main>
    );
  if (organization.status === "suspended")
    return (
      <main>
        <h1>{organization.name} is suspended</h1>
        <p>
          Normal private functionality is unavailable. Contact support or your
          account administrator.
        </p>
        <LogoutButton />
      </main>
    );

  const activeOrganizations = session.session.organizations.filter(
    (item) => item.status === "active",
  );
  return (
    <main>
      <p className="eyebrow">Protected organization workspace</p>
      <h1>{organization.name}</h1>
      <p>
        Signed in as {session.session.user.email}. Your role is{" "}
        {organization.role.replaceAll("_", " ")}.
      </p>
      <p>
        This protected placeholder confirms the organization access boundary.
        Dashboard features are intentionally deferred.
      </p>
      <p>
        <Link href={`/o/${organization.slug}/subscription`}>
          View subscription and capabilities
        </Link>
      </p>
      <OrganizationSwitcher
        current={organization}
        organizations={activeOrganizations}
      />
      <LogoutButton />
    </main>
  );
}
