import { expect, test } from "@playwright/test";
import { decideAtSimulator, expectSimulatorPage, WEBHOOK_TIMEOUT_MS } from "../support/payment";
import { addVariantOf, chooseProvider, fillAddress, tileNamed } from "../support/shop";

// Every variant of it has 50 units (fixtures/stock.yaml): each run reserves 1 for the order that is paid in the end,
// and that reservation stays until the order is shipped (ORDER-MANAGEMENT).
const PRODUCT = "Buty trailowe Norvane Hala 500";

test("a guest who went back from the page of the provider finds the unpaid order, returns to the same payment session and pays", async ({
  page,
}) => {
  const email = `e2e-platnosc+${Date.now()}@example.com`;
  await page.goto("/");
  const tile = tileNamed(page, PRODUCT);
  await expect(tile).toBeVisible();
  await addVariantOf(page, tile, 1);

  await page.goto("/checkout");
  await page.getByLabel("E-mail").fill(email);
  await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
  await fillAddress(page);
  await chooseProvider(page, "Szybki przelew (demo)");
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();
  await expectSimulatorPage(page, "demo-transfer");
  const sessionUrl = page.url();

  // "Back" of the browser from the page of the provider: the checkout, whose cart is empty now.
  await page.goBack();
  await expect(page).toHaveURL(/\/checkout$/);
  await expect(page.getByText("Twój koszyk jest pusty.")).toBeVisible();
  const payLink = page.getByRole("link", { name: /^Opłać zamówienie R-\d{6,}$/ });
  await expect(payLink).toBeVisible();
  const number = ((await payLink.innerText()).match(/R-\d{6,}/) ?? [""])[0];
  await payLink.click();

  // The order waits for its payment; the way back leads to the same session of the provider, not to a new one.
  await expect(page).toHaveURL(/\/checkout\/confirmation$/);
  await expect(page.getByTestId("order-number")).toHaveText(number);
  await expect(page.getByText("Czekamy na potwierdzenie płatności…")).toBeVisible();
  await page.getByRole("link", { name: "Wróć do płatności" }).click();
  await expect(page).toHaveURL(sessionUrl);
  await expectSimulatorPage(page, "demo-transfer");

  await decideAtSimulator(page, "Zapłać");
  await expect(page.getByTestId("order-number")).toHaveText(number);
  await expect(page.getByText(`Płatność przyjęta. Potwierdzenie wysłaliśmy na adres ${email}.`)).toBeVisible({ timeout: WEBHOOK_TIMEOUT_MS });
});
