import type { ReactNode } from "react";
import { Card } from "./ui";

export function AuthShell({
  title,
  children,
}: {
  title: string;
  children: ReactNode;
}) {
  return (
    <main className="auth-shell">
      <Card className="auth-card" aria-labelledby="page-title">
        <p className="eyebrow">CProviderPrices</p>
        <h1 id="page-title">{title}</h1>
        {children}
      </Card>
    </main>
  );
}
