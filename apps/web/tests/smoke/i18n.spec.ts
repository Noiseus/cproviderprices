import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { expect, test, type Page } from "@playwright/test";

type Fixture = {
  search_login: { email: string; password: string; slug: string };
  search_comparison: { canonical_product_id: string };
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

test.afterEach(async ({ page }) => {
  const language = page.getByRole("combobox", { name: /^(Language|Idioma)$/ });
  if (await language.count()) await language.selectOption("en");
});

async function login(page: Page) {
  await page.goto(`${web}/login`);
  await page.getByLabel("Email address").fill(fixture.search_login.email);
  await page
    .getByLabel("Password", { exact: true })
    .fill(fixture.search_login.password);
  await page.getByRole("button", { name: "Sign in" }).click();
  await expect(page).toHaveURL(
    `${web}/o/${fixture.search_login.slug}/dashboard`,
  );
}

test("an authenticated buyer can use Spanish without losing route, filters, context, or client navigation", async ({
  page,
}) => {
  await login(page);
  await page.getByRole("link", { name: "Search", exact: true }).click();
  const searchInput = page.getByLabel("Search products");
  await searchInput.fill("tomate");
  await Promise.all([
    page.waitForURL(
      (url) =>
        url.pathname.endsWith("/search") &&
        url.searchParams.get("q") === "tomate" &&
        url.searchParams.get("sort") === "lowest_case_price",
    ),
    page
      .getByRole("combobox", { name: "Sort" })
      .selectOption("lowest_case_price"),
  ]);
  await expect(
    page.getByRole("heading", { name: "Tomato", exact: true }),
  ).toBeVisible();
  await page.evaluate(() => {
    (window as Window & { i18nMarker?: string }).i18nMarker = "same-document";
  });
  const navigationEntries = await page.evaluate(
    () => performance.getEntriesByType("navigation").length,
  );
  const pathBeforeSwitch = new URL(page.url()).pathname;

  const preferenceUpdate = page.waitForResponse(
    (response) =>
      response.url().endsWith("/api/v1/auth/preferences") &&
      response.request().method() === "PATCH",
  );
  await page.getByRole("combobox", { name: "Language" }).selectOption("es");
  const preferenceResponse = await preferenceUpdate;
  expect(preferenceResponse.status()).toBe(200);
  await expect(preferenceResponse.json()).resolves.toMatchObject({
    session: { user: { preferred_language: "es" } },
  });
  await expect(
    page.getByRole("link", { name: "Buscar", exact: true }),
  ).toBeVisible();
  await expect(page.getByLabel("Buscar productos")).toHaveValue("tomate");
  expect(new URL(page.url()).pathname).toBe(pathBeforeSwitch);
  expect(new URL(page.url()).searchParams.get("sort")).toBe(
    "lowest_case_price",
  );
  expect(await page.evaluate(() => document.documentElement.lang)).toBe("es");
  expect(
    await page.evaluate(
      () => (window as Window & { i18nMarker?: string }).i18nMarker,
    ),
  ).toBe("same-document");
  expect(
    await page.evaluate(
      () => performance.getEntriesByType("navigation").length,
    ),
  ).toBe(navigationEntries);

  for (const [query, canonicalName] of [
    ["tomate", "Tomato"],
    ["pechuga de pollo", "Chicken Breast"],
    ["aceite de canola", "Canola Oil"],
    ["queso mozzarella", "Mozzarella Cheese"],
  ]) {
    await page.getByLabel("Buscar productos").fill(query);
    await expect(
      page.getByRole("heading", { name: canonicalName, exact: true }),
    ).toBeVisible();
  }

  await page.getByLabel("Buscar productos").fill("tomate beefsteak");
  const beefsteakCard = page.locator(".search-result-card").filter({
    has: page.getByRole("heading", {
      name: "Beefsteak Tomato",
      exact: true,
    }),
  });
  await expect(beefsteakCard).toBeVisible();
  await beefsteakCard.getByRole("link", { name: "Comparar ofertas" }).click();
  await expect(
    page.getByText("Mejor precio normalizado", { exact: true }),
  ).toBeVisible();

  await page.goto(
    `${web}/o/${fixture.search_login.slug}/lists/${fixture.saved_lists.unavailable_selection_id}`,
  );
  await expect(
    page.getByText("Mejor opción normalizada", { exact: true }),
  ).toBeVisible();

  await page.getByRole("link", { name: "Equipo", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Equipo", exact: true }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Ubicaciones", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Ubicaciones", level: 1 }),
  ).toBeVisible();
  await page.getByRole("link", { name: "Suscripción", exact: true }).click();
  await expect(
    page.getByRole("heading", { name: "Plan", exact: true }),
  ).toBeVisible();
  const importsLoaded = page.waitForResponse(
    (response) =>
      response.url().includes("/imports?page=1") &&
      response.request().method() === "GET",
  );
  await page.getByRole("link", { name: "Importaciones", exact: true }).click();
  expect((await importsLoaded).status()).toBe(200);
  await expect(page).toHaveURL(`${web}/o/${fixture.search_login.slug}/imports`);
  await expect(
    page.getByRole("heading", { name: "Centro de importaciones", exact: true }),
  ).toBeVisible();

  await Promise.all([
    page.waitForResponse(
      (response) =>
        response.url().includes("/price-history/") && response.status() === 200,
    ),
    page.goto(
      `${web}/o/${fixture.search_login.slug}/price-history?product=${fixture.search_comparison.canonical_product_id}`,
    ),
  ]);
  await expect(
    page.getByRole("heading", { name: "Gráfico de precios accesible" }),
  ).toBeVisible();

  await page.setViewportSize({ width: 390, height: 844 });
  await page.getByRole("button", { name: "Abrir navegación" }).click();
  await expect(
    page.getByRole("dialog", { name: "Navegación del espacio de trabajo" }),
  ).toBeVisible();
  expect(
    await page.evaluate(
      () =>
        document.documentElement.scrollWidth <=
        document.documentElement.clientWidth,
    ),
  ).toBe(true);
  await page.keyboard.press("Escape");
  await expect(
    page.getByRole("dialog", { name: "Navegación del espacio de trabajo" }),
  ).not.toBeVisible();
  await page.setViewportSize({ width: 768, height: 1024 });
  expect(
    await page.evaluate(
      () =>
        document.documentElement.scrollWidth <=
        document.documentElement.clientWidth,
    ),
  ).toBe(true);

  await page.getByRole("combobox", { name: "Idioma" }).selectOption("en");
  await expect(page.getByRole("combobox", { name: "Language" })).toHaveValue(
    "en",
  );
  expect(await page.evaluate(() => document.documentElement.lang)).toBe("en");
});
