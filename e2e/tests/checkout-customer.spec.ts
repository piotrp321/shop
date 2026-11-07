import { expect, test } from "@playwright/test";
import { customers } from "../support/accounts";
import { waitForOrderEmail } from "../support/mailpit";
import { decideAtSimulator, expectSimulatorPage, WEBHOOK_TIMEOUT_MS } from "../support/payment";
import { addVariantOf, chooseProvider, emptyCart, fillAddress, logInAsJan, tileNamed } from "../support/shop";

// Every variant of it has 45 units (fixtures/stock.yaml): each run reserves 1, and the reservation of the paid order
// stays until it is shipped (ORDER-MANAGEMENT). The rejected first payment reserves nothing more: it is the same order.
const PRODUCT = "Buty szosowe Norvane Tempo 600";

test("Jan orders his cart with the e-mail of his account, his card payment is rejected, he chooses again and pays, and the e-mail with that order's number arrives", async ({ page }) => {
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
  await chooseProvider(page, "Karta płatnicza (demo)");
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();

  // The first payment is rejected at the provider...
  await expectSimulatorPage(page, "demo-card");
  await decideAtSimulator(page, "Odrzuć płatność");
  const number = (await page.getByTestId("order-number").innerText()).trim();
  expect(number).toMatch(/^R-\d{6,}$/);
  await expect(page.getByText("Płatność nie powiodła się.")).toBeVisible({ timeout: WEBHOOK_TIMEOUT_MS });
  await expect(page.getByTestId("order-total")).toHaveText(cartTotal);
  await expect(page.getByTestId("cart-count")).toHaveText("0");

  // ...he chooses again (no provider is chosen for him) and pays the same order.
  await expect(page.getByRole("radio", { name: "Karta płatnicza (demo)" })).not.toBeChecked();
  await expect(page.getByRole("radio", { name: "Szybki przelew (demo)" })).not.toBeChecked();
  await chooseProvider(page, "Karta płatnicza (demo)");
  await page.getByRole("button", { name: "Zapłać", exact: true }).click();
  await expectSimulatorPage(page, "demo-card");
  await decideAtSimulator(page, "Zapłać");

  await expect(page.getByTestId("order-number")).toHaveText(number);
  await expect(page.getByText(`Płatność przyjęta. Potwierdzenie wysłaliśmy na adres ${customers.jan.email}.`)).toBeVisible({
    timeout: WEBHOOK_TIMEOUT_MS,
  });
  await expect(page.getByTestId("order-total")).toHaveText(cartTotal);

  // Jan gets e-mails for every order of every run: the ones of this order are found by the address and the number.
  const confirmation = await waitForOrderEmail(customers.jan.email, number);
  expect(confirmation.subject).toBe(`Potwierdzenie zamówienia ${number}`);
  expect(confirmation.text).toContain(PRODUCT);
  const payment = await waitForOrderEmail(customers.jan.email, number, "payment");
  expect(payment.subject).toBe(`Płatność przyjęta – zamówienie ${number}`);
});
