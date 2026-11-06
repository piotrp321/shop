import { expect, test, type Page } from "@playwright/test";
import { changePriceInPanel, formatPln, normalize } from "../support/price-change";

// The price event purges the product in Varnish and revalidates its page in the store (no waiting for a cache time):
// the new price must show up on the product page and on the list within this time after the change.
const STORE_PRICE_WAIT_MS = 20_000;
// A demo product without variant axes (apps/api/fixtures/catalog.yaml): it has the default variant only, so the panel
// lists one row for it and its tile has no variant choice and no "od" before the price. It is in the category
// "Fitness" > "Trening".
const PRODUCT_NAME = "Hantle regulowane Lumora Para 10";
const PRODUCT_PATH = "/p/hantle-regulowane-lumora-para-10";

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

test("a price changed in the panel shows up on the product page and on the store list", async ({ page, context }) => {
  test.setTimeout(STORE_PRICE_WAIT_MS + 90_000);

  // Both readings come before the change, so Varnish (and the ISR cache) hold the old price.
  const store = await context.newPage();
  await store.goto(PRODUCT_PATH);
  const pageBefore = normalize(await store.getByTestId("price").innerText());
  await openStoreTraining(store);
  const listBefore = normalize(await storeTile(store, PRODUCT_NAME).getByTestId("price").innerText());

  const change = await changePriceInPanel(page, PRODUCT_NAME);
  const changedAt = Date.now();
  const expected = formatPln(change.after);
  expect(pageBefore, "the product page showed another price before the change").toBe(formatPln(change.before));
  expect(listBefore, "the list showed another price before the change").toBe(formatPln(change.before));

  // The text is compared whole, so a price with "od" would not match: the product has one variant.
  const remaining = () => Math.max(1, STORE_PRICE_WAIT_MS - (Date.now() - changedAt));
  await expect
    .poll(
      async () => {
        await store.goto(PRODUCT_PATH);
        return normalize(await store.getByTestId("price").innerText());
      },
      { message: `the product page did not show ${expected} within 20 s`, timeout: remaining(), intervals: [1_000] },
    )
    .toBe(expected);
  await openStoreTraining(store);
  await expect
    .poll(
      async () => {
        await store.reload();
        return normalize(await storeTile(store, PRODUCT_NAME).getByTestId("price").innerText());
      },
      { message: `the list did not show ${expected} for "${PRODUCT_NAME}" within 20 s`, timeout: remaining(), intervals: [1_000] },
    )
    .toBe(expected);
});
