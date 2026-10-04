import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test, type Page } from "@playwright/test";

type Credentials = { email: string; password: string; slug: string };
type Fixture = {
  workspace_login: Credentials & { name: string; team_member_name: string };
  workspace_viewer: Credentials;
};

const fixture = JSON.parse(
  readFileSync(
    resolve(process.cwd(), "../api/storage/app/private/auth-e2e-isolated.json"),
    "utf8",
  ),
) as Fixture;
const webUrl = process.env.WEB_URL ?? "http://localhost:3000";
test.describe.configure({ mode: "serial" });

async function login(page: Page, credentials: Credentials) {
  await page.goto(`${webUrl}/login`);
  await page.getByLabel("Email address").fill(credentials.email);
  await page.getByLabel("Password").fill(credentials.password);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).toHaveURL(`${webUrl}/o/${credentials.slug}/dashboard`);
}

test("owner manages team while last-owner and high-risk protections remain visible", async ({
  page,
}) => {
  await login(page, fixture.workspace_login);
  await page.getByRole("link", { name: "Team" }).click();

  const invitedEmail = `viewer-${Date.now()}@example.test`;
  await page.getByLabel("Email").fill(invitedEmail);
  await page
    .locator("form")
    .getByRole("combobox", { name: "Role" })
    .selectOption("viewer");
  await page.getByRole("button", { name: "Send invitation" }).click();
  await expect(page.getByText(invitedEmail)).toBeVisible();

  const ownerEmail = `owner-${Date.now()}@example.test`;
  await page.getByLabel("Email").fill(ownerEmail);
  await page
    .locator("form")
    .getByRole("combobox", { name: "Role" })
    .selectOption("organization_owner");
  page.once("dialog", async (dialog) => {
    expect(dialog.type()).toBe("confirm");
    expect(dialog.message()).toContain("Invite this person as an owner");
    await dialog.accept();
  });
  await page.getByRole("button", { name: "Send invitation" }).click();
  await expect(page.getByText(ownerEmail)).toBeVisible();

  const memberRow = page
    .getByText(fixture.workspace_login.team_member_name, { exact: true })
    .locator("..")
    .locator("..");
  await memberRow
    .getByRole("combobox", {
      name: `Role for ${fixture.workspace_login.team_member_name}`,
    })
    .selectOption("buyer");
  await expect(memberRow.getByText("buyer", { exact: true })).toBeVisible();
  page.once("dialog", (dialog) => dialog.accept());
  await memberRow.getByRole("button", { name: "Revoke" }).click();
  await expect(
    page.getByText(fixture.workspace_login.team_member_name, { exact: true }),
  ).toHaveCount(0);

  const ownerRow = page
    .getByText(fixture.workspace_login.name, { exact: true })
    .locator("..")
    .locator("..");
  await ownerRow
    .getByRole("combobox", { name: `Role for ${fixture.workspace_login.name}` })
    .selectOption("viewer");
  await expect(
    page.getByText("The organization must retain at least one active owner."),
  ).toBeVisible();
  page.once("dialog", (dialog) => dialog.accept());
  await ownerRow.getByRole("button", { name: "Revoke" }).click();
  await expect(
    page.getByText("The organization must retain at least one active owner."),
  ).toBeVisible();
});

test("owner manages locations without document reload and sees the server limit", async ({
  page,
}) => {
  await login(page, fixture.workspace_login);
  await page.getByRole("link", { name: "Locations" }).click();
  await page.evaluate(
    () => ((window as Window & { __prompt10?: string }).__prompt10 = "kept"),
  );

  await page.getByLabel("Name").fill("E2E Downtown");
  await page.getByRole("button", { name: "Create location" }).click();
  await expect(
    page.locator("main").getByText("E2E Downtown", { exact: true }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => (window as Window & { __prompt10?: string }).__prompt10,
    ),
  ).toBe("kept");

  page.once("dialog", (dialog) => dialog.accept("E2E Downtown Edited"));
  await page.getByRole("button", { name: "Edit" }).click();
  await expect(
    page.locator("main").getByText("E2E Downtown Edited", { exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Select" }).click();
  expect(
    await page.evaluate(
      () => (window as Window & { __prompt10?: string }).__prompt10,
    ),
  ).toBe("kept");
  await page.getByRole("button", { name: "Deactivate" }).click();
  await expect(
    page.getByRole("button", { name: "Activate", exact: true }),
  ).toBeVisible();
  await page.getByRole("button", { name: "Activate", exact: true }).click();

  await page.getByLabel("Name").fill("E2E Uptown");
  await page.getByRole("button", { name: "Create location" }).click();
  const uptown = page.locator("main").getByText("E2E Uptown", { exact: true });
  const locationLimit = page.getByText("Location limit reached");
  await expect(uptown.or(locationLimit)).toBeVisible();
  if (await locationLimit.isVisible()) return;
  await page.getByLabel("Name").fill("E2E Over Limit");
  await page.getByRole("button", { name: "Create location" }).click();
  await expect(locationLimit).toBeVisible();
});

test("owner manages location-scoped supplier metadata and sees the server limit", async ({
  page,
}, testInfo) => {
  testInfo.setTimeout(45_000);
  await login(page, fixture.workspace_login);
  await page.getByRole("link", { name: "Suppliers" }).click();
  await page.evaluate(
    () => ((window as Window & { __prompt10?: string }).__prompt10 = "kept"),
  );
  await page
    .getByLabel("Location")
    .selectOption({ label: "E2E Downtown Edited" });
  await page.getByLabel("Supplier").selectOption({ index: 1 });
  await page.getByLabel("Display name").fill("E2E Primary Supplier");
  await page.getByLabel("Masked account reference").fill("ending 4321");
  await page.getByRole("button", { name: "Add supplier account" }).click();
  await expect(
    page.getByText("E2E Primary Supplier", { exact: true }),
  ).toBeVisible();
  await expect(
    page.locator("main p").filter({ hasText: "E2E Downtown Edited" }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () => (window as Window & { __prompt10?: string }).__prompt10,
    ),
  ).toBe("kept");

  page.once("dialog", (dialog) => dialog.accept("E2E Edited Supplier"));
  await page.getByRole("button", { name: "Edit" }).click();
  await expect(
    page.getByText("E2E Edited Supplier", { exact: true }),
  ).toBeVisible({ timeout: 15_000 });
  expect(
    await page.evaluate(
      () => (window as Window & { __prompt10?: string }).__prompt10,
    ),
  ).toBe("kept");

  await page.getByLabel("Supplier").selectOption({ index: 2 });
  await page.getByLabel("Display name").fill("E2E Over Limit Supplier");
  await page.getByRole("button", { name: "Add supplier account" }).click();
  await expect(page.getByText("Supplier account limit reached")).toBeVisible();
  await expect(
    page.locator(
      'input[type="password"], input[name*="password"], input[name*="credential"], input[name*="secret"]',
    ),
  ).toHaveCount(0);
});

test("viewer configuration pages are read-only and route tampering is denied", async ({
  page,
}) => {
  await login(page, fixture.workspace_viewer);
  for (const route of ["team", "locations", "suppliers"]) {
    await page.goto(`${webUrl}/o/${fixture.workspace_viewer.slug}/${route}`);
    await expect(page.getByText("Read-only access")).toBeVisible();
  }
  await expect(
    page.getByRole("button", {
      name: /invite|create|add|edit|revoke|deactivate/i,
    }),
  ).toHaveCount(0);
  await page.goto(`${webUrl}/o/${fixture.workspace_login.slug}/team`);
  await expect(page.getByText(/not authorized|could not load/i)).toBeVisible();
});
