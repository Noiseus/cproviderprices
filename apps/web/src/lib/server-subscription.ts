import { cookies } from "next/headers";
import { apiServerBaseUrl } from "./server-api";
import type { ApiError } from "./entitlement-state";

export type SubscriptionStatusPayload = {
  plan: { code: string; name: string } | null;
  subscription: {
    status:
      "trialing" | "active" | "past_due" | "suspended" | "canceled" | "expired";
    recorded_status:
      "trialing" | "active" | "past_due" | "suspended" | "canceled" | "expired";
    starts_at: string;
    trial_ends_at: string | null;
    current_period_ends_at: string | null;
    access_available: boolean;
  } | null;
  capabilities: Record<string, boolean>;
  limits: Record<
    string,
    {
      limit: number | null;
      current_usage: number | null;
      reached: boolean | null;
    }
  >;
};

export async function organizationSubscription(
  organizationId: string,
): Promise<
  | { data: SubscriptionStatusPayload; error: null }
  | { data: null; error: ApiError }
> {
  try {
    const cookieStore = await cookies();
    const response = await fetch(
      `${apiServerBaseUrl}/api/v1/organizations/${encodeURIComponent(organizationId)}/subscription`,
      {
        headers: { Accept: "application/json", Cookie: cookieStore.toString() },
        cache: "no-store",
      },
    );
    const body = (await response.json()) as
      { subscription_status: SubscriptionStatusPayload } | { error: ApiError };

    if (!response.ok || !("subscription_status" in body)) {
      const error = "error" in body ? body.error : null;
      return {
        data: null,
        error: error ?? {
          code: "subscription_status_unavailable",
          message: "Subscription status is temporarily unavailable.",
          request_id: response.headers.get("X-Request-ID") ?? "unavailable",
        },
      };
    }

    return { data: body.subscription_status, error: null };
  } catch {
    return {
      data: null,
      error: {
        code: "offline",
        message: "The subscription service could not be reached.",
        request_id: "unavailable",
      },
    };
  }
}
