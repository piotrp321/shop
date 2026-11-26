import { expect, test, type APIRequestContext, type Page } from "@playwright/test";
import { staff } from "../support/accounts";
import { createDraftInPanel, newProduct, publishInPanel, unpublishInPanel } from "../support/catalog-admin";
import { documentWidths } from "../support/panel-layout";
import { loginStaff, waitForBearerToken } from "../support/staff-login";
import { urls } from "../support/urls";

// The product card with the brand, the description, the parameters, the product code and the EAN (PRODUCT-DATA). The
// product is created in the panel with two variants: one with a global EAN, one with an EAN of restricted circulation
// (the GS1 prefix 2), for which the API gives no gtin, so the structured data has none.
const EVENT_WAIT_MS = 20_000;
const GLOBAL_EAN = "4006381333931";
const RESTRICTED_EAN = "2000000000008";
const BRAND = "Zz Marka E2E";
const SCRIPT_LINE = "Uwaga: <script>alert(1)</script> i <b>pogrubienie</b> zostają tekstem.";
const DESCRIPTION = `Pierwszy akapit opisu.\n\n${SCRIPT_LINE}\nDruga linia tego samego akapitu.\n\nTrzeci akapit.`;
const PARAMETERS = [
  { name: "Masa", value: "3,2 kg" },
  { name: "Liczba osób", value: "4" },
];

type JsonLdOffer = { sku?: string; gtin?: string; name?: string; price?: string; availability?: string };
type JsonLdProduct = { "@type": string; brand?: { "@type": string; name: string }; description?: string; offers: JsonLdOffer[] };

/** The product of the structured data of the page, read from the HTML of a plain request (no JavaScript, as a crawler sees it). */
async function readJsonLd(request: APIRequestContext, path: string): Promise<{ status: number; product: JsonLdProduct | undefined; html: string }> {
  const response = await request.get(path);
  const html = await response.text();
  const product = [...html.matchAll(/<script type="application\/ld\+json">([\s\S]*?)<\/script>/g)]
    .map((match) => JSON.parse(match[1] ?? "null") as JsonLdProduct)
    .find((entry) => entry?.["@type"] === "Product");
  return { status: response.status(), product, html };
}

async function putJson(request: APIRequestContext, url: string, headers: Record<string, string>, data: unknown): Promise<void> {
  const response = await request.put(url, { headers, data });
  expect(response.status(), `PUT ${url}: ${await response.text()}`).toBe(200);
}

/** Gives every variant of the product a price and some stock through the admin API, so both can be chosen and have an offer. */
async function priceAndStock(page: Page, request: APIRequestContext, productId: string): Promise<void> {
  const bearer = waitForBearerToken(page);
  await loginStaff(page, staff.admin);
  const headers = { Authorization: `Bearer ${await bearer}` };
  const view = (await (await request.get(`${urls.api}/api/v1/admin/products/${productId}`, { headers })).json()) as { variants: { variantId: string }[] };
  expect(view.variants).toHaveLength(2);
  for (const [index, variant] of view.variants.entries()) {
    await putJson(request, `${urls.api}/api/v1/admin/variants/${variant.variantId}/price`, headers, { gross: 10000 + index * 500, version: null });
    const receipt = await request.post(`${urls.api}/api/v1/admin/variants/${variant.variantId}/stock/receipts`, {
      headers: { ...headers, "Idempotency-Key": `zz-e2e-product-data-${variant.variantId}` },
      data: { quantity: 5, note: null },
    });
    expect(receipt.status(), `the receipt of ${variant.variantId}: ${await receipt.text()}`).toBeLessThan(300);
  }
}

