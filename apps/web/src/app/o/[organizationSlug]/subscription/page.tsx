"use client";

import { useWorkspace } from "@/components/app-shell/AppShellContext";
import { EntitlementNotice } from "@/components/EntitlementNotice";
import { Badge, Card, EmptyState, PageHeader } from "@/components/ui";
import { useTranslation } from "@/components/i18n/I18nProvider";

const capabilityKeys: Record<string, string> = {
  manual_imports_enabled: "subscription.manualImports",
  automatic_sync_enabled: "subscription.automaticSync",
  price_history_enabled: "subscription.priceHistory",
  basket_optimizer_enabled: "subscription.basketOptimizer",
};
const limitKeys: Record<string, string> = {
  max_locations: "subscription.restaurantLocations",
  max_users: "subscription.users",
  max_supplier_accounts: "subscription.supplierAccounts",
};

export default function SubscriptionPage() {
  const {
    organization,
    subscription: status,
    locations,
    locationId,
  } = useWorkspace();
  const { formatDateTime, t } = useTranslation();
  const timeZone = (locations ?? []).find(
    (location) => location.id === locationId,
  )?.timezone;
  if (!status)
    return (
      <EmptyState
        title={t("subscription.statusUnavailable")}
        description={t("subscription.statusUnavailableDescription")}
      />
    );
  const subscription = status.subscription;

  return (
    <div className="route-placeholder">
      <PageHeader
        eyebrow={t("subscription.account")}
        title={t("subscription.title")}
        description={t("subscription.description", { name: organization.name })}
        actions={
          <Badge tone={subscription?.access_available ? "success" : "warning"}>
            {subscription?.status
              ? t(`subscription.${subscription.status}`)
              : t("subscription.notConfigured")}
          </Badge>
        }
      />
      {subscription?.status === "past_due" && (
        <EntitlementNotice
          kind="unavailable"
          title={t("subscription.past_due")}
        >
          <p>{t("subscription.pastDueNotice")}</p>
        </EntitlementNotice>
      )}
      {subscription && !subscription.access_available && (
        <EntitlementNotice
          kind="suspended"
          title={`${t("subscription.title")}: ${t(`subscription.${subscription.status}`)}`}
        >
          <p>{t("subscription.gatedNotice")}</p>
        </EntitlementNotice>
      )}
      {!subscription && (
        <EntitlementNotice
          kind="unavailable"
          title={t("subscription.noSubscription")}
        >
          <p>{t("subscription.noSubscriptionDescription")}</p>
        </EntitlementNotice>
      )}

      <Card>
        <h2>{t("subscription.plan")}</h2>
        <p>{status.plan?.name ?? t("subscription.notConfigured")}</p>
        <dl>
          <div>
            <dt>{t("common.status")}</dt>
            <dd>
              {subscription?.status
                ? t(`subscription.${subscription.status}`)
                : t("common.none")}
            </dd>
          </div>
          {subscription?.status === "trialing" &&
            subscription.trial_ends_at && (
              <div>
                <dt>{t("subscription.trialEnds")}</dt>
                <dd>{formatDateTime(subscription.trial_ends_at, timeZone)}</dd>
              </div>
            )}
        </dl>
      </Card>
      <section>
        <h2>{t("subscription.capabilities")}</h2>
        <ul className="capability-list">
          {Object.entries(status.capabilities).map(([key, enabled]) => (
            <li key={key}>
              <span>
                {capabilityKeys[key]
                  ? t(capabilityKeys[key])
                  : key.replaceAll("_", " ")}
              </span>
              <Badge tone={enabled ? "success" : "neutral"}>
                {enabled
                  ? t("subscription.enabled")
                  : t("subscription.unavailable")}
              </Badge>
            </li>
          ))}
        </ul>
      </section>
      <section>
        <h2>{t("subscription.limitsUsage")}</h2>
        <ul className="capability-list">
          {Object.entries(status.limits).map(([key, value]) => (
            <li key={key}>
              <span>
                {limitKeys[key] ? t(limitKeys[key]) : key.replaceAll("_", " ")}
              </span>
              <strong>
                {value.current_usage === null || value.limit === null
                  ? t("subscription.futureModule")
                  : t("subscription.usageValue", {
                      current: value.current_usage,
                      limit: value.limit,
                    })}
              </strong>
              {value.reached && (
                <span className="limit-text">
                  {t("subscription.limitReached")}
                </span>
              )}
            </li>
          ))}
        </ul>
      </section>
      <EntitlementNotice
        kind="unavailable"
        title={t("subscription.needCapability")}
      >
        <p>{t("subscription.contactSupport")}</p>
      </EntitlementNotice>
    </div>
  );
}
