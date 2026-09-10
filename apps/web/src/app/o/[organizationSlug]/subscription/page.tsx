import Link from "next/link";
import { redirect } from "next/navigation";
import { EntitlementNotice } from "@/components/EntitlementNotice";
import { LogoutButton } from "@/components/LogoutButton";
import { entitlementErrorState } from "@/lib/entitlement-state";
import { currentSession } from "@/lib/server-session";
import { organizationSubscription } from "@/lib/server-subscription";

const capabilityLabels: Record<string, string> = {
  manual_imports_enabled: "Manual imports",
  automatic_sync_enabled: "Automatic sync",
  price_history_enabled: "Price history",
  basket_optimizer_enabled: "Basket optimizer",
};

const limitLabels: Record<string, string> = {
  max_locations: "Restaurant locations",
  max_users: "Users",
  max_supplier_accounts: "Supplier accounts",
};

export default async function SubscriptionPage({
  params,
}: {
  params: Promise<{ organizationSlug: string }>;
}) {
  const { organizationSlug } = await params;
  const session = await currentSession();
  if (!session)
    redirect(
      `/login?next=${encodeURIComponent(`/o/${organizationSlug}/subscription`)}`,
    );

  const organization = session.session.organizations.find(
    (item) => item.slug === organizationSlug,
  );
  if (!organization || organization.status === "closed") {
    return (
      <main>
        <h1>Organization unavailable</h1>
        <p>You do not have access to this organization.</p>
        <LogoutButton />
      </main>
    );
  }

  const result = await organizationSubscription(organization.id);
  if (result.error) {
    const state = entitlementErrorState(result.error);
    return (
      <main>
        <h1>Subscription status</h1>
        <EntitlementNotice kind={state.kind} title={state.message}>
          <p>{state.detail}</p>
          <small>Request ID: {state.requestId}</small>
        </EntitlementNotice>
        <LogoutButton />
      </main>
    );
  }

  const status = result.data;
  const subscription = status.subscription;
  return (
    <main>
      <p className="eyebrow">Subscription status</p>
      <h1>{organization.name}</h1>
      <p>
        <Link href={`/o/${organization.slug}/dashboard`}>
          Back to dashboard
        </Link>
      </p>

      {organization.status === "suspended" && (
        <EntitlementNotice kind="suspended" title="Organization suspended">
          <p>
            Normal private functionality is unavailable. Your data is preserved.
          </p>
        </EntitlementNotice>
      )}
      {subscription?.status === "past_due" && (
        <EntitlementNotice kind="unavailable" title="Subscription past due">
          <p>
            Access remains available during this status. Contact your account
            administrator.
          </p>
        </EntitlementNotice>
      )}
      {subscription && !subscription.access_available && (
        <EntitlementNotice
          kind="suspended"
          title={`Subscription ${subscription.status}`}
        >
          <p>
            Entitlement-gated actions are unavailable. Existing organization
            data is preserved.
          </p>
        </EntitlementNotice>
      )}
      {!subscription && (
        <EntitlementNotice
          kind="unavailable"
          title="No subscription configured"
        >
          <p>
            Contact support or your account administrator to enable plan
            capabilities.
          </p>
        </EntitlementNotice>
      )}

      <section className="status-card">
        <h2>Plan</h2>
        <p>{status.plan?.name ?? "Not configured"}</p>
        <dl>
          <div>
            <dt>Status</dt>
            <dd>{subscription?.status.replaceAll("_", " ") ?? "none"}</dd>
          </div>
          {subscription?.status === "trialing" &&
            subscription.trial_ends_at && (
              <div>
                <dt>Trial ends</dt>
                <dd>
                  {new Date(subscription.trial_ends_at).toLocaleString(
                    "en-US",
                    { timeZone: "UTC" },
                  )}{" "}
                  UTC
                </dd>
              </div>
            )}
        </dl>
      </section>

      <section>
        <h2>Capabilities</h2>
        <ul className="capability-list">
          {Object.entries(status.capabilities).map(([key, enabled]) => (
            <li key={key}>
              <span>{capabilityLabels[key] ?? key.replaceAll("_", " ")}</span>
              <strong>{enabled ? "Enabled" : "Unavailable"}</strong>
            </li>
          ))}
        </ul>
      </section>

      <section>
        <h2>Limits and usage</h2>
        <ul className="capability-list">
          {Object.entries(status.limits).map(([key, value]) => (
            <li key={key}>
              <span>{limitLabels[key] ?? key.replaceAll("_", " ")}</span>
              <strong>
                {value.current_usage === null || value.limit === null
                  ? "Available in a future module"
                  : `${value.current_usage} of ${value.limit}`}
              </strong>
              {value.reached && (
                <span className="limit-text">Limit reached</span>
              )}
            </li>
          ))}
        </ul>
      </section>

      <EntitlementNotice
        kind="unavailable"
        title="Need a different capability or limit?"
      >
        <p>
          Contact your account administrator or CProviderPrices support. Online
          checkout is not available.
        </p>
      </EntitlementNotice>
      <LogoutButton />
    </main>
  );
}
