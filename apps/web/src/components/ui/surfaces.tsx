"use client";

import type { HTMLAttributes, ReactNode } from "react";
import { useTranslation } from "@/components/i18n/I18nProvider";
import { cn } from "@/lib/cn";
import { Tooltip } from "./feedback";

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
  timestamp,
  timeZone,
}: {
  state: "fresh" | "aging" | "stale" | "connection_failed" | "manual_import";
  timestamp?: string | null;
  timeZone?: string;
}) {
  const { formatDateTime, t } = useTranslation();
  const badge = (
    <Badge
      tone={
        state === "fresh"
          ? "success"
          : state === "aging" || state === "manual_import"
            ? "warning"
            : "danger"
      }
    >
      {t(`freshness.${state}`)}
    </Badge>
  );
  if (!timestamp) return badge;
  return (
    <Tooltip
      label={t("freshness.sourceDetail", {
        value: formatDateTime(timestamp, timeZone),
      })}
    >
      {badge}
    </Tooltip>
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
