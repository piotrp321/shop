import { expect, test } from "@playwright/test";
import { cartLineOf, chooseFirstVariant } from "../support/variants";

// A cart total in grosze from the text of a price ("1 234,50 zł" -> 123450); the amounts are never computed from
// anything but the tile's own price, so the page must show 3 x that price.
const groszeOf = (text: string) => Number(text.replace(/\D/g, ""));

test("a guest adds a product from the list, changes its quantity on the cart page and removes it", async ({ page }) => {
  await page.goto("/");

  // The first product of the list that is plainly in stock: it has a price, the label "Dostępny" and the button.
  const tile = page
    .getByRole("main")
    .getByRole("listitem")
    .filter({ has: page.getByTestId("availability").getByText("Dostępny", { exact: true }) })
    .filter({ has: page.getByRole("button", { name: "Do koszyka" }) })
    .first();
  await expect(tile).toBeVisible();
  const name = (await tile.getByRole("heading").innerText()).trim();
  // A tile with a choice of variants shows the price of the chosen one.
  const label = await chooseFirstVariant(tile);
  const unitGrosze = groszeOf(await tile.getByTestId("price").innerText());
  expect(unitGrosze).toBeGreaterThan(0);

  // The header counter is loaded in the browser; an empty cart for a new visitor.
  await expect(page.getByTestId("cart-count")).toHaveText("0");

  await tile.getByRole("button", { name: "Do koszyka" }).click();
  await expect(tile.getByText("Dodano do koszyka")).toBeVisible();
  await expect(page.getByTestId("cart-count")).toHaveText("1");

  await page.getByRole("link", { name: /^Koszyk/ }).click();
  await expect(page).toHaveURL(/\/cart$/);
  await expect(page.getByRole("heading", { level: 1, name: "Koszyk" })).toBeVisible();

  const line = cartLineOf(page, name, label);
  await expect(line).toHaveCount(1);
  await expect(line.getByTestId("unit-price")).toBeVisible();
  const quantity = line.getByRole("spinbutton");
  await expect(quantity).toHaveValue("1");

  // The cart survives a reload: it is kept by the API under the id in the signed cookie.
  await page.reload();
  await expect(line).toHaveCount(1);
  await expect(quantity).toHaveValue("1");

  await quantity.fill("3");
  await quantity.press("Enter");
  await expect(quantity).toHaveValue("3");
  await expect(page.getByTestId("cart-item-count")).toHaveText("3");
  await expect(page.getByTestId("cart-count")).toHaveText("3");
  await expect.poll(async () => groszeOf(await line.getByTestId("line-total").innerText())).toBe(3 * unitGrosze);
  await expect.poll(async () => groszeOf(await page.getByTestId("cart-total").innerText())).toBe(3 * unitGrosze);

  await line.getByRole("button", { name: /^Usuń/ }).click();
  await expect(page.getByText("Twój koszyk jest pusty")).toBeVisible();
  await expect(page.getByTestId("cart-count")).toHaveText("0");

  // Still empty after a reload.
  await page.reload();
  await expect(page.getByText("Twój koszyk jest pusty")).toBeVisible();
});

test("two tabs of a guest without a cart add two products at the same time and both land in one cart", async ({ context }) => {
  const [first, second] = [await context.newPage(), await context.newPage()];
  await Promise.all([first.goto("/"), second.goto("/")]);
  expect((await context.cookies()).some((cookie) => cookie.name === "store_cart")).toBe(false);

  // The first two products of the list that are plainly in stock, one per tab.
  const inStock = (page: typeof first) =>
    page
      .getByRole("main")
      .getByRole("listitem")
      .filter({ has: page.getByTestId("availability").getByText("Dostępny", { exact: true }) })
      .filter({ has: page.getByRole("button", { name: "Do koszyka" }) });
  const tileA = inStock(first).nth(0);
  const tileB = inStock(second).nth(1);
  await expect(tileA).toBeVisible();
  await expect(tileB).toBeVisible();
  const nameA = (await tileA.getByRole("heading").innerText()).trim();
  const nameB = (await tileB.getByRole("heading").innerText()).trim();
  expect(nameA).not.toBe(nameB);
  const [labelA, labelB] = [await chooseFirstVariant(tileA), await chooseFirstVariant(tileB)];

  await Promise.all([
    tileA.getByRole("button", { name: "Do koszyka" }).click(),
    tileB.getByRole("button", { name: "Do koszyka" }).click(),
  ]);
  await expect(tileA.getByText("Dodano do koszyka")).toBeVisible();
  await expect(tileB.getByText("Dodano do koszyka")).toBeVisible();

  // One cart for both tabs: after a reload each tab counts both products.
  await first.reload();
  await expect(first.getByTestId("cart-count")).toHaveText("2");

  await first.goto("/cart");
  await expect(cartLineOf(first, nameA, labelA)).toHaveCount(1);
  await expect(cartLineOf(first, nameB, labelB)).toHaveCount(1);
  await expect(first.getByTestId("cart-item-count")).toHaveText("2");

  await second.reload();
  await expect(second.getByTestId("cart-count")).toHaveText("2");
});
