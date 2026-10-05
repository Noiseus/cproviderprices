// @vitest-environment jsdom
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import type { components } from "@cproviderprices/generated-api-client";
import { ApiClientError, apiRequest } from "@/lib/api";
import { SavedListDetailWorkspace } from "./SavedListDetailWorkspace";
import { SavedListsWorkspace } from "./SavedListsWorkspace";

type Detail = components["schemas"]["SavedListDetail"];
type Item = components["schemas"]["SavedListItem"];
type Offer = components["schemas"]["ProductComparisonOffer"];
type Summary = components["schemas"]["SavedListSummary"];

const workspace = vi.hoisted(() => ({
  organization: {
    id: "organization",
    slug: "acme",
    role: "buyer" as "buyer" | "viewer",
  },
  locationId: "location",
}));

vi.mock("@/components/app-shell/AppShellContext", () => ({
  useWorkspace: () => workspace,
}));
vi.mock("@/lib/api", async (original) => ({
  ...(await original<object>()),
  apiRequest: vi.fn(),
}));

function offer(
  id = "offer",
  availability: "available" | "unavailable" = "available",
): Offer {
  return {
    id,
    supplier: {
      id: `${id}-supplier`,
      display_name: `${id} supplier`,
      name: "Supplier",
    },
    raw_supplier_name: "TOM BEEF 5X6 1/25 LB CS",
    pack: { packs: [] },
    case_price: "20",
    currency: "USD",
    normalized_price: "0.8",
    normalized_unit: "lb",
    normalized_denominator: {
      value: "25",
      unit: "lb",
      canonical_value: "11339.80925",
      canonical_unit: "g",
    },
    availability,
    source_updated_at: null,
    observed_at: "2026-10-04T12:00:00Z",
    imported_at: "2026-10-04T12:01:00Z",
    freshness: "fresh",
    comparison_class: "equivalent",
    warnings: [],
    winner_labels: ["best_available_option"],
    calculation: {
      source_case_price: "20",
      currency: "USD",
      source_price_evidence: {},
      source_pack_evidence: {},
      normalized_denominator: {
        value: "25",
        unit: "lb",
        canonical_value: "11339.80925",
        canonical_unit: "g",
      },
      normalized_price: "0.8",
      normalized_unit: "lb",
      formula: "case_price * exact_unit_factor / normalized_quantity",
      formula_version: "price-comparison-1.0.0",
      observation: {
        id: "observation",
        source_updated_at: null,
        observed_at: "2026-10-04T12:00:00Z",
        imported_at: "2026-10-04T12:01:00Z",
        import_run_id: "run",
        normalized_import_record_id: "record",
      },
      match: {
        mapping_id: "mapping",
        canonical_product_id: "product",
        product_variant_id: null,
        match_class: "equivalent_product",
        confidence: "B",
        matcher_version: "catalog-matcher-1.0.0",
        evidence: {},
      },
    },
  };
}

function item(overrides: Partial<Item> = {}): Item {
  const best = offer("best");
  return {
    id: "item",
    version: 1,
    sort_order: 1,
    canonical_product: {
      id: "product",
      english_name: "Beefsteak Tomato",
      attributes: {},
      category: { id: "category", english_name: "Fresh tomatoes" },
    },
    product_variant: null,
    quantity_target: "2.500000",
    preferred_unit: "lb",
    notes: "Use ripe tomatoes",
    selected_offer_id: "selected",
    selected_offer: offer("selected", "unavailable"),
    selected_offer_unavailable: true,
    best_normalized_option: best,
    best_available_option: best,
    alternative_offer_count: 2,
    offer_choices: [offer("selected", "unavailable"), best],
    warnings: ["selected_offer_unavailable"],
    updated_at: "2026-10-04T12:01:00Z",
    ...overrides,
  };
}

function detail(overrides: Partial<Detail> = {}): Detail {
  return {
    id: "list",
    name: "Friday prep",
    status: "active",
    version: 2,
    location: { id: "location", name: "Kitchen" },
    created_at: "2026-10-04T10:00:00Z",
    updated_at: "2026-10-04T12:01:00Z",
    items: [item()],
    ...overrides,
  };
}

function summary(overrides: Partial<Summary> = {}): Summary {
  return {
    id: "list",
    name: "Friday prep",
    status: "active",
    version: 2,
    location: { id: "location", name: "Kitchen" },
    item_count: 1,
    updated_at: "2026-10-04T12:01:00Z",
    ...overrides,
  };
}

