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
  pilot: {
    owner: Identity;
    buyer: Identity;
    viewer: Identity;
    locations: { main: string; catering: string };
    comparison: {
      canonical_product_id: string;
      offers: Record<string, string>;
    };
    saved_list_id: string;
    matching_candidate_id: string;
    matching_offer_id: string;
    isolation: Identity & {
      location_id: string;
      supplier_account_id: string;
      saved_list_id: string;
      saved_list_item_id: string;
      offer_id: string;
    };
  };
  platform: { email: string; password: string };
};

type ComparisonPayload = {
  offers: Array<{
    id: string;
    raw_supplier_name: string;
    normalized_price: string | null;
    availability: string;
    freshness: string;
    comparison_class: string;
    winner_labels: string[];
  }>;
};

type SavedListDetailPayload = {
  saved_list: {
    id: string;
    items: Array<{ selected_offer_id: string | null }>;
  };
};

const fixture = JSON.parse(
  readFileSync(
    resolve(process.cwd(), "../api/storage/app/private/auth-e2e-isolated.json"),
    "utf8",
  ),
) as Fixture;
const web = process.env.WEB_URL ?? "http://localhost:3100";
const api = process.env.NEXT_PUBLIC_API_URL ?? "http://localhost:8180";

test.describe.configure({ mode: "serial" });

async function login(
  page: Page,
  identity: Pick<Identity, "email" | "password" | "slug">,
) {
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

async function selectPilotMainLocation(page: Page) {
  const locationSelected = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname.endsWith(
        `/restaurant-locations/${fixture.pilot.locations.main}/select`,
      ),
  );
  await page
    .getByRole("combobox", { name: "Location context" })
    .selectOption(fixture.pilot.locations.main);
  expect((await locationSelected).status()).toBe(200);
}

test("pilot buyer sees traceable, safe Beefsteak outcomes and cannot read the isolation tenant", async ({
  page,
}) => {
  await login(page, fixture.pilot.buyer);
  await selectPilotMainLocation(page);
  const comparisonResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "GET" &&
      new URL(response.url()).pathname ===
        `/api/v1/organizations/${fixture.pilot.buyer.organization_id}/comparisons/${fixture.pilot.comparison.canonical_product_id}` &&
      new URL(response.url()).searchParams.get("location_id") ===
        fixture.pilot.locations.main,
  );
  await page.goto(
    `${web}/o/${fixture.pilot.buyer.slug}/compare/${fixture.pilot.comparison.canonical_product_id}`,
  );
  const comparison = await comparisonResponse;
  expect(comparison.status()).toBe(200);
  const payload = (await comparison.json()) as ComparisonPayload;
  const normalized = payload.offers.find((offer) =>
    offer.winner_labels.includes("best_normalized_price"),
  );
  const available = payload.offers.find((offer) =>
    offer.winner_labels.includes("best_available_option"),
  );
  expect(normalized).toMatchObject({
    id: fixture.pilot.comparison.offers.best_normalized,
    normalized_price: "0.5",
    availability: "unavailable",
  });
  expect(available).toMatchObject({
    id: fixture.pilot.comparison.offers.best_available,
    freshness: "fresh",
  });
  expect(
    payload.offers.find(
      (offer) => offer.id === fixture.pilot.comparison.offers.connection_failed,
    ),
  ).toMatchObject({ freshness: "connection_failed", winner_labels: [] });
  expect(
    payload.offers.find(
      (offer) => offer.id === fixture.pilot.comparison.offers.variable_weight,
    ),
  ).toMatchObject({ normalized_price: null });
  expect(
    payload.offers.find(
      (offer) => offer.id === fixture.pilot.comparison.offers.alternative,
    ),
  ).toMatchObject({ comparison_class: "alternative" });
  await expect(
    page.locator(".ui-badge--danger").filter({ hasText: "Stale" }).first(),
  ).toBeVisible();
  await expect(
    page
      .locator(".ui-badge--danger")
      .filter({ hasText: "Connection failed" })
      .first(),
  ).toBeVisible();
  await expect(
    page
      .locator(".comparison-offer__warning")
      .filter({ hasText: "Not safely comparable" })
      .first(),
  ).toBeVisible();
  await page
    .getByText("Calculation details and source evidence")
    .first()
    .click();
  await expect(page.getByText("Formula version").first()).toBeVisible();
  await page.evaluate(() => {
    (window as Window & { pilotMarker?: string }).pilotMarker = "same-document";
  });

  const listsUrl = `${web}/o/${fixture.pilot.buyer.slug}/lists`;
  const listsRoute = page.waitForResponse(
    (response) =>
      response.request().method() === "GET" &&
      response.request().resourceType() === "fetch" &&
      new URL(response.url()).pathname ===
        `/o/${fixture.pilot.buyer.slug}/lists` &&
      new URL(response.url()).searchParams.has("_rsc"),
  );
  await Promise.all([
    page.waitForURL(listsUrl),
    page.getByRole("link", { name: "Lists", exact: true }).click(),
  ]);
  expect((await listsRoute).status()).toBe(200);
  expect(
    await page.evaluate(
      () => (window as Window & { pilotMarker?: string }).pilotMarker,
    ),
  ).toBe("same-document");
  const pilotListLink = page.getByRole("link", {
    name: "Pilot weekly produce",
    exact: true,
  });
  await expect(pilotListLink).toHaveAttribute(
    "href",
    `/o/${fixture.pilot.buyer.slug}/lists/${fixture.pilot.saved_list_id}`,
  );
  const pilotListResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "GET" &&
      new URL(response.url()).pathname ===
        `/api/v1/organizations/${fixture.pilot.buyer.organization_id}/saved-lists/${fixture.pilot.saved_list_id}`,
    { timeout: 15_000 },
  );
  const pilotListNavigation = page.waitForURL(
    `${web}/o/${fixture.pilot.buyer.slug}/lists/${fixture.pilot.saved_list_id}`,
    { timeout: 15_000 },
  );
  await pilotListLink.click();
  const [pilotListDetail] = await Promise.all([
    pilotListResponse,
    pilotListNavigation,
  ]);
  expect(pilotListDetail.status()).toBe(200);
  const pilotListPayload =
    (await pilotListDetail.json()) as SavedListDetailPayload;
  expect(pilotListPayload.saved_list.id).toBe(fixture.pilot.saved_list_id);
  expect(pilotListPayload.saved_list.items).toContainEqual(
    expect.objectContaining({
      selected_offer_id:
        fixture.pilot.comparison.offers.same_case_different_normalized,
    }),
  );
  await expect(page.getByText("Selected option")).toBeVisible();
  await expect(page.getByText("Best normalized option")).toBeVisible();
  await expect(page.getByText("Best available option")).toBeVisible();

  const denied = await page.evaluate(
    async ({ apiOrigin, organizationId, productId, locationId }) => {
      const response = await fetch(
        `${apiOrigin}/api/v1/organizations/${organizationId}/comparisons/${productId}?location_id=${locationId}`,
        { credentials: "include" },
      );
      return {
        status: response.status,
        body: await response.text(),
      };
    },
    {
      apiOrigin: api,
      organizationId: fixture.pilot.isolation.organization_id,
      productId: fixture.pilot.comparison.canonical_product_id,
      locationId: fixture.pilot.isolation.location_id,
    },
  );
  expect(denied.status).toBe(403);
  expect(denied.body).not.toContain("ISOLATION TOM BEEF");
});

