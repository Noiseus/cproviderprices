import type { HTMLAttributes, ReactNode } from "react";
import { cn } from "@/lib/cn";

export function Card({ className, ...props }: HTMLAttributes<HTMLElement>) {
  return <section className={cn("ui-card", className)} {...props} />;
}

export function StatCard({
  label,
  value,
  detail,
}: {
  label: string;
  value: string;
  detail?: string;
}) {
  return (
    <Card className="ui-stat-card">
      <span>{label}</span>
      <strong>{value}</strong>
      {detail && <small>{detail}</small>}
    </Card>
  );
}

export function Badge({
  children,
  tone = "neutral",
}: {
  children: ReactNode;
  tone?: "neutral" | "accent" | "success" | "warning" | "danger";
}) {
  return (
    <span className={cn("ui-badge", `ui-badge--${tone}`)}>{children}</span>
  );
}

export function FreshnessBadge({
  state,
}: {
  state: "fresh" | "aging" | "stale";
}) {
  return (
    <Badge
      tone={
        state === "fresh" ? "success" : state === "aging" ? "warning" : "danger"
      }
    >
      {state}
    </Badge>
  );
}

export function ConfidenceBadge({
  confidence,
}: {
  confidence: "high" | "medium" | "low";
}) {
  return (
    <Badge
      tone={
        confidence === "high"
          ? "success"
          : confidence === "medium"
            ? "warning"
            : "danger"
      }
    >
      {confidence} confidence
    </Badge>
  );
}
