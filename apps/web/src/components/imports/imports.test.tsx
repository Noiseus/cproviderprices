// @vitest-environment jsdom
import {
  act,
  cleanup,
  fireEvent,
  render,
  renderHook,
  screen,
  waitFor,
} from "@testing-library/react";
import { StrictMode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { apiRequest, ApiClientError } from "@/lib/api";
import {
  importError,
  importFieldErrors,
  mappingErrors,
  shouldPoll,
  terminal,
  type ImportDetail,
  type ImportSummary,
} from "@/lib/imports";
import { ImportMappingForm } from "./ImportMappingForm";
import { useImportPolling } from "./useImportPolling";
import { ImportCenter } from "./ImportCenter";

const { push, workspace } = vi.hoisted(() => ({
  push: vi.fn(),
  workspace: {
    organization: { id: "org", slug: "test", role: "organization_owner" },
    subscription: {
      capabilities: { manual_imports_enabled: true },
      subscription: { access_available: true },
    },
    locations: [{ id: "location", name: "Kitchen", status: "active" }],
    locationId: "location",
  },
}));
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));
vi.mock("@/components/app-shell/AppShellContext", () => ({
  useWorkspace: () => workspace,
}));
vi.mock("@/lib/api", async (original) => ({
  ...(await original<object>()),
  apiRequest: vi.fn(),
}));

const run: ImportSummary = {
  id: "run",
  restaurant_location_id: "location",
  supplier_account_id: "account",
  status: "needs_mapping",
  mapping_snapshot: null,
  mapping_template_id: null,
  validation_started_at: null,
  validation_completed_at: null,
  validated_through_row: 1,
  valid_rows: 0,
  processed_count: 0,
  warning_count: 0,
  failed_count: 0,
  normalization_failed_count: 0,
  remaining_count: 0,
  retry_count: 0,
  created_at: "2026-09-01T00:00:00Z",
  finished_at: null,
};
const detail: ImportDetail = {
  import: run,
  file: {
    id: "file",
    original_filename: "supplier.csv",
    size_bytes: 50,
    format: "csv",
    headers: ["SKU", "Product"],
    sample_rows: [{ source_row: 2, values: ["00012", "MOZZ"] }],
  },
  errors: [],
};
beforeEach(() => {
  vi.mocked(apiRequest).mockReset();
  push.mockReset();
  workspace.organization.role = "organization_owner";
  workspace.subscription.capabilities.manual_imports_enabled = true;
  workspace.locations = [{ id: "location", name: "Kitchen", status: "active" }];
  Object.defineProperty(document, "hidden", {
    configurable: true,
    value: false,
  });
});
afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

