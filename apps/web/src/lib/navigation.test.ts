import { describe, expect, it } from "vitest";
import { buildNavigation } from "./navigation";

describe("workspace navigation", () => {
  it("gives owners tenant administration routes without platform routes", () => {
    const labels = buildNavigation("organization_owner", {
      price_history_enabled: true,
      manual_imports_enabled: true,
    }).map((item) => item.label);
    expect(labels).toContain("Team");
    expect(labels).toContain("Locations");
    expect(labels).toContain("Settings");
    expect(labels).not.toContain("Platform");
  });

  it("gives viewers a reduced navigation set", () => {
    const labels = buildNavigation("viewer", {
      price_history_enabled: true,
    }).map((item) => item.label);
    expect(labels).toEqual([
      "Dashboard",
      "Search",
      "Lists",
      "Price history",
      "Suppliers",
      "Team",
      "Locations",
      "Subscription",
    ]);
  });

  it("marks disabled entitlements unavailable without plan-name checks", () => {
    const history = buildNavigation("viewer", {
      price_history_enabled: false,
    }).find((item) => item.label === "Price history");
    expect(history).toMatchObject({
      available: false,
      unavailableReason: "Unavailable on the current plan",
    });
  });
});
