import { describe, expect, it } from "vitest";
import { platformListQuery, platformReturnHref } from "./platform-api";

describe("platform list context", () => {
  it("preserves only supported URL filters", () => {
    expect(
      platformListQuery({
        search: " alpha ",
        status: "active",
        subscription_status: "trialing",
        page: "2",
        ignored: "secret",
      }),
    ).toBe("search=alpha&status=active&subscription_status=trialing&page=2");
  });

  it("builds a safe return URL without accepting another path", () => {
    expect(platformReturnHref("status=suspended&page=3")).toBe(
      "/platform/organizations?status=suspended&page=3",
    );
    expect(platformReturnHref("/o/other/dashboard")).toBe(
      "/platform/organizations",
    );
  });
});
