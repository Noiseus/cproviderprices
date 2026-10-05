"use client";

import { useRouter } from "next/navigation";
import { Card } from "@/components/ui";
import { useVisibleInterval } from "@/components/refresh/useVisibleRefresh";

export function DashboardWorkspace() {
  const router = useRouter();
  useVisibleInterval(60_000, () => router.refresh());

  return (
    <main className="ui-container">
      <header className="ui-page-header">
        <div>
          <p className="eyebrow">Workspace</p>
          <h1>Dashboard</h1>
          <p>
            Workspace data refreshes every minute while this tab is visible.
          </p>
        </div>
      </header>
      <Card>
        <h2>Purchasing overview</h2>
        <p>
          Product price summaries will appear here as their underlying data is
          configured. This page does not contact supplier systems.
        </p>
      </Card>
    </main>
  );
}
