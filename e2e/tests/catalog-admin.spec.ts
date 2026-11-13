import { expect, test, type APIRequestContext, type Page } from "@playwright/test";
import { staff } from "../support/accounts";
import { createDraftInPanel, newProduct, publishInPanel, receiveStockInPanel, solidPng, unpublishInPanel } from "../support/catalog-admin";
import { openMenuItem } from "../support/panel-menu";
import { changePriceInPanel, formatPln, normalize } from "../support/price-change";
import { loginStaff, waitForBearerToken } from "../support/staff-login";
import { urls } from "../support/urls";

// A change of the product is handled (outbox, RabbitMQ, purge of Varnish and revalidation of the store) within this
// time; the store page and the panel are compared after it at the latest.
const EVENT_WAIT_MS = 20_000;
const RECEIVED_QUANTITY = 10;
const MIB = 1024 * 1024;

type StorePage = { status: number; heading: string | null; price: string | null; level: string | null; photoLoaded: boolean };

const productPath = (slug: string) => `/p/${slug}`;

/** What a visitor sees at the address of the product: the status, and on a 200 the name, the price, the level and the photo. */
async function visit(page: Page, slug: string): Promise<StorePage> {
  const response = await page.goto(productPath(slug));
  const status = response?.status() ?? 0;
  if (status !== 200) return { status, heading: null, price: null, level: null, photoLoaded: false };
  const text = async (testId: string) => ((await page.getByTestId(testId).count()) === 0 ? null : normalize(await page.getByTestId(testId).first().innerText()));
  const photo = page.getByRole("main").locator("img").first();
  return {
    status,
    heading: await page.getByRole("heading", { level: 1 }).innerText(),
    price: await text("price"),
    level: await text("availability"),
    photoLoaded: (await photo.count()) > 0 && (await photo.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0)),
  };
}

/** Visits the address until the page satisfies the condition; the message names what was missing. */
async function expectStorePage(page: Page, slug: string, message: string, accepted: (seen: StorePage) => boolean): Promise<StorePage> {
  let last: StorePage | undefined;
  try {
    await expect
      .poll(
        async () => {
          last = await visit(page, slug);
          return accepted(last);
        },
        { message, timeout: EVENT_WAIT_MS, intervals: [1_000] },
      )
      .toBe(true);
  } catch (error) {
    throw new Error(`${message} within 20 s (last seen: ${JSON.stringify(last)})`, { cause: error });
  }
  return last as StorePage;
}

const getStatus = async (request: APIRequestContext, slug: string) => (await request.get(productPath(slug))).status();

test("marketing creates and publishes a product, admin prices it and receives stock; it is edited and withdrawn", async ({ page, browser }) => {
  test.setTimeout(6 * EVENT_WAIT_MS + 180_000);
  const product = newProduct("Pelny obieg");

  // marketing: a draft with a photo and a variant, then its publication.
  await loginStaff(page, staff.marketing);
  await createDraftInPanel(page, product);
  await expect(page.getByTestId("image-alt"), "the alt text of the photo starts as the name of the product").toHaveValue(product.name);
  expect((await page.context().request.get(productPath(product.slug))).status(), "a draft is not on sale").toBe(404);
  await publishInPanel(page);

  // admin: the price of the only variant, then a stock receipt.
  const adminContext = await browser.newContext({ locale: "pl-PL", baseURL: urls.store });
  const adminPage = await adminContext.newPage();
  let priceText: string;
  try {
    const change = await changePriceInPanel(adminPage, product.name);
    priceText = formatPln(change.after);
    await receiveStockInPanel(adminPage, product.name, product.variantValue, RECEIVED_QUANTITY);
  } finally {
    await adminContext.close();
  }

  // the product page: name, photo, price and level of the product.
  const store = await page.context().newPage();
  const seen = await expectStorePage(store, product.slug, `the product page did not show the name, the photo, ${priceText} and "Dostępny"`, (page) => page.status === 200 && page.heading === product.name && page.photoLoaded && page.price === priceText && page.level === "Dostępny");
  expect(seen.heading).toBe(product.name);
  await expect(store.getByRole("img", { name: product.name }).first()).toBeVisible();

  // marketing renames it: the new name shows on the page, the address stays.
  const renamed = `${product.name} nowa nazwa`;
  await page.getByTestId("name").fill(renamed);
  await page.getByTestId("save").click();
  await expect(page.locator("section[aria-live=polite]").getByText("Produkt zapisany."), "the save is announced in a toast").toBeVisible();
  await expect(page.getByRole("region", { name: "Zapis formularza" }), "the save bar goes away after the save").toHaveCount(0);
  await expect(page.getByRole("heading", { level: 1 }), "the focus is on the heading after the save").toBeFocused();
  await expect(page.getByTestId("form-error")).toHaveCount(0);
  await expect(page.getByTestId("slug")).toHaveValue(product.slug);
  await expect(page.getByTestId("slug")).toBeDisabled();
  await expectStorePage(store, product.slug, `the product page did not show the new name "${renamed}"`, (page) => page.status === 200 && page.heading === renamed);

  // marketing withdraws it: the page is a 404.
  await unpublishInPanel(page);
  await expectStorePage(store, product.slug, "the withdrawn product page was not a 404", (page) => page.status === 404);
});

