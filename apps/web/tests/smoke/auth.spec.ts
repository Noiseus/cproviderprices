import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test, type Page } from "@playwright/test";

type Credentials = { email: string; password: string; slug: string };
type Fixture = {
  login: Credentials & { switch_slug: string; switch_id: string };
  viewer: Credentials;
  suspended: Credentials & { name: string };
  invitation: { token: string; password: string; slug: string };
  expired_token: string;
};

const fixturePath = resolve(
  process.cwd(),
  "../api/storage/app/private/auth-e2e-isolated.json",
);
const fixture = JSON.parse(readFileSync(fixturePath, "utf8")) as Fixture;
const webUrl = process.env.WEB_URL ?? "http://localhost:3000";

// These flows intentionally exercise shared login and invitation rate limits.
// Keep them serial so parallel workers do not turn valid fixture requests into
// unrelated 429 responses.
test.describe.configure({ mode: "serial" });

async function login(page: Page, credentials: Credentials) {
  await page.goto(`${webUrl}/login`);
  await page.getByLabel("Email address").fill(credentials.email);
  await page.getByLabel("Password").fill(credentials.password);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).toHaveURL(`${webUrl}/o/${credentials.slug}/dashboard`);
}

test("invitation acceptance reaches its organization shell", async ({
  page,
}) => {
  await page.goto(
    `${webUrl}/accept-invitation?token=${encodeURIComponent(fixture.invitation.token)}`,
  );
  await page.getByLabel("Your name").fill("Invited E2E Owner");
  await page
    .getByLabel("Password", { exact: true })
    .fill(fixture.invitation.password);
  await page.getByLabel("Confirm password").fill(fixture.invitation.password);
  const acceptance = page.waitForResponse(
    (response) =>
      response.url().endsWith("/api/v1/auth/invitations/accept") &&
      response.request().method() === "POST",
  );
  await page.getByRole("button", { name: "Accept invitation" }).click();
  const acceptanceResponse = await acceptance;
  expect(acceptanceResponse.status()).toBe(200);
  await expect(acceptanceResponse.json()).resolves.toMatchObject({
    destination: `/o/${fixture.invitation.slug}/dashboard`,
  });
  await expect(page).toHaveURL(
    `${webUrl}/o/${fixture.invitation.slug}/dashboard`,
  );
  await expect(
    page.getByRole("heading", { name: "Dashboard", exact: true }),
  ).toBeVisible();
  await expect(page.getByText("Invited E2E Owner")).toBeVisible();
});

test("owner shell uses client navigation, active state, compact mode, and organization switching", async ({
  page,
}) => {
  await login(page, fixture.login);
  const sidebar = page.getByRole("complementary");
  await expect(
    sidebar.getByText("Organization", { exact: true }),
  ).toBeVisible();
  await expect(sidebar.getByRole("link", { name: "Team" })).toBeVisible();
  await expect(sidebar.getByRole("link", { name: "Settings" })).toBeVisible();
  await expect(
    sidebar.getByRole("link", { name: "Dashboard" }),
  ).toHaveAttribute("aria-current", "page");

  await page.evaluate(() => {
    (window as Window & { __shellMarker?: string }).__shellMarker = "preserved";
  });
  await sidebar.getByRole("link", { name: "Search" }).click();
  await expect(page).toHaveURL(`${webUrl}/o/${fixture.login.slug}/search`);
  expect(
    await page.evaluate(
      () => (window as Window & { __shellMarker?: string }).__shellMarker,
    ),
  ).toBe("preserved");
  await expect(sidebar.getByRole("link", { name: "Search" })).toHaveAttribute(
    "aria-current",
    "page",
  );

  await sidebar.getByRole("button", { name: "Collapse sidebar" }).focus();
  await page.keyboard.press("Enter");
  await expect(page.getByTestId("app-shell")).toHaveClass(/is-compact/);

  await page.locator(".ui-dropdown > summary").click();
  await page
    .getByRole("combobox", { name: "Organization" })
    .selectOption(fixture.login.switch_id);
  await expect(page).toHaveURL(
    `${webUrl}/o/${fixture.login.switch_slug}/dashboard`,
  );
});

