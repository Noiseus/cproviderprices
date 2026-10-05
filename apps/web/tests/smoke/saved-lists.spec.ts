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

  await page.getByRole("link", { name: "Lists", exact: true }).click();
  await expect(page).toHaveURL(`${web}/o/${fixture.search_login.slug}/lists`);
  expect(
    await page.evaluate(
      () => (window as Window & { savedListMarker?: string }).savedListMarker,
    ),
  ).toBe("same-document");
  await page
    .getByRole("link", { name: "Beefsteak buyer list", exact: true })
    .click();
  await expect(page.getByText("Best normalized option")).toBeVisible();
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

  await page.getByRole("link", { name: "Lists", exact: true }).click();
  await page
    .getByRole("region", { name: "Beefsteak buyer list" })
    .getByRole("button", { name: "Duplicate" })
    .click();
  await page
    .getByRole("dialog", { name: "Duplicate list" })
    .getByRole("button", { name: "Duplicate", exact: true })
    .click();
  await expect(page.getByText(/Beefsteak buyer list copy/i)).toBeVisible();
  await page
    .getByRole("region", { name: "Beefsteak buyer list", exact: true })
    .getByRole("button", { name: "Archive" })
    .click();
  await page.getByRole("button", { name: "Archived" }).click();
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
  await page.goto(
    `${web}/o/${fixture.search_login.slug}/lists/${fixture.saved_lists.unavailable_selection_id}`,
  );
  await expect(page.getByText("Selected offer is unavailable.")).toBeVisible();
  await expect(page.getByText("Best available option")).toBeVisible();
  await expect(page.getByText(/eligible current offers/i)).toBeVisible();
});
