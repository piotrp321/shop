import { expect, test, type Page } from "@playwright/test";
import { staff } from "../support/accounts";
import { gridTiles } from "../support/listing";
import { formatPln, normalize } from "../support/price-change";
import { endActivePromotionsOf, offerOf, PanelToken, promotionDetails, warsawLocal } from "../support/promotions";
import { loginStaff, waitForBearerToken } from "../support/staff-login";
import { urls } from "../support/urls";

// The promotion reaches the store through the boundary message of the worker (PromotionPricesChanged purges the product
// in Varnish and revalidates its page): the page and the list show it within this time after it was saved.
const STORE_PROMOTION_WAIT_MS = 70_000;
// A demo product that no other test uses and no promotion of the demo data covers (apps/api/fixtures/promotions.yaml,
// apps/api/tests/Unit/Pricing/Demo/RealDemoPromotionsTest.php): two variants of one price, in Fitness > Odżywki > Suplementy.
const PRODUCT_NAME = "Elektrolity w tabletkach Lumora Nawodnienie 20";
const PRODUCT_PATH = "/p/elektrolity-w-tabletkach-lumora-nawodnienie-20";
const LIST_PATH = "/c/fitness/odzywki/suplementy";
const PROMOTION_NAME = "E2E promocja elektrolitów";
const DAY_MS = 86_400_000;

const omnibusText = (grosze: number) => `Najniższa cena z 30 dni przed obniżką: ${formatPln(grosze)}`;

/** What the store shows of the price of the product: the price, whether it has the badge and the Omnibus line. */
async function noticeOf(scope: ReturnType<Page["locator"]>) {
  const badge = scope.getByTestId("promotion-badge");
  const omnibus = scope.getByTestId("omnibus");
  return {
    price: normalize(await scope.getByTestId("price").first().innerText()),
    badge: (await badge.count()) > 0,
    omnibus: (await omnibus.count()) > 0 ? normalize(await omnibus.first().innerText()) : null,
  };
}

const productPageNotice = async (store: Page) => {
  await store.goto(PRODUCT_PATH);
  return noticeOf(store.getByRole("main"));
};

const listNotice = async (store: Page) => {
  await store.reload();
  return noticeOf(gridTiles(store).filter({ has: store.getByRole("heading", { name: PRODUCT_NAME, exact: true }) }));
};

test("a promotion created in the panel shows on the product page and on the list with the Omnibus price", async ({ page, context, request }) => {
  test.setTimeout(STORE_PROMOTION_WAIT_MS + 180_000);

  const pendingToken = waitForBearerToken(page);
  await loginStaff(page, staff.marketing);
  const token = await PanelToken.from(pendingToken);

  try {
    // A promotion of the product left by an earlier run would overlap the new one.
    const ended = await endActivePromotionsOf(request, await token.fresh(page), PRODUCT_NAME);

    // The store before the promotion: the earlier one (if it was ended just now) has gone from the page and the list.
    const store = await context.newPage();
    await expect
      .poll(async () => (await productPageNotice(store)).badge, { message: `the product page still showed a promotion (${ended} ended) after 70 s`, timeout: STORE_PROMOTION_WAIT_MS, intervals: [1_000] })
      .toBe(false);
    await store.goto(LIST_PATH);
    await expect(store.getByRole("heading", { level: 1, name: "Suplementy" })).toBeVisible();
    await expect
      .poll(async () => (await listNotice(store)).badge, { message: "the list still showed a promotion after 70 s", timeout: STORE_PROMOTION_WAIT_MS, intervals: [1_000] })
      .toBe(false);

    // The marketing staff member plans a promotion from now on the whole product.
    await page.getByRole("link", { name: "Promocje" }).click();
    await page.getByTestId("add-promotion").click();
    await page.getByTestId("name-input").fill(PROMOTION_NAME);
    await page.getByTestId("percent-input").fill("20");
    await page.getByTestId("start-now").click();
    await page.getByTestId("ends-input").fill(warsawLocal(Date.now() + DAY_MS));
    await page.getByTestId("open-picker").click();
    await page.getByTestId("picker-search").fill(PRODUCT_NAME);
    // Exactly the two variants of the product: the (debounced) search has been applied.
    await expect(page.getByTestId("picker-row"), "the search narrows the picker to the two variants of the product").toHaveCount(2);
    await page.getByTestId("add-product").click();
    await expect(page.getByTestId("variant-row")).toHaveCount(2);
    await page.getByTestId("save").click();
    await expect(page.getByTestId("success-message"), "the promotion was rejected (conflict, validation or API error)").toHaveText("Promocja zapisana.");
    const savedAt = Date.now();

    // The panel moves to the page of the new promotion after the save (the route is replaced without waiting).
    await expect(page).toHaveURL(/\/promotions\/[0-9a-f-]{36}$/);
    const id = new URL(page.url()).pathname.split("/").pop() ?? "";
    const promotion = await promotionDetails(request, await token.fresh(page), id);
    const productId = promotion.variants[0]?.productId;
    if (productId === null || productId === undefined) throw new Error("the promotion has no variant of a known product");

    // The API reads the price at the time of the request: the promotion is in force within a couple of seconds.
    await expect
      .poll(async () => (await offerOf(request, productId)).fromPromotion, { message: "the offer did not get the promotion within 20 s", timeout: 20_000, intervals: [500] })
      .not.toBeNull();
    const offer = await offerOf(request, productId);
    const regular = offer.variants[0]?.regularGross;
    const lowestPrior = offer.fromPromotion?.lowestPriorGross;
    if (offer.fromGross === null || regular === null || regular === undefined || lowestPrior === null || lowestPrior === undefined) {
      throw new Error(`the offer has no promotional price or Omnibus price: ${JSON.stringify(offer)}`);
    }
    expect(offer.fromGross, "the promotional price is below the catalog price").toBeLessThan(regular);
    // Not a constant: an earlier run leaves its promotion inside the 30 days before this one's start.
    const expected = { price: formatPln(offer.fromGross), badge: true, omnibus: omnibusText(lowestPrior) };

    const remaining = () => Math.max(1, STORE_PROMOTION_WAIT_MS - (Date.now() - savedAt));
    await expect
      .poll(() => productPageNotice(store), { message: `the product page did not show the promotion ${JSON.stringify(expected)} within 70 s`, timeout: remaining(), intervals: [1_000] })
      .toEqual(expected);
    await store.goto(LIST_PATH);
    await expect
      .poll(() => listNotice(store), { message: `the list did not show the promotion ${JSON.stringify(expected)} for "${PRODUCT_NAME}" within 70 s`, timeout: remaining(), intervals: [1_000] })
      .toEqual(expected);
  } finally {
    // The next run (and the stack, until the next make seed) must not meet this promotion.
    await endActivePromotionsOf(request, await token.fresh(page), PRODUCT_NAME);
  }
});
