"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import { useEffect, useMemo, useState, type ReactNode } from "react";
import { apiRequest } from "@/lib/api";
import type { Location } from "@/lib/customer-api";
import { buildNavigation, titleForSegment } from "@/lib/navigation";
import type { OrganizationSession, SessionPayload } from "@/lib/server-session";
import type { SubscriptionStatusPayload } from "@/lib/server-subscription";
import { ThemeToggle } from "../ThemeToggle";
import { I18nProvider, useTranslation } from "../i18n/I18nProvider";
import { LanguageSelector } from "../i18n/LanguageSelector";
import {
  Button,
  Drawer,
  Dropdown,
  IconButton,
  PartialDataWarning,
  Select,
} from "../ui";
import { WorkspaceProvider } from "./AppShellContext";

type Props = {
  user: SessionPayload["session"]["user"];
  organization: OrganizationSession;
  organizations: OrganizationSession[];
  subscription: SubscriptionStatusPayload | null;
  subscriptionProblem?: boolean;
  children: ReactNode;
  locations: Location[];
};

export function AppShell(props: Props) {
  return (
    <I18nProvider initialLocale={props.user.preferred_language}>
      <AppShellContent {...props} />
    </I18nProvider>
  );
}

function AppShellContent(props: Props) {
  const {
    user,
    organization,
    organizations,
    subscription,
    subscriptionProblem,
    children,
    locations,
  } = props;
  const pathname = usePathname();
  const router = useRouter();
  const { t } = useTranslation();
  const [compact, setCompact] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [switching, setSwitching] = useState(false);
  const activeLocations = useMemo(
    () => locations.filter((location) => location.status === "active"),
    [locations],
  );
  const [locationId, setLocationId] = useState(activeLocations[0]?.id ?? "");
  const segment = pathname.split("/").filter(Boolean)[2] ?? "dashboard";
  const capabilities = subscription?.capabilities ?? {};
  const navigation = buildNavigation(organization.role, capabilities, t);

  useEffect(() => {
    setCompact(localStorage.getItem("cproviderprices-sidebar") === "compact");
    const locationKey = `cproviderprices-location:${organization.id}`;
    const stored = localStorage.getItem(locationKey);
    if (stored && activeLocations.some((location) => location.id === stored))
      setLocationId(stored);
    else setLocationId(activeLocations[0]?.id ?? "");
  }, [activeLocations, organization.id]);

  const navigationContent = (mobile = false) => (
    <nav
      className="app-nav"
      aria-label={t(mobile ? "nav.mobileWorkspace" : "nav.workspace")}
    >
      {navigation.map((item) => {
        const href = `/o/${organization.slug}/${item.segment}`;
        const active = segment === item.segment;
        return item.available ? (
          <Link
            key={item.segment}
            href={href}
            prefetch={item.prefetch}
            aria-current={active ? "page" : undefined}
            onClick={() => mobile && setMobileOpen(false)}
          >
            <span className="app-nav__icon" aria-hidden="true">
              {item.icon}
            </span>
            <span className="app-sidebar__label">{item.label}</span>
          </Link>
        ) : (
          <span
            key={item.segment}
            className="app-nav__disabled"
            aria-disabled="true"
            title={item.unavailableReason}
          >
            <span className="app-nav__icon" aria-hidden="true">
              {item.icon}
            </span>
            <span className="app-sidebar__label">
              {item.label} · unavailable
            </span>
          </span>
        );
      })}
    </nav>
  );

  return (
    <WorkspaceProvider
      value={{
        user,
        organization,
        organizations,
        subscription,
        locations,
        locationId,
      }}
    >
      <div
        className={`app-shell${compact ? " is-compact" : ""}`}
        data-testid="app-shell"
      >
        <aside className="app-sidebar">
          <Link
            className="app-sidebar__brand"
            href={`/o/${organization.slug}/dashboard`}
            prefetch
          >
            <span className="brand-mark">CP</span>
            <span>CProviderPrices</span>
          </Link>
          <div className="app-sidebar__context">
            <small>{t("nav.organization")}</small>
            <strong>{organization.name}</strong>
            <small>{t(`roles.${organization.role}`)}</small>
          </div>
          {navigationContent()}
          <div className="app-sidebar__footer">
            <IconButton
              variant="quiet"
              label={t(compact ? "nav.expandSidebar" : "nav.collapseSidebar")}
              aria-pressed={compact}
              onClick={() => {
                const next = !compact;
                setCompact(next);
                localStorage.setItem(
                  "cproviderprices-sidebar",
                  next ? "compact" : "expanded",
                );
              }}
            >
              {compact ? "→" : "←"}
            </IconButton>
          </div>
        </aside>

        <div className="app-workspace">
          <header className="app-topbar">
            <IconButton
              className="app-topbar__mobile-menu"
              variant="secondary"
              label={t("nav.openNavigation")}
              onClick={() => setMobileOpen(true)}
            >
              ☰
            </IconButton>
            <div className="app-topbar__location">
              <Select
                label={t("nav.locationContext")}
                value={locationId}
                onChange={(event) => {
                  const next = event.target.value;
                  if (!activeLocations.some((location) => location.id === next))
                    return;
                  void apiRequest(
                    `/api/v1/organizations/${organization.id}/restaurant-locations/${next}/select`,
                    { method: "POST", body: JSON.stringify({}) },
                  ).then(() => {
                    setLocationId(next);
                    localStorage.setItem(
                      `cproviderprices-location:${organization.id}`,
                      next,
                    );
                    window.dispatchEvent(
                      new CustomEvent("workspace-location-changed", {
                        detail: { locationId: next },
                      }),
                    );
                  });
                }}
              >
                {activeLocations.length === 0 && (
                  <option value="">{t("nav.noActiveLocations")}</option>
                )}
                {activeLocations.map((location) => (
                  <option key={location.id} value={location.id}>
                    {location.name}
                  </option>
                ))}
              </Select>
            </div>
            <div className="app-topbar__spacer" />
            <ThemeToggle compact />
            <LanguageSelector compact />
            <Dropdown
              label={
                <span className="account-summary">
                  <span className="account-avatar" aria-hidden="true">
                    {user.name.charAt(0).toUpperCase()}
                  </span>
                  <span>{user.name}</span>
                </span>
              }
            >
              <small>{user.email}</small>
              {organizations.filter((item) => item.status === "active").length >
                1 && (
                <Select
                  label={t("nav.organization")}
                  value={organization.id}
                  disabled={switching}
                  onChange={async (event) => {
                    const selected = organizations.find(
                      (item) =>
                        item.id === event.target.value &&
                        item.status === "active",
                    );
                    if (!selected) return;
                    setSwitching(true);
                    try {
                      const response = await apiRequest<{
                        destination: string;
                      }>(`/api/v1/organizations/${selected.id}/switch`, {
                        method: "POST",
                        body: JSON.stringify({}),
                      });
                      router.push(response.destination);
                    } finally {
                      setSwitching(false);
                    }
                  }}
                >
                  {organizations
                    .filter((item) => item.status === "active")
                    .map((item) => (
                      <option key={item.id} value={item.id}>
                        {item.name}
                      </option>
                    ))}
                </Select>
              )}
              <Button
                variant="quiet"
                onClick={async () => {
                  try {
                    await apiRequest("/api/v1/auth/session", {
                      method: "DELETE",
                    });
                  } finally {
                    router.push("/login");
                    router.refresh();
                  }
                }}
              >
                {t("auth.signOut")}
              </Button>
            </Dropdown>
          </header>

          <main className="app-content" id="main-content">
            <nav className="breadcrumbs" aria-label={t("nav.breadcrumb")}>
              <span>{organization.name}</span>
              <span aria-hidden="true">/</span>
              <span>{titleForSegment(segment, t)}</span>
            </nav>
            {subscriptionProblem && (
              <PartialDataWarning>{t("nav.unavailable")}</PartialDataWarning>
            )}
            {children}
          </main>
        </div>

        <Drawer
          open={mobileOpen}
          onClose={() => setMobileOpen(false)}
          title={t("nav.workspaceNavigation")}
        >
          <div className="mobile-navigation">
            <div className="app-sidebar__context">
              <small>{t("nav.organization")}</small>
              <strong>{organization.name}</strong>
            </div>
            {navigationContent(true)}
            <ThemeToggle />
            <LanguageSelector />
          </div>
        </Drawer>
      </div>
    </WorkspaceProvider>
  );
}
