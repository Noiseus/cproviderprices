// @vitest-environment jsdom
import {
  act,
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@cproviderprices/generated-api-client";
import { ComparisonWorkspace } from "@/components/comparison/ComparisonWorkspace";
import { SearchWorkspace } from "@/components/search/SearchWorkspace";
import { apiRequest } from "@/lib/api";

type SearchResponse = components["schemas"]["ProductSearchResponse"];
type ComparisonResponse = components["schemas"]["ProductComparisonResponse"];

const { navigation, workspace } = vi.hoisted(() => ({
  navigation: { params: "", replace: vi.fn() },
  workspace: {
    organization: {
      id: "organization",
      slug: "acme",
      role: "organization_owner",
    },
    locationId: "location",
  },
}));

const memoryStorage = new Map<string, string>();
Object.defineProperty(globalThis, "localStorage", {
  configurable: true,
  value: {
    clear: () => memoryStorage.clear(),
    getItem: (key: string) => memoryStorage.get(key) ?? null,
    removeItem: (key: string) => memoryStorage.delete(key),
    setItem: (key: string, value: string) => memoryStorage.set(key, value),
  },
});

vi.mock("next/navigation", () => ({
  usePathname: () => "/o/acme/search",
  useRouter: () => ({ replace: navigation.replace }),
  useSearchParams: () => new URLSearchParams(navigation.params),
}));
vi.mock("@/components/app-shell/AppShellContext", () => ({
  useWorkspace: () => workspace,
}));
vi.mock("@/lib/api", async (original) => ({
  ...(await original<object>()),
  apiRequest: vi.fn(),
}));

function searchResponse(name: string, id: string): SearchResponse {
  return {
    results: [
      {
        canonical_product: {
          id,
          english_name: name,
          category: { id: "category", english_name: "Fresh tomatoes" },
          attributes: {},
        },
        variants: [],
        offer_count: 1,
        suppliers: [{ id: "supplier", display_name: "Produce Co" }],
        supplier_count: 1,
        match_quality_summary: {
          exact: 1,
          equivalent: 0,
          alternative: 0,
          not_safely_comparable: 0,
        },
        freshness_summary: { fresh: 1, aging: 0, stale: 0, unknown: 0 },
        price_summary: {
          lowest_case_price: "20",
          currency: "USD",
          lowest_normalized_price: "0.8",
          normalized_unit: "lb",
        },
        relevance_score: 100,
      },
    ],
    pagination: { page: 1, per_page: 25, total: 1, total_pages: 1 },
    partial: { supplier_accounts: [] },
  };
}

function comparisonResponse(): ComparisonResponse {
  return {
    canonical_product: {
      id: "beef",
      english_name: "Beefsteak Tomato",
      category: { id: "category", english_name: "Fresh tomatoes" },
      attributes: { size_code: "5X6" },
    },
    location: { id: "location", name: "Kitchen" },
    formula_version: "price-comparison-1.0.0",
    freshness_summary: { fresh: 1, aging: 0, stale: 1, unknown: 0 },
    warnings: [],
    pagination: { page: 1, per_page: 50, total: 1, total_pages: 1 },
    partial: { supplier_accounts: [] },
    offers: [
      {
        id: "offer",
        supplier: {
          id: "supplier",
          display_name: "Tomato Supplier",
          name: "Tomato Supplier",
        },
        raw_supplier_name: "TOM BEEF 5X6 1/25 LB CS",
        pack: { packs: [{ original_text: "1/25 LB CS" }] },
        case_price: "10",
        currency: "USD",
        normalized_price: null,
        normalized_unit: null,
        normalized_denominator: null,
        availability: "available",
        observed_at: "2026-10-04T12:00:00Z",
        freshness: "stale",
        comparison_class: "alternative",
        warnings: [
          "alternative_not_directly_interchangeable",
          "normalized_price_unavailable",
        ],
        winner_labels: ["lowest_case_price"],
        calculation: {
          source_case_price: "10",
          currency: "USD",
          source_price_evidence: { source_price: "10.00" },
          source_pack_evidence: { packs: [{ original_text: "1/25 LB CS" }] },
          normalized_denominator: null,
          normalized_price: null,
          normalized_unit: null,
          formula: null,
          formula_version: "price-comparison-1.0.0",
          observation: {
            id: "observation",
            observed_at: "2026-10-04T12:00:00Z",
            import_run_id: "run",
            normalized_import_record_id: "record",
          },
          match: {
            mapping_id: "mapping",
            canonical_product_id: "beef",
            product_variant_id: null,
            match_class: "comparable_alternative",
            confidence: "C",
            matcher_version: "catalog-matcher-1.0.0",
            evidence: { stage: "fixture" },
          },
        },
      },
    ],
  };
}

beforeEach(() => {
  navigation.params = "";
  navigation.replace.mockReset();
  vi.mocked(apiRequest).mockReset();
  localStorage.clear();
  vi.mocked(apiRequest).mockImplementation(async (path) => {
    if (path.includes("supplier-accounts"))
      return { supplier_accounts: [] } as never;
    return searchResponse("Tomato", "tomato") as never;
  });
});
afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

describe("search workspace", () => {
  it("suppresses whitespace and debounces meaningful input before changing URL state", () => {
    vi.useFakeTimers();
    render(<SearchWorkspace />);
    const input = screen.getByLabelText("Search products");
    fireEvent.change(input, { target: { value: "   " } });
    act(() => vi.advanceTimersByTime(260));
    expect(navigation.replace).not.toHaveBeenCalled();
    fireEvent.change(input, { target: { value: "tomato" } });
    act(() => vi.advanceTimersByTime(249));
    expect(navigation.replace).not.toHaveBeenCalled();
    act(() => vi.advanceTimersByTime(1));
    expect(navigation.replace).toHaveBeenCalledWith("/o/acme/search?q=tomato", {
      scroll: false,
    });
  });

  it("cancels stale search rendering and keeps only safe recent query text", async () => {
    let resolveFirst!: (value: SearchResponse) => void;
    let resolveSecond!: (value: SearchResponse) => void;
    const first = new Promise<SearchResponse>((resolve) => {
      resolveFirst = resolve;
    });
    const second = new Promise<SearchResponse>((resolve) => {
      resolveSecond = resolve;
    });
    navigation.params = "q=tomato";
    vi.mocked(apiRequest).mockImplementation((path) => {
      if (path.includes("supplier-accounts"))
        return Promise.resolve({ supplier_accounts: [] } as never);
      return path.includes("q=beef+tomato")
        ? (second as never)
        : (first as never);
    });
    const view = render(<SearchWorkspace />);
    await waitFor(() =>
      expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(
        expect.stringContaining("q=tomato"),
        expect.anything(),
      ),
    );
    navigation.params = "q=beef+tomato";
    view.rerender(<SearchWorkspace />);
    await act(async () =>
      resolveSecond(searchResponse("Beefsteak Tomato", "beef")),
    );
    await screen.findByText("Beefsteak Tomato");
    await act(async () => resolveFirst(searchResponse("Tomato", "tomato")));
    expect(screen.queryByText("Tomato")).toBeNull();
    expect(
      localStorage.getItem("cproviderprices-recent-searches:organization"),
    ).toBe('["beef tomato"]');
  });

  it("opens the mobile filter drawer without storing results in local storage", async () => {
    render(<SearchWorkspace />);
    await act(async () => {});
    fireEvent.click(screen.getByRole("button", { name: "Filters" }));
    expect(
      screen.getByRole("dialog", { name: "Search filters" }),
    ).not.toBeNull();
    expect(
      localStorage.getItem("cproviderprices-recent-searches:organization"),
    ).toBeNull();
  });

  it("shows bounded query-only history and reuses it through URL state", async () => {
    localStorage.setItem(
      "cproviderprices-recent-searches:organization",
      JSON.stringify(["tomate", "beef tomato 5x6 25 lb"]),
    );
    render(<SearchWorkspace />);
    const recent = await screen.findByRole("button", { name: "tomate" });
    fireEvent.click(recent);
    expect(navigation.replace).toHaveBeenCalledWith("/o/acme/search?q=tomate", {
      scroll: false,
    });
    expect(
      localStorage.getItem("cproviderprices-recent-searches:organization"),
    ).toBe(JSON.stringify(["tomate", "beef tomato 5x6 25 lb"]));
  });
});

describe("comparison workspace", () => {
  it("shows server-authoritative winner, unsafe warning, and expandable calculation evidence", async () => {
    vi.mocked(apiRequest).mockResolvedValue(comparisonResponse() as never);
    render(<ComparisonWorkspace canonicalProductId="beef" />);
    await screen.findByText("Beefsteak Tomato");
    expect(screen.getByText("Lowest Case Price")).not.toBeNull();
    expect(
      screen.getByText(/Alternative offer: it is not directly interchangeable/),
    ).not.toBeNull();
    fireEvent.click(
      screen.getByText("Calculation details and source evidence"),
    );
    expect(screen.getByText("No safe normalization formula")).not.toBeNull();
    expect(screen.getByText("catalog-matcher-1.0.0")).not.toBeNull();
  });
});
