"use client";

import Link from "next/link";
import { usePathname, useRouter } from "next/navigation";
import type { ReactNode } from "react";
import type { SessionPayload } from "@/lib/server-session";
import { apiRequest } from "@/lib/api";
import { Button } from "../ui";
import { ThemeToggle } from "../ThemeToggle";
import { LanguageSelector } from "../i18n/LanguageSelector";
import { useTranslation } from "../i18n/I18nProvider";

export function PlatformShell({
  user,
  organizations,
  children,
}: {
  user: SessionPayload["session"]["user"];
  organizations: SessionPayload["session"]["organizations"];
  children: ReactNode;
}) {
  const pathname = usePathname();
  const router = useRouter();
  const { t } = useTranslation();
  const links = [
    { href: "/platform", label: t("dashboard.overview"), exact: true },
    {
      href: "/platform/organizations",
      label: t("platform.organizations"),
      exact: false,
    },
    { href: "/platform/matching", label: t("platform.matching"), exact: false },
  ];

  return (
    <div className="app-shell platform-shell" data-testid="platform-shell">
      <aside className="app-sidebar">
        <Link className="app-sidebar__brand" href="/platform">
          <span className="brand-mark">CP</span>
          <span>CProviderPrices</span>
        </Link>
        <div className="app-sidebar__context">
          <small>{t("nav.account")}</small>
          <strong>{t("platform.title")}</strong>
          <small>{t("nav.workspace")}</small>
        </div>
        <nav className="app-nav" aria-label={t("platform.title")}>
          {links.map((item) => {
            const active = item.exact
              ? pathname === item.href
              : pathname.startsWith(item.href);
            return (
              <Link
                key={item.href}
                href={item.href}
                aria-current={active ? "page" : undefined}
              >
                <span className="app-nav__icon" aria-hidden="true">
                  PA
                </span>
                <span>{item.label}</span>
              </Link>
            );
          })}
        </nav>
      </aside>
      <div className="app-workspace">
        <header className="app-topbar">
          <strong>{t("platform.title")}</strong>
          <nav
            className="platform-mobile-nav"
            aria-label={t("nav.mobileWorkspace")}
          >
            {links.map((item) => (
              <Link key={item.href} href={item.href}>
                {item.label}
              </Link>
            ))}
          </nav>
          <div className="app-topbar__spacer" />
          {organizations[0] && (
            <Link href={`/o/${organizations[0].slug}/dashboard`}>
              {t("nav.workspace")}
            </Link>
          )}
          <ThemeToggle compact />
          <LanguageSelector compact />
          <span>{user.name}</span>
          <Button
            variant="quiet"
            onClick={async () => {
              try {
                await apiRequest("/api/v1/auth/session", { method: "DELETE" });
              } finally {
                router.push("/login");
              }
            }}
          >
            {t("auth.signOut")}
          </Button>
        </header>
        <main className="app-content" id="main-content">
          {children}
        </main>
      </div>
    </div>
  );
}
