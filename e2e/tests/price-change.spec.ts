import { expect, test, type Page } from "@playwright/test";
import { staff } from "../support/accounts";
import { loginStaff } from "../support/staff-login";

// The store list reads the offers (with the prices) from `GET /api/v1/product-offers`, cached for 60 s (`public, max-age=60`); the rest is slack.
const STORE_PRICE_WAIT_MS = 70_000;
// A demo product without variant axes (apps/api/fixtures/catalog.yaml): it has the default variant only, so the panel
// lists one row for it and its tile has no variant choice and no "od" before the price. It is in the category
// "Fitness" > "Trening".
const PRODUCT_NAME = "Hantle regulowane Lumora Para 10";
// Two fixed amounts; the test takes the one the product does not have now, so every run really changes the price.
const CANDIDATE_PRICES = [
  { input: "123,45", grosze: 12345 },
  { input: "234,56", grosze: 23456 },
] as const;

const formatPln = (grosze: number) => new Intl.NumberFormat("pl-PL", { style: "currency", currency: "PLN" }).format(grosze / 100);
// Intl separates thousands and the currency sign with non-breaking spaces, which a browser may pick differently.
const normalize = (text: string) => text.replace(/\s+/g, " ").trim();
const digitsOf = (text: string) => Number(text.replace(/\D/g, ""));

async function openStoreTraining(page: Page): Promise<void> {
  await page.goto("/");
  const categories = page.getByRole("navigation", { name: "Kategorie" });
  await categories.getByRole("link", { name: "Fitness", exact: true }).click();
  await categories.getByRole("link", { name: "Trening", exact: true }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Trening" })).toBeVisible();
}

const storeTile = (page: Page, name: string) =>
  page
    .getByRole("main")
    .getByRole("listitem")
    .filter({ has: page.getByRole("heading", { level: 2, name, exact: true }) });

test("a price changed in the panel shows up on the store list", async ({ page }) => {
  test.setTimeout(STORE_PRICE_WAIT_MS + 80_000);

  const productName = PRODUCT_NAME;
  await openStoreTraining(page);
  const priceBefore = normalize(await storeTile(page, productName).getByTestId("price").innerText());

  await loginStaff(page, staff.admin);
  await page.getByRole("link", { name: "Ceny" }).click();
  await page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" }).fill(productName);
  // The unfiltered first page has many rows: exactly one row means the (debounced) search has been applied. Changing
  // the price earlier would let a late search answer overwrite the row with the old price.
  await expect(page.getByTestId("price-row"), "the search narrows the list to the one row of the product's default variant").toHaveCount(1);
  const row = page.getByTestId("price-row").filter({ has: page.getByTestId("name").getByText(productName, { exact: true }) });
  await expect(row).toHaveCount(1);

  const currentGross = digitsOf(await row.getByTestId("gross").innerText());
  const target = CANDIDATE_PRICES.find((candidate) => candidate.grosze !== currentGross);
  if (target === undefined) throw new Error("no candidate price differs from the current one");

  await row.getByRole("button", { name: "Zmień cenę" }).click();
  await page.getByTestId("gross-input").fill(target.input);
  await page.getByRole("button", { name: "Zapisz" }).click();
  await expect(page.getByTestId("change-dialog"), "the price change was rejected (conflict, validation or API error)").toBeHidden();
  await expect(row.getByTestId("gross")).toHaveText(new RegExp(`^${target.input.replace(",", "[,.]")}\\s`));

  // The offers endpoint may be cached for up to 60 s: reload until the new price appears. The text is compared whole,
  // so a price with "od" would not match: the product has one variant.
  const expected = normalize(formatPln(target.grosze));
  expect(priceBefore, "the store showed another price before the change").not.toBe(expected);
  await openStoreTraining(page);
  await expect
    .poll(
      async () => {
        await page.reload();
        return normalize(await storeTile(page, productName).getByTestId("price").innerText());
      },
      { message: `the store did not show ${expected} for "${productName}"`, timeout: STORE_PRICE_WAIT_MS, intervals: [2_000] },
    )
    .toBe(expected);
});