test("a product created in the panel shows its brand, description, parameters, codes and structured data on the card", async ({ page, request, browser }) => {
  test.setTimeout(6 * 60_000);
  const product = newProduct("Dane produktu");
  const skuOf = (index: number) => `ZZ-E2E-${product.slug.split("-").at(-1)!.toUpperCase()}-${index}`;
  const path = `/p/${product.slug}`;

  // marketing: the draft with the data of the product, two variants with their codes, then the publication.
  await loginStaff(page, staff.marketing);
  const productId = await createDraftInPanel(page, product);
  await page.getByTestId("brand").fill(BRAND);
  await page.getByTestId("description").fill(DESCRIPTION);
  for (const [index, parameter] of PARAMETERS.entries()) {
    await page.getByTestId("add-parameter").click();
    await page.getByTestId("parameter-name").nth(index).fill(parameter.name);
    await page.getByTestId("parameter-value").nth(index).fill(parameter.value);
  }
  await page.getByTestId("variant-sku").first().fill(skuOf(1));
  await page.getByTestId("variant-ean").first().fill(GLOBAL_EAN);
  await page.getByTestId("add-variant").click();
  await expect(page.getByTestId("variant-row")).toHaveCount(2);
  await page.getByTestId("variant-value").nth(1).fill("biały");
  await page.getByTestId("variant-sku").nth(1).fill(skuOf(2));
  await page.getByTestId("variant-ean").nth(1).fill(RESTRICTED_EAN);
  await page.getByTestId("save").click();
  await expect(page.locator("section[aria-live=polite]").getByText("Produkt zapisany."), "the save is announced in a toast").toBeVisible();
  await expect(page.getByTestId("form-error"), "the save was rejected").toHaveCount(0);
  await publishInPanel(page);

  // admin: a price and stock for both variants.
  const adminContext = await browser.newContext({ locale: "pl-PL", baseURL: urls.store });
  try {
    await priceAndStock(await adminContext.newPage(), request, productId);
  } finally {
    await adminContext.close();
  }

  // The structured data (a plain request, so also the ISR page in the production build) has both offers with a price.
  let seen = await readJsonLd(request, path);
  await expect
    .poll(
      async () => {
        seen = await readJsonLd(request, path);
        return seen.status === 200 && seen.product?.brand?.name === BRAND && seen.product.offers.length === 2 && seen.product.offers.every((offer) => offer.availability !== "https://schema.org/OutOfStock");
      },
      { message: "the card had no brand, no two offers in stock 20 s after the last change", timeout: EVENT_WAIT_MS, intervals: [250] },
    )
    .toBe(true);

  const ld = seen.product!;
  expect(ld.brand).toEqual({ "@type": "Brand", name: BRAND });
  expect(ld.description, "the description of the structured data stays the short description").toBe(product.description);
  expect(ld.offers.map((offer) => offer.sku)).toEqual([skuOf(1), skuOf(2)]);
  expect(ld.offers[0]?.gtin, "a global EAN is the gtin").toBe(GLOBAL_EAN);
  expect(ld.offers[1], "an EAN of restricted circulation is no gtin").not.toHaveProperty("gtin");
  expect(seen.html, "the first HTML has no code, the variant is not chosen yet").not.toContain("Kod produktu");

  // The card.
  const store = await page.context().newPage();
  await store.setViewportSize({ width: 1280, height: 900 });
  await store.goto(path);
  await expect(store.getByTestId("brand")).toHaveText(BRAND);
  expect(await store.getByTestId("brand").evaluate((brand) => brand.compareDocumentPosition(document.querySelector("h1")!) & Node.DOCUMENT_POSITION_FOLLOWING)).toBeTruthy();
  const description = store.getByTestId("product-description");
  await expect(store.getByRole("heading", { level: 2, name: "Opis" })).toBeVisible();
  await expect(description.locator("p")).toHaveCount(3);
  await expect(description.locator("p").nth(0)).toHaveText("Pierwszy akapit opisu.");
  await expect(description.locator("p").nth(1), "markup is shown as text").toHaveText(`${SCRIPT_LINE}Druga linia tego samego akapitu.`);
  await expect(description.locator("p").nth(1).locator("br"), "a single line end is a line break").toHaveCount(1);
  await expect(description.locator("script, b")).toHaveCount(0);
  await expect(description.locator("p").nth(2)).toHaveText("Trzeci akapit.");
  await expect(store.getByRole("heading", { level: 2, name: "Parametry" })).toBeVisible();
  const parameters = store.getByTestId("product-parameters");
  await expect(parameters.locator("dt")).toHaveText(PARAMETERS.map((parameter) => parameter.name));
  await expect(parameters.locator("dd")).toHaveText(PARAMETERS.map((parameter) => parameter.value));

  // The codes follow the variant that is chosen (after the hydration), none before.
  await expect(store.getByTestId("variant-codes")).toHaveCount(0);
  await store.getByRole("button", { name: "czarny", exact: true }).click();
  await expect(store.getByTestId("variant-codes")).toHaveText(`Kod produktu: ${skuOf(1)}EAN: ${GLOBAL_EAN}`);
  await store.getByRole("button", { name: "biały", exact: true }).click();
  await expect(store.getByTestId("variant-codes"), "the EAN of restricted circulation is on the card").toHaveText(`Kod produktu: ${skuOf(2)}EAN: ${RESTRICTED_EAN}`);

  // 375 px: the page does not scroll sideways.
  await store.setViewportSize({ width: 375, height: 812 });
  await store.goto(path);
  await expect(store.getByTestId("product-parameters")).toBeVisible();
  const widths = await documentWidths(store);
  expect(widths.scrollWidth, `the card scrolls sideways at 375 px (${widths.scrollWidth} px of ${widths.clientWidth} px)`).toBeLessThanOrEqual(widths.clientWidth);

  // Take the product off the shop again: products cannot be deleted, a published one would stay in the catalog.
  await unpublishInPanel(page);
});
