import { expect, test, type Locator, type Page } from "@playwright/test";

const WIDTH = 375;

test.use({ viewport: { width: WIDTH, height: 812 } });

async function expectInsideScreen(locator: Locator): Promise<void> {
  await expect(locator).toBeVisible();
  const box = await locator.boundingBox();
  expect(box).not.toBeNull();
  expect(box!.x).toBeGreaterThanOrEqual(0);
  expect(box!.x + box!.width).toBeLessThanOrEqual(WIDTH);
}

async function expectHeaderFits(page: Page): Promise<void> {
  const header = page.getByRole("banner");
  await expectInsideScreen(header.getByRole("link", { name: "Rytm" }));
  await expectInsideScreen(header.getByRole("searchbox", { name: "Szukaj produktów" }));
  await expectInsideScreen(header.getByRole("button", { name: "Szukaj" }));
  await expectInsideScreen(header.getByRole("link", { name: /Koszyk/ }));
  await expectInsideScreen(header.getByRole("link", { name: "Zaloguj się" }));
  // The page does not scroll sideways.
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(WIDTH);
}

test("the header fits a 375 px screen: search, cart and account, no horizontal scroll", async ({ page }) => {
  await page.goto("/");
  await expectHeaderFits(page);
});

test("the header fits a 375 px screen on the search page", async ({ page }) => {
  await page.goto("/szukaj?q=asfalt");
  await expectHeaderFits(page);
});
