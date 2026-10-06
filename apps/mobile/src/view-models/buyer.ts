import {
  formatMoney,
  translate,
  type SupportedLocale,
} from "@cproviderprices/i18n";

import type {
  ProductComparisonOffer,
  ProductSearchResult,
  SavedListItem,
} from "@/types/api";

export function searchResultView(
  result: ProductSearchResult,
  locale: SupportedLocale,
) {
  const price =
    result.price_summary.lowest_normalized_price ??
    result.price_summary.lowest_case_price;
  return {
    title: result.canonical_product.english_name,
    supplierCount: result.supplier_count,
    price:
      price === null || result.price_summary.currency === null
        ? translate(locale, "common.notAvailable")
        : formatMoney(price, result.price_summary.currency, locale),
  };
}

export function winnerLabelKeys(offer: ProductComparisonOffer): string[] {
  return offer.winner_labels.map((label) => {
    if (label === "best_normalized_price") {
      return "comparison.bestNormalizedPrice";
    }
    if (label === "best_available_option") {
      return "comparison.bestAvailableOption";
    }
    return "comparison.lowestCasePrice";
  });
}

export function savedListWarningKey(item: SavedListItem): string | null {
  return item.selected_offer_unavailable ? "lists.unavailableWarning" : null;
}