beforeEach(() => {
  workspace.organization.role = "buyer";
  vi.mocked(apiRequest).mockReset();
  vi.mocked(apiRequest).mockImplementation(async (path) => {
    if (path.includes("saved-lists/list"))
      return { saved_list: detail() } as never;
    return {
      saved_lists: [summary()],
      pagination: { page: 1, per_page: 25, total: 1, total_pages: 1 },
    } as never;
  });
});
afterEach(cleanup);

describe("saved lists workspace", () => {
  it("creates a list without a document reload and shows lifecycle actions", async () => {
    vi.mocked(apiRequest).mockImplementation(async (path) => {
      if (path.endsWith("/saved-lists"))
        return {
          saved_list: summary({
            id: "new",
            name: "Weekend prep",
            item_count: 0,
          }),
        } as never;
      return {
        saved_lists: [],
        pagination: { page: 1, per_page: 25, total: 0, total_pages: 1 },
      } as never;
    });
    render(<SavedListsWorkspace />);
    await screen.findByText("No saved lists yet");
    fireEvent.click(screen.getAllByRole("button", { name: "Create list" })[0]);
    fireEvent.change(screen.getByLabelText("List name"), {
      target: { value: "Weekend prep" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Save" }));
    await screen.findByText("Weekend prep");
    expect(screen.getByRole("button", { name: "Duplicate" })).not.toBeNull();
    expect(
      vi
        .mocked(apiRequest)
        .mock.calls.some(([path]) => String(path).endsWith("/saved-lists")),
    ).toBe(true);
  });

  it("renders selected and best offers separately, freshness, unavailable warning, and explicit save state", async () => {
    vi.mocked(apiRequest).mockImplementation(async (path, init) => {
      if (String(path).includes("/items/item") && init?.method === "PATCH")
        return {
          saved_list: { id: "list", version: 3 },
          item: item({ version: 2, quantity_target: "3.000000" }),
        } as never;
      return {
        saved_list: detail({
          items: [item({ notes: "<script>private note</script>" })],
        }),
      } as never;
    });
    render(<SavedListDetailWorkspace listId="list" />);
    await screen.findByText("Beefsteak Tomato");
    expect(screen.getByText("Selected option")).not.toBeNull();
    expect(screen.getByText("Best normalized option")).not.toBeNull();
    expect(screen.getByText(/Selected offer is unavailable/i)).not.toBeNull();
    const savedNote = screen.getByText(
      (_, element) =>
        element?.textContent === "Saved note: <script>private note</script>",
    );
    expect(savedNote.querySelector("script")).toBeNull();
    expect(screen.getAllByText("Fresh").length).toBeGreaterThan(0);
    fireEvent.change(screen.getByLabelText("Quantity target"), {
      target: { value: "3" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Save item" }));
    await screen.findByText("Saved");
    expect(vi.mocked(apiRequest)).toHaveBeenCalledWith(
      expect.stringContaining("/items/item"),
      expect.objectContaining({ method: "PATCH" }),
    );
  });

  it("shows a conflict state without pretending an inline change succeeded", async () => {
    vi.mocked(apiRequest).mockImplementation(async (path, init) => {
      if (String(path).includes("/items/item") && init?.method === "PATCH")
        throw new ApiClientError(409, "conflict", "Changed elsewhere");
      return { saved_list: detail() } as never;
    });
    render(<SavedListDetailWorkspace listId="list" />);
    await screen.findByText("Beefsteak Tomato");
    fireEvent.click(screen.getByRole("button", { name: "Save item" }));
    await screen.findByText(/list changed elsewhere/i);
  });

  it("keeps viewer controls read-only while preserving price evidence", async () => {
    workspace.organization.role = "viewer";
    render(<SavedListDetailWorkspace listId="list" />);
    await screen.findByText("Read-only saved list");
    expect(screen.getByText("Best available option")).not.toBeNull();
    expect(screen.queryByRole("button", { name: "Save item" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Add to list" })).toBeNull();
  });

  it("keeps list detail visible after a refresh failure", async () => {
    let calls = 0;
    vi.mocked(apiRequest).mockImplementation(async () => {
      calls += 1;
      if (calls > 1) throw new Error("Unavailable");
      return { saved_list: detail() } as never;
    });
    render(<SavedListDetailWorkspace listId="list" />);
    await screen.findByText("Friday prep");
    fireEvent.click(
      screen.getByRole("button", { name: "Refresh CProviderPrices data" }),
    );
    await waitFor(() => expect(screen.getByText("Friday prep")).not.toBeNull());
  });
});