describe("mapping and status rules", () => {
  it("requires SKU/description, refuses duplicates and unknown columns", () => {
    expect(mappingErrors({}, ["SKU"])).toHaveProperty("description");
    expect(
      mappingErrors({ supplier_sku: "SKU", description: "SKU" }, ["SKU"]),
    ).toHaveProperty("supplier_sku");
    expect(
      mappingErrors({ supplier_sku: "SKU", description: "Unknown" }, ["SKU"]),
    ).toHaveProperty("description");
    expect(
      mappingErrors(
        { supplier_sku: "SKU", description: "Product" },
        detail.file.headers,
      ),
    ).toEqual({});
  });
  it.each(["completed", "partially_completed", "failed", "canceled"] as const)(
    "never polls terminal %s",
    (status) => {
      expect(terminal(status)).toBe(true);
      expect(shouldPoll({ ...run, status })).toBe(false);
    },
  );
  it("polls queued/processing and validation but not idle mapping", () => {
    expect(shouldPoll(run)).toBe(false);
    expect(shouldPoll({ ...run, status: "processing" })).toBe(true);
    expect(shouldPoll({ ...run, status: "queued" })).toBe(true);
    expect(shouldPoll({ ...run, validation_started_at: "now" })).toBe(true);
    expect(
      shouldPoll({
        ...run,
        validation_started_at: "now",
        validation_completed_at: "later",
      }),
    ).toBe(false);
  });
  it("associates mapping errors and preserves source strings", async () => {
    const save = vi.fn().mockResolvedValue(true);
    render(
      <ImportMappingForm
        detail={detail}
        templates={[]}
        pending={false}
        onSave={save}
        serverErrors={{}}
      />,
    );
    fireEvent.click(screen.getByRole("button", { name: "Save mapping" }));
    expect(
      screen.getByLabelText(/^Supplier SKU/).getAttribute("aria-invalid"),
    ).toBe("true");
    expect(save).not.toHaveBeenCalled();
    fireEvent.change(screen.getByLabelText(/^Supplier SKU/), {
      target: { value: "SKU" },
    });
    fireEvent.change(screen.getByLabelText(/^Description/), {
      target: { value: "Product" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Save mapping" }));
    await waitFor(() =>
      expect(save).toHaveBeenCalledWith({
        mapping: { supplier_sku: "SKU", description: "Product" },
        source_conventions: {},
      }),
    );
  });
  it("uses only templates with identical ordered headers", () => {
    render(
      <ImportMappingForm
        detail={detail}
        templates={[
          {
            id: "good",
            name: "Compatible",
            headers: detail.file.headers,
            mapping: { supplier_sku: "SKU", description: "Product" },
            source_conventions: { produce_size_code: true },
          },
          {
            id: "bad",
            name: "Wrong order",
            headers: ["Product", "SKU"],
            mapping: { supplier_sku: "SKU", description: "Product" },
            source_conventions: {},
          },
        ]}
        pending={false}
        onSave={vi.fn()}
        serverErrors={{}}
      />,
    );
    expect(screen.queryByRole("option", { name: "Wrong order" })).toBeNull();
    fireEvent.change(screen.getByLabelText("Reusable mapping template"), {
      target: { value: "good" },
    });
    expect(
      (screen.getByLabelText("Description (required)") as HTMLSelectElement)
        .value,
    ).toBe("Product");
  });
  it("presents safe structured errors and associated fields", () => {
    const error = new ApiClientError(
      422,
      "validation_failed",
      "Please correct these fields.",
      { fields: { file: ["Invalid format."] } },
    );
    expect(importError(error)).toBe(error.message);
    expect(importFieldErrors(error)).toEqual({ file: "Invalid format." });
    expect(
      importError(new ApiClientError(500, "oops", "SQL password=secret")),
    ).not.toContain("secret");
    expect(
      importError(new ApiClientError(403, "forbidden", "Denied")),
    ).toContain("permissions");
  });
});

describe("scoped polling", () => {
  it("polls every two seconds and stops on terminal/unmount", async () => {
    vi.useFakeTimers();
    const refresh = vi.fn().mockResolvedValue(undefined);
    const { rerender } = renderHook(
      ({ active }) => useImportPolling(active, refresh),
      { initialProps: { active: true } },
    );
    await act(() => vi.advanceTimersByTimeAsync(1999));
    expect(refresh).not.toHaveBeenCalled();
    await act(() => vi.advanceTimersByTimeAsync(1));
    expect(refresh).toHaveBeenCalledTimes(1);
    rerender({ active: false });
    await act(() => vi.advanceTimersByTimeAsync(10000));
    expect(refresh).toHaveBeenCalledTimes(1);
  });
  it("pauses hidden documents and resumes once visible without duplicate timers", async () => {
    vi.useFakeTimers();
    const refresh = vi.fn(
      (signal: AbortSignal) =>
        new Promise<void>((resolve) => {
          signal.addEventListener("abort", () => resolve());
        }),
    );
    const { unmount } = renderHook(() => useImportPolling(true, refresh));
    await act(() => vi.advanceTimersByTimeAsync(2000));
    act(() => {
      Object.defineProperty(document, "hidden", { value: true });
      document.dispatchEvent(new Event("visibilitychange"));
    });
    act(() => {
      Object.defineProperty(document, "hidden", { value: false });
      document.dispatchEvent(new Event("visibilitychange"));
    });
    await act(() => vi.advanceTimersByTimeAsync(6000));
    expect(refresh).toHaveBeenCalledTimes(2); // resumed request is still pending, no extra timer
    unmount();
    expect(refresh.mock.calls[1][0].aborted).toBe(true);
  });
  it("does not start while hidden", async () => {
    vi.useFakeTimers();
    Object.defineProperty(document, "hidden", { value: true });
    const refresh = vi.fn();
    renderHook(() => useImportPolling(true, refresh));
    await act(() => vi.advanceTimersByTimeAsync(6000));
    expect(refresh).not.toHaveBeenCalled();
  });
});

describe("Import Center states", () => {
  function setup(data = detail) {
    vi.mocked(apiRequest).mockImplementation(async (path) => {
      if (path.endsWith("supplier-accounts")) return { supplier_accounts: [] };
      if (path.includes("mapping-templates"))
        return { templates: [], pagination: { total_pages: 1 } };
      return data;
    });
  }
  it.each([true, false])(
    "guides missing active location/account (location missing: %s)",
    async (missingLocation) => {
      if (missingLocation) workspace.locations[0].status = "inactive";
      vi.mocked(apiRequest).mockImplementation(async (path) =>
        path.endsWith("supplier-accounts")
          ? { supplier_accounts: [] }
          : { imports: [], pagination: { total_pages: 1 } },
      );
      render(<ImportCenter />);
      await screen.findByRole("link", {
        name: missingLocation
          ? "Configure locations"
          : "Configure supplier accounts",
      });
      if (missingLocation)
        expect(
          screen.queryByRole("button", { name: "Upload file" }),
        ).toBeNull();
      else
        expect(
          (
            screen.getByRole("button", {
              name: "Upload file",
            }) as HTMLButtonElement
          ).disabled,
        ).toBe(true);
    },
  );
  it("prevents repeated start mutations while awaiting server confirmation", async () => {
    const ready = {
      ...detail,
      import: {
        ...run,
        validation_started_at: "now",
        validation_completed_at: "now",
        valid_rows: 3,
      },
    };
    let finish: ((value: ImportDetail) => void) | undefined;
    vi.mocked(apiRequest).mockImplementation(async (path, init) => {
      if (init?.method === "POST")
        return new Promise<ImportDetail>((resolve) => {
          finish = resolve;
        });
      if (path.endsWith("supplier-accounts")) return { supplier_accounts: [] };
      return ready;
    });
    render(<ImportCenter importId="run" />);
    const start = await screen.findByRole("button", { name: "Start import" });
    fireEvent.click(start);
    fireEvent.click(start);
    expect(
      vi
        .mocked(apiRequest)
        .mock.calls.filter(([, init]) => init?.method === "POST"),
    ).toHaveLength(1);
    expect((start as HTMLButtonElement).disabled).toBe(true);
    await act(async () =>
      finish?.({ ...ready, import: { ...ready.import, status: "queued" } }),
    );
    await screen.findByRole("button", { name: "Resume processing" });
  });
  it("retains mutations through React StrictMode effect replay", async () => {
    setup({
      ...detail,
      import: {
        ...run,
        mapping_snapshot: {
          version: 1,
          headers: detail.file.headers,
          mapping: { supplier_sku: "SKU", description: "Product" },
          source_conventions: {},
        },
      },
    });
    render(
      <StrictMode>
        <ImportCenter importId="run" />
      </StrictMode>,
    );
    fireEvent.click(
      await screen.findByRole("button", { name: "Validate rows" }),
    );
    await screen.findByText("Validation queued.");
  });
  it.each(["viewer", "buyer"])("applies %s mutation policy", async (role) => {
    workspace.organization.role = role;
    setup();
    render(<ImportCenter importId="run" />);
    await screen.findByText("Source preview");
    expect(!!screen.queryByRole("button", { name: "Save mapping" })).toBe(
      role === "buyer",
    );
    expect(!!screen.queryByText("Read-only access")).toBe(role === "viewer");
  });
  it("feature disablement preserves history/downloads and cancellation", async () => {
    workspace.subscription.capabilities.manual_imports_enabled = false;
    setup();
    render(<ImportCenter importId="run" />);
    await screen.findByText("Source preview");
    expect(screen.getByText("Manual imports unavailable")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Save mapping" })).toBeNull();
    expect(
      screen.getByRole("button", { name: "Download source file" }),
    ).toBeTruthy();
    expect(screen.getByRole("button", { name: "Cancel import" })).toBeTruthy();
  });
  it("separates partial validation failures from eligible rows", async () => {
    setup({
      ...detail,
      import: {
        ...run,
        validation_started_at: "now",
        validation_completed_at: "now",
        valid_rows: 3,
        failed_count: 1,
      },
      errors: [
        {
          source_row: 5,
          field: "description",
          code: "missing_description",
          message: "A value is required.",
        },
      ],
    });
    render(<ImportCenter importId="run" />);
    await screen.findByText("A value is required.");
    expect(screen.getByTestId("import-eligible-rows").textContent).toBe("3");
    expect(screen.getByTestId("import-failed-rows").textContent).toBe("1");
    expect(screen.getByRole("button", { name: "Start import" })).toBeTruthy();
  });
});
