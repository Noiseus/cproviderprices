import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test, type Page } from "@playwright/test";

type Fixture = {
  viewer: { email: string; password: string; slug: string };
  platform: {
    email: string;
    password: string;
    organization_id: string;
    organization_name: string;
  };
};
const fixture = JSON.parse(
  readFileSync(
    resolve(process.cwd(), "../api/storage/app/private/auth-e2e.json"),
    "utf8",
  ),
) as Fixture;
const webUrl = process.env.WEB_URL ?? "http://localhost:3000";

test.describe.configure({ mode: "serial" });

async function loginFor(
  page: Page,
  email: string,
  password: string,
  next = "/platform",
) {
  await page.goto(`${webUrl}/login?next=${encodeURIComponent(next)}`);
  await page.getByLabel("Email address").fill(email);
  await page.getByLabel("Password").fill(password);
  await Promise.all([
    page.waitForURL((url) => url.pathname !== "/login"),
    page.getByRole("button", { name: "Sign in" }).click(),
  ]);
}

test("non-platform user is rejected from the direct platform route", async ({
  page,
}) => {
  await loginFor(page, fixture.viewer.email, fixture.viewer.password);
  await page.goto(`${webUrl}/platform`);
  await expect(
    page.getByRole("heading", { name: "Permission required" }),
  ).toBeVisible();
  await expect(page.getByTestId("platform-shell")).toHaveCount(0);
});

test("platform overview, URL filters, pagination, detail, and return context work", async ({
  page,
}) => {
  await loginFor(page, fixture.platform.email, fixture.platform.password);
  await expect(page).toHaveURL(`${webUrl}/platform`);
  await expect(page.getByRole("heading", { name: "Overview" })).toBeVisible();
  await page.getByRole("link", { name: "Organizations" }).first().click();
  await page.getByLabel("Search").fill("Platform E2E");
  await page.getByLabel("Trial/subscription").selectOption("trialing");
  await page.getByRole("button", { name: "Apply filters" }).click();
  await expect(page).toHaveURL(
    /search=Platform(?:\+|%20)E2E.*subscription_status=trialing/,
  );
  await page
    .locator(
      `a[href^="/platform/organizations/${fixture.platform.organization_id}"]`,
    )
    .click();
  await expect(
    page.getByRole("heading", { name: fixture.platform.organization_name }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Back to organizations" }).click();
  await expect(page).toHaveURL(
    /search=Platform(?:\+|%20)E2E.*subscription_status=trialing/,
  );
  await page.goto(`${webUrl}/platform/organizations?per_page=10`);
  await expect(
    page.getByRole("navigation", { name: "Pagination" }),
  ).toBeVisible();
});

test("platform admin creates an organization and receives server validation errors", async ({
  page,
}) => {
  const suffix = fixture.platform.organization_id.slice(-8);
  const organizationName = `Prompt 09 E2E Organization ${suffix}`;
  const organizationSlug = `prompt-09-e2e-${suffix}`;
  await loginFor(page, fixture.platform.email, fixture.platform.password);
  await page.goto(`${webUrl}/platform/organizations/new`);
  await page.getByLabel("Organization name").fill(organizationName);
  await page
    .getByLabel("Organization slug", { exact: false })
    .fill(organizationSlug);
  await page.getByRole("button", { name: "Create organization" }).click();
  await expect(
    page.getByRole("heading", { name: organizationName }),
  ).toBeVisible();
  await page.goto(`${webUrl}/platform/organizations/new`);
  await page.getByLabel("Organization name").fill("Duplicate slug");
  await page
    .getByLabel("Organization slug", { exact: false })
    .fill(organizationSlug);
  await page.getByRole("button", { name: "Create organization" }).click();
  await expect(page.getByText("Creation failed")).toBeVisible();
});

test("trial extension, suspension confirmation, invite, and resend wait for the server", async ({
  page,
}, testInfo) => {
  testInfo.setTimeout(45_000);
  await loginFor(page, fixture.platform.email, fixture.platform.password);
  await page.goto(
    `${webUrl}/platform/organizations/${fixture.platform.organization_id}`,
  );
  const future = new Date(Date.now() + 21 * 24 * 60 * 60 * 1000)
    .toISOString()
    .slice(0, 16);
  await page.locator('select[name="action"]').selectOption("extend_trial");
  await page.getByLabel("Trial end (UTC)").fill(future);
  await page.locator('input[name="reason"]').nth(1).fill("E2E trial extension");
  await page.getByRole("button", { name: "Apply subscription action" }).click();
  await expect(
    page.getByText(/Subscription extend trial completed/),
  ).toBeVisible();

  await page.locator('select[name="action"]').selectOption("suspend");
  await page
    .locator('input[name="reason"]')
    .nth(1)
    .fill("E2E lifecycle verification");
  await page.getByRole("button", { name: "Apply subscription action" }).click();
  const dialog = page.getByRole("dialog", { name: "Confirm high-risk action" });
  await expect(dialog).toBeVisible();
  await dialog.getByRole("button", { name: "Confirm action" }).click();
  await expect(page.getByText(/Subscription suspend completed/)).toBeVisible();

  await page.getByLabel("Owner email").fill("platform-e2e-owner@example.test");
  await page.getByRole("button", { name: "Invite initial owner" }).click();
  await expect(page.getByText("Initial-owner invitation sent.")).toBeVisible();
  await page.getByRole("button", { name: "Resend" }).first().click();
  await expect(page.getByText("Invitation replaced and resent.")).toBeVisible({
    timeout: 15_000,
  });
});
