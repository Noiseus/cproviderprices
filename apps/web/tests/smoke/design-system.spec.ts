import { expect, test } from "@playwright/test";

const webUrl = process.env.WEB_URL ?? "http://localhost:3000";

for (const viewport of [
  { name: "desktop", width: 1280, height: 900 },
  { name: "tablet", width: 768, height: 900 },
  { name: "mobile", width: 390, height: 844 },
]) {
  test(`design system has no page overflow at ${viewport.name} width`, async ({
    page,
  }) => {
    await page.setViewportSize({
      width: viewport.width,
      height: viewport.height,
    });
    await page.goto(`${webUrl}/design-system`);
    await expect(
      page.getByRole("heading", { name: "CProviderPrices design system" }),
    ).toBeVisible();
    expect(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= window.innerWidth,
      ),
    ).toBe(true);
  });
}

test("explicit dark theme is applied during initial document rendering", async ({
  page,
}) => {
  await page.addInitScript(() =>
    localStorage.setItem("cproviderprices-theme", "dark"),
  );
  await page.goto(`${webUrl}/design-system`);
  expect(await page.locator("html").getAttribute("data-theme")).toBe("dark");
  await expect(page.getByRole("combobox", { name: "Theme" })).toHaveValue(
    "dark",
  );
});
