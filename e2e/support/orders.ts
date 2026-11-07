import { expect, type Locator, type Page } from "@playwright/test";
import { decideAtSimulator, expectSimulatorPage, WEBHOOK_TIMEOUT_MS } from "./payment";
import { addVariantOf, chooseProvider, emptyCart, fillAddress, tileNamed } from "./shop";

const PROVIDERS = {
  "Karta płatnicza (demo)": "demo-card",
  "Szybki przelew (demo)": "demo-transfer",
} as const;

export interface PlacedOrder {
  number: string;
  /** The gross total as the cart showed it, e.g. "199,00 zł". */
  total: string;
}

/**
 * From a logged-in customer's page: empties the cart, orders one unit of `product` with the given provider, pays at the
 * simulator and waits for the confirmation page to say the payment is accepted (the webhook reaches the store through
 * the worker). Returns the number and the total of the order the test has just made.
 */
export async function placeAndPayOrder(page: Page, product: string, provider: keyof typeof PROVIDERS): Promise<PlacedOrder> {
  await emptyCart(page);
  await page.goto("/");
  const tile = tileNamed(page, product);
  await expect(tile).toBeVisible();
  await addVariantOf(page, tile, 1);

  await page.goto("/cart");
  const total = (await page.getByTestId("cart-total").innerText()).trim();
  await page.getByRole("link", { name: "Przejdź do kasy" }).click();
  await expect(page).toHaveURL(/\/checkout$/);
  await expect(page.getByLabel("Imię i nazwisko")).not.toHaveValue("");
  await fillAddress(page);
  await chooseProvider(page, provider);
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();

  await expectSimulatorPage(page, PROVIDERS[provider]);
  await decideAtSimulator(page, "Zapłać");
  const number = (await page.getByTestId("order-number").innerText()).trim();
  expect(number).toMatch(/^R-\d{6,}$/);
  await expect(page.getByText(/^Płatność przyjęta\./)).toBeVisible({ timeout: WEBHOOK_TIMEOUT_MS });
  return { number, total };
}

/** Opens "Moje zamówienia" from the header of the store; the page lists the orders of the customer, newest first. */
export async function openOrderHistory(page: Page): Promise<void> {
  await page.getByRole("banner").getByRole("link", { name: "Moje zamówienia" }).click();
  await expect(page).toHaveURL(/\/konto\/zamowienia$/);
  await expect(page.getByRole("heading", { level: 1, name: "Moje zamówienia" })).toBeVisible();
}

/** The entry of an order (by its number) in the history of the customer. */
export function historyEntry(page: Page, number: string): Locator {
  return page.getByRole("main").getByRole("listitem").filter({ has: page.getByRole("link", { name: number, exact: true }) });
}
