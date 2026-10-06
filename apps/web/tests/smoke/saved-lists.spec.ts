import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test, type Page } from "@playwright/test";

type Fixture = {
  search_login: { email: string; password: string; slug: string };
  workspace_viewer: { email: string; password: string; slug: string };
  search_comparison: {
    canonical_product_id: string;
    offers: Record<string, string>;
  };
  saved_lists: { unavailable_selection_id: string };
};

type SavedListDetailPayload = {
  saved_list: {
    items: Array<{
      canonical_product: { id: string };
      selected_offer_id: string | null;
      best_normalized_option: {
        id: string;
        normalized_price: string | null;
        normalized_unit: string | null;
        availability: string;
        freshness: string;
        comparison_class: string;
      } | null;
      best_available_option: { id: string } | null;
    }>;
  };
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
  const loginResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/api/v1/auth/login",
    { timeout: 15_000 },
  );
  const dashboardNavigation = page.waitForURL(
    `${web}/o/${identity.slug}/dashboard`,
    { timeout: 15_000 },
  );
  await page.getByRole("button", { name: "Sign in" }).click();
  const [login] = await Promise.all([loginResponse, dashboardNavigation]);
  expect(login.status()).toBe(200);
}

test("buyer saves Beefsteak intent, chooses an offer, updates it, and preserves client navigation", async ({
  page,
}) => {
  await login(page);
  await page.goto(
    `${web}/o/${fixture.search_login.slug}/compare/${fixture.search_comparison.canonical_product_id}`,
  );
  await expect(
    page.getByRole("heading", { name: "Beefsteak Tomato" }),
  ).toBeVisible();
  await page.evaluate(() => {
    (window as Window & { savedListMarker?: string }).savedListMarker =
      "same-document";
  });
  await page.getByRole("button", { name: "Add to list" }).click();
  const dialog = page.getByRole("dialog", {
    name: "Add to saved purchasing list",
  });
  await dialog.getByLabel("Or create a new list").fill("Beefsteak buyer list");
  await dialog.getByRole("button", { name: "Create and add" }).click();
  await expect(
    dialog.getByText(/Added to Beefsteak buyer list/i),
  ).toBeVisible();
  await dialog
    .getByRole("button", { name: /Close Add to saved purchasing list/i })
    .click();

  const listsUrl = `${web}/o/${fixture.search_login.slug}/lists`;
  const listsRouteResponse = page.waitForResponse(
    (response) => {
      const responseUrl = new URL(response.url());
      return (
        response.request().method() === "GET" &&
        response.request().resourceType() === "fetch" &&
        responseUrl.pathname === `/o/${fixture.search_login.slug}/lists` &&
        responseUrl.searchParams.has("_rsc")
      );
    },
    { timeout: 15_000 },
  );
  const listsNavigation = page.waitForURL(listsUrl, { timeout: 15_000 });
  await page.getByRole("link", { name: "Lists", exact: true }).click();
  const [listsRoute] = await Promise.all([listsRouteResponse, listsNavigation]);
  expect(listsRoute.status()).toBe(200);
  expect(
    await page.evaluate(
      () => (window as Window & { savedListMarker?: string }).savedListMarker,
    ),
  ).toBe("same-document");
  const savedListLink = page.getByRole("link", {
    name: "Beefsteak buyer list",
    exact: true,
  });
  const savedListHref = await savedListLink.getAttribute("href");
  const savedListId = savedListHref?.split("/").at(-1);
  if (!savedListId) throw new Error("Created saved-list link had no list ID.");
  const detailResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "GET" &&
      new URL(response.url()).pathname.endsWith(`/saved-lists/${savedListId}`),
    { timeout: 15_000 },
  );
  await savedListLink.click();
  const detail = await detailResponse;
  expect(detail.status()).toBe(200);
  const savedListsPath = new URL(detail.url()).pathname.replace(/\/[^/]+$/, "");
  const detailPayload = (await detail.json()) as SavedListDetailPayload;
  const [item] = detailPayload.saved_list.items;
  expect(item).toBeDefined();
  expect(item.canonical_product.id).toBe(
    fixture.search_comparison.canonical_product_id,
  );
  expect(item.selected_offer_id).toBeNull();
  expect(item.best_normalized_option).toMatchObject({
    id: fixture.search_comparison.offers.best_normalized,
    normalized_price: "0.6",
    normalized_unit: "lb",
    availability: "unavailable",
    freshness: "manual_import",
    comparison_class: "equivalent",
  });
  expect(item.best_available_option).toMatchObject({
    id: fixture.search_comparison.offers.best_available,
  });
  await expect(page.getByText("Best normalized option")).toBeVisible({
    timeout: 15_000,
  });
  await expect(page.getByText("Best available option")).toBeVisible();
  await page.getByLabel("Preferred unit").selectOption("lb");
  await page.getByLabel("Private notes").fill("Use ripe tomatoes");
  await page.getByRole("button", { name: "Save item" }).click();
  await expect(page.getByText("Saved", { exact: true })).toBeVisible();
  await page
    .getByLabel("Selected supplier offer")
    .selectOption(fixture.search_comparison.offers.best_available);
  await expect(page.getByLabel("Selected supplier offer")).toHaveValue(
    fixture.search_comparison.offers.best_available,
  );

  const returnToListsResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "GET" &&
      new URL(response.url()).pathname === savedListsPath,
    { timeout: 15_000 },
  );
  const returnToListsRoute = page.waitForResponse(
    (response) =>
      response.request().method() === "GET" &&
      response.request().resourceType() === "fetch" &&
      new URL(response.url()).pathname ===
        `/o/${fixture.search_login.slug}/lists` &&
      new URL(response.url()).searchParams.has("_rsc"),
    { timeout: 15_000 },
  );
  const returnToListsNavigation = page.waitForURL(listsUrl, {
    timeout: 15_000,
  });
  await page.getByRole("link", { name: "Lists", exact: true }).click();
  const [returnToLists, returnRoute] = await Promise.all([
    returnToListsResponse,
    returnToListsRoute,
    returnToListsNavigation,
  ]);
  expect(returnToLists.status()).toBe(200);
  expect(returnRoute.status()).toBe(200);
  const duplicateResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname ===
        `${savedListsPath}/${savedListId}/duplicate`,
    { timeout: 15_000 },
  );
  await page
    .getByRole("region", { name: "Beefsteak buyer list" })
    .getByRole("button", { name: "Duplicate" })
    .click();
  await page
    .getByRole("dialog", { name: "Duplicate list" })
    .getByRole("button", { name: "Duplicate", exact: true })
    .click();
  expect((await duplicateResponse).status()).toBe(201);
  await expect(page.getByText(/Beefsteak buyer list copy/i)).toBeVisible();
  const archiveResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname ===
        `${savedListsPath}/${savedListId}/archive`,
    { timeout: 15_000 },
  );
  await page
    .getByRole("region", { name: "Beefsteak buyer list", exact: true })
    .getByRole("button", { name: "Archive" })
    .click();
  expect((await archiveResponse).status()).toBe(200);
  const archivedListsResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "GET" &&
      new URL(response.url()).pathname === savedListsPath &&
      new URL(response.url()).searchParams.get("status") === "archived",
    { timeout: 15_000 },
  );
  await page.getByRole("button", { name: "Archived" }).click();
  expect((await archivedListsResponse).status()).toBe(200);
  await expect(
    page
      .getByRole("region", { name: "Beefsteak buyer list", exact: true })
      .getByRole("button", { name: "Restore" }),
  ).toBeVisible();
});

