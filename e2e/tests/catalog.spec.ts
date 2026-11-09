import { expect, test, type Page } from "@playwright/test";
import { departmentMenu, openCategoryFromMenu, openDepartment } from "../support/menu";
import { urls } from "../support/urls";

const grid = (page: Page) => page.getByRole("main").locator("ul:has(h2)");
const productNames = (page: Page) => grid(page).getByRole("heading", { level: 2 }).allInnerTexts();
const pageIndicator = (page: Page) => page.getByText(/^Strona \d+ z \d+$/);

/** Product names of every page of the current listing, following "next page" until it disappears. */
async function allProductNames(page: Page): Promise<string[]> {
  const names: string[] = [];
  for (let current = 1; ; current++) {
    // A single-page listing has no indicator; after "next" the indicator must show the incremented page.
    if (current > 1) await expect(pageIndicator(page)).toHaveText(new RegExp(`^Strona ${current} z \\d+$`));
    names.push(...(await productNames(page)));
    const next = page.getByRole("link", { name: "Przejdź do następnej strony" });
    if ((await next.count()) === 0) return names;
    await next.click();
  }
}

test("home page shows products with loaded photos", async ({ page }) => {
  await page.goto("/");
  await expect(page.getByRole("heading", { level: 1, name: "Wszystkie produkty" })).toBeVisible();

  const tiles = grid(page).getByRole("listitem");
  await expect(tiles.first()).toBeVisible();
  expect(await tiles.count()).toBeGreaterThan(1);

  // Photos below the fold are lazy: scroll each into view before checking that it decoded.
  const images = grid(page).locator("img");
  const count = await images.count();
  expect(count).toBeGreaterThan(0);
  for (let i = 0; i < count; i++) {
    const image = images.nth(i);
    await image.scrollIntoViewIfNeeded();
    await expect.poll(() => image.evaluate((img: HTMLImageElement) => img.naturalWidth), { message: `photo ${i} did not load` }).toBeGreaterThan(0);
  }
});

test("a category shows the products of its subcategories too", async ({ page }) => {
  await page.goto("/");

  await openCategoryFromMenu(page, "Bieganie", "Buty biegowe");
  await expect(page).toHaveURL(/\/c\/bieganie\/buty$/);
  await expect(page.getByRole("heading", { level: 1, name: "Buty biegowe" })).toBeVisible();
  const parentNames = await allProductNames(page);

  await openCategoryFromMenu(page, "Bieganie", "Buty trailowe");
  await expect(page).toHaveURL(/\/c\/bieganie\/buty\/trailowe$/);
  await expect(page.getByRole("heading", { level: 1, name: "Buty trailowe" })).toBeVisible();
  const childNames = await allProductNames(page);

  expect(childNames.length).toBeGreaterThan(0);
  expect(parentNames.length).toBeGreaterThan(childNames.length);
  for (const name of childNames) {
    expect(parentNames).toContain(name);
  }
});

test("a category page has the path, the number of products, its own canonical address and structured data", async ({ page }) => {
  await page.goto("/c/bieganie/buty/trailowe");

  const path = page.getByRole("navigation", { name: "Ścieżka" });
  await expect(path.getByRole("link")).toHaveText(["Wszystkie produkty", "Bieganie", "Buty biegowe"]);
  await expect(path.getByText("Buty trailowe", { exact: true })).toHaveAttribute("aria-current", "page");
  await expect(page.getByRole("heading", { level: 1 })).toHaveText("Buty trailowe");
  await expect(page.getByRole("main").getByText(/^\d+ (produkt|produkty|produktów)$/)).toBeVisible();
  await expect(page).toHaveTitle("Buty trailowe | Worf");
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", /\/c\/bieganie\/buty\/trailowe$/);

  const scripts = await page.locator('script[type="application/ld+json"]').allTextContents();
  const breadcrumb = scripts.map((text) => JSON.parse(text)).find((entry) => entry["@type"] === "BreadcrumbList");
  expect(breadcrumb.itemListElement.map((item: { name: string }) => item.name)).toEqual(["Wszystkie produkty", "Bieganie", "Buty biegowe", "Buty trailowe"]);
  expect(breadcrumb.itemListElement.at(-1).item).toBe(`${urls.store}/c/bieganie/buty/trailowe`);

  await path.getByRole("link", { name: "Bieganie", exact: true }).click();
  await expect(page).toHaveURL(/\/c\/bieganie$/);
});

