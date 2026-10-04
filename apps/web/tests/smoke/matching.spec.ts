import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test, type Page } from "@playwright/test";

type Fixture = {
  viewer: { email: string; password: string };
  platform: {
    email: string;
    password: string;
    matching: Record<
      "accept" | "reject" | "choose" | "create",
      {
        candidate_id: string;
        supplier_account_id: string;
        description: string;
        category_id: string;
      }
    >;
  };
};
const fixture = JSON.parse(
  readFileSync(
    resolve(process.cwd(), "../api/storage/app/private/auth-e2e-isolated.json"),
    "utf8",
  ),
) as Fixture;
const webUrl = process.env.WEB_URL ?? "http://localhost:3000";

test.describe.configure({ mode: "serial" });

async function login(
  page: Page,
  credentials: { email: string; password: string } = fixture.platform,
) {
  await page.goto(
    `${webUrl}/login?next=${encodeURIComponent("/platform/matching")}`,
  );
  await page.getByLabel("Email address").fill(credentials.email);
  await page.getByLabel("Password").fill(credentials.password);
  await Promise.all([
    page.waitForURL((url) => url.pathname !== "/login"),
    page.getByRole("button", { name: "Sign in" }).click(),
  ]);
}

test("non-platform users cannot access matching review", async ({ page }) => {
  await login(page, fixture.viewer);
  await page.goto(`${webUrl}/platform/matching`);
  await expect(
    page.getByRole("heading", { name: "Permission required" }),
  ).toBeVisible();
  await expect(page.getByTestId("platform-shell")).toHaveCount(0);
});

test("platform admin reviews the matching queue without a document reload", async ({
  page,
}) => {
  await login(page);
  await page.getByLabel("Match confidence").selectOption("B");
  await page
    .getByLabel("Supplier")
    .selectOption(fixture.platform.matching.accept.supplier_account_id);
  await page.getByRole("button", { name: "Apply filters" }).click();
  await expect(page).toHaveURL(
    (url) =>
      url.pathname === "/platform/matching" &&
      url.searchParams.get("confidence") === "B" &&
      url.searchParams.get("supplier_account_id") ===
        fixture.platform.matching.accept.supplier_account_id,
  );
  const navigation = await page.evaluate(
    () => performance.getEntriesByType("navigation").length,
  );
  const acceptCandidateLink = page.locator(
    `a[href^="/platform/matching/${fixture.platform.matching.accept.candidate_id}"]`,
  );
  await expect(acceptCandidateLink).toHaveText(
    fixture.platform.matching.accept.description,
  );
  await acceptCandidateLink.click();
  await expect(
    page.getByRole("heading", { name: "Beefsteak Tomato" }),
  ).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Evidence and differences" }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Accept" }).click();
  await expect(page).toHaveURL(
    (url) =>
      url.pathname === "/platform/matching" &&
      url.searchParams.get("confidence") === "B" &&
      url.searchParams.get("supplier_account_id") ===
        fixture.platform.matching.accept.supplier_account_id,
  );
  await expect(acceptCandidateLink).toHaveCount(0);
  await expect(page).toHaveTitle(/CProviderPrices/);
  expect(
    await page.evaluate(
      () => performance.getEntriesByType("navigation").length,
    ),
  ).toBe(navigation);

  await page.goto(
    `${webUrl}/platform/matching/${fixture.platform.matching.reject.candidate_id}`,
  );
  await page
    .getByLabel("Rejection reason")
    .fill("Supplier text is not sufficient evidence.");
  await page.getByRole("button", { name: "Reject" }).click();
  await expect(page).toHaveURL(`${webUrl}/platform/matching`);

  await page.goto(
    `${webUrl}/platform/matching/${fixture.platform.matching.choose.candidate_id}`,
  );
  await page
    .getByLabel("Choose canonical product")
    .selectOption({ label: "Roma Tomato" });
  await page.getByRole("button", { name: "Choose existing" }).click();
  await expect(page).toHaveURL(`${webUrl}/platform/matching`);

  await page.goto(
    `${webUrl}/platform/matching/${fixture.platform.matching.create.candidate_id}`,
  );
  const canonicalName = `Vine Tomato ${fixture.platform.matching.create.candidate_id.slice(-8)}`;
  await page.getByLabel("Canonical English name").fill("Tomato");
  await page.getByLabel("Spanish alias (optional)").fill("tomate en rama");
  await page.getByRole("button", { name: "Create canonical product" }).click();
  const dialog = page.getByRole("dialog", { name: "Create canonical product" });
  await expect(dialog).toBeVisible();
  await dialog.getByRole("button", { name: "Create and map" }).click();
  await expect(
    page.getByText(
      "A matching canonical product already exists in this category.",
    ),
  ).toBeVisible();

  await page.getByLabel("Canonical English name").fill(canonicalName);
  await page.getByRole("button", { name: "Create canonical product" }).click();
  await expect(dialog).toBeVisible();
  await dialog.getByRole("button", { name: "Create and map" }).click();
  await expect(page).toHaveURL(`${webUrl}/platform/matching`);
});
