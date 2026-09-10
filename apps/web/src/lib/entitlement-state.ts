export type ApiError = {
  code: string;
  message: string;
  request_id: string;
  details?: {
    entitlement_key?: string;
    limit?: number;
    current_usage?: number;
    subscription_status?: string;
  };
};

export type EntitlementErrorState =
  | { kind: "limit"; message: string; requestId: string; detail: string }
  | { kind: "unavailable"; message: string; requestId: string; detail: string }
  | { kind: "suspended"; message: string; requestId: string; detail: string }
  | { kind: "error"; message: string; requestId: string; detail: string };

export function entitlementErrorState(error: ApiError): EntitlementErrorState {
  if (error.code === "limit_reached") {
    const usage = error.details?.current_usage;
    const limit = error.details?.limit;
    return {
      kind: "limit",
      message: error.message,
      requestId: error.request_id,
      detail:
        usage !== undefined && limit !== undefined
          ? `${usage} of ${limit} currently used.`
          : "The configured limit has been reached.",
    };
  }

  if (error.code === "feature_disabled") {
    return {
      kind: "unavailable",
      message: error.message,
      requestId: error.request_id,
      detail: "Contact your account administrator about available plans.",
    };
  }

  if (error.code === "subscription_suspended") {
    return {
      kind: "suspended",
      message: error.message,
      requestId: error.request_id,
      detail:
        "Contact support or your account administrator to restore access.",
    };
  }

  return {
    kind: "error",
    message: error.message,
    requestId: error.request_id,
    detail: "Try again or contact support if the problem continues.",
  };
}