test("the pages of a category are paginated under its address and the canonical address follows the page", async ({ page }) => {
  await page.goto("/c/bieganie");
  await page.getByRole("link", { name: "Przejdź do następnej strony" }).click();
  await expect(page).toHaveURL(/\/c\/bieganie\?page=2$/);
  await expect(pageIndicator(page)).toHaveText(/^Strona 2 z \d+$/);
  await expect(page).toHaveTitle("Bieganie – strona 2 | Worf");
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", /\/c\/bieganie\?page=2$/);
});

test("an unknown category path is a 404 that search engines do not index", async ({ page }) => {
  const response = await page.goto("/c/bieganie/nie-ma-takiej");
  expect(response?.status()).toBe(404);
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", /noindex/);
});

test("the old address /?category=<id> redirects permanently to the category page", async ({ request }) => {
  const tree = (await (await request.get(`${urls.api}/api/v1/categories`)).json()) as { id: string; path: string; children: { id: string; path: string }[] }[];
  const bieganie = tree.find((category) => category.path === "bieganie");
  expect(bieganie).toBeDefined();
  const response = await request.get(`/?category=${bieganie!.id}&page=2`, { maxRedirects: 0 });
  expect(response.status()).toBe(308);
  const location = new URL(response.headers().location ?? "", urls.store);
  expect(location.pathname + location.search).toBe("/c/bieganie?page=2");
});

test("the menu opens with the mouse and the keyboard and closes with Escape", async ({ page }) => {
  await page.goto("/");
  const bieganie = departmentMenu(page).getByRole("button", { name: "Bieganie", exact: true });
  await expect(bieganie).toHaveAttribute("aria-expanded", "false");

  const panel = await openDepartment(page, "Bieganie");
  await expect(panel.getByRole("link", { name: "Wszystko z działu" })).toHaveAttribute("href", "/c/bieganie");
  await expect(panel.getByRole("link", { name: "Buty szosowe", exact: true })).toBeVisible();
  await page.keyboard.press("Escape");
  await expect(bieganie).toHaveAttribute("aria-expanded", "false");
  await expect(bieganie).toBeFocused();

  await page.keyboard.press("Enter");
  await expect(bieganie).toHaveAttribute("aria-expanded", "true");
  await page.keyboard.press("Escape");
  await expect(bieganie).toHaveAttribute("aria-expanded", "false");

  await bieganie.click();
  // The panel spans the whole width under the header, so the click outside the menu is lower on the page.
  await page.getByRole("main").click({ position: { x: 1, y: 500 } });
  await expect(bieganie).toHaveAttribute("aria-expanded", "false");
});

test("the HTML has the links of every department and of levels 2 and 3 while the menu is closed", async ({ request }) => {
  const html = await (await request.get("/")).text();
  for (const href of ["/c/bieganie", "/c/bieganie/buty", "/c/bieganie/buty/szosowe", "/c/rower/rowery", "/c/turystyka/biwak/spanie", "/c/sporty-wodne/kajaki"]) {
    expect(html, href).toContain(`href="${href}"`);
  }
});

test("pagination moves between pages and keeps the listing consistent", async ({ page }) => {
  await page.goto("/");
  const firstPage = await productNames(page);
  await expect(pageIndicator(page)).toHaveText(/^Strona 1 z \d+$/);
  await expect(page.getByRole("link", { name: "Przejdź do poprzedniej strony" })).toHaveCount(0);

  await page.getByRole("link", { name: "Przejdź do następnej strony" }).click();
  await expect(page).toHaveURL(/page=2/);
  await expect(pageIndicator(page)).toHaveText(/^Strona 2 z \d+$/);
  const secondPage = await productNames(page);
  expect(secondPage.length).toBeGreaterThan(0);
  expect(secondPage).not.toEqual(firstPage);
  expect(secondPage.filter((name) => firstPage.includes(name))).toEqual([]);

  await page.getByRole("link", { name: "Przejdź do poprzedniej strony" }).click();
  await expect(pageIndicator(page)).toHaveText(/^Strona 1 z \d+$/);
  expect(await productNames(page)).toEqual(firstPage);
});
