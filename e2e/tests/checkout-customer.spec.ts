import { expect, test } from "@playwright/test";
import { customers } from "../support/accounts";
import { waitForOrderEmail } from "../support/mailpit";
import { addVariantOf, emptyCart, fillAddress, logInAsJan, tileNamed } from "../support/shop";

// Every variant of it has 45 units (fixtures/stock.yaml): each run reserves 1 until PAYMENT.
const PRODUCT = "Buty szosowe Norvane Tempo 600";

test("Jan orders his cart with the e-mail of his account and the e-mail with that order's number arrives", async ({ page }) => {
  await page.goto("/");
  await logInAsJan(page);
  await emptyCart(page);

  await page.goto("/");
  const tile = tileNamed(page, PRODUCT);
  await expect(tile).toBeVisible();
  await addVariantOf(page, tile, 1);

  await page.goto("/cart");
  const cartTotal = (await page.getByTestId("cart-total").innerText()).trim();
  await page.getByRole("link", { name: "Przejdź do kasy" }).click();
  await expect(page).toHaveURL(/\/checkout$/);

  // The e-mail of the account, as text: no field for it.
  await expect(page.getByTestId("account-email")).toHaveText(customers.jan.email);
  await expect(page.getByLabel("E-mail")).toHaveCount(0);
  await expect(page.getByLabel("Imię i nazwisko")).not.toHaveValue("");
  await expect(page.getByTestId("checkout-total")).toHaveText(cartTotal);
  await fillAddress(page);
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();

  await expect(page).toHaveURL(/\/checkout\/confirmation$/);
  const number = (await page.getByTestId("order-number").innerText()).trim();
  expect(number).toMatch(/^R-\d{6,}$/);
  await expect(page.getByText(`Potwierdzenie wysłaliśmy na adres ${customers.jan.email}.`)).toBeVisible();
  await expect(page.getByTestId("order-total")).toHaveText(cartTotal);
  await expect(page.getByTestId("cart-count")).toHaveText("0");

  // Jan gets an e-mail for every order of every run: the one of this order is found by the address and the number.
  const message = await waitForOrderEmail(customers.jan.email, number);
  expect(message.subject).toBe(`Potwierdzenie zamówienia ${number}`);
  expect(message.text).toContain(PRODUCT);
});
