import { expect, type Page, test } from "@playwright/test";
import { addVariantOf, tileNamed } from "../support/shop";

// A demo product of the category "Fitness" > "Trening" (apps/api/fixtures/catalog.yaml, prices.yaml: 89,90 zł), cheaper
// than the threshold of free delivery of every delivery method of the demo (apps/api/fixtures/delivery-methods.yaml).
// The tests do not count the methods: they only rely on a method with a threshold above this price, and on the cheapest
// cost being above 0 below the smallest threshold.
const PRODUCT_NAME = "Mata do ćwiczeń Lumora Podłoże 6";

// An amount in grosze from the text of a price ("1 234,50 zł" -> 123450).
const groszeOf = (text: string) => Number(text.replace(/\D/g, ""));

async function cartWithOneMat(page: Page): Promise<void> {
  await page.goto("/c/fitness/trening");
  await expect(page.getByRole("heading", { level: 1, name: "Trening" })).toBeVisible();
  const tile = tileNamed(page, PRODUCT_NAME);
  await expect(tile).toHaveCount(1);
  await addVariantOf(page, tile, 1);
  await page.getByRole("link", { name: /^Koszyk/ }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Koszyk" })).toBeVisible();
  await expect(page.getByTestId("cart-total")).toBeVisible();
}

test("the cart shows the cheapest delivery and what is missing to free delivery below the threshold, free delivery above it", async ({ page }) => {
  await cartWithOneMat(page);

  // The live region is in the page before the rows change, so that a screen reader announces the change.
  const region = page.getByTestId("cart-delivery");
  await expect(region).toHaveAttribute("aria-live", "polite");
  const from = page.getByTestId("cart-delivery-from");
  const missing = page.getByTestId("cart-delivery-missing");
  await expect(from).toHaveText(/^Dostawa od \d/);
  await expect(missing).toHaveText(/^Do darmowej dostawy brakuje \d/);
  await expect(region).toContainText("Dostawa od");

  // The amount missing is the API's: the quantity that brings the goods to total + missing makes some method free.
  const total = groszeOf(await page.getByTestId("cart-total").innerText());
  const missingGrosze = groszeOf(await missing.innerText());
  expect(missingGrosze).toBeGreaterThan(0);
  const line = page.getByRole("main").getByRole("listitem").first();
  const unit = groszeOf(await line.getByTestId("unit-price").innerText());
  const quantity = Math.ceil((total + missingGrosze) / unit);
  expect(quantity).toBeLessThanOrEqual(99);

  const field = line.getByRole("spinbutton");
  await field.fill(String(quantity));
  await field.press("Enter");
  await expect(from).toHaveText("Darmowa dostawa");
  await expect(missing).toHaveCount(0);
  await expect(region).not.toContainText("Dostawa od");
  expect(groszeOf(await page.getByTestId("cart-total").innerText())).toBeGreaterThanOrEqual(total + missingGrosze);

  // Back below the threshold the rows come back.
  await field.fill("1");
  await field.press("Enter");
  await expect(from).toHaveText(/^Dostawa od \d/);
  await expect(missing).toHaveText(/^Do darmowej dostawy brakuje \d/);
  await expect.poll(async () => groszeOf(await missing.innerText())).toBe(missingGrosze);
});

test("the delivery rows of the cart fit a 375 px screen without horizontal scrolling", async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 800 });
  await cartWithOneMat(page);

  await expect(page.getByTestId("cart-delivery-from")).toBeVisible();
  await expect(page.getByTestId("cart-delivery-missing")).toBeVisible();
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBeLessThanOrEqual(0);
});

test("an empty cart has no delivery rows", async ({ page }) => {
  await page.goto("/cart");
  await expect(page.getByText("Twój koszyk jest pusty")).toBeVisible();
  await expect(page.getByTestId("cart-delivery")).toHaveCount(0);
  await expect(page.getByText(/dostaw/i)).toHaveCount(0);
});