test("viewer cannot mutate a direct cross-tenant saved-list route", async ({
  page,
}) => {
  await login(page, fixture.workspace_viewer);
  await page.goto(
    `${web}/o/${fixture.workspace_viewer.slug}/lists/${fixture.saved_lists.unavailable_selection_id}`,
  );
  await expect(
    page.getByRole("heading", { name: "List unavailable" }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "Save item" })).toHaveCount(0);
});

test("retains a selected offer after it becomes unavailable and shows alternatives", async ({
  page,
}) => {
  await login(page);
  const unavailableListUrl = `${web}/o/${fixture.search_login.slug}/lists/${fixture.saved_lists.unavailable_selection_id}`;
  const unavailableListResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "GET" &&
      new URL(response.url()).pathname.endsWith(
        `/saved-lists/${fixture.saved_lists.unavailable_selection_id}`,
      ),
    { timeout: 15_000 },
  );
  const unavailableListNavigation = page.waitForURL(unavailableListUrl, {
    timeout: 15_000,
  });
  await page.goto(unavailableListUrl);
  const [unavailableList] = await Promise.all([
    unavailableListResponse,
    unavailableListNavigation,
  ]);
  expect(unavailableList.status()).toBe(200);
  const unavailableListPayload =
    (await unavailableList.json()) as SavedListDetailPayload;
  expect(unavailableListPayload.saved_list.items).toContainEqual(
    expect.objectContaining({
      selected_offer_id: expect.any(String),
    }),
  );
  await expect(page.getByText("Selected offer is unavailable.")).toBeVisible();
  await expect(page.getByText("Best available option")).toBeVisible();
  await expect(page.getByText(/eligible current offers/i)).toBeVisible();
});
