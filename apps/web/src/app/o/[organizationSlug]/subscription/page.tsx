"use client";

import { useWorkspace } from "@/components/app-shell/AppShellContext";
import { EntitlementNotice } from "@/components/EntitlementNotice";
import { Badge, Card, EmptyState, PageHeader } from "@/components/ui";

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

export default function SubscriptionPage() {
  const { organization, subscription: status } = useWorkspace();
  if (!status)
    return (
      <EmptyState
        title="Subscription status unavailable"
        description="Plan information could not be loaded. The workspace remains safely restricted."
      />
    );
  const subscription = status.subscription;

  return (
    <div className="route-placeholder">
      <PageHeader
        eyebrow="Account"
        title="Subscription"
        description={`Plan status and capabilities for ${organization.name}.`}
        actions={
          <Badge tone={subscription?.access_available ? "success" : "warning"}>
            {subscription?.status.replaceAll("_", " ") ?? "not configured"}
          </Badge>
        }
      />
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

      <Card>
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
      </Card>
      <section>
        <h2>Capabilities</h2>
        <ul className="capability-list">
          {Object.entries(status.capabilities).map(([key, enabled]) => (
            <li key={key}>
              <span>{capabilityLabels[key] ?? key.replaceAll("_", " ")}</span>
              <Badge tone={enabled ? "success" : "neutral"}>
                {enabled ? "Enabled" : "Unavailable"}
              </Badge>
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
    </div>
  );
}
