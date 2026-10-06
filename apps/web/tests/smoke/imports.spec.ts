import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test, type Page } from "@playwright/test";

type Identity = {
  email: string;
  password: string;
  slug: string;
  organization_id: string;
};
type Fixture = {
  imports: {
    owner: Identity;
    buyer: Identity;
    viewer: Identity;
    disabled: Identity;
    location_id: string;
    other_location_id: string;
    accounts: Record<string, string>;
  };
};
const fixture = (
  JSON.parse(
    readFileSync(
      resolve(
        process.cwd(),
        "../api/storage/app/private/auth-e2e-isolated.json",
      ),
      "utf8",
    ),
  ) as Fixture
).imports;
const web = process.env.WEB_URL ?? "http://localhost:3100";
const api = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8180";
test.describe.configure({ mode: "serial" });
let csvDetailUrl = "";

async function login(page: Page, identity = fixture.owner) {
  await page.goto(`${web}/login`);
  await page.getByLabel("Email address").fill(identity.email);
  await page.getByLabel("Password", { exact: true }).fill(identity.password);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).toHaveURL(`${web}/o/${identity.slug}/dashboard`, {
    timeout: 15_000,
  });
  await page.getByRole("link", { name: "Imports", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Import Center" }),
  ).toBeVisible();
}
async function upload(
  page: Page,
  supplier: string,
  file: string | { name: string; mimeType: string; buffer: Buffer },
) {
  await page.getByLabel("Import location").selectOption(fixture.location_id);
  const supplierAccount = page.getByRole("combobox", {
    name: "Supplier account",
    exact: true,
  });
  await expect(
    supplierAccount.locator(`option[value="${fixture.accounts[supplier]}"]`),
  ).toHaveCount(1);
  await supplierAccount.selectOption(fixture.accounts[supplier]);
  await page
    .getByRole("combobox", { name: "Supplier account", exact: true })
    .focus();
  await page.keyboard.press("Tab");
  await expect(page.getByLabel(/^Supplier file/)).toBeFocused();
  await page.getByLabel(/^Supplier file/).setInputFiles(file);
  const uploadResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname ===
        `/api/v1/organizations/${fixture.owner.organization_id}/imports`,
  );
  const detailResponse = page.waitForResponse((response) => {
    const path = new URL(response.url()).pathname;
    return (
      response.request().method() === "GET" &&
      path.startsWith(
        `/api/v1/organizations/${fixture.owner.organization_id}/imports/`,
      ) &&
      path.split("/").length === 7
    );
  });
  await page.getByRole("button", { name: "Upload file", exact: true }).click();
  const uploaded = await uploadResponse;
  expect(uploaded.status()).toBe(201);
  const created = (await uploaded.json()) as { import: { id: string } };
  await expect(page).toHaveURL(
    `${web}/o/${fixture.owner.slug}/imports/${created.import.id}`,
  );
  expect((await detailResponse).status()).toBe(200);
  await expect(
    page.getByRole("heading", { name: "Source preview" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Column mapping" }),
  ).toBeVisible();
}
async function mapping(page: Page, supplier: "a" | "b") {
  const sku = page.getByRole("combobox", {
    name: "Supplier SKU (required)",
    exact: true,
  });
  await sku.focus();
  await page.keyboard.press("ArrowDown");
  await expect(sku).toBeFocused();
  await page
    .getByRole("combobox", { name: "Supplier SKU (required)", exact: true })
    .selectOption(supplier === "a" ? "Item" : "Vendor Code");
  await page
    .getByRole("combobox", { name: "Description (required)", exact: true })
    .selectOption(supplier === "a" ? "Description" : "Product Text");
  await page
    .getByRole("combobox", { name: "Pack / size", exact: true })
    .selectOption(supplier === "a" ? "Pack" : "Size Label");
  await page.getByLabel("Volumes use US liquid units").check();
  if (supplier === "a")
    await page
      .getByLabel("Save reusable template as (optional)")
      .fill("CSV columns");
  await page.getByRole("button", { name: "Save mapping", exact: true }).click();
  await expect(
    page.getByText("Mapping saved. Ready to validate.", { exact: true }),
  ).toBeVisible();
  await page
    .getByRole("button", { name: "Validate rows", exact: true })
    .click();
  await expect(
    page.getByText("Validation complete.", { exact: true }),
  ).toBeVisible({ timeout: 20000 });
  await expect(page.getByTestId("import-eligible-rows")).toHaveText("3");
}
async function noLeakOrOverflow(page: Page) {
  expect(await page.locator("main").innerText()).not.toMatch(
    /storage_key|AWS_SECRET|Bearer |minio:9000|INGESTION_SERVICE_TOKEN/,
  );
  expect(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth,
    ),
  ).toBe(true);
}