test("production build: a slug visited before the publication is a 404 and shows the product page after it", async ({ page, request }) => {
  test.skip(process.env.STORE_MODE !== "production", "the ISR cache and its invalidation exist only with STORE_MODE=production");
  test.setTimeout(3 * EVENT_WAIT_MS + 120_000);
  const product = newProduct("Cache");

  await loginStaff(page, staff.marketing);
  await createDraftInPanel(page, product);

  // The 404 is visited twice, so the second answer may come from the caches (ISR and Varnish).
  expect(await getStatus(request, product.slug)).toBe(404);
  expect(await getStatus(request, product.slug)).toBe(404);

  await publishInPanel(page);
  await expect
    .poll(() => getStatus(request, product.slug), {
      message: "the product page stayed a 404 for 20 s after the publication (the cached 404 was not invalidated)",
      timeout: EVENT_WAIT_MS,
      intervals: [250],
    })
    .toBe(200);
  const html = await (await request.get(productPath(product.slug))).text();
  expect(html).toContain(product.name);

  // Take the product off the shop again: products cannot be deleted, a published one would stay in the catalog.
  await unpublishInPanel(page);
  await expect
    .poll(() => getStatus(request, product.slug), { message: "the withdrawn product page was not a 404 within 20 s", timeout: EVENT_WAIT_MS, intervals: [250] })
    .toBe(404);
});

