import { redirect } from "next/navigation";
import type { ReactNode } from "react";
import { AppShell } from "@/components/app-shell/AppShell";
import {
  ShellFailure,
  SuspendedWorkspace,
} from "@/components/app-shell/ShellStates";
import { currentSessionResult } from "@/lib/server-session";
import { organizationSubscription } from "@/lib/server-subscription";

export default async function OrganizationLayout({
  children,
  params,
}: {
  children: ReactNode;
  params: Promise<{ organizationSlug: string }>;
}) {
  const { organizationSlug } = await params;
  const sessionResult = await currentSessionResult();
  if (sessionResult.kind === "unauthenticated")
    redirect(
      `/login?next=${encodeURIComponent(`/o/${organizationSlug}/dashboard`)}`,
    );
  if (sessionResult.kind !== "authenticated")
    return <ShellFailure kind={sessionResult.kind} />;

  const session = sessionResult.data.session;
  const organization = session.organizations.find(
    (item) => item.slug === organizationSlug,
  );
  if (!organization || organization.status === "closed")
    return <ShellFailure kind="forbidden" />;

  const subscriptionResult = await organizationSubscription(organization.id);
  const subscription = subscriptionResult.data;
  if (organization.status === "suspended")
    return (
      <SuspendedWorkspace
        organization={organization}
        subscription={subscription}
      />
    );

  return (
    <AppShell
      user={session.user}
      organization={organization}
      organizations={session.organizations}
      subscription={subscription}
      subscriptionProblem={subscriptionResult.error !== null}
    >
      {children}
    </AppShell>
  );
}
