import { expect, test } from "@playwright/test";
import { waitForOrderEmail } from "../support/mailpit";
import { addVariantOf, fillAddress, tileNamed } from "../support/shop";
import { cartLineOf } from "../support/variants";

// Every variant of it has 50 units (fixtures/stock.yaml): each run reserves 2 until PAYMENT, so the demo data last
// many runs before `make seed`.
const PRODUCT = "Buty trailowe Norvane Hala 500";

test("a guest orders two units of a variant chosen on the tile and sees the confirmation, and the e-mail arrives", async ({ page }) => {
  const email = `e2e-zamowienie+${Date.now()}@example.com`;
  await page.goto("/");
  const tile = tileNamed(page, PRODUCT);
  await expect(tile).toBeVisible();
  const label = await addVariantOf(page, tile, 2);

  await page.getByRole("link", { name: /^Koszyk/ }).click();
  await expect(page).toHaveURL(/\/cart$/);
  await expect(cartLineOf(page, PRODUCT, label).getByRole("spinbutton")).toHaveValue("2");
  const cartTotal = (await page.getByTestId("cart-total").innerText()).trim();

  await page.getByRole("link", { name: "Przejdź do kasy" }).click();
  await expect(page).toHaveURL(/\/checkout$/);
  await expect(page.getByRole("heading", { level: 1, name: "Kasa" })).toBeVisible();
  // The summary of the checkout is the cart's: the same total from the API.
  await expect(page.getByTestId("checkout-total")).toHaveText(cartTotal);
  await expect(page.getByRole("main").getByRole("link", { name: "Zaloguj się" })).toHaveAttribute("href", "/auth/login?returnTo=%2Fcheckout");

  await page.getByLabel("E-mail").fill(email);
  await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
  await fillAddress(page);
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();

  await expect(page).toHaveURL(/\/checkout\/confirmation$/);
  await expect(page.getByRole("heading", { level: 1, name: "Dziękujemy za zamówienie" })).toBeVisible();
  const number = (await page.getByTestId("order-number").innerText()).trim();
  expect(number).toMatch(/^R-\d{6,}$/);
  await expect(page.getByText(`Potwierdzenie wysłaliśmy na adres ${email}.`)).toBeVisible();
  await expect(page.getByTestId("order-total")).toHaveText(cartTotal);
  await expect(page.getByTestId("cart-count")).toHaveText("0");

  const message = await waitForOrderEmail(email, number);
  expect(message.subject).toBe(`Potwierdzenie zamówienia ${number}`);
  expect(message.text).toContain(PRODUCT);

  // The cart is empty after the order, also after a reload.
  await page.goto("/cart");
  await expect(page.getByText("Twój koszyk jest pusty")).toBeVisible();
});
