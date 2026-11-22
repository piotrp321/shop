import { expect, type Locator, type Page, test } from "@playwright/test";
import { tileNamed } from "../support/shop";
import { chooseFirstVariant } from "../support/variants";

// „Do koszyka” keeps the keyboard focus while the add request runs (STORE-UX-FIXES, S7; #390). It guards behaviour
// that already works: the button blocks with aria-disabled, not disabled. Every test starts in a new browser context,
// so the guest cart is empty and the items added here do not reach other files.
const PRODUCT = "Buty szosowe Tarvik Asfalt 200";
const LISTING_PATH = "/c/bieganie/buty/szosowe";
const PRODUCT_PATH = "/p/buty-szosowe-tarvik-asfalt-200";
const HOLD_MS = 3000;

/** Holds every POST /api/cart/items for `HOLD_MS` before it reaches the server; `posts` counts the requests. */
async function holdAddRequests(page: Page): Promise<{ posts: () => number }> {
  let posts = 0;
  await page.route("**/api/cart/items", async (route) => {
    if (route.request().method() !== "POST") {
      await route.continue();
      return;
    }
    posts++;
    await new Promise((resolve) => setTimeout(resolve, HOLD_MS));
    await route.continue();
  });
  return { posts: () => posts };
}

async function expectKeyKeepsFocus(page: Page, button: Locator, key: "Enter" | "Space", held: { posts: () => number }) {
  await button.focus();
  await expect(button).toBeFocused();
  await page.keyboard.press(key);
  await expect(button).toHaveAttribute("aria-disabled", "true");
  await expect(button).toBeFocused();
  // Another activation during the request sends nothing.
  await page.keyboard.press(key);
  await expect(button).toBeFocused();
  await expect(page.getByTestId("cart-count")).toHaveText("1", { timeout: HOLD_MS + 10_000 });
  await expect(button).not.toHaveAttribute("aria-disabled", "true");
  await expect(button).toBeFocused();
  expect(held.posts()).toBe(1);
}

for (const key of ["Enter", "Space"] as const) {
  test(`${key} on „Do koszyka” of the product page keeps the focus while the request runs, and one POST goes out`, async ({ page }) => {
    const held = await holdAddRequests(page);
    await page.goto(PRODUCT_PATH);
    await expect(page.getByTestId("cart-count")).toHaveText("0");
    const choose = (name: string) => page.getByRole("main").getByRole("button", { name, exact: true });
    await choose("czarne").click();
    await choose("42").click();
    const add = page.getByRole("button", { name: "Do koszyka" });
    await expect(add).toBeEnabled();
    await expectKeyKeepsFocus(page, add, key, held);
  });

  test(`${key} on „Do koszyka” of a listing tile keeps the focus while the request runs, and one POST goes out`, async ({ page }) => {
    const held = await holdAddRequests(page);
    await page.goto(LISTING_PATH);
    await expect(page.getByTestId("cart-count")).toHaveText("0");
    const tile = tileNamed(page, PRODUCT);
    await expect(tile).toHaveCount(1);
    await chooseFirstVariant(tile);
    await expectKeyKeepsFocus(page, tile.getByRole("button", { name: "Do koszyka" }), key, held);
  });
}
