import { expect, test, type Page } from "@playwright/test";
import { addVariantOf, chooseDelivery, chooseProvider, fillAddress, tileNamed } from "../support/shop";

// The size of an order of a guest (docs/features/abuse-limits/spec.md, L5, D5): at most 10 lines of 10 units. The cart of a
// guest takes more (50 lines of 99 units), the checkout refuses the order with the message of the API and nothing is
// ordered. Each test has its own browser context, so its guest cart is its own.

const TOO_LARGE = "Zamówienie bez konta może mieć najwyżej 10 różnych produktów, po 10 sztuk każdego. Zmniejsz ilości w koszyku.";

/** The names of the first `count` products of the home page that are plainly in stock (a price, „Dostępny”, a button to buy). */
async function purchasableNames(page: Page, count: number): Promise<string[]> {
  await page.goto("/");
  const tiles = page
    .getByTestId("product-grid")
    .getByRole("listitem")
    .filter({ has: page.getByTestId("availability").getByText("Dostępny", { exact: true }) })
    .filter({ has: page.getByRole("button", { name: /^(Do koszyka|Wybierz wariant)$/ }) });
  await expect(tiles.first()).toBeVisible();
  const names = (await tiles.getByRole("heading").allInnerTexts()).map((name) => name.trim());
  expect(names.length, "the first page of the list has enough products in stock").toBeGreaterThanOrEqual(count);
  return names.slice(0, count);
}

async function fillGuestForm(page: Page): Promise<void> {
  await page.getByLabel("E-mail").fill(`e2e-limit+${Date.now()}@example.com`);
  await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
  await fillAddress(page);
  await chooseDelivery(page);
  await chooseProvider(page, "Szybki przelew (demo)");
}

async function expectRefusedAtCheckout(page: Page): Promise<void> {
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();

  const alert = page.getByRole("alert").filter({ hasText: TOO_LARGE });
  await expect(alert).toBeVisible();
  await expect(alert.getByRole("link", { name: "Wróć do koszyka" })).toHaveAttribute("href", "/cart");
  // The form stays as it was and nothing was ordered: the browser did not leave the checkout.
  await expect(page).toHaveURL(/\/checkout$/);
  await expect(page.getByLabel("Telefon")).toHaveValue("600 100 200");
  await expect(page.getByRole("radio", { name: "Szybki przelew (demo)" })).toBeChecked();
}

test("a guest with eleven different products in the cart is told at the checkout that the order is too large, and nothing is ordered", async ({ page }) => {
  const names = await purchasableNames(page, 11);
  for (const name of names) {
    await addVariantOf(page, tileNamed(page, name), 1);
  }
  await expect(page.getByTestId("cart-count")).toHaveText("11");

  await page.goto("/checkout");
  await expect(page.getByRole("heading", { level: 1, name: "Kasa" })).toBeVisible();
  await fillGuestForm(page);
  await expectRefusedAtCheckout(page);

  // The cart is untouched: still eleven lines.
  await page.goto("/cart");
  await expect(page.getByTestId("cart-count")).toHaveText("11");
  await expect(page.getByRole("main").getByRole("listitem").getByRole("button", { name: /^Usuń/ })).toHaveCount(11);
});

test("a guest with eleven units of one product in the cart is told at the checkout that the order is too large, and nothing is ordered", async ({ page }) => {
  const name = (await purchasableNames(page, 1)).at(0) ?? "";
  await addVariantOf(page, tileNamed(page, name), 1);

  await page.goto("/cart");
  const quantity = page.getByRole("main").getByRole("listitem").first().getByRole("spinbutton");
  await quantity.fill("11");
  await quantity.press("Enter");
  await expect(page.getByTestId("cart-count")).toHaveText("11");

  await page.goto("/checkout");
  await expect(page.getByRole("heading", { level: 1, name: "Kasa" })).toBeVisible();
  await fillGuestForm(page);
  await expectRefusedAtCheckout(page);

  await page.goto("/cart");
  await expect(page.getByTestId("cart-count")).toHaveText("11");
});
