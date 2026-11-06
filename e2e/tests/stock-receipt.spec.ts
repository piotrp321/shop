import { expect, test, type Page } from "@playwright/test";
import { staff } from "../support/accounts";
import { loginStaff } from "../support/staff-login";

// A change of the availability level purges the product in Varnish and revalidates its page in the store (no waiting
// for a cache time): the product page must show the new level within this time.
const STORE_AVAILABILITY_WAIT_MS = 20_000;
// The demo product with nothing on the shelf in any variant (apps/api/fixtures/stock.yaml); it is in the category
// "Sporty wodne" > "Kajaki".
const PRODUCT_NAME = "Kajak turystyczny Vilmar Rzeka 2";
const PRODUCT_PATH = "/p/kajak-turystyczny-vilmar-rzeka-2";
// The variant of the product to receive into: the panel lists a row per variant, the stock is kept per variant.
const VARIANT_LABEL = "zielony";
// More than the low stock threshold, so the level after the receipt is "Dostępny" and not "Ostatnie sztuki".
const RECEIVED_QUANTITY = 10;

/** The whole text, literally (also an empty one, which `getByText` cannot match). */
const exactly = (text: string): RegExp => new RegExp(`^${text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}$`);

/**
 * Opens the product page until it shows the level of the product (before a variant is chosen); the level event of the
 * last change has to be handled first.
 */
async function expectProductPageAvailability(page: Page, label: string): Promise<void> {
  await expect
    .poll(
      async () => {
        await page.goto(PRODUCT_PATH);
        return (await page.getByRole("main").getByTestId("availability").first().innerText()).trim();
      },
      { message: `the product page did not show "${label}" for "${PRODUCT_NAME}" within 20 s`, timeout: STORE_AVAILABILITY_WAIT_MS, intervals: [1_000] },
    )
    .toBe(label);
}

test("a stock receipt for a variant in the panel makes the product available on its page", async ({ page }) => {
  test.setTimeout(2 * STORE_AVAILABILITY_WAIT_MS + 120_000);

  // The product starts out of stock in every variant. Right after a previous run the event of its final correction
  // may still be on its way; waiting for "Niedostępny" makes the "Dostępny" after the receipt a consequence of it.
  await expectProductPageAvailability(page, "Niedostępny");

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
      await expectProductPageAvailability(store, "Dostępny");
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
