import { describe, expect, it } from "vitest";

import { localizedMobileError, MobileApiError } from "@/lib/errors";
import {
  searchResultView,
  savedListWarningKey,
  winnerLabelKeys,
} from "@/view-models/buyer";
import { formatDate, translate } from "@cproviderprices/i18n";

describe("mobile buyer view models", () => {
  it("uses shared EN/ES translation and formatting behavior", () => {
    expect(translate("es", "mobile.organizationSelector")).toContain(
      "organizaci",
    );
    expect(translate("unsupported", "common.save")).toBe("Save");
    expect(formatDate("2026-10-06T12:00:00Z", "es", "UTC")).toContain("2026");
  });

  it("renders server-provided search prices without recalculating them", () => {
    const view = searchResultView(
      {
        canonical_product: {
          id: "product",
          english_name: "Beefsteak",
          category: { id: "category", english_name: "Tomatoes" },
          attributes: {},
        },
        variants: [],
        offer_count: 2,
        suppliers: [],
        supplier_count: 2,
        match_quality_summary: {
          exact: 2,
          equivalent: 0,
          alternative: 0,
          not_safely_comparable: 0,
        },
        freshness_summary: {
          fresh: 2,
          aging: 0,
          stale: 0,
          connection_failed: 0,
          manual_import: 0,
        },
        price_summary: {
          lowest_case_price: "12.50",
          lowest_normalized_price: "1.25",
          normalized_unit: "lb",
          currency: "USD",
        },
        relevance_score: 1,
      },
      "en",
    );
    expect(view).toMatchObject({
      title: "Beefsteak",
      supplierCount: 2,
      price: "$1.25",
    });
  });

  it("keeps winner labels and unavailable warnings distinct", () => {
    expect(
      winnerLabelKeys({
        winner_labels: ["best_normalized_price", "best_available_option"],
      } as never),
    ).toEqual([
      "comparison.bestNormalizedPrice",
      "comparison.bestAvailableOption",
    ]);
    expect(
      savedListWarningKey({ selected_offer_unavailable: true } as never),
    ).toBe("lists.unavailableWarning");
    expect(
      localizedMobileError(
        "en",
        new MobileApiError("mobile_token_expired", 401, "trace"),
      ),
    ).toContain("expired");
  });
});
