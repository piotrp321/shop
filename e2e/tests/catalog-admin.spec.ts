import { expect, test, type APIRequestContext, type Locator, type Page, type Route } from "@playwright/test";
import { staff } from "../support/accounts";
import { createCategory, readToken, removeTestCategories, SLUG_PREFIX, testCategorySlugs } from "../support/categories";
import { createDraftInPanel, newProduct, publishInPanel, receiveStockInPanel, solidPng, unpublishInPanel } from "../support/catalog-admin";
import { documentWidths, expectControlsReachable, expectWithinWidth, viewports, WCAG_TAGS } from "../support/panel-layout";
import { openMenuItem } from "../support/panel-menu";
import { closePanelContexts, go, inState, newPanelPage, type PanelState, signInPanelRoles, violationsOf } from "../support/panel-states";
import { toasts } from "../support/promotions";
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

  // A request over post_max_size: PHP drops the whole body, so the controller reads the Content-Length instead. The file is
  // 70 MiB, so the body is just over post_max_size and under the 71 MiB that Caddy allows (request-body-limit.spec.ts).
  const requestTooLarge = await upload({ name: "enormous.png", mimeType: "image/png", buffer: new Uint8Array(70 * MIB) });
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

type PanelCategory = { id: string; slug: string; path: string; version: number; children?: PanelCategory[] };

/** A category of the panel tree by its path (a fixed path of the demo catalog), or the failure naming the path. */
async function panelCategoryByPath(request: APIRequestContext, headers: Record<string, string>, path: string): Promise<PanelCategory> {
  const response = await request.get(`${urls.api}/api/v1/admin/categories`, { headers });
  expect(response.status(), await response.text()).toBe(200);
  const stack = (await response.json()) as PanelCategory[];
  for (let node = stack.pop(); node !== undefined; node = stack.pop()) {
    if (node.path === path) return node;
    stack.push(...(node.children ?? []));
  }
  throw new Error(`the panel tree has no category "${path}"`);
}

test("an old category address follows the renamed category: 308 to the new address with the query, an unknown address is 404", async ({ page, request }) => {
  test.setTimeout(8 * EVENT_WAIT_MS + 60_000);
  const bearer = waitForBearerToken(page);
  await loginStaff(page, staff.marketing);
  const headers = { Authorization: `Bearer ${await bearer}` };

  // A category of its own on the 4th level, below a leaf of the demo tree: the menu shows 3 levels, the sections of the
  // home page and the footer only the roots, so no other test sees it, and the demo slugs stay as they are.
  const parent = await panelCategoryByPath(request, headers, "bieganie/buty/szosowe");
  const suffix = Date.now().toString(36);
  const oldSlug = `e2e-stara-${suffix}`;
  const newSlug = `e2e-nowa-${suffix}`;
  const oldPath = `${parent.path}/${oldSlug}`;
  const newPath = `${parent.path}/${newSlug}`;
  const query = "?sort=cena-malejaco&o=Kolor%3Aczarne&o=Rozmiar%3A42&page=2";

  const created = await request.post(`${urls.api}/api/v1/admin/categories`, { headers, data: { name: `E2E kategoria ${suffix}`, slug: oldSlug, parentId: parent.id } });
  expect(created.status(), await created.text()).toBe(201);
  let category = (await created.json()) as { id: string; version: number };
  let deleted = false;
  const locationOf = async (address: string) => {
    const response = await request.get(`${urls.store}${address}`, { maxRedirects: 0 });
    const header = response.headers().location;
    const target = header === undefined ? null : new URL(header, urls.store);
    return { status: response.status(), location: target === null ? null : target.pathname + target.search };
  };

  try {
    expect(await locationOf(`/c/${parent.path}/${oldSlug}-nigdy`), "an address that was never a category is a 404, not a redirect").toMatchObject({ status: 404 });

    // Wait until the store has the new category in its tree. A 308 after the change of the slug proves a fresh tree only
    // if the tree before it had the old slug: on a tree from before the creation the old address is outside it, the
    // redirect (read after the change) leads to the new one, and the new one is outside that stale tree as well.
    await expect
      .poll(async () => (await locationOf(`/c/${oldPath}`)).status, { message: `the new category /c/${oldPath} was not in the tree of the store within 20 s`, timeout: EVENT_WAIT_MS, intervals: [500] })
      .toBe(200);

    const revised = await request.put(`${urls.api}/api/v1/admin/categories/${category.id}`, {
      headers,
      data: { name: `E2E kategoria ${suffix}`, slug: newSlug, version: category.version },
    });
    expect(revised.status(), await revised.text()).toBe(200);
    category = (await revised.json()) as { id: string; version: number };

    // The change reaches the store through the outbox, the purge of Varnish and the revalidation of the tag `catalog`.
    // Until then the tree still has the old slug (200); the 308 means that the store reads the tree after the change.
    await expect
      .poll(() => locationOf(`/c/${oldPath}${query}`), { message: `the old address did not redirect to /c/${newPath} with the query within 20 s`, timeout: EVENT_WAIT_MS, intervals: [500] })
      .toEqual({ status: 308, location: `/c/${newPath}${query}` });

    // The new address is the page of the category; the name and the breadcrumbs show it.
    const target = await page.goto(`/c/${oldPath}?page=1`);
    expect(page.url(), "the browser follows the redirect to the new address").toBe(`${urls.store}/c/${newPath}?page=1`);
    expect(target?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1, name: `E2E kategoria ${suffix}` })).toBeVisible();

    // A deleted category takes its old addresses with it.
    const removed = await request.delete(`${urls.api}/api/v1/admin/categories/${category.id}?version=${category.version}`, { headers });
    expect(removed.status(), await removed.text()).toBe(204);
    deleted = true;
    await expect
      .poll(() => locationOf(`/c/${oldPath}${query}`), { message: "the old address of the deleted category was not a 404 within 20 s", timeout: EVENT_WAIT_MS, intervals: [500] })
      .toMatchObject({ status: 404 });
    // The new address is a 404 once the store has the tree without the category (until then the tree still lists it).
    await expect
      .poll(async () => (await locationOf(`/c/${newPath}`)).status, { message: `the address /c/${newPath} of the deleted category was not a 404 within 20 s`, timeout: EVENT_WAIT_MS, intervals: [500] })
      .toBe(404);
  } finally {
    if (!deleted) {
      await request.delete(`${urls.api}/api/v1/admin/categories/${category.id}?version=${category.version}`, { headers });
    }
  }
});

