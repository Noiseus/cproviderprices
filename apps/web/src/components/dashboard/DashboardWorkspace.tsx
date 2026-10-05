"use client";

import { useRouter } from "next/navigation";
import { Card } from "@/components/ui";
import { useTranslation } from "@/components/i18n/I18nProvider";
import { useVisibleInterval } from "@/components/refresh/useVisibleRefresh";

export function DashboardWorkspace() {
  const router = useRouter();
  const { t } = useTranslation();
  useVisibleInterval(60_000, () => router.refresh());

  return (
    <main className="ui-container">
      <header className="ui-page-header">
        <div>
          <p className="eyebrow">{t("nav.workspace")}</p>
          <h1>{t("dashboard.title")}</h1>
          <p>{t("dashboard.refreshNotice")}</p>
        </div>
      </header>
      <Card>
        <h2>{t("dashboard.overview")}</h2>
        <p>{t("dashboard.description")}</p>
      </Card>
    </main>
  );
}