test("the upload of an image refuses a file over upload_max_filesize and a request over post_max_size", async ({ page, request }) => {
  test.setTimeout(120_000);
  const bearer = waitForBearerToken(page);
  await loginStaff(page, staff.marketing);
  const headers = { Authorization: `Bearer ${await bearer}` };
  const upload = (file: { name: string; mimeType: string; buffer: Uint8Array }) =>
    request.post(`${urls.api}/api/v1/admin/product-images`, { headers, multipart: { file: { ...file, buffer: Buffer.from(file.buffer) } }, timeout: 90_000 });

  // The same path accepts a normal image, so the refusals below come from the sizes and not from the setup.
  const accepted = await upload({ name: "ok.png", mimeType: "image/png", buffer: solidPng(200, 60, 60) });
  expect(accepted.status(), await accepted.text()).toBe(201);
  expect(await accepted.json()).toMatchObject({ key: expect.stringMatching(/^originals\//) });

  // A file one MiB over upload_max_filesize (64M); the whole request is still below post_max_size (70M).
  const fileTooLarge = await upload({ name: "huge.png", mimeType: "image/png", buffer: new Uint8Array(65 * MIB) });
  expect(fileTooLarge.status()).toBe(422);
  expect(await fileTooLarge.json()).toMatchObject({ detail: "Plik jest za duży." });

  // A request over post_max_size: PHP drops the whole body, so the controller reads the Content-Length instead.
  const requestTooLarge = await upload({ name: "enormous.png", mimeType: "image/png", buffer: new Uint8Array(71 * MIB) });
  expect(requestTooLarge.status()).toBe(422);
  expect(await requestTooLarge.json()).toMatchObject({ detail: "Plik jest za duży." });
});

test("support sees the list of products and a read-only form", async ({ page }) => {
  await loginStaff(page, staff.support);
  await openMenuItem(page, "Lista produktów", "Katalog", "Produkty");
  await expect(page.getByRole("heading", { level: 1, name: "Produkty" })).toBeVisible();
  await expect(page.getByTestId("product-row").first()).toBeVisible();
  await expect(page.getByTestId("add-product"), "support cannot add products").toHaveCount(0);

  await page.getByTestId("product-link").first().click();
  await expect(page.getByTestId("read-only-note")).toBeVisible();
  await expect(page.getByTestId("name")).toBeDisabled();
  await expect(page.getByTestId("slug")).toBeDisabled();
  await expect(page.getByRole("combobox", { name: "Klasa podatkowa" })).toBeDisabled();
  await expect(page.getByRole("region", { name: "Zapis formularza" }), "a read-only form has no save bar").toHaveCount(0);
  for (const action of ["save", "publish", "unpublish", "add-images", "add-axis", "add-variant"]) {
    await expect(page.getByTestId(action), `the read-only form has no "${action}"`).toHaveCount(0);
  }

  await page.goto(`${urls.admin}/products/new`);
  await expect(page, "the new product form is not for support").toHaveURL(/\/products$/);
});

test("product form: the save bar keeps a focused field in view, and leaving with unsaved changes asks first", async ({ page }) => {
  // A short window, so that the form is taller than the screen and the focus has to scroll.
  await page.setViewportSize({ width: 1280, height: 500 });
  await loginStaff(page, staff.marketing);
  await openMenuItem(page, "Lista produktów", "Katalog", "Produkty");
  await page.getByTestId("add-product").click();
  await expect(page.getByRole("heading", { level: 1, name: "Nowy produkt" })).toBeVisible();

  const bar = page.getByRole("region", { name: "Zapis formularza" });
  await expect(bar, "a new product has the bar from the start").toContainText("Nowy produkt");
  await expect(bar.getByRole("button", { name: "Anuluj" })).toBeVisible();

  // The bar sticks to the bottom of the screen; a field that the focus scrolled to is above it, never under it.
  for (const control of [page.getByTestId("add-axis"), page.getByRole("button", { name: "Dodaj zdjęcia" })]) {
    await control.focus();
    await expect(control).toBeFocused();
    const field = await control.boundingBox();
    const barBox = await bar.boundingBox();
    expect(field && barBox && field.y + field.height <= barBox.y + 1, `the focused control (${JSON.stringify(field)}) is above the save bar (${JSON.stringify(barBox)})`).toBe(true);
  }

  // Leaving with an unsaved change asks; „Zostań” keeps the form.
  await page.getByLabel("Nazwa", { exact: true }).fill("Zz E2E niezapisany");
  await expect(bar).toContainText("Nowy produkt");
  await page.getByRole("navigation", { name: "Menu główne" }).getByRole("link", { name: "Lista produktów", exact: true }).click();
  const dialog = page.getByRole("alertdialog", { name: "Masz niezapisane zmiany" });
  await expect(dialog).toBeVisible();
  await expect(dialog.getByRole("button", { name: "Zostań" })).toBeFocused();
  await dialog.getByRole("button", { name: "Zostań" }).click();
  await expect(dialog).toHaveCount(0);
  await expect(page).toHaveURL(/\/products\/new$/);
  await expect(page.getByLabel("Nazwa", { exact: true })).toHaveValue("Zz E2E niezapisany");

  // „Opuść bez zapisu” goes on to the list.
  await page.getByRole("navigation", { name: "Menu główne" }).getByRole("link", { name: "Lista produktów", exact: true }).click();
  await page.getByRole("alertdialog", { name: "Masz niezapisane zmiany" }).getByRole("button", { name: "Opuść bez zapisu" }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Produkty" })).toBeVisible();
});