// The category screen (docs/features/admin-features/spec.md, „Kategorie”). The tests make categories of their own, all
// with the slug prefix of `support/categories.ts`, and remove them at the end: the demo categories are not changed, so the
// tree of the store is as it was for the files that run after this one.
test.describe("categories screen", () => {
  const suffix = Date.now().toString(36);
  const rootName = `Zz E2E Kategorie ${suffix}`;
  const rootSlug = `${SLUG_PREFIX}${suffix}`;

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    await signInPanelRoles(browser, ["admin", "support"]);
  });

  test.afterAll(async () => {
    test.setTimeout(60_000);
    try {
      const page = await newPanelPage("admin", viewports.desktop);
      try {
        await removeTestCategories(page.context().request, await readToken(page));
      } finally {
        await page.close();
      }
    } finally {
      await closePanelContexts();
    }
  });

  // A node is named by its name, its slug and its count: the name followed by a space (a longer name that starts with it is another node).
  const treeItem = (page: Page, name: string) => page.getByRole("treeitem", { name: new RegExp(`^${name} `) });
  const toast = (page: Page) => toasts(page);

  /** Fills the window of a category (Nazwa and Slug) and presses its button. */
  async function submitCategory(page: Page, windowName: string, name: string | null, slug: string | null, button: string) {
    const window = page.getByRole("dialog", { name: windowName });
    await expect(window).toBeVisible();
    if (name !== null) await window.getByLabel("Nazwa").fill(name);
    if (slug !== null) await window.getByLabel("Slug").fill(slug);
    await window.getByRole("button", { name: button, exact: true }).click();
  }

  test("admin adds, renames, moves and deletes categories, and an error is said where it belongs", async () => {
    test.setTimeout(180_000);
    const alfa = `Alfa ${suffix}`;
    const beta = `Beta ${suffix}`;
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, "/");
      await openMenuItem(page, "Kategorie", "Katalog");
      await expect(page.getByRole("heading", { level: 1, name: "Kategorie" })).toBeVisible();
      const tree = page.getByRole("tree", { name: "Drzewo kategorii" });
      await expect(tree.getByRole("treeitem", { name: /^Turystyka turystyka / }), "the tree of the demo data, with the count").toBeVisible();
      await expect(tree.getByRole("treeitem", { name: /^Turystyka turystyka / }).getByTestId("category-count")).toContainText(/^Produkty: \d+|ponad/);

      // A root, then two subcategories of it.
      await page.getByTestId("add-category").click();
      await submitCategory(page, "Dodaj kategorię", rootName, rootSlug, "Dodaj");
      await expect(toast(page)).toContainText(`Dodano kategorię: ${rootName}`);
      await expect(treeItem(page, rootName)).toBeVisible();

      const subcategories: [string, string][] = [
        [alfa, `${rootSlug}-alfa`],
        [beta, `${rootSlug}-beta`],
      ];
      for (const [name, slug] of subcategories) {
        await treeItem(page, rootName).getByRole("button", { name: `Dodaj podkategorię: ${rootName}` }).click();
        await submitCategory(page, "Dodaj podkategorię", name, slug, "Dodaj");
        await expect(treeItem(page, name), "the parent is open, so the new subcategory is seen").toBeVisible();
        await expect(treeItem(page, name)).toHaveAttribute("aria-level", "2");
      }

      // A taken slug is said at the field of the slug, with the focus in it; nothing else changed.
      await treeItem(page, rootName).getByRole("button", { name: `Dodaj podkategorię: ${rootName}` }).click();
      const window = page.getByRole("dialog", { name: "Dodaj podkategorię" });
      await submitCategory(page, "Dodaj podkategorię", `Gamma ${suffix}`, `${rootSlug}-alfa`, "Dodaj");
      await expect(window.getByTestId("slug-error")).toBeVisible();
      await expect(window.getByLabel("Slug")).toBeFocused();
      await window.getByRole("button", { name: "Anuluj" }).click();
      await expect(window).toHaveCount(0);

      // A new name only: no note about the address; a new slug: the old address redirects.
      await treeItem(page, alfa).getByRole("button", { name: `Edytuj: ${alfa}` }).click();
      await expect(page.getByRole("dialog", { name: "Edytuj kategorię" }).getByLabel("Slug")).toHaveValue(`${rootSlug}-alfa`);
      await submitCategory(page, "Edytuj kategorię", `${alfa} nowa`, null, "Zapisz");
      await expect(toast(page)).toContainText(`Zapisano kategorię: ${alfa} nowa`);
      await expect(toast(page)).not.toContainText("przekierowuje");
      await treeItem(page, `${alfa} nowa`).getByRole("button", { name: `Edytuj: ${alfa} nowa` }).click();
      await submitCategory(page, "Edytuj kategorię", null, `${rootSlug}-alfa-2`, "Zapisz");
      await expect(toast(page)).toContainText("Stary adres przekierowuje na nowy.");

      // Move under the other subcategory, which makes the tree three levels deep.
      await treeItem(page, `${alfa} nowa`).getByRole("button", { name: `Przenieś do…: ${alfa} nowa` }).click();
      const moveWindow = page.getByRole("dialog", { name: `Przenieś kategorię ${alfa} nowa` });
      await moveWindow.getByRole("combobox", { name: "Nowa kategoria nadrzędna" }).click();
      await expect(page.getByRole("option", { name: `${rootName} › ${alfa} nowa` }), "the category itself cannot be chosen").toHaveAttribute("aria-disabled", "true");
      await page.getByRole("option", { name: `${rootName} › ${beta}`, exact: true }).click();
      await moveWindow.getByRole("button", { name: "Przenieś", exact: true }).click();
      await expect(toast(page)).toContainText(`Przeniesiono kategorię: ${alfa} nowa. Stary adres przekierowuje na nowy.`);
      await expect(treeItem(page, `${alfa} nowa`)).toHaveAttribute("aria-level", "3");

      // A category with a subcategory is not deleted: the window says why and stays open.
      await treeItem(page, rootName).getByRole("button", { name: `Usuń: ${rootName}` }).click();
      const confirm = page.getByRole("alertdialog", { name: `Usunąć kategorię ${rootName}?` });
      await confirm.getByRole("button", { name: "Usuń kategorię" }).click();
      await expect(confirm.getByRole("alert")).not.toBeEmpty();
      await expect(confirm.getByRole("alert"), "the refusal is read: the focus is on it, not lost on the page").toBeFocused();
      await confirm.getByRole("button", { name: "Wróć" }).click();
      await expect(confirm).toHaveCount(0);

      // Empty categories are deleted, the deepest first.
      for (const name of [`${alfa} nowa`, beta, rootName]) {
        await treeItem(page, name).getByRole("button", { name: `Usuń: ${name}` }).click();
        await page.getByRole("alertdialog", { name: `Usunąć kategorię ${name}?` }).getByRole("button", { name: "Usuń kategorię" }).click();
        await expect(toast(page)).toContainText(`Usunięto kategorię: ${name}`);
        await expect(treeItem(page, name)).toHaveCount(0);
      }
      await expect(page.getByRole("heading", { level: 1, name: "Kategorie" }), "the focus of a deleted row is on the heading").toBeFocused();
      expect(await testCategorySlugs(page.context().request, await readToken(page)), "nothing of the test is left").toEqual([]);
    } finally {
      await page.close();
    }
  });

  /** Presses Tab (or Shift+Tab) until the focus is on the control with this name (its aria-label or its text). */
  async function tabTo(page: Page, name: string, key = "Tab") {
    for (let step = 0; step < 40; step++) {
      await page.keyboard.press(key);
      const focused = await page.evaluate(() => (document.activeElement?.getAttribute("aria-label") ?? document.activeElement?.textContent ?? "").trim());
      if (focused === name) return;
    }
    throw new Error(`the focus did not reach "${name}" with ${key}`);
  }

  test("every action is done with the keyboard alone: the arrows of the tree, then the buttons of the node", async () => {
    test.setTimeout(180_000);
    const keyboardSlug = `${rootSlug}-klawiatura`;
    const first = `Pierwsza ${suffix}`;
    const second = `Druga ${suffix}`;
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      const token = await readToken(page);
      const rootId = await createCategory(page.context().request, token, { name: `Zz E2E Klawiatura ${suffix}`, slug: keyboardSlug, parentId: null });
      await createCategory(page.context().request, token, { name: first, slug: `${keyboardSlug}-pierwsza`, parentId: rootId });
      await createCategory(page.context().request, token, { name: second, slug: `${keyboardSlug}-druga`, parentId: rootId });
      await go(page, "/categories");
      const tree = page.getByRole("tree", { name: "Drzewo kategorii" });
      await expect(treeItem(page, second)).toBeVisible();

      // The tree: one stop for the Tab key, the arrows and End move inside it.
      await tree.getByRole("treeitem").first().focus();
      await page.keyboard.press("End");
      await expect(treeItem(page, second), "End goes to the last node").toBeFocused();
      await page.keyboard.press("ArrowUp");
      await expect(treeItem(page, first)).toBeFocused();
      await page.keyboard.press("ArrowLeft");
      await expect(treeItem(page, `Zz E2E Klawiatura ${suffix}`), "Left goes to the parent").toBeFocused();
      await page.keyboard.press("ArrowLeft");
      await expect(treeItem(page, `Zz E2E Klawiatura ${suffix}`), "Left closes an open node").toHaveAttribute("aria-expanded", "false");
      await page.keyboard.press("ArrowRight");
      await expect(treeItem(page, second)).toBeVisible();
      await page.keyboard.press("End");
      await expect(treeItem(page, second)).toBeFocused();

      // The buttons of the node: up, said in a toast; the focus stays on a button of the node.
      await tabTo(page, `W górę: ${second}`);
      await page.keyboard.press("Enter");
      await expect(toast(page)).toContainText(`Zmieniono kolejność: ${second}`);
      await expect(page.getByRole("button", { name: `W dół: ${second}` }), "the focus follows the node that moved").toBeFocused();

      // Edit: the window opens on the name, Enter saves, the focus is back on the button.
      await tabTo(page, `Edytuj: ${second}`, "Shift+Tab");
      await page.keyboard.press("Enter");
      await expect(page.getByLabel("Nazwa")).toBeFocused();
      await page.keyboard.press("End");
      await page.keyboard.type(" 2");
      await page.keyboard.press("Enter");
      await expect(toast(page)).toContainText(`Zapisano kategorię: ${second} 2`);
      await expect(page.getByRole("button", { name: `Edytuj: ${second} 2` })).toBeFocused();

      // Move to the top level: the picker is used with the keys only.
      await tabTo(page, `Przenieś do…: ${second} 2`);
      await page.keyboard.press("Enter");
      const combobox = page.getByRole("combobox", { name: "Nowa kategoria nadrzędna" });
      await expect(combobox).toBeFocused();
      await page.keyboard.type("Poziom");
      await page.keyboard.press("ArrowDown");
      await page.keyboard.press("Enter");
      await tabTo(page, "Przenieś");
      await page.keyboard.press("Enter");
      await expect(toast(page)).toContainText(`Przeniesiono kategorię: ${second} 2. Stary adres przekierowuje na nowy.`);
      await expect(treeItem(page, `${second} 2`)).toHaveAttribute("aria-level", "1");

      // Delete: „Wróć” has the focus, Tab goes to the action.
      await tabTo(page, `Usuń: ${second} 2`);
      await page.keyboard.press("Enter");
      await expect(page.getByRole("button", { name: "Wróć" })).toBeFocused();
      await page.keyboard.press("Tab");
      await expect(page.getByRole("button", { name: "Usuń kategorię" })).toBeFocused();
      await page.keyboard.press("Enter");
      await expect(toast(page)).toContainText(`Usunięto kategorię: ${second} 2`);
      await expect(page.getByRole("heading", { level: 1, name: "Kategorie" })).toBeFocused();
    } finally {
      await page.close();
    }
  });

  test("dragging a node onto another gives the command of the menu: it becomes its last subcategory", async () => {
    test.setTimeout(120_000);
    const dragSlug = `${rootSlug}-przeciaganie`;
    const source = `Przenoszona ${suffix}`;
    const target = `Cel ${suffix}`;
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      const token = await readToken(page);
      const rootId = await createCategory(page.context().request, token, { name: `Zz E2E Przeciaganie ${suffix}`, slug: dragSlug, parentId: null });
      await createCategory(page.context().request, token, { name: source, slug: `${dragSlug}-zrodlo`, parentId: rootId });
      await createCategory(page.context().request, token, { name: target, slug: `${dragSlug}-cel`, parentId: rootId });
      await go(page, "/categories");
      await expect(treeItem(page, source)).toHaveAttribute("aria-level", "2");

      // The middle of the target is „inside”: the node becomes its last child (the same command as „Przenieś do…”).
      await treeItem(page, source).dragTo(treeItem(page, target), { targetPosition: { x: 200, y: (await treeItem(page, target).boundingBox())!.height / 2 } });
      await expect(toast(page)).toContainText(`Przeniesiono kategorię: ${source}. Stary adres przekierowuje na nowy.`);
      await expect(treeItem(page, source)).toHaveAttribute("aria-level", "3");

      // The tree of the API agrees: the node is under the target.
      const response = await page.context().request.get(`${urls.api}/api/v1/admin/categories`, { headers: { Authorization: `Bearer ${await readToken(page)}` } });
      const roots = (await response.json()) as { slug: string; children: { slug: string; children: { slug: string }[] }[] }[];
      const moved = roots.find((root) => root.slug === dragSlug)!.children.find((child) => child.slug === `${dragSlug}-cel`)!.children;
      expect(moved.map((child) => child.slug)).toEqual([`${dragSlug}-zrodlo`]);
    } finally {
      await page.close();
    }
  });

  test("support reads the tree and has neither the actions nor „Dodaj kategorię”", async () => {
    const page = await newPanelPage("support", viewports.desktop);
    try {
      await go(page, "/categories");
      await expect(page.getByRole("tree", { name: "Drzewo kategorii" }).getByRole("treeitem").first()).toBeVisible();
      await expect(page.getByTestId("add-category")).toHaveCount(0);
      await expect(page.getByRole("button", { name: /^(Edytuj|Usuń|Przenieś do…|Dodaj podkategorię):/ })).toHaveCount(0);
      await expect(page.getByRole("treeitem").first()).not.toHaveAttribute("draggable", "true");
    } finally {
      await page.close();
    }
  });

  // #572, #627: a click on an option gave the focus to the page (out of the window of a move) and left the field pointing
  // at the removed option. The tests choose an option and change nothing else: the move is not confirmed.
  test("the window of a move keeps the focus inside after an option is clicked, filters by the next typed character and has no aria-activedescendant of a removed option", async () => {
    test.setTimeout(90_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, "/categories");
      await expect(page.getByRole("treeitem").first()).toBeVisible();
      await page.getByRole("treeitem").first().getByRole("button", { name: /^Przenieś do…:/ }).click();
      const window = page.getByRole("dialog", { name: /^Przenieś kategorię/ });
      const field = window.getByRole("combobox", { name: "Nowa kategoria nadrzędna" });

      await field.click();
      await page.getByRole("option", { name: "Poziom główny" }).click();
      await expect(field, "the field keeps the focus after a click on an option").toBeFocused();
      await expect(window.locator(":focus"), "the focus stays inside the window").toHaveCount(1);
      expect(await violationsOf(page, null, ["aria-valid-attr-value"]), "axe after a choice by the mouse").toEqual([]);

      await page.keyboard.press("ControlOrMeta+A");
      await page.keyboard.type("tur");
      const options = page.getByRole("option");
      await expect(options.first()).toBeVisible();
      for (const name of await options.evaluateAll((elements) => elements.map((element) => element.getAttribute("aria-label") ?? ""))) {
        expect(name.toLocaleLowerCase("pl"), "the list is filtered by the typed text").toContain("tur");
      }

      await field.press("ArrowDown");
      await field.press("Enter");
      await expect(page.getByRole("option")).toHaveCount(0);
      await expect(field).toBeFocused();
      expect(await violationsOf(page, null, ["aria-valid-attr-value"]), "axe after a choice with Enter").toEqual([]);

      await window.getByRole("button", { name: "Anuluj" }).click();
      await expect(window).toHaveCount(0);
    } finally {
      await page.close();
    }
  });

  test("the field „Kategoria główna” of a new product keeps the focus after an option is clicked, filters by the next typed character and has no aria-activedescendant of a removed option", async () => {
    test.setTimeout(90_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, "/products/new");
      await expect(page.getByRole("heading", { level: 1, name: "Nowy produkt" })).toBeVisible();
      const field = page.getByRole("combobox", { name: "Kategoria główna" });
      await expect(field).toBeEnabled();

      await field.click();
      await page.getByRole("option", { name: /Kajaki/ }).first().click();
      await expect(field, "the field keeps the focus after a click on an option").toBeFocused();
      expect(await violationsOf(page, null, ["aria-valid-attr-value"]), "axe after a choice by the mouse").toEqual([]);

      await page.keyboard.press("ControlOrMeta+A");
      await page.keyboard.type("kaj");
      const options = page.getByRole("option");
      await expect(options.first()).toBeVisible();
      for (const name of await options.evaluateAll((elements) => elements.map((element) => element.getAttribute("aria-label") ?? ""))) {
        expect(name.toLocaleLowerCase("pl"), "the list is filtered by the typed text").toContain("kaj");
      }

      await field.press("ArrowDown");
      await field.press("Enter");
      await expect(page.getByRole("option")).toHaveCount(0);
      expect(await violationsOf(page, null, ["aria-valid-attr-value"]), "axe after a choice with Enter").toEqual([]);
    } finally {
      // Nothing is saved; the form has unsaved changes, so the page is closed without a request.
      await page.close();
    }
  });

  // The screen and its windows, opened on a demo category (opening a window changes nothing).
  const opensTree = async (page: Page) => {
    await go(page, "/categories");
    await expect(page.getByRole("treeitem").first()).toBeVisible();
  };
  const states: PanelState[] = [
    {
      name: "the tree",
      role: "admin",
      open: async (page) => {
        await opensTree(page);
        return null;
      },
    },
    {
      name: "the window of a new category",
      role: "admin",
      overlay: true,
      open: async (page) => {
        await opensTree(page);
        await page.getByTestId("add-category").click();
        const window = page.getByRole("dialog", { name: "Dodaj kategorię" });
        await expect(window.getByLabel("Nazwa")).toBeFocused();
        return window;
      },
    },
    {
      name: "the window of a new category with errors",
      role: "admin",
      overlay: true,
      open: async (page) => {
        await opensTree(page);
        await page.getByTestId("add-category").click();
        const window = page.getByRole("dialog", { name: "Dodaj kategorię" });
        await window.getByRole("button", { name: "Dodaj", exact: true }).click();
        await expect(window.getByTestId("name-error")).toBeVisible();
        return window;
      },
    },
    {
      name: "the window of a move",
      role: "admin",
      overlay: true,
      open: async (page) => {
        await opensTree(page);
        await page.getByRole("treeitem").first().getByRole("button", { name: /^Przenieś do…:/ }).click();
        const window = page.getByRole("dialog", { name: /^Przenieś kategorię/ });
        await window.getByRole("combobox").click();
        await expect(page.getByRole("option", { name: "Poziom główny" })).toBeVisible();
        return window;
      },
    },
    {
      name: "the confirmation of a delete",
      role: "admin",
      overlay: true,
      open: async (page) => {
        await opensTree(page);
        await page.getByRole("treeitem").first().getByRole("button", { name: /^Usuń:/ }).click();
        const window = page.getByRole("alertdialog", { name: /^Usunąć kategorię/ });
        await expect(window).toBeVisible();
        return window;
      },
    },
  ];

  for (const state of states) {
    test(`axe finds no violation of WCAG 2.2 AA at 1280 and 768 px: ${state.name}`, async () => {
      test.setTimeout(90_000);
      for (const size of [viewports.desktop, viewports.tablet]) {
        await inState(state, size, async (page) => {
          expect(await violationsOf(page, WCAG_TAGS), `axe at ${size.width} px`).toEqual([]);
        });
      }
    });

    test(`at 320 px nothing scrolls sideways and every control is reachable: ${state.name}`, async () => {
      test.setTimeout(90_000);
      await inState(state, viewports.reflow, async (page, overlay) => {
        const widths = await documentWidths(page);
        expect(widths.scrollWidth, "the document does not scroll sideways").toBe(widths.clientWidth);
        await expectControlsReachable(page, overlay);
        const after = await documentWidths(page);
        expect(after.scrollWidth).toBe(after.clientWidth);
      });
    });
  }
});

