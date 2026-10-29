import { expect, test, type Page } from "@playwright/test";

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
  const categories = page.getByRole("navigation", { name: "Kategorie" });

  await categories.getByRole("link", { name: "Bieganie", exact: true }).click();
  await categories.getByRole("link", { name: "Buty biegowe", exact: true }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Buty biegowe" })).toBeVisible();
  const parentNames = await allProductNames(page);

  await categories.getByRole("link", { name: "Buty trailowe", exact: true }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Buty trailowe" })).toBeVisible();
  const childNames = await allProductNames(page);

  expect(childNames.length).toBeGreaterThan(0);
  expect(parentNames.length).toBeGreaterThan(childNames.length);
  for (const name of childNames) {
    expect(parentNames).toContain(name);
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
