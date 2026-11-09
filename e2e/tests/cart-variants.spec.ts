import { expect, test } from "@playwright/test";
import { cartLineOf } from "../support/variants";

// A demo product with sizes (apps/api/fixtures/catalog.yaml: axes "Kolor" and "Rozmiar") whose sizes 41 and 42 in
// "czarne" are in stock (apps/api/fixtures/stock.yaml). It is in the category "Bieganie" > "Buty biegowe" > "Buty szosowe".
const PRODUCT_NAME = "Buty szosowe Tarvik Asfalt 200";
const VARIANT_LABELS = ["czarne, 41", "czarne, 42"];

test("a guest chooses two sizes of one product on the tile and the cart has two lines with the variant labels", async ({ page }) => {
  await page.goto("/c/bieganie/buty/szosowe");
  await expect(page.getByRole("heading", { level: 1, name: "Buty szosowe" })).toBeVisible();

  const tile = page
    .getByRole("main")
    .getByRole("listitem")
    .filter({ has: page.getByRole("heading", { name: PRODUCT_NAME, exact: true }) });
  await expect(tile).toHaveCount(1);
  // Without a choice the tile cannot add anything.
  await expect(tile.getByRole("button", { name: "Do koszyka" })).toBeDisabled();

  await expect(page.getByTestId("cart-count")).toHaveText("0");
  for (const [index, label] of VARIANT_LABELS.entries()) {
    await tile.getByRole("combobox").selectOption({ label });
    await expect(tile.getByTestId("price")).toBeVisible();
    await tile.getByRole("button", { name: "Do koszyka" }).click();
    await expect(page.getByTestId("cart-count")).toHaveText(String(index + 1));
  }

  await page.getByRole("link", { name: /^Koszyk/ }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Koszyk" })).toBeVisible();
  for (const label of VARIANT_LABELS) {
    const line = cartLineOf(page, PRODUCT_NAME, label);
    await expect(line).toHaveCount(1);
    await expect(line.getByRole("spinbutton")).toHaveValue("1");
  }
  // The same product twice, once per size: two lines, not one line of two pieces.
  await expect(cartLineOf(page, PRODUCT_NAME, "")).toHaveCount(2);
  await expect(page.getByTestId("cart-item-count")).toHaveText("2");
});
