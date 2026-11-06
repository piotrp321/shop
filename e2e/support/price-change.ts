import { expect, type Page } from "@playwright/test";
import { staff } from "./accounts";
import { loginStaff } from "./staff-login";

// Two fixed amounts; a test takes the one the product does not have now, so every run really changes the price.
const CANDIDATE_PRICES = [
  { input: "123,45", grosze: 12345 },
  { input: "234,56", grosze: 23456 },
] as const;

export type PriceChange = { before: number; after: number };

/** The price formatted as the store shows it, with plain spaces (Intl uses non-breaking ones). */
export const formatPln = (grosze: number) =>
  normalize(new Intl.NumberFormat("pl-PL", { style: "currency", currency: "PLN" }).format(grosze / 100));
// Intl separates thousands and the currency sign with non-breaking spaces, which a browser may pick differently.
export const normalize = (text: string) => text.replace(/\s+/g, " ").trim();
const digitsOf = (text: string) => Number(text.replace(/\D/g, ""));

/**
 * Logs the admin into the panel and changes the gross price of the product's only (default) variant to a candidate
 * amount it does not have now. Resolves once the panel shows the saved price.
 */
export async function changePriceInPanel(page: Page, productName: string): Promise<PriceChange> {
  await loginStaff(page, staff.admin);
  await page.getByRole("link", { name: "Ceny" }).click();
  await page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" }).fill(productName);
  // The unfiltered first page has many rows: exactly one row means the (debounced) search has been applied. Changing
  // the price earlier would let a late search answer overwrite the row with the old price.
  await expect(page.getByTestId("price-row"), "the search narrows the list to the one row of the product's default variant").toHaveCount(1);
  const row = page.getByTestId("price-row").filter({ has: page.getByTestId("name").getByText(productName, { exact: true }) });
  await expect(row).toHaveCount(1);

  const before = digitsOf(await row.getByTestId("gross").innerText());
  const target = CANDIDATE_PRICES.find((candidate) => candidate.grosze !== before);
  if (target === undefined) throw new Error("no candidate price differs from the current one");

  await row.getByRole("button", { name: "Zmień cenę" }).click();
  await page.getByTestId("gross-input").fill(target.input);
  await page.getByRole("button", { name: "Zapisz" }).click();
  await expect(page.getByTestId("change-dialog"), "the price change was rejected (conflict, validation or API error)").toBeHidden();
  await expect(row.getByTestId("gross")).toHaveText(new RegExp(`^${target.input.replace(",", "[,.]")}\\s`));

  return { before, after: target.grosze };
}