test("platform review maps the pending pilot item and exposes it to the authorized buyer", async ({
  page,
}) => {
  await page.goto(
    `${web}/login?next=${encodeURIComponent("/platform/matching")}`,
  );
  await page.getByLabel("Email address").fill(fixture.platform.email);
  await page
    .getByLabel("Password", { exact: true })
    .fill(fixture.platform.password);
  await Promise.all([
    page.waitForURL((url) => url.pathname === "/platform/matching"),
    page.getByRole("button", { name: "Sign in" }).click(),
  ]);
  await page.goto(
    `${web}/platform/matching/${fixture.pilot.matching_candidate_id}`,
  );
  await expect(
    page.getByRole("heading", { name: "Beefsteak Tomato" }),
  ).toBeVisible();
  await expect(page.getByText("Evidence and differences")).toBeVisible();
  const reviewPath = `/api/v1/platform/matching/candidates/${fixture.pilot.matching_candidate_id}/review`;
  const reviewResponse = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === reviewPath,
    { timeout: 15_000 },
  );
  const matchingRoute = page.waitForResponse(
    (response) =>
      response.request().method() === "GET" &&
      response.request().resourceType() === "fetch" &&
      new URL(response.url()).pathname === "/platform/matching" &&
      new URL(response.url()).searchParams.has("_rsc"),
    { timeout: 15_000 },
  );
  const navigationCount = await page.evaluate(
    () => performance.getEntriesByType("navigation").length,
  );
  const matchingNavigation = page.waitForURL(`${web}/platform/matching`, {
    timeout: 15_000,
  });
  await page.getByRole("button", { name: "Accept" }).click();
  const [review, route] = await Promise.all([
    reviewResponse,
    matchingRoute,
    matchingNavigation,
  ]);
  expect(review.status()).toBe(200);
  expect(route.status()).toBe(200);
  expect(
    await page.evaluate(
      () => performance.getEntriesByType("navigation").length,
    ),
  ).toBe(navigationCount);

  await page.context().clearCookies();
  await login(page, fixture.pilot.buyer);
  await selectPilotMainLocation(page);
  const updatedComparison = page.waitForResponse(
    (response) =>
      response.request().method() === "GET" &&
      new URL(response.url()).pathname ===
        `/api/v1/organizations/${fixture.pilot.buyer.organization_id}/comparisons/${fixture.pilot.comparison.canonical_product_id}` &&
      new URL(response.url()).searchParams.get("location_id") ===
        fixture.pilot.locations.main,
  );
  await page.goto(
    `${web}/o/${fixture.pilot.buyer.slug}/compare/${fixture.pilot.comparison.canonical_product_id}`,
  );
  const body = (await (await updatedComparison).json()) as ComparisonPayload;
  expect(body.offers).toContainEqual(
    expect.objectContaining({
      id: fixture.pilot.matching_offer_id,
      raw_supplier_name: "TOM BEEFSTEAK 25#",
    }),
  );
});
