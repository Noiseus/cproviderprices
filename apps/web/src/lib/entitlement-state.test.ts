import { describe, expect, it } from "vitest";
import { entitlementErrorState } from "./entitlement-state";

describe("entitlementErrorState", () => {
  it("keeps structured limit usage visible", () => {
    expect(
      entitlementErrorState({
        code: "limit_reached",
        message: "The current plan limit has been reached.",
        request_id: "request-1",
        details: {
          entitlement_key: "max_locations",
          limit: 2,
          current_usage: 2,
        },
      }),
    ).toEqual({
      kind: "limit",
      message: "The current plan limit has been reached.",
      requestId: "request-1",
      detail: "2 of 2 currently used.",
    });
  });

  it.each([
    ["feature_disabled", "unavailable"],
    ["subscription_suspended", "suspended"],
  ] as const)("maps %s to a reusable %s state", (code, kind) => {
    expect(
      entitlementErrorState({
        code,
        message: "Unavailable",
        request_id: "request-2",
      }).kind,
    ).toBe(kind);
  });
});
