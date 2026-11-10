import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { addVariantOf, chooseProvider, tileNamed } from "../support/shop";

// The cart and the checkout in the visual direction (VISUAL-DESIGN-9): no serious or critical axe violation and no
// horizontal scroll on a phone and on a desktop, with an item in the cart of a guest.

// Demo data (apps/api/fixtures/catalog.yaml).
const PRODUCT = "Buty trailowe Norvane Hala 500";

const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1440, height: 900 };

const scrollWidth = (page: Page): Promise<number> => page.evaluate(() => document.documentElement.scrollWidth);

/** Puts one unit of the product into the cart of the guest (the cookie of this browser context). */
async function putProductInCart(page: Page): Promise<void> {
  await page.goto("/");
  const tile = tileNamed(page, PRODUCT);
  await expect(tile).toBeVisible();
  await addVariantOf(page, tile, 1);
}

async function expectNoSeriousViolation(page: Page, viewport: { width: number }): Promise<void> {
  await page.evaluate(() => document.fonts.ready);
  const results = await new AxeBuilder({ page }).exclude("nextjs-portal").analyze();
  const serious = results.violations.filter((violation) => violation.impact === "serious" || violation.impact === "critical");
  expect(serious.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
  expect(await scrollWidth(page)).toBeLessThanOrEqual(viewport.width);
}

for (const viewport of [DESKTOP, PHONE]) {
  test.describe(`at ${viewport.width} px`, () => {
    test.beforeEach(async ({ page }) => {
      await page.setViewportSize(viewport);
      await putProductInCart(page);
    });

    test("the cart with an item has no serious or critical axe violation and no horizontal scroll", async ({ page }) => {
      await page.goto("/cart");
      await expect(page.getByRole("heading", { level: 1, name: "Koszyk" })).toBeVisible();
      await expect(page.getByTestId("cart-total")).toBeVisible();
      await expect(page.getByRole("main").getByRole("listitem")).toHaveCount(1);
      await expectNoSeriousViolation(page, viewport);
    });

    test("the checkout with an item has no serious or critical axe violation and no horizontal scroll", async ({ page }) => {
      await page.goto("/checkout");
      await expect(page.getByRole("heading", { level: 1, name: "Kasa" })).toBeVisible();
      await expect(page.getByTestId("checkout-total")).toBeVisible();
      await expect(page.getByLabel("E-mail")).toBeVisible();
      await expectNoSeriousViolation(page, viewport);
    });

    test("the checkout with the messages of a refused order has no serious or critical axe violation and no horizontal scroll", async ({ page }) => {
      await page.goto("/checkout");
      await expect(page.getByTestId("checkout-total")).toBeVisible();
      await chooseProvider(page, "Szybki przelew (demo)");
      // The empty form is refused by the API: the messages of the fields appear and nothing is ordered.
      await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();
      await expect(page.getByLabel("E-mail")).toHaveAttribute("aria-invalid", "true");
      await expectNoSeriousViolation(page, viewport);
    });
  });
}
