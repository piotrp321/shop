import { expect, test, type APIRequestContext, type Page } from "@playwright/test";
import { changePriceInPanel, formatPln, normalize } from "../support/price-change";

// Decision P5: after the price event the first visitor already gets the new page (revalidateTag with expire 0, no
// stale page served in the background). Only the production build has the ISR cache; `next dev` renders every request.
const PRODUCT_NAME = "Hantle regulowane Lumora Para 10";
const PRODUCT_PATH = "/p/hantle-regulowane-lumora-para-10";
// The price event is handled (outbox, RabbitMQ, purge and revalidation) within this time after the change.
const EVENT_WAIT_MS = 20_000;

type Reading = { cache: string | undefined; price: string | null; jsonLdPrice: string | null };

/** One HTTP request of the product page: its ISR cache header, the visible price and the price of the JSON-LD offer. */
async function readProductPage(request: APIRequestContext): Promise<Reading> {
  const response = await request.get(PRODUCT_PATH);
  expect(response.status(), "the product page did not render").toBe(200);
  const html = await response.text();
  const price = html.match(/data-testid="price"[^>]*>([^<]*)</)?.[1] ?? null;
  const product = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)]
    .map((match) => JSON.parse(match[1] ?? "null") as { "@type": string; offers?: { price: string }[] })
    .find((entry) => entry?.["@type"] === "Product");
  return { cache: response.headers()["x-nextjs-cache"], price: price === null ? null : normalize(price), jsonLdPrice: product?.offers?.[0]?.price ?? null };
}

const jsonLdPrice = (grosze: number) => (grosze / 100).toFixed(2);

async function openStoreTraining(page: Page): Promise<void> {
  await page.goto("/c/fitness/trening");
  await expect(page.getByRole("heading", { level: 1, name: /^Trening/ })).toBeVisible();
}

const tilePrice = (page: Page) =>
  page
    .getByRole("main")
    .getByRole("listitem")
    .filter({ has: page.getByRole("heading", { level: 2, name: PRODUCT_NAME, exact: true }) })
    .getByTestId("price");

test("after a price change the first fresh render of the product page and the first list visit show the new price", async ({ page, request }) => {
  test.skip(process.env.STORE_MODE !== "production", "ISR cache exists only with STORE_MODE=production");
  test.setTimeout(EVENT_WAIT_MS + 90_000);

  // The page is in the ISR cache before the change.
  await readProductPage(request);
  const cached = await readProductPage(request);
  expect(cached.cache).toBe("HIT");

  const change = await changePriceInPanel(page, PRODUCT_NAME);
  const oldPrice = formatPln(change.before);
  const newPrice = formatPln(change.after);
  expect(cached.price, "the cached page showed another price before the change").toBe(oldPrice);

  // Until the event is handled the page comes from the cache with the old price; the first other answer is the render
  // that the revalidation forced, and it must already have the new price.
  const deadline = Date.now() + EVENT_WAIT_MS;
  let reading = await readProductPage(request);
  while (reading.cache === "HIT" && reading.price === oldPrice && Date.now() < deadline) {
    await page.waitForTimeout(250);
    reading = await readProductPage(request);
  }
  expect(reading.cache, "the product page was still served from the cache with the old price after 20 s").not.toBe("HIT");
  expect(reading.price, "the first render after the event did not have the new price").toBe(newPrice);
  expect(reading.jsonLdPrice, "the JSON-LD of the first render after the event did not have the new price").toBe(jsonLdPrice(change.after));

  // The first visit of the list (no reload) reads the offers through Varnish, already purged.
  const list = await page.context().newPage();
  await openStoreTraining(list);
  await expect(tilePrice(list)).toHaveText(newPrice);

  // Nothing goes back to the old price afterwards.
  for (let attempt = 0; attempt < 3; attempt++) {
    const later = await readProductPage(request);
    expect(later.price).toBe(newPrice);
    expect(later.jsonLdPrice).toBe(jsonLdPrice(change.after));
  }
  await list.reload();
  await expect(tilePrice(list)).toHaveText(newPrice);
});
