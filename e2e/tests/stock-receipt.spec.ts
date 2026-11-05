import { expect, test, type Page } from "@playwright/test";
import { staff } from "../support/accounts";
import { loginStaff } from "../support/staff-login";

// The store list reads the offers (with the availability level) from `GET /api/v1/product-offers`, cached for 60 s (`public, max-age=60`); the rest is slack.
const STORE_AVAILABILITY_WAIT_MS = 70_000;
// The demo product with nothing on the shelf in any variant (apps/api/fixtures/stock.yaml); it is in the category
// "Sporty wodne" > "Kajaki".
const PRODUCT_NAME = "Kajak turystyczny Vilmar Rzeka 2";
// The variant of the product to receive into: the panel lists a row per variant, the stock is kept per variant.
const VARIANT_LABEL = "zielony";
// More than the low stock threshold, so the level after the receipt is "Dostępny" and not "Ostatnie sztuki".
const RECEIVED_QUANTITY = 10;

/** The whole text, literally (also an empty one, which `getByText` cannot match). */
const exactly = (text: string): RegExp => new RegExp(`^${text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}$`);

const storeAvailability = (page: Page) =>
  page
    .getByRole("main")
    .getByRole("listitem")
    .filter({ has: page.getByRole("heading", { level: 2, name: PRODUCT_NAME, exact: true }) })
    .getByTestId("availability");

async function openStoreKayaks(page: Page): Promise<void> {
  await page.goto("/");
  const categories = page.getByRole("navigation", { name: "Kategorie" });
  await categories.getByRole("link", { name: "Sporty wodne", exact: true }).click();
  await categories.getByRole("link", { name: "Kajaki", exact: true }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Kajaki" })).toBeVisible();
}

/** Reloads the store listing until the product shows the label; the offers may be cached for up to 60 s. */
async function expectStoreAvailability(page: Page, label: string): Promise<void> {
  await expect
    .poll(
      async () => {
        await page.reload();
        return (await storeAvailability(page).innerText()).trim();
      },
      { message: `the store did not show "${label}" for "${PRODUCT_NAME}"`, timeout: STORE_AVAILABILITY_WAIT_MS, intervals: [2_000] },
    )
    .toBe(label);
}

test("a stock receipt for a variant in the panel makes the product available in the store", async ({ page }) => {
  test.setTimeout(2 * STORE_AVAILABILITY_WAIT_MS + 120_000);

  // The product starts out of stock in every variant. After a previous run the store may still show the cached "Dostępny" for up to
  // 60 s; waiting for "Niedostępny" makes the "Dostępny" after the receipt a consequence of it, not a leftover.
  await openStoreKayaks(page);
  await expectStoreAvailability(page, "Niedostępny");

  await loginStaff(page, staff.warehouse);
  await page.getByRole("link", { name: "Stany magazynowe" }).click();
  await page.getByRole("searchbox", { name: "Szukaj po nazwie produktu" }).fill(PRODUCT_NAME);
  // The unfiltered first page has many products: the product name as the only one (a name is shown on the first row of
  // each product, its variants follow) means the (debounced) search has been applied. Changing the stock earlier would let a late search
  // answer overwrite the row with the old stock.
  await expect(page.getByTestId("name"), "the search narrows the list to the product").toHaveText([PRODUCT_NAME]);
  // Every row left is a variant of the product (pomarańczowy, zielony); the one to receive into is found by its label.
  const row = page
    .getByTestId("stock-row")
    .filter({ has: page.getByTestId("variant-label").filter({ hasText: exactly(VARIANT_LABEL) }) });
  await expect(row).toHaveCount(1);
  await expect(
    row.getByTestId("physical"),
    "the demo product has to start with no stock (run `make seed` if an earlier run was interrupted)",
  ).toHaveText("0");

  let received = false;
  try {
    await row.getByTestId("receive-stock").click();
    const receiveDialog = page.getByTestId("receive-dialog");
    await receiveDialog.getByLabel("Przyjęta ilość (szt.)").fill(String(RECEIVED_QUANTITY));
    await receiveDialog.getByTestId("receive-submit").click();
    await expect(receiveDialog, "the receipt was rejected (validation, permission or API error)").toBeHidden();
    received = true;

    await expect(row.getByTestId("physical")).toHaveText(String(RECEIVED_QUANTITY));
    await expect(row.getByTestId("available")).toHaveText(String(RECEIVED_QUANTITY));
    await expect(row.getByTestId("level")).toHaveText("Dostępny");

    // A second tab keeps the panel page, with its row and session, for the cleanup below.
    const store = await page.context().newPage();
    try {
      await openStoreKayaks(store);
      await expectStoreAvailability(store, "Dostępny");
    } finally {
      await store.close();
    }
  } finally {
    // The test changes shared demo data: put the stock back to 0 with a correction so the next `make e2e` can run
    // without `make seed`.
    if (received) {
      await row.getByTestId("correct-stock").click();
      const correctDialog = page.getByTestId("correct-dialog");
      await correctDialog.getByLabel("Stan policzony (szt.)").fill("0");
      await correctDialog.getByTestId("correct-submit").click();
      await expect(correctDialog, "restoring the stock to 0 failed").toBeHidden();
      await expect(row.getByTestId("physical")).toHaveText("0");
    }
  }
});
