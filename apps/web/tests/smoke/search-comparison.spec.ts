import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test, type Page } from "@playwright/test";

type Fixture = {
  workspace_viewer: { email: string; password: string; slug: string };
  search_login: { email: string; password: string; slug: string };
  search_comparison: { canonical_product_id: string };
};

const fixture = JSON.parse(
  readFileSync(
    resolve(process.cwd(), "../api/storage/app/private/auth-e2e-isolated.json"),
    "utf8",
  ),
) as Fixture;
const web = process.env.WEB_URL ?? "http://localhost:3100";

test.describe.configure({ mode: "serial" });

async function login(page: Page, identity = fixture.search_login) {
  await page.goto(`${web}/login`);
  await page.getByLabel("Email address").fill(identity.email);
  await page.getByLabel("Password", { exact: true }).fill(identity.password);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).toHaveURL(`${web}/o/${identity.slug}/dashboard`);
}

async function search(page: Page, query: string) {
  const input = page.getByLabel("Search products");
  await input.fill(query);
  await expect(page).toHaveURL(
    new RegExp(`q=${encodeURIComponent(query).replace(/%20/g, "(?:%20|\\+)")}`),
  );
}

test("bilingual structured search opens a traceable Beefsteak comparison with client navigation", async ({
  page,
}) => {
  await login(page, fixture.search_login);
  await page.evaluate(() => {
    (window as Window & { searchMarker?: string }).searchMarker =
      "same-document";
  });
  await page.getByRole("link", { name: "Search", exact: true }).click();
  await expect(page).toHaveURL(`${web}/o/${fixture.search_login.slug}/search`);
  await search(page, "tomato");
  await expect(
    page.getByRole("heading", { name: "Tomato", exact: true }),
  ).toBeVisible();
  await search(page, "tomate");
  await expect(
    page.getByRole("heading", { name: "Tomato", exact: true }),
  ).toBeVisible();
  await search(page, "beef tomato");
  await expect(
    page.getByRole("heading", { name: "Beefsteak Tomato" }).first(),
  ).toBeVisible();
  await search(page, "beef tomato 5x6 25 lb");
  await expect(
    page.getByRole("heading", { name: "Beefsteak Tomato" }).first(),
  ).toBeVisible();
  await page.getByLabel("Search products").press("ArrowDown");
  await expect(
    page.getByRole("link", { name: "Compare offers" }).first(),
  ).toBeFocused();
  await page
    .getByRole("combobox", { name: "Availability" })
    .first()
    .selectOption("available");
  await expect(page).toHaveURL(/availability=available/);
  await page.reload();
  await expect(page.getByLabel("Search products")).toHaveValue(
    "beef tomato 5x6 25 lb",
  );
  await page
    .getByRole("combobox", { name: "Availability" })
    .first()
    .selectOption("");
  await page.evaluate(() => {
    (window as Window & { searchMarker?: string }).searchMarker =
      "same-document";
  });
  await page.getByRole("link", { name: "Compare offers" }).first().click();
  await expect(page).toHaveURL(
    new RegExp(`/compare/${fixture.search_comparison.canonical_product_id}`),
  );
  expect(
    await page.evaluate(
      () => (window as Window & { searchMarker?: string }).searchMarker,
    ),
  ).toBe("same-document");
  await expect(
    page.getByText("Lowest Case Price", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Best Normalized Price", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Best Available Option", { exact: true }),
  ).toBeVisible();
  await expect(
    page.getByText("Alternative", { exact: true }).last(),
  ).toBeVisible();
  await expect(page.getByText("Not safely available")).toBeVisible();
  await expect(
    page.locator(".ui-badge--warning", { hasText: "manual import" }).first(),
  ).toBeVisible();
  await page
    .getByText("Calculation details and source evidence")
    .first()
    .click();
  await expect(page.getByText("Formula version").first()).toBeVisible();
  await page.goBack();
  await expect(page.getByLabel("Search products")).toHaveValue(
    "beef tomato 5x6 25 lb",
  );
  await expect(page).toHaveURL(/q=beef(?:%20|\+)tomato/);
});

test("price history keeps an accessible table fallback and refreshes only CProviderPrices data", async ({
  page,
}) => {
  await login(page, fixture.search_login);
  await page.goto(
    `${web}/o/${fixture.search_login.slug}/compare/${fixture.search_comparison.canonical_product_id}`,
  );
  await expect(
    page.getByRole("link", { name: "View price history" }),
  ).toBeVisible();
  await page.evaluate(() => {
    (window as Window & { historyMarker?: string }).historyMarker =
      "same-document";
  });
  const priceHistoryLink = page.getByRole("link", {
    name: "View price history",
  });
  const expectedHistoryPath = `/o/${fixture.search_login.slug}/price-history?product=${fixture.search_comparison.canonical_product_id}`;
  await expect(priceHistoryLink).toHaveAttribute("href", expectedHistoryPath);
  const historyLoaded = page.waitForResponse(
    (response) =>
      response
        .url()
        .includes(
          `/price-history/${fixture.search_comparison.canonical_product_id}`,
        ) && response.request().method() === "GET",
  );
  await Promise.all([
    page.waitForURL(`${web}${expectedHistoryPath}`),
    priceHistoryLink.click(),
  ]);
  expect((await historyLoaded).status()).toBe(200);
  await expect(page).toHaveURL(
    new RegExp(
      `/price-history\\?product=${fixture.search_comparison.canonical_product_id}`,
    ),
  );
  expect(
    await page.evaluate(
      () => (window as Window & { historyMarker?: string }).historyMarker,
    ),
  ).toBe("same-document");
  await expect(
    page.getByRole("heading", { name: "Accessible price chart" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Daily compatible-unit values" }),
  ).toBeVisible();
  await expect(
    page.getByRole("img", { name: /daily compatible-unit/i }),
  ).toBeVisible();

  let historyRequests = 0;
  page.on("request", (request) => {
    if (
      request.url().includes("/api/v1/organizations/") &&
      request.url().includes("/price-history/")
    ) {
      historyRequests += 1;
    }
  });
  const beforeSync = historyRequests;
  await page.getByRole("button", { name: "Sync supplier" }).click();
  await expect(page.getByText(/future connector action/i)).toBeVisible();
  expect(historyRequests).toBe(beforeSync);

  const refreshed = page.waitForRequest(
    (request) =>
      request.url().includes("/api/v1/organizations/") &&
      request.url().includes("/price-history/"),
  );
  await page
    .getByRole("button", { name: "Refresh CProviderPrices data" })
    .click();
  await refreshed;
  expect(historyRequests).toBeGreaterThan(beforeSync);
});

test("mobile filter drawer is usable and a foreign workspace never receives private Beefsteak offers", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page, fixture.search_login);
  await page.getByRole("button", { name: "Open navigation" }).click();
  await page
    .getByRole("dialog", { name: "Workspace navigation" })
    .getByRole("link", { name: "Search", exact: true })
    .click();
  await page.getByRole("button", { name: "Filters" }).click();
  await expect(
    page.getByRole("dialog", { name: "Search filters" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Close Search filters" }).click();
  await page.locator(".ui-dropdown > summary").click();
  await page.getByRole("button", { name: "Sign out" }).click();
  await login(page, fixture.workspace_viewer);
  await page.goto(
    `${web}/o/${fixture.workspace_viewer.slug}/compare/${fixture.search_comparison.canonical_product_id}`,
  );
  await expect(page.getByText("Lowest case produce")).toHaveCount(0);
  await expect(page.getByText("Normalized value produce")).toHaveCount(0);
});
