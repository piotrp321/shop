import { expect, type Locator, type Page, test } from "@playwright/test";
import { customers } from "../support/accounts";
import { urls } from "../support/urls";

// Products of the list that are plainly in stock: a price, the label "Dostępny" and the button.
const inStockTiles = (page: Page) =>
  page
    .getByRole("main")
    .getByRole("listitem")
    .filter({ has: page.getByTestId("availability").getByText("Dostępny", { exact: true }) })
    .filter({ has: page.getByRole("button", { name: "Do koszyka" }) });

const tileNamed = (page: Page, name: string) =>
  inStockTiles(page).filter({ has: page.getByRole("heading", { level: 2, name, exact: true }) });

const cartLines = (page: Page) => page.getByRole("main").getByRole("listitem");

const cartLine = (page: Page, name: string) =>
  cartLines(page).filter({ has: page.getByRole("heading", { level: 2, name, exact: true }) });

/** Logs in as Jan from the current page; Keycloak sends the browser back to it. */
async function logInAsJan(page: Page): Promise<void> {
  const loginLink = page.getByRole("link", { name: "Zaloguj się" });
  await expect(loginLink).toBeVisible();
  await loginLink.click();
  await page.locator("#username").fill(customers.jan.email);
  await page.locator("#password").fill(customers.jan.password);
  await page.locator("#kc-login").click();
  await expect(page.getByText(/^Zalogowany jako .+/)).toBeVisible();
}

async function addToCart(tile: Locator, page: Page, expectedCount: string): Promise<void> {
  await tile.getByRole("button", { name: "Do koszyka" }).click();
  await expect(tile.getByText("Dodano do koszyka")).toBeVisible();
  await expect(page.getByTestId("cart-count")).toHaveText(expectedCount);
}

/** Removes every line on the cart page: whatever earlier runs left in the customer's cart. */
async function emptyCart(page: Page): Promise<void> {
  await page.goto("/cart");
  const empty = page.getByText("Twój koszyk jest pusty");
  await expect(empty.or(cartLines(page).first())).toBeVisible();
  for (let count = await cartLines(page).count(); count > 0; count--) {
    await cartLines(page).first().getByRole("button", { name: /^Usuń/ }).click();
    await expect(cartLines(page)).toHaveCount(count - 1);
  }
  await expect(empty).toBeVisible();
}

async function expectMergedCart(page: Page, a: string, b: string): Promise<void> {
  await page.goto("/cart");
  await expect(cartLine(page, a).getByRole("spinbutton")).toHaveValue("1");
  await expect(cartLine(page, b).getByRole("spinbutton")).toHaveValue("2");
  await expect(cartLines(page)).toHaveCount(2);
  await expect(page.getByTestId("cart-item-count")).toHaveText("3");
}

test("a guest cart is merged into the customer cart at login and the customer sees it on another device", async ({ page, browser }) => {
  await page.goto("/");
  const tiles = inStockTiles(page);
  await expect(tiles.nth(1)).toBeVisible();
  const productA = (await tiles.nth(0).getByRole("heading", { level: 2 }).innerText()).trim();
  const productB = (await tiles.nth(1).getByRole("heading", { level: 2 }).innerText()).trim();

  // Jan starts from a cart with B only.
  await logInAsJan(page);
  await emptyCart(page);
  await page.goto("/");
  await addToCart(tileNamed(page, productB), page, "1");

  // After the logout the browser has an empty guest cart.
  await page.getByRole("button", { name: "Wyloguj się" }).click();
  await expect(page.getByRole("link", { name: "Zaloguj się" })).toBeVisible();
  await expect(page.getByTestId("cart-count")).toHaveText("0");

  await addToCart(tileNamed(page, productA), page, "1");
  await addToCart(tileNamed(page, productB), page, "2");
  expect((await page.context().cookies()).map((cookie) => cookie.name)).toContain("store_cart");

  // The login merges the guest cart: the quantities of B add up and the guest cookie is gone.
  await logInAsJan(page);
  await expect(page.getByTestId("cart-count")).toHaveText("3");
  await expect(page.getByText("Część produktów z koszyka gościa nie zmieściła się w koszyku")).toHaveCount(0);
  expect((await page.context().cookies()).map((cookie) => cookie.name)).not.toContain("store_cart");
  await expectMergedCart(page, productA, productB);

  // Another browser logged in as Jan sees the same cart.
  const other = await browser.newContext({ baseURL: urls.store, locale: "pl-PL" });
  try {
    const otherPage = await other.newPage();
    await otherPage.goto("/");
    await logInAsJan(otherPage);
    await expectMergedCart(otherPage, productA, productB);
  } finally {
    await other.close();
  }
});