// ADMIN-UX-FIXES stage 2 (B2, B3): the form of a product does not lose what the staff member types while a request runs.
// The answers of the save and of the reads are given by the test (`page.route`), so no product is written; a request the
// test holds is always answered or aborted at the end, so that it never reaches the API after the test.
test.describe("the requests of the product form (stage 2)", () => {
  let productId = "";
  const PRODUCT_URL = () => `**/api/v1/admin/products/${productId}`;

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    await signInPanelRoles(browser, ["admin"]);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, "/products");
      const link = page.getByTestId("product-link").first();
      await expect(link, "the list has a demo product").toBeVisible();
      productId = (await link.getAttribute("href"))!.split("/").pop()!;
    } finally {
      await page.close();
    }
  });

  test.afterAll(closePanelContexts);

  /** Whatever the test did, a read it holds is let go at the end. */
  let releasers: (() => void)[] = [];

  test.afterEach(() => {
    releasers.forEach((release) => release());
    releasers = [];
  });

  /**
   * Opens the product with the PUT of its form answered by `answerPut` (the request never goes on to the API) and the reads
   * after the first one answered by `answerRead`; returns the product as the API sent it.
   */
  async function openWith(
    page: Page,
    answerPut: (route: Route, product: Record<string, unknown>, index: number) => Promise<void>,
    answerRead?: (route: Route, response: Awaited<ReturnType<Route["fetch"]>>) => Promise<void>,
  ) {
    let reads = 0;
    let puts = 0;
    let product: Record<string, unknown> = {};
    await page.route(PRODUCT_URL(), async (route) => {
      const method = route.request().method();
      if (method === "GET") {
        const response = await route.fetch();
        if (reads++ === 0 || answerRead === undefined) {
          product = (await response.json()) as Record<string, unknown>;
          await route.fulfill({ response });
        } else {
          await answerRead(route, response);
        }
        return;
      }
      if (method !== "PUT") {
        await route.fallback();
        return;
      }
      await answerPut(route, product, puts++);
    });
    await go(page, `/products/${productId}`);
    await expect(page.getByTestId("name")).toBeVisible();
    return product;
  }

  test("(b) the fields are off while the answer of the save is 3 s late, and the answer gives the toast", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openWith(page, async (route, product) => {
        const body = route.request().postDataJSON() as { shortDescription: string };
        await new Promise((resolve) => setTimeout(resolve, 3_000));
        await route.fulfill({ status: 200, json: { ...product, shortDescription: body.shortDescription, version: (product.version as number) + 1 } }).catch(() => undefined);
      });
      const description = page.getByTestId("short-description");
      await description.fill("Opis wpisany przed zapisem.");

      await page.getByTestId("save").click();

      await expect(description, "the field is off while the save waits for its answer").toBeDisabled();
      await expect(page.getByTestId("name")).toBeDisabled();
      await expect(page.getByTestId("slug")).toBeDisabled();
      await expect(page.getByTestId("image-alt").first()).toBeDisabled();
      await expect(page.getByTestId("variant-value").first()).toBeDisabled();
      await expect(toasts(page).getByText("Produkt zapisany.")).toBeVisible({ timeout: 10_000 });
      await expect(description).toBeEnabled();
      await expect(description).toHaveValue("Opis wpisany przed zapisem.");
    } finally {
      await page.close();
    }
  });

  test("(c) „Odrzuć moje zmiany i pokaż stan z serwera” after a 409 keeps the fields off while the read is held, and the server state replaces the draft after its answer", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      let release!: () => void;
      const released = new Promise<void>((resolve) => (release = resolve));
      releasers.push(release);
      await openWith(
        page,
        async (route) => {
          await route.fulfill({
            status: 409,
            contentType: "application/problem+json",
            json: { type: "tag:piotrp321.github.io,2026:shop/problems/product-modified", title: "Conflict", status: 409, detail: "Produkt został zmieniony." },
          });
        },
        async (route, response) => {
          await released;
          await route.fulfill({ response }).catch(() => undefined);
        },
      );
      const name = page.getByTestId("name");
      const original = await name.inputValue();
      await name.fill(`${original} (zmiana)`);
      await page.getByTestId("save").click();

      await expect(page.getByTestId("form-error")).toContainText("Twoje zmiany zostają w formularzu.");
      await expect(name).toHaveValue(`${original} (zmiana)`);
      await page.getByTestId("restore-state").click();

      await expect(page.getByTestId("safety-status")).toHaveText("Odświeżamy…");
      await expect(name, "the fields are off while the read waits for its answer").toBeDisabled();
      await expect(page.getByTestId("short-description")).toBeDisabled();
      await expect(page.getByTestId("variant-value").first()).toBeDisabled();
      await expect(page.getByTestId("save")).toBeDisabled();

      release();

      await expect(name).toBeEnabled();
      await expect(name).toHaveValue(original);
    } finally {
      await page.close();
    }
  });
});

