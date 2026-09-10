import Link from "next/link";
import type { OrganizationSession } from "@/lib/server-session";
import type { SubscriptionStatusPayload } from "@/lib/server-subscription";
import { ErrorState, PermissionState } from "../ui";

export function ShellFailure({
  kind,
}: {
  kind: "forbidden" | "offline" | "unexpected";
}) {
  const content =
    kind === "forbidden"
      ? {
          title: "Permission required",
          description: "Your account is not authorized for this workspace.",
        }
      : kind === "offline"
        ? {
            title: "You appear to be offline",
            description: "Reconnect and reload to return to your workspace.",
          }
        : {
            title: "Workspace unavailable",
            description:
              "An unexpected error prevented the workspace from loading.",
          };
  return (
    <main className="shell-status">
      <div>
        {kind === "forbidden" ? (
          <PermissionState {...content} />
        ) : (
          <ErrorState {...content} />
        )}
        <Link href="/login">Return to sign in</Link>
      </div>
    </main>
  );
}

export function SuspendedWorkspace({
  organization,
  subscription,
}: {
  organization: OrganizationSession;
  subscription: SubscriptionStatusPayload | null;
}) {
  return (
    <main className="shell-status">
      <div>
        <p className="eyebrow">Account status</p>
        <PermissionState
          title={`${organization.name} is suspended`}
          description="Normal private workspace access is unavailable. Organization data and history remain preserved."
        />
        <section className="ui-card">
          <h2>Subscription</h2>
          <p>{subscription?.plan?.name ?? "Plan unavailable"}</p>
          <p>
            Status:{" "}
            {subscription?.subscription?.status.replaceAll("_", " ") ??
              "unavailable"}
          </p>
        </section>
        <p>
          Contact CProviderPrices support or your account administrator to
          restore access.
        </p>
      </div>
    </main>
  );
}