test("viewer gets reduced and entitlement-aware navigation", async ({
  page,
}) => {
  await login(page, fixture.viewer);
  await expect(page.getByRole("link", { name: "Team" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Locations" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Suppliers" })).toBeVisible();
  await expect(page.getByRole("link", { name: "Settings" })).toBeVisible();
  await expect(
    page
      .getByRole("navigation", { name: "Workspace" })
      .getByText("Price history · unavailable"),
  ).toBeVisible();
  await expect(page.getByRole("link", { name: "Price history" })).toHaveCount(
    0,
  );

  await page.getByRole("link", { name: "Team" }).click();
  await expect(page.getByText("Read-only access")).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Send invitation" }),
  ).toHaveCount(0);
});

test("owner workspace configuration uses client navigation", async ({
  page,
}) => {
  await login(page, fixture.login);
  await page.evaluate(() => {
    (
      window as Window & { __configurationMarker?: string }
    ).__configurationMarker = "preserved";
  });
  await page.getByRole("link", { name: "Team" }).click();
  await expect(
    page.getByRole("heading", { level: 1, name: "Team" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Send invitation" }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () =>
        (window as Window & { __configurationMarker?: string })
          .__configurationMarker,
    ),
  ).toBe("preserved");
  const locationsLoaded = page.waitForResponse(
    (response) =>
      response.url().endsWith("/restaurant-locations") &&
      response.request().method() === "GET",
  );
  await page.getByRole("link", { name: "Locations" }).click();
  expect((await locationsLoaded).status()).toBe(200);
  await expect(page).toHaveURL(`${webUrl}/o/${fixture.login.slug}/locations`);
  await expect(
    page.getByRole("heading", { level: 1, name: "Locations" }),
  ).toBeVisible();
  await expect(
    page.getByRole("button", { name: "Create location" }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Suppliers" }).click();
  await expect(
    page.getByRole("heading", { level: 1, name: "Suppliers" }),
  ).toBeVisible();
});

test("mobile shell uses an accessible navigation drawer", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await login(page, fixture.login);
  await expect(
    page.getByRole("navigation", { name: "Workspace" }),
  ).not.toBeVisible();
  await page.getByRole("button", { name: "Open navigation" }).click();
  await expect(
    page.getByRole("dialog", { name: "Workspace navigation" }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Lists" }).click();
  await expect(page).toHaveURL(`${webUrl}/o/${fixture.login.slug}/lists`);
  await expect(
    page.getByRole("dialog", { name: "Workspace navigation" }),
  ).not.toBeVisible();
});

test("suspended organization receives only account status", async ({
  page,
}) => {
  await login(page, fixture.suspended);
  await expect(
    page.getByRole("heading", {
      name: `${fixture.suspended.name} is suspended`,
    }),
  ).toBeVisible();
  await expect(page.getByRole("navigation", { name: "Workspace" })).toHaveCount(
    0,
  );
  await expect(
    page.getByText("Normal private workspace access is unavailable.", {
      exact: false,
    }),
  ).toBeVisible();
});

test("subscription status reuses the authenticated shell", async ({ page }) => {
  await login(page, fixture.login);
  await page.getByRole("link", { name: "Subscription" }).click();
  await expect(page.getByRole("heading", { name: "Plan" })).toBeVisible();
  await expect(page.getByText("E2E Plan")).toBeVisible();
  await expect(
    page.getByText("Online checkout is not available."),
  ).toBeVisible();
});

test("expired invitation has a clear state", async ({ page }) => {
  await page.goto(
    `${webUrl}/accept-invitation?token=${encodeURIComponent(fixture.expired_token)}`,
  );
  await expect(page.getByText(/invitation has expired/i)).toBeVisible();
});

test("unauthenticated private route preserves a safe destination", async ({
  page,
}) => {
  await page.goto(`${webUrl}/o/not-authorized/dashboard`);
  await expect(page).toHaveURL(
    /\/login\?next=%2Fo%2Fnot-authorized%2Fdashboard$/,
  );
});
