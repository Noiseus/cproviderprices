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
import { PriceHistoryWorkspace } from "./PriceHistoryWorkspace";
import { apiRequest } from "@/lib/api";

type History = components["schemas"]["PriceHistoryResponse"];

const workspace = vi.hoisted(() => ({
  organization: {
    id: "organization",
    slug: "acme",
    role: "organization_owner",
  },
  locationId: "location",
}));

vi.mock("next/navigation", () => ({
  useSearchParams: () => new URLSearchParams("product=product"),
}));
vi.mock("@/components/app-shell/AppShellContext", () => ({
  useWorkspace: () => workspace,
}));
vi.mock("@/lib/api", async (original) => ({
  ...(await original<object>()),
  apiRequest: vi.fn(),
}));

function history(): History {
  return {
    canonical_product: {
      id: "product",
      english_name: "Beefsteak Tomato",
      category: { id: "category", english_name: "Fresh tomatoes" },
    },
    location: { id: "location", name: "Kitchen" },
    range: { from: "2026-08-01T00:00:00Z", to: "2026-10-04T23:59:59Z" },
    aggregation: {
      method: "daily_mean_per_supplier_compatible_unit",
      state: "compatible_units",
      dimensions: ["weight"],
      excluded_unsafe_count: 1,
    },
    series: [
      {
        date: "2026-10-04",
        supplier: {
          id: "supplier",
          display_name: "Tomato Supplier",
          name: "Tomato Supplier",
        },
        currency: "USD",
        dimension: "weight",
        normalized_unit: "lb",
        normalized_price: "0.8",
        observation_count: 2,
      },
    ],
    observations: [
      {
        id: "observation",
        supplier: {
          id: "supplier",
          display_name: "Tomato Supplier",
          name: "Tomato Supplier",
        },
        case_price: "20",
        currency: "USD",
        normalized_price: "0.8",
        normalized_unit: "lb",
        dimension: "weight",
        availability: "available",
        freshness: "manual_import",
        source_updated_at: null,
        observed_at: "2026-10-04T12:00:00Z",
        imported_at: "2026-10-04T12:01:00Z",
        aggregation_state: "safe",
        provenance: {
          import_run_id: "run",
          normalized_import_record_id: "record",
          formula_version: "price-comparison-1.0.0",
        },
        match: null,
      },
      {
        id: "unsafe",
        supplier: {
          id: "supplier",
          display_name: "Tomato Supplier",
          name: "Tomato Supplier",
        },
        case_price: "30",
        currency: "USD",
        normalized_price: null,
        normalized_unit: null,
        dimension: null,
        availability: "available",
        freshness: "manual_import",
        source_updated_at: null,
        observed_at: "2026-10-04T13:00:00Z",
        imported_at: "2026-10-04T13:01:00Z",
        aggregation_state: "unsafe_variable_weight",
        provenance: {
          import_run_id: "run-two",
          normalized_import_record_id: "record-two",
          formula_version: "price-comparison-1.0.0",
        },
        match: null,
      },
    ],
  };
}

beforeEach(() => {
  vi.mocked(apiRequest).mockReset();
  vi.mocked(apiRequest).mockImplementation(async (path) =>
    path.includes("supplier-accounts")
      ? ({ supplier_accounts: [] } as never)
      : (history() as never),
  );
});
afterEach(cleanup);

describe("price history workspace", () => {
  it("renders the accessible chart, table fallback, freshness, and unsafe-state evidence", async () => {
    render(<PriceHistoryWorkspace />);
    await screen.findByText("Beefsteak Tomato");
    expect(
      screen.getByRole("img", { name: /daily compatible-unit price point/i }),
    ).not.toBeNull();
    expect(
      screen.getByRole("heading", { name: "Daily compatible-unit values" }),
    ).not.toBeNull();
    expect(screen.getByText("unsafe variable weight")).not.toBeNull();
    expect(screen.getAllByText("manual import").length).toBeGreaterThan(0);
  });

  it("keeps trusted history visible if Refresh fails and never calls supplier Sync", async () => {
    render(<PriceHistoryWorkspace />);
    await screen.findByText("Beefsteak Tomato");
    const beforeSync = vi.mocked(apiRequest).mock.calls.length;
    fireEvent.click(screen.getByRole("button", { name: "Sync supplier" }));
    expect(vi.mocked(apiRequest).mock.calls).toHaveLength(beforeSync);

    vi.mocked(apiRequest).mockRejectedValueOnce(
      new Error("Backend unavailable"),
    );
    fireEvent.click(
      screen.getByRole("button", { name: "Refresh CProviderPrices data" }),
    );
    await waitFor(() =>
      expect(screen.getByText(/last trusted history data/i)).not.toBeNull(),
    );
    expect(screen.getByText("Beefsteak Tomato")).not.toBeNull();
  });
});