// PRODUCT-DATA: the brand, the description, the parameters of a product and the codes of its variants (docs/features/product-data/spec.md,
// criterion 9). The panel sends them as they are in the draft, and only the comparison of „Sprawdź stan” after a save without
// an answer goes through its copy of the rule of texts of the API (T1–T3, and the capitals of the SKU). The copy and the API
// can differ (the classes of characters of PCRE and of JavaScript do), and this is the one test that puts the two side by
// side: a save that reaches the API, an answer the browser never gets, and the check that must find the saved product equal
// to the form.
test.describe("the brand, the description, the parameters of a product and the codes of its variants", () => {
  const MATCHES = "Stan na serwerze odpowiada formularzowi.";
  const LINE_FEED: [string, string] = ["U+000A", "\n"];

  /** The characters the API trims from the ends of a text (T2) that a one-line field can hold: the list of criterion 9b. */
  const EDGE_CHARACTERS: [string, string][] = [
    ["a space", " "],
    ["U+00A0", "\u{A0}"],
    ["U+2003", "\u{2003}"],
    ["U+202F", "\u{202F}"],
    ["U+3000", "\u{3000}"],
    ["U+180E", "\u{180E}"],
    ["U+200B", "\u{200B}"],
    ["U+2060", "\u{2060}"],
    ["U+FEFF", "\u{FEFF}"],
    ["U+00AD", "\u{AD}"],
  ];
  const ENGLAND = "\u{1F3F4}\u{E0067}\u{E0062}\u{E0065}\u{E006E}\u{E0067}\u{E007F}";

  // The SKU is unique among all the variants: one per run, so a rerun on the same stack never meets the code of an earlier one.
  const RUN = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`;
  const SKU_TYPED = `zz-e2e-${RUN}/a_1.b`;
  const SKU_STORED = SKU_TYPED.toUpperCase();
  const EAN = "5901234123457";
  // The field takes 14 characters, also when a test fills it: an EAN of 8 digits has room for the characters at both its edges.
  const EAN_SHORT = "59012344";

  /**
   * A field of the product form whose text the API normalizes (the fields of the comparison). `kept` is what the API stores
   * when the text has nothing to trim; `typed` what is typed when it differs (the SKU is typed in small letters and stored in
   * capitals); `edges` the characters trimmed from its ends; `inside` a text with characters that must stay inside it (a
   * code has none: its alphabet is closed).
   */
  type ContentField = { name: string; control: (page: Page) => Locator; kept: string; typed?: string; edges: [string, string][]; inside?: string };
  const FIELDS: ContentField[] = [
    { name: "brand", control: (page) => page.getByTestId("brand"), kept: "Marka", edges: EDGE_CHARACTERS, inside: `Ma\u{200B}rka \u{1F469}\u{200D}\u{1F4BB} ${ENGLAND}` },
    {
      name: "description",
      control: (page) => page.getByTestId("description"),
      kept: "Pierwszy akapit.\n\nDrugi akapit.",
      edges: [...EDGE_CHARACTERS, LINE_FEED],
      inside: `Opis \u{1F469}\u{200D}\u{1F4BB}\n\nZ\u{200B}drugim akapitem ${ENGLAND}`,
    },
    { name: "parameter name", control: (page) => page.getByTestId("parameter-name").first(), kept: "Waga", edges: EDGE_CHARACTERS, inside: `Wa\u{200B}ga \u{1F469}\u{200D}\u{1F4BB} ${ENGLAND}` },
    { name: "parameter value", control: (page) => page.getByTestId("parameter-value").first(), kept: "2 kg", edges: EDGE_CHARACTERS, inside: `2\u{200B} kg \u{1F469}\u{200D}\u{1F4BB} ${ENGLAND}` },
    { name: "SKU", control: (page) => page.getByTestId("variant-sku").first(), kept: SKU_STORED, typed: SKU_TYPED, edges: EDGE_CHARACTERS },
    { name: "EAN", control: (page) => page.getByTestId("variant-ean").first(), kept: EAN_SHORT, edges: EDGE_CHARACTERS },
  ];
  /** What the staff member types into the field, without the characters at its edges. */
  const typedOf = (field: ContentField): string => field.typed ?? field.kept;

  const PRODUCT_API = (id: string) => `**/api/v1/admin/products/${id}`;

  /** Fills every field of the list with its text, as given; the typed text goes to the field untouched. */
  async function fill(page: Page, texts: Map<ContentField, string>): Promise<void> {
    for (const [field, text] of texts) await field.control(page).fill(text);
  }

  /**
   * The save that the API does and the browser never hears of: the PUT goes to the API, its answer is thrown away. Returns the
   * statuses of the answers of the API, which the caller checks to prove the save reached it and was not refused.
   */
  async function loseAnswersOfSaves(page: Page, id: string): Promise<number[]> {
    const statuses: number[] = [];
    await page.route(PRODUCT_API(id), async (route) => {
      if (route.request().method() !== "PUT") {
        await route.fallback();
        return;
      }
      const response = await route.fetch();
      statuses.push(response.status());
      await route.abort("failed");
    });
    return statuses;
  }

  /** Saves what the form holds, the answer is lost, and „Sprawdź stan” must find the server equal to the form. */
  async function saveAndCheck(page: Page, statuses: number[], texts: Map<ContentField, string>, expected: Map<ContentField, string>, what: string): Promise<void> {
    const before = statuses.length;
    await fill(page, texts);
    await page.getByTestId("save").click();
    await expect(page.getByTestId("unknown-result"), `${what}: the form does not know whether the save was done`).toBeVisible();
    expect(statuses.length, `${what}: the save reached the API`).toBe(before + 1);
    expect(statuses.at(-1), `${what}: the API accepted the save (a 409 here means the form kept a stale version)`).toBe(200);

    await page.getByTestId("check-state").click();
    await expect(page.getByTestId("safety-status"), `${what}: „Sprawdź stan” says the server equals the form`).toHaveText(MATCHES);
    for (const [field, text] of expected) {
      await expect(field.control(page), `${what}: the ${field.name} is the text the API stored`).toHaveValue(text);
    }
    await expect(page.getByTestId("unknown-result")).toHaveCount(0);
  }

  test("a save the server did, whose answer is lost, is recognized by „Sprawdź stan” for every character the API trims, in the brand, the description and the parameters", async ({ page }) => {
    test.setTimeout(10 * 60_000);
    await loginStaff(page, staff.marketing);
    const id = await createDraftInPanel(page, newProduct("Sprawdz stan"));
    await page.getByTestId("add-parameter").click();
    await expect(page.getByTestId("parameter-row")).toHaveCount(1);
    const statuses = await loseAnswersOfSaves(page, id);

    // Every field holds the character at both its edges; the API stores the text without them.
    for (const edge of new Map([...FIELDS.flatMap((field) => field.edges)]).entries()) {
      const [label, character] = edge;
      const typed = new Map(FIELDS.map((field) => [field, field.edges.some(([, candidate]) => candidate === character) ? `${character}${typedOf(field)}${character}` : typedOf(field)] as const));
      await saveAndCheck(page, statuses, typed, new Map(FIELDS.map((field) => [field, field.kept] as const)), `the edges of ${label}`);
    }

    // The characters trimmed from the edge are the same mixed with spaces and the ones inside the text stay (a code has none inside).
    await saveAndCheck(
      page,
      statuses,
      new Map(FIELDS.map((field) => [field, ` \u{200B}${field.inside ?? typedOf(field)}\u{A0} `] as const)),
      new Map(FIELDS.map((field) => [field, field.inside ?? field.kept] as const)),
      "U+200B between letters, U+200D in an emoji and the tags of a flag at the end",
    );

    // A code without a visible character is no code (T3), and the server then has none.
    for (const field of FIELDS.filter((candidate) => candidate.name === "SKU" || candidate.name === "EAN")) {
      for (const [label, text] of [
        ["only white space", " \u{A0}\u{3000} \u{2003}"],
        ["only zero width characters", "\u{200B}\u{2060}\u{FEFF}\u{AD}"],
        ["only U+200D", "\u{200D}"],
      ] as const) {
        await saveAndCheck(page, statuses, new Map([[field, text]]), new Map([[field, ""]]), `a ${field.name} of ${label}`);
        await saveAndCheck(page, statuses, new Map([[field, typedOf(field)]]), new Map([[field, field.kept]]), `the ${field.name} after ${label}`);
      }
    }

    // A description without a visible character is no description (T3): only white space, only U+200D.
    const description = FIELDS[1]!;
    for (const [label, text] of [
      ["only white space", " \u{A0}\u{3000} \n \u{2003}"],
      ["only U+200D", "\u{200D}"],
    ] as const) {
      await saveAndCheck(page, statuses, new Map([[description, description.kept]]), new Map([[description, description.kept]]), `the description before ${label}`);
      await saveAndCheck(page, statuses, new Map([[description, text]]), new Map([[description, ""]]), `a description of ${label}`);
    }

    // The form is still the one of the product: the next save, whose answer arrives, is not refused.
    await page.unroute(PRODUCT_API(id));
    await FIELDS[0]!.control(page).fill("Koniec");
    await page.getByTestId("save").click();
    await expect(toasts(page).getByText("Produkt zapisany.")).toBeVisible();
    await expect(page.getByTestId("form-error")).toHaveCount(0);
  });

  test("a new row of the variants with codes and no identifier, whose save is lost, is recognized by „Sprawdź stan” too", async ({ page }) => {
    test.setTimeout(3 * 60_000);
    await loginStaff(page, staff.marketing);
    const id = await createDraftInPanel(page, newProduct("Nowy wiersz"));
    const statuses = await loseAnswersOfSaves(page, id);

    await page.getByTestId("add-variant").click();
    await expect(page.getByTestId("variant-row")).toHaveCount(2);
    await page.getByTestId("variant-value").nth(1).fill("biały");
    await page.getByTestId("variant-sku").nth(1).fill(`\u{A0}zz-e2e-${RUN}/row2\u{200B}`);
    await page.getByTestId("variant-ean").nth(1).fill("\u{3000}59012344\u{FEFF}");
    await page.getByTestId("save").click();
    await expect(page.getByTestId("unknown-result")).toBeVisible();
    expect(statuses.at(-1), "the API accepted the save").toBe(200);

    await page.getByTestId("check-state").click();

    await expect(page.getByTestId("safety-status"), "„Sprawdź stan” says the server equals the form").toHaveText(MATCHES);
    await expect(page.getByTestId("variant-sku").nth(1)).toHaveValue(`ZZ-E2E-${RUN.toUpperCase()}/ROW2`);
    await expect(page.getByTestId("variant-ean").nth(1)).toHaveValue("59012344");
    await expect(page.getByTestId("unknown-result")).toHaveCount(0);
  });

  test("a code with a character T1 forbids at the edge is a 422 at its field and nothing is saved: the panel does not trim it away", async ({ page, request }) => {
    test.setTimeout(3 * 60_000);
    const bearer = waitForBearerToken(page);
    await loginStaff(page, staff.marketing);
    const id = await createDraftInPanel(page, newProduct("Kod z tabulatorem"));
    const headers = { Authorization: `Bearer ${await bearer}` };
    const stored = async () => (await (await request.get(`${urls.api}/api/v1/admin/products/${id}`, { headers })).json()) as { version: number; variants: { sku: string; ean: string }[] };
    const before = await stored();
    const requests: { variants: { sku: string; ean: string }[] }[] = [];
    page.on("request", (sent) => {
      if (sent.method() === "PUT" && sent.url().includes(`/api/v1/admin/products/${id}`)) requests.push(sent.postDataJSON() as { variants: { sku: string; ean: string }[] });
    });
    const SKU_FORMAT = "Kod SKU może mieć od 1 do 40 znaków: litery A–Z, cyfry oraz znaki - _ . / i musi zaczynać się literą albo cyfrą.";
    const EAN_FORMAT = "Podaj poprawny kod EAN: 8, 12, 13 albo 14 cyfr z cyfrą kontrolną.";

    for (const [label, character] of [["a tab", "\t"], ["U+2028", "\u{2028}"], ["U+2029", "\u{2029}"], ["U+0085", "\u{85}"]] as const) {
      await page.getByTestId("variant-ean").first().fill("");
      await page.getByTestId("variant-sku").first().fill(`${character}zz-e2e-${RUN}`);
      await page.getByTestId("save").click();
      await expect(page.getByTestId("sku-error").first(), `the API refuses ${label} at the start of the SKU`).toHaveText(SKU_FORMAT);
      await expect(page.getByTestId("variant-sku").first()).toHaveAttribute("aria-invalid", "true");
      expect(requests.at(-1)?.variants[0]?.sku, `the request carries the SKU as it was typed, with ${label}`).toBe(`${character}ZZ-E2E-${RUN.toUpperCase()}`);

      await page.getByTestId("variant-sku").first().fill("");
      await page.getByTestId("variant-ean").first().fill(`${EAN}${character}`);
      await page.getByTestId("save").click();
      await expect(page.getByTestId("ean-error").first(), `the API refuses ${label} at the end of the EAN`).toHaveText(EAN_FORMAT);
      await expect(page.getByTestId("variant-ean").first()).toHaveAttribute("aria-invalid", "true");
      expect(requests.at(-1)?.variants[0]?.ean, `the request carries the EAN as it was typed, with ${label}`).toBe(`${EAN}${character}`);
      await expect(page.getByTestId("variant-ean").first(), "the form keeps what was typed").toHaveValue(`${EAN}${character}`);

      const now = await stored();
      expect(now.version, `nothing was saved (${label})`).toBe(before.version);
      expect(now.variants.map((variant) => [variant.sku, variant.ean])).toEqual(before.variants.map((variant) => [variant.sku, variant.ean]));
    }
  });

  test("a character T1 forbids at the edge is a 422 at its field and nothing is saved: the panel does not trim it away", async ({ page, request }) => {
    test.setTimeout(3 * 60_000);
    const bearer = waitForBearerToken(page);
    await loginStaff(page, staff.marketing);
    const id = await createDraftInPanel(page, newProduct("Tabulator"));
    const headers = { Authorization: `Bearer ${await bearer}` };
    const stored = async () => (await (await request.get(`${urls.api}/api/v1/admin/products/${id}`, { headers })).json()) as Record<string, unknown>;
    const before = await stored();
    const requests: unknown[] = [];
    page.on("request", (sent) => {
      if (sent.method() === "PUT" && sent.url().includes(`/api/v1/admin/products/${id}`)) requests.push(sent.postDataJSON());
    });

    // The brand with a tab at the end.
    await page.getByTestId("brand").fill("Marka\t");
    await page.getByTestId("save").click();
    await expect(page.getByTestId("error-brand"), "the API refuses the tab at the end of the brand").toHaveText("Marka zawiera niedozwolone znaki.");
    await expect(page.getByTestId("brand")).toHaveAttribute("aria-invalid", "true");
    expect(requests.at(-1), "the request carries the brand as it was typed").toMatchObject({ brand: "Marka\t" });
    await expect(page.getByTestId("brand"), "the form keeps what was typed").toHaveValue("Marka\t");
    expect((await stored()).version, "nothing was saved").toBe(before.version);
    expect((await stored()).brand).toBe("");

    // A value of a parameter with U+2028 at the edge.
    await page.getByTestId("brand").fill("");
    await page.getByTestId("add-parameter").click();
    await page.getByTestId("parameter-name").first().fill("Waga");
    await page.getByTestId("parameter-value").first().fill("2 kg\u{2028}");
    await page.getByTestId("save").click();
    await expect(page.getByTestId("parameter-error").first()).toHaveText("Wartość parametru zawiera niedozwolone znaki.");
    expect(requests.at(-1)).toMatchObject({ parameters: [{ name: "Waga", value: "2 kg\u{2028}" }] });
    expect((await stored()).version, "nothing was saved").toBe(before.version);
    expect((await stored()).parameters).toEqual([]);

    // A carriage return in the description cannot be typed (a textarea turns it into a line feed): the API is asked directly.
    const view = await stored();
    const refused = await request.put(`${urls.api}/api/v1/admin/products/${id}`, {
      headers,
      data: {
        version: view.version,
        name: view.name,
        slug: view.slug,
        shortDescription: view.shortDescription,
        brand: view.brand,
        description: "Pierwszy akapit.\r\nDrugi akapit.",
        parameters: view.parameters,
        mainCategoryId: view.mainCategoryId,
        additionalCategoryIds: view.additionalCategoryIds,
        taxClass: view.taxClass,
        images: (view.images as { key: string; alt: string }[]).map((image) => ({ key: image.key, alt: image.alt })),
        variantAxes: view.variantAxes,
        variants: view.variants,
      },
    });
    expect(refused.status()).toBe(422);
    const problem = (await refused.json()) as { violations: { propertyPath: string; title: string }[] };
    expect(problem.violations.map(({ propertyPath, title }) => ({ propertyPath, title }))).toEqual([{ propertyPath: "description", title: "Opis zawiera niedozwolone znaki." }]);
    expect((await stored()).description).toBe("");
  });

  test("the fields and the section fit a 375 px screen and are operated from the keyboard", async ({ page }) => {
    test.setTimeout(3 * 60_000);
    await loginStaff(page, staff.marketing);
    const id = await createDraftInPanel(page, newProduct("Telefon"));
    await page.setViewportSize(viewports.phone);
    await page.goto(`${urls.admin}/products/${id}`);
    await expect(page.getByTestId("brand")).toBeVisible();

    // A parameter added with the keyboard: Enter on the button, the focus on the name, Tab to the value.
    await page.getByTestId("add-parameter").focus();
    await page.keyboard.press("Enter");
    await expect(page.getByTestId("parameter-name").first(), "the focus goes to the name of the new row").toBeFocused();
    await page.keyboard.type("N".repeat(60));
    await page.keyboard.press("Tab");
    await expect(page.getByTestId("parameter-value").first()).toBeFocused();
    await page.keyboard.type("W".repeat(200));
    await page.getByTestId("add-parameter").focus();
    await page.keyboard.press("Space");
    await expect(page.getByTestId("parameter-name").nth(1)).toBeFocused();
    await page.keyboard.type("Druga");

    await page.getByTestId("brand").fill("M".repeat(60));
    await page.getByTestId("description").fill("D".repeat(300));
    const widths = await documentWidths(page);
    expect(widths.scrollWidth, "the page does not scroll sideways").toBe(widths.clientWidth);
    for (const [what, locator] of [
      ["the brand", page.getByTestId("brand")],
      ["the description", page.getByTestId("description")],
      ["the name of a parameter", page.getByTestId("parameter-name").first()],
      ["the value of a parameter", page.getByTestId("parameter-value").first()],
      ["the button that moves a parameter up", page.getByTestId("parameter-up").nth(1)],
      ["the button that removes a parameter", page.getByTestId("parameter-remove").nth(1)],
      ["the button that adds a parameter", page.getByTestId("add-parameter")],
    ] as const) {
      await locator.scrollIntoViewIfNeeded();
      await expectWithinWidth(locator, viewports.phone.width, what);
    }

    // The codes of the variant are in the table that scrolls inside its own region: reached with the Tab key after the value,
    // limited to 40 and 14 characters, the SKU in capitals while it is typed, and the page does not scroll sideways for them.
    await page.getByTestId("variant-value").first().focus();
    await page.keyboard.press("Tab");
    await expect(page.getByTestId("variant-sku").first(), "the Tab key goes from the value of the variant to its SKU").toBeFocused();
    await page.keyboard.type("a".repeat(45));
    await expect(page.getByTestId("variant-sku").first()).toHaveValue("A".repeat(40));
    await page.keyboard.press("Tab");
    await expect(page.getByTestId("variant-ean").first(), "the Tab key goes from the SKU to the EAN").toBeFocused();
    await page.keyboard.type("1".repeat(20));
    await expect(page.getByTestId("variant-ean").first()).toHaveValue("1".repeat(14));
    const withCodes = await documentWidths(page);
    expect(withCodes.scrollWidth, "the page does not scroll sideways for the codes").toBe(withCodes.clientWidth);

    // Removing a parameter with the keyboard puts the focus on the next row; after the last one on „Dodaj parametr”.
    await page.getByTestId("parameter-remove").first().focus();
    await page.keyboard.press("Enter");
    await expect(page.getByTestId("parameter-row")).toHaveCount(1);
    await expect(page.getByTestId("parameter-name").first()).toBeFocused();
    await expect(page.getByTestId("parameter-name").first()).toHaveValue("Druga");
    await page.getByTestId("parameter-remove").first().focus();
    await page.keyboard.press("Space");
    await expect(page.getByTestId("parameter-row")).toHaveCount(0);
    await expect(page.getByTestId("add-parameter")).toBeFocused();
  });
});
