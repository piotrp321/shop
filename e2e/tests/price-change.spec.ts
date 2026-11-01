import { expect, test, type Page } from "@playwright/test";
import { staff } from "../support/accounts";
import { loginStaff } from "../support/staff-login";

// The store list reads prices from `GET /api/v1/prices`, cached for 60 s (`public, max-age=60`); the rest is slack.
const STORE_PRICE_WAIT_MS = 70_000;
// Two fixed amounts; the test takes the one the product does not have now, so every run really changes the price.
const CANDIDATE_PRICES = [
  { input: "123,45", grosze: 12345 },
  { input: "234,56", grosze: 23456 },
] as const;

const formatPln = (grosze: number) => new Intl.NumberFormat("pl-PL", { style: "currency", currency: "PLN" }).format(grosze / 100);
// Intl separates thousands and the currency sign with non-breaking spaces, which a browser may pick differently.
const normalize = (text: string) => text.replace(/\s+/g, " ").trim();
const digitsOf = (text: string) => Number(text.replace(/\D/g, ""));

const storeTile = (page: Page, name: string) =>
  page
    .getByRole("main")
    .getByRole("listitem")
    .filter({ has: page.getByRole("heading", { level: 2, name, exact: true }) });

test("a price changed in the panel shows up on the store list", async ({ page }) => {
  test.setTimeout(STORE_PRICE_WAIT_MS + 80_000);

  // The first product of the first page of the store: always on the list and with a name unique on that page.
  await page.goto("/");
  const productName = (await page.getByRole("main").getByRole("heading", { level: 2 }).first().innerText()).trim();

  await loginStaff(page, staff.admin);
  await page.getByRole("link", { name: "Ceny" }).click();
  await page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" }).fill(productName);
  const row = page.getByTestId("price-row").filter({ has: page.getByTestId("name").getByText(productName, { exact: true }) });
  await expect(row).toHaveCount(1);

  const currentGross = digitsOf(await row.getByTestId("gross").innerText());
  const target = CANDIDATE_PRICES.find((candidate) => candidate.grosze !== currentGross);
  if (target === undefined) throw new Error("no candidate price differs from the current one");

  await row.getByRole("button", { name: "Zmień cenę" }).click();
  await page.getByTestId("gross-input").fill(target.input);
  await page.getByRole("button", { name: "Zapisz" }).click();
  await expect(page.getByTestId("change-dialog")).toBeHidden();
  await expect(row.getByTestId("gross")).toHaveText(new RegExp(`^${target.input.replace(",", "[,.]")}\\s`));

  // The prices endpoint may be cached for up to 60 s: reload until the new price appears.
  const expected = normalize(formatPln(target.grosze));
  await page.goto("/");
  await expect
    .poll(
      async () => {
        await page.reload();
        return normalize(await storeTile(page, productName).locator("p").innerText());
      },
      { message: `the store did not show ${expected} for "${productName}"`, timeout: STORE_PRICE_WAIT_MS, intervals: [2_000] },
    )
    .toBe(expected);
});
