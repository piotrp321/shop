import { expect, type Locator, type Page, test } from "@playwright/test";
import { emptyCart, logInAsJan } from "../support/shop";
import { urls } from "../support/urls";
import { cartLineOf, chooseFirstVariant } from "../support/variants";

// Products of the list that are plainly in stock: a price, the label "Dostępny" and the button.
const inStockTiles = (page: Page) =>
  page
    .getByRole("main")
    .getByRole("listitem")
    .filter({ has: page.getByTestId("availability").getByText("Dostępny", { exact: true }) })
    .filter({ has: page.getByRole("button", { name: "Do koszyka" }) });

const tileNamed = (page: Page, name: string) =>
  inStockTiles(page).filter({ has: page.getByRole("heading", { name, exact: true }) });

const cartLines = (page: Page) => page.getByRole("main").getByRole("listitem");

/** Adds the first purchasable variant of the tile (its only variant without a choice) and returns its label. */
async function addToCart(tile: Locator, page: Page, expectedCount: string): Promise<string> {
  const label = await chooseFirstVariant(tile);
  await tile.getByRole("button", { name: "Do koszyka" }).click();
  await expect(tile.getByText("Dodano do koszyka")).toBeVisible();
  await expect(page.getByTestId("cart-count")).toHaveText(expectedCount);
  return label;
}

type Item = { name: string; label: string };

async function expectMergedCart(page: Page, a: Item, b: Item): Promise<void> {
  await page.goto("/cart");
  await expect(cartLineOf(page, a.name, a.label).getByRole("spinbutton")).toHaveValue("1");
  await expect(cartLineOf(page, b.name, b.label).getByRole("spinbutton")).toHaveValue("2");
  await expect(cartLines(page)).toHaveCount(2);
  await expect(page.getByTestId("cart-item-count")).toHaveText("3");
}

test("a guest cart is merged into the customer cart at login and the customer sees it on another device", async ({ page, browser }) => {
  await page.goto("/");
  const tiles = inStockTiles(page);
  await expect(tiles.nth(1)).toBeVisible();
  const productA = (await tiles.nth(0).getByRole("heading").innerText()).trim();
  const productB = (await tiles.nth(1).getByRole("heading").innerText()).trim();

  // Jan starts from a cart with B only.
  await logInAsJan(page);
  await emptyCart(page);
  await page.goto("/");
  const labelB = await addToCart(tileNamed(page, productB), page, "1");

  // After the logout the browser has an empty guest cart.
  await page.getByRole("button", { name: "Wyloguj się" }).click();
  await expect(page.getByRole("link", { name: "Zaloguj się" })).toBeVisible();
  await expect(page.getByTestId("cart-count")).toHaveText("0");

  const labelA = await addToCart(tileNamed(page, productA), page, "1");
  // The same variant of B as Jan's, so the quantities add up.
  expect(await addToCart(tileNamed(page, productB), page, "2")).toBe(labelB);
  expect((await page.context().cookies()).map((cookie) => cookie.name)).toContain("store_cart");

  // The login merges the guest cart: the quantities of B add up and the guest cookie is gone.
  await logInAsJan(page);
  await expect(page.getByTestId("cart-count")).toHaveText("3");
  await expect(page.getByText("Część produktów z koszyka gościa nie zmieściła się w koszyku")).toHaveCount(0);
  expect((await page.context().cookies()).map((cookie) => cookie.name)).not.toContain("store_cart");
  await expectMergedCart(page, { name: productA, label: labelA }, { name: productB, label: labelB });

  // Another browser logged in as Jan sees the same cart.
  const other = await browser.newContext({ baseURL: urls.store, locale: "pl-PL" });
  try {
    const otherPage = await other.newPage();
    await otherPage.goto("/");
    await logInAsJan(otherPage);
    await expectMergedCart(otherPage, { name: productA, label: labelA }, { name: productB, label: labelB });
  } finally {
    await other.close();
  }
});
