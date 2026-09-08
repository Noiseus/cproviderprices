import { readFileSync, rmSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test } from "@playwright/test";

type Fixture = {
  login: { email: string; password: string; slug: string };
  invitation: { token: string; password: string; slug: string };
  expired_token: string;
};

const fixturePath = resolve(
  process.cwd(),
  "../api/storage/app/private/auth-e2e.json",
);
const fixture = JSON.parse(readFileSync(fixturePath, "utf8")) as Fixture;
const webUrl = process.env.WEB_URL ?? "http://localhost:3000";

test.afterAll(() => rmSync(fixturePath, { force: true }));

test("invitation acceptance reaches only its organization", async ({
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
  await page.getByRole("button", { name: "Accept invitation" }).click();
  await expect(page).toHaveURL(
    `${webUrl}/o/${fixture.invitation.slug}/dashboard`,
  );
  await expect(
    page.getByText("Protected organization workspace"),
  ).toBeVisible();
});

test("login and logout", async ({ page }) => {
  await page.goto(`${webUrl}/login`);
  await page.getByLabel("Email address").fill(fixture.login.email);
  await page.getByLabel("Password").fill(fixture.login.password);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).toHaveURL(`${webUrl}/o/${fixture.login.slug}/dashboard`);
  await page.getByRole("button", { name: "Sign out" }).click();
  await expect(page).toHaveURL(`${webUrl}/login`);
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
