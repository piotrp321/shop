import { expect, test, type APIRequestContext, type Page } from "@playwright/test";
import { staff } from "../support/accounts";
import { createCategory, readToken, removeTestCategories, SLUG_PREFIX, testCategorySlugs } from "../support/categories";
import { createDraftInPanel, newProduct, publishInPanel, receiveStockInPanel, solidPng, unpublishInPanel } from "../support/catalog-admin";
import { documentWidths, expectControlsReachable, viewports, WCAG_TAGS } from "../support/panel-layout";
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