async function assertMobileTableContainment(
  page: Page,
  tableClass: "import-preview" | "import-errors",
) {
  const table = page.locator(`table.${tableClass}`);
  await expect(table.locator("thead th").first()).toHaveCSS(
    "white-space",
    "nowrap",
  );
  expect(
    await table
      .locator("thead th")
      .first()
      .evaluate((cell) => cell.clientWidth),
  ).toBeGreaterThanOrEqual(128);
  const scroll = table.locator("xpath=..");
  expect(
    await scroll.evaluate(
      (element) => element.scrollWidth > element.clientWidth,
    ),
  ).toBe(true);
  await scroll.evaluate((element) => {
    element.scrollLeft = element.scrollWidth;
  });
  expect(
    await scroll.evaluate((element) => element.scrollLeft),
  ).toBeGreaterThan(0);
  await scroll.evaluate((element) => {
    element.scrollLeft = 0;
  });
}

test("owner completes real CSV flow, restores state, downloads, and avoids document reload", async ({
  page,
}, info) => {
  test.setTimeout(90000);
  await login(page);
  await page.evaluate(() => {
    (window as Window & { importMarker?: string }).importMarker =
      "same-document";
  });
  await page
    .getByLabel("Import location")
    .selectOption(fixture.other_location_id);
  await expect(
    page
      .getByRole("combobox", { name: "Supplier account", exact: true })
      .locator("option"),
  ).toHaveText(["Choose supplier account", "Other Location Supplier"]);
  await expect(
    page
      .getByRole("combobox", { name: "Supplier account", exact: true })
      .locator("option")
      .filter({ hasText: "CSV Supplier" }),
  ).toHaveCount(0);
  await expect(
    page
      .getByLabel("Import location")
      .locator("option")
      .filter({ hasText: "Inactive Kitchen" }),
  ).toHaveCount(0);
  await upload(
    page,
    "CSV Supplier",
    resolve(process.cwd(), "../api/tests/Fixtures/import-supplier-a.csv"),
  );
  await expect(
    page.getByRole("cell", { name: "00012", exact: true }),
  ).toBeVisible();
  await mapping(page, "a");
  await expect(
    page
      .getByRole("row")
      .filter({ hasText: "A value is required for this mapped field." }),
  ).toContainText("5");
  await expect(
    page
      .getByRole("row")
      .filter({ hasText: "A value is required for this mapped field." }),
  ).toContainText("description");
  await expect(page.getByTestId("import-failed-rows")).toHaveText("1");
  csvDetailUrl = page.url();
  await page.getByRole("button", { name: "Start import", exact: true }).click();
  await expect(
    page.getByRole("button", { name: "Resume processing" }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Dashboard", exact: true }).click();
  await page.getByRole("link", { name: "Imports", exact: true }).click();
  await page
    .getByRole("link", { name: "View import", exact: true })
    .first()
    .click();
  await expect(page).toHaveURL(csvDetailUrl);
  await expect(
    page.getByRole("heading", { name: "Import summary", exact: true }),
  ).toBeVisible({ timeout: 20000 });
  await expect(
    page.locator(".ui-badge").filter({ hasText: "partially completed" }),
  ).toBeVisible();
  await expect(page.getByTestId("import-processed")).toHaveText("3");
  await expect(page.getByTestId("import-total-rows")).toHaveText("4");
  await expect(page.getByTestId("import-remaining")).toHaveText("0");
  expect(
    await page.evaluate(
      () => (window as Window & { importMarker?: string }).importMarker,
    ),
  ).toBe("same-document");
  await page.setViewportSize({ width: 390, height: 844 });
  await assertMobileTableContainment(page, "import-preview");
  await assertMobileTableContainment(page, "import-errors");
  await noLeakOrOverflow(page);
  await page.screenshot({
    path: info.outputPath("import-mobile-errors.png"),
    fullPage: true,
  });
  await page.setViewportSize({ width: 1280, height: 900 });
  for (const label of ["Download source file", "Download error report"]) {
    const download = page.waitForEvent("download");
    await page.getByRole("button", { name: label }).click();
    expect((await download).suggestedFilename()).toMatch(/\.csv$/);
  }
  await noLeakOrOverflow(page);
  await page.screenshot({
    path: info.outputPath("import-desktop.png"),
    fullPage: true,
  });
  // Expired signed URL: exercise the real backend rejection, not a mock lifecycle.
  await page.route("**/imports/*/files/*/download?*", async (route) => {
    await route.continue({
      url: route
        .request()
        .url()
        .replace(/expires=\d+/, "expires=1"),
    });
  });
  await page.getByRole("button", { name: "Download source file" }).click();
  await expect(
    page.getByText(/Download unavailable or authorization expired/),
  ).toBeVisible();
});

test("buyer completes differently shaped XLSX through real Python on mobile and tablet", async ({
  page,
}, info) => {
  test.setTimeout(90000);
  await login(page, fixture.buyer);
  await page.setViewportSize({ width: 390, height: 844 });
  await upload(
    page,
    "XLSX Supplier",
    resolve(process.cwd(), "../api/tests/Fixtures/import-supplier-b.xlsx"),
  );
  await noLeakOrOverflow(page);
  await mapping(page, "b");
  await page.setViewportSize({ width: 768, height: 900 });
  await noLeakOrOverflow(page);
  await page.getByRole("button", { name: "Start import", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Import summary", exact: true }),
  ).toBeVisible({ timeout: 20000 });
  await expect(
    page.locator(".ui-badge").filter({ hasText: /^completed$/ }),
  ).toBeVisible();
  await expect(page.getByTestId("import-processed")).toHaveText("3");
  await expect(page.getByTestId("import-failed-rows")).toHaveText("0");
  await page.screenshot({
    path: info.outputPath("import-tablet.png"),
    fullPage: true,
  });
  await page.setViewportSize({ width: 390, height: 844 });
  await noLeakOrOverflow(page);
  await page.screenshot({
    path: info.outputPath("import-mobile.png"),
    fullPage: true,
  });
});

test("template reuse, keyboard cancellation and focus restoration preserve history", async ({
  page,
}) => {
  await login(page);
  const buffer = Buffer.concat([
    Buffer.from([0xef, 0xbb, 0xbf]),
    readFileSync(
      resolve(process.cwd(), "../api/tests/Fixtures/import-supplier-a.csv"),
    ),
  ]);
  await upload(page, "CSV Supplier", {
    name: "template-reuse.csv",
    mimeType: "text/csv",
    buffer,
  });
  await page
    .getByLabel("Reusable mapping template")
    .selectOption({ label: "CSV columns" });
  await expect(
    page.getByRole("combobox", { name: "Description (required)", exact: true }),
  ).toHaveValue("Description");
  await page.getByRole("button", { name: "Save mapping", exact: true }).click();
  await expect(
    page.getByText("Mapping saved. Ready to validate.", { exact: true }),
  ).toBeVisible();
  const cancel = page.getByRole("button", {
    name: "Cancel import",
    exact: true,
  });
  await cancel.focus();
  await page.keyboard.press("Enter");
  const dialog = page.getByRole("dialog", { name: "Cancel this import?" });
  await expect(dialog).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(dialog).not.toBeVisible();
  await expect(cancel).toBeFocused();
  await cancel.click();
  await dialog.getByRole("button", { name: "Confirm cancellation" }).click();
  await expect(
    page.getByRole("heading", { name: "Import summary" }),
  ).toBeVisible();
  await expect(
    page.locator(".ui-badge").filter({ hasText: /^canceled$/ }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Source preview" }),
  ).toBeVisible();
});

test("viewer reads history and downloads but real API denies mutation", async ({
  page,
}) => {
  await login(page, fixture.viewer);
  await expect(
    page.getByText("Read-only access", { exact: true }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "Upload file" })).toHaveCount(
    0,
  );
  await page.goto(csvDetailUrl);
  await expect(
    page.getByRole("heading", { name: "Import summary" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Cancel import", exact: true }),
  ).toHaveCount(0);
  const download = page.waitForEvent("download");
  await page.getByRole("button", { name: "Download source file" }).click();
  expect((await download).suggestedFilename()).toBe("import-supplier-a.csv");
  const result = await page.evaluate(
    async ({ api, org, id }) => {
      await fetch(`${api}/api/v1/auth/csrf-cookie`, { credentials: "include" });
      const token =
        document.cookie
          .split("; ")
          .find((c) => c.startsWith("XSRF-TOKEN="))
          ?.split("=")
          .slice(1)
          .join("=") ?? "";
      const response = await fetch(
        `${api}/api/v1/organizations/${org}/imports/${id}/start`,
        {
          method: "POST",
          credentials: "include",
          headers: {
            Accept: "application/json",
            "X-XSRF-TOKEN": decodeURIComponent(token),
          },
        },
      );
      return response.status;
    },
    {
      api,
      org: fixture.viewer.organization_id,
      id: csvDetailUrl.split("/").at(-1) ?? "",
    },
  );
  expect(result).toBe(403);
});

test("disabled entitlement and foreign import deny access without private data", async ({
  page,
}) => {
  await login(page, fixture.disabled);
  await expect(
    page.getByText("Manual imports unavailable", { exact: true }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "Upload file" })).toHaveCount(
    0,
  );
  await page.goto(
    `${web}/o/${fixture.disabled.slug}/imports/${csvDetailUrl.split("/").at(-1)}`,
  );
  await expect(
    page.getByText("This import is unavailable in this organization.", {
      exact: true,
    }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Source preview" }),
  ).toHaveCount(0);
  await noLeakOrOverflow(page);
});

test("unauthenticated deep links show sign-in and never a private preview", async ({
  page,
}) => {
  await page.goto(csvDetailUrl);
  await expect(
    page.getByRole("heading", { name: /Sign in|Session/ }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Source preview" }),
  ).toHaveCount(0);
});
