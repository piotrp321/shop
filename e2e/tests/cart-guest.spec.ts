import { expect, test } from "@playwright/test";

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
  const name = (await tile.getByRole("heading", { level: 2 }).innerText()).trim();
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

  const line = page.getByRole("main").getByRole("listitem").filter({ has: page.getByRole("heading", { level: 2, name, exact: true }) });
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
