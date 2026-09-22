import { cookies } from "next/headers";
import { apiServerBaseUrl } from "./server-api";
import type { SubscriptionStatusPayload } from "./server-subscription";

export type OrganizationStatus = "active" | "suspended" | "closed";
export type SubscriptionStatus =
  "trialing" | "active" | "past_due" | "suspended" | "canceled" | "expired";

export type PlatformOrganizationSummary = {
  id: string;
  name: string;
  slug: string;
  status: OrganizationStatus;
  created_at: string;
  subscription: {
    status: SubscriptionStatus;
    plan_code: string;
    trial_ends_at: string | null;
  } | null;
};

export type PlatformAudit = {
  id: string;
  action: string;
  actor_label: string | null;
  subject_type: string | null;
  subject_id: string | null;
  metadata: Record<string, unknown>;
  occurred_at: string;
};

export type PlatformOverview = {
  organization_counts: Record<"total" | OrganizationStatus, number>;
  trialing_subscriptions: number;
  pending_invitations: number;
  recent_organizations: PlatformOrganizationSummary[];
  recent_audit_activity: PlatformAudit[];
};

export type PlatformOrganizationDetail = {
  organization: PlatformOrganizationSummary;
  locations: Array<{
    id: string;
    name: string;
    code: string;
    status: string;
    timezone: string;
  }>;
  members: Array<{
    id: string;
    name: string;
    email: string;
    role: string;
    status: string;
    joined_at: string | null;
  }>;
  invitations: PlatformInvitation[];
  subscription_status: SubscriptionStatusPayload;
  available_plans: Array<{ code: string; name: string }>;
  audit_activity: PlatformAudit[];
};

export type PlatformInvitation = {
  id: string;
  intended_email: string;
  intended_role: "organization_owner";
  status: "pending" | "accepted" | "expired" | "revoked";
  expires_at: string;
  created_at: string;
};

export class PlatformServerError extends Error {
  constructor(
    message: string,
    readonly code: string,
    readonly requestId?: string,
  ) {
    super(message);
  }
}

export async function platformServerRequest<T>(path: string): Promise<T> {
  const cookieStore = await cookies();
  const response = await fetch(`${apiServerBaseUrl}${path}`, {
    headers: { Accept: "application/json", Cookie: cookieStore.toString() },
    cache: "no-store",
  });
  const body = (await response.json().catch(() => ({}))) as T & {
    error?: { code?: string; message?: string; request_id?: string };
  };

  if (!response.ok) {
    throw new PlatformServerError(
      body.error?.message ?? "Platform data could not be loaded.",
      body.error?.code ?? "request_failed",
      body.error?.request_id,
    );
  }

  return body;
}

export function platformListQuery(
  values: Record<string, string | string[] | undefined>,
): string {
  const parameters = new URLSearchParams();
  for (const key of [
    "search",
    "status",
    "subscription_status",
    "page",
    "per_page",
  ] as const) {
    const value = values[key];
    if (typeof value === "string" && value.trim() !== "")
      parameters.set(key, value.trim());
  }
  return parameters.toString();
}

export function platformReturnHref(
  value: string | string[] | undefined,
): string {
  const query = typeof value === "string" ? value : "";
  return query && !query.startsWith("/") && query.length <= 1000
    ? `/platform/organizations?${query}`
    : "/platform/organizations";
}
