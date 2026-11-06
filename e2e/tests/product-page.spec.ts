import { expect, test, type Page } from "@playwright/test";
import { emptyCart, logInAsJan } from "../support/shop";
import { cartLineOf } from "../support/variants";

// Demo product with variants and a photo (apps/api/fixtures/catalog.yaml), in Bieganie > Buty biegowe > Buty szosowe.
const PRODUCT_NAME = "Buty szosowe Tarvik Asfalt 200";
const PRODUCT_PATH = "/p/buty-szosowe-tarvik-asfalt-200";

test("from the list to the product page: the path, the photo and the structured data", async ({ page }) => {
  await page.goto("/");
  const categories = page.getByRole("navigation", { name: "Kategorie" });
  await categories.getByRole("link", { name: "Bieganie", exact: true }).click();
  await categories.getByRole("link", { name: "Buty biegowe", exact: true }).click();
  await categories.getByRole("link", { name: "Buty szosowe", exact: true }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Buty szosowe" })).toBeVisible();

  await page.getByRole("main").getByRole("heading", { level: 2, name: PRODUCT_NAME, exact: true }).getByRole("link").click();

  await expect(page).toHaveURL(new RegExp(`${PRODUCT_PATH}$`));
  await expect(page.getByRole("heading", { level: 1, name: PRODUCT_NAME })).toBeVisible();
  await expect(page).toHaveTitle(`${PRODUCT_NAME} | Rytm`);
  await expect(page.locator('link[rel="canonical"]')).toHaveAttribute("href", new RegExp(`${PRODUCT_PATH}$`));

  const path = page.getByRole("navigation", { name: "Ścieżka" });
  await expect(path.getByRole("link")).toHaveText(["Wszystkie produkty", "Bieganie", "Buty biegowe", "Buty szosowe"]);

  // The photos are one under the other; the first one decodes (it is eager, the others are lazy).
  const photos = page.getByRole("main").locator("img");
  expect(await photos.count()).toBeGreaterThan(0);
  await expect.poll(() => photos.first().evaluate((img: HTMLImageElement) => img.naturalWidth), { message: "the first photo did not load" }).toBeGreaterThan(0);

  // Before a variant is chosen: the price of the product ("od X zł" when the variants differ) and its level.
  await expect(page.getByTestId("price")).toHaveText(/^(od )?\d/);
  await expect(page.getByTestId("availability")).toBeVisible();

  const scripts = await page.locator('script[type="application/ld+json"]').allTextContents();
  const data = scripts.map((text) => JSON.parse(text));
  const product = data.find((entry) => entry["@type"] === "Product");
  const breadcrumb = data.find((entry) => entry["@type"] === "BreadcrumbList");
  expect(product.name).toBe(PRODUCT_NAME);
  expect(product.url).toMatch(new RegExp(`${PRODUCT_PATH}$`));
  expect(product.offers.length).toBeGreaterThan(0);
  expect(product.offers[0]).toMatchObject({ "@type": "Offer", priceCurrency: "PLN" });
  expect(breadcrumb.itemListElement.map((item: { name: string }) => item.name)).toEqual(["Wszystkie produkty", "Bieganie", "Buty biegowe", "Buty szosowe"]);

  // The last category of the path leads back to the list of that category.
  await path.getByRole("link", { name: "Buty szosowe", exact: true }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Buty szosowe" })).toBeVisible();
});

test("an unknown product is a 404 that search engines do not index", async ({ page }) => {
  const response = await page.goto("/p/nie-ma-takiego-produktu");
  expect(response?.status()).toBe(404);
  await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", /noindex/);
});

test("the second request of a product page comes from the ISR cache", async ({ request }) => {
  // Only the production build (`next build` + `next start`) has the ISR cache; `next dev` renders on every request.
  test.skip(process.env.STORE_MODE !== "production", "ISR cache exists only with STORE_MODE=production");

  const first = await request.get(PRODUCT_PATH);
  expect(first.status()).toBe(200);
  const second = await request.get(PRODUCT_PATH);
  expect(second.status()).toBe(200);
  expect(second.headers()["x-nextjs-cache"]).toBe("HIT");
});

// Every variant of this product is in stock (apps/api/fixtures/stock.yaml), so no value is disabled here; the
// disabled values are covered by the unit tests.
async function chooseBlack42AndAdd(page: Page): Promise<void> {
  const choose = (name: string) => page.getByRole("main").getByRole("button", { name, exact: true });
  const add = page.getByRole("button", { name: "Do koszyka" });

  // Nothing is chosen yet: the later axis waits and the button is off.
  await expect(add).toBeDisabled();
  await expect(choose("42")).toBeDisabled();

  await choose("czarne").click();
  await expect(choose("czarne")).toHaveAttribute("aria-pressed", "true");
  await expect(add).toBeDisabled();
  await choose("czarne").click(); // a second click does not unselect
  await expect(choose("czarne")).toHaveAttribute("aria-pressed", "true");
  await choose("42").click();

  // The price and the level of the chosen variant replace the "od X zł" of the product.
  await expect(page.getByTestId("price")).toHaveText(/^\d/);
  await expect(page.getByTestId("availability")).toHaveText(/^(Dostępny|Ostatnie sztuki)$/);
  await expect(add).toBeEnabled();
  await add.click();
  await expect(page.getByText("Dodano do koszyka")).toBeVisible();
  await expect(page.getByTestId("cart-count")).toHaveText("1");
}

test("a guest chooses the colour and the size on the product page and adds the variant to the cart", async ({ page }) => {
  await page.goto(PRODUCT_PATH);
  await expect(page.getByTestId("cart-count")).toHaveText("0");
  await chooseBlack42AndAdd(page);

  await page.getByRole("link", { name: /^Koszyk/ }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Koszyk" })).toBeVisible();
  const line = cartLineOf(page, PRODUCT_NAME, "czarne, 42");
  await expect(line).toHaveCount(1);
  await expect(line.getByRole("spinbutton")).toHaveValue("1");
});

test("a signed-in customer chooses the colour and the size on the product page and adds the variant to the cart", async ({ page }) => {
  await page.goto("/");
  await logInAsJan(page);
  await emptyCart(page);

  await page.goto(PRODUCT_PATH);
  await expect(page.getByTestId("cart-count")).toHaveText("0");
  await chooseBlack42AndAdd(page);

  await page.getByRole("link", { name: /^Koszyk/ }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Koszyk" })).toBeVisible();
  const line = cartLineOf(page, PRODUCT_NAME, "czarne, 42");
  await expect(line).toHaveCount(1);
  await expect(line.getByRole("spinbutton")).toHaveValue("1");
  await emptyCart(page);
});
