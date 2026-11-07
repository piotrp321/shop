import { expect, test, type APIRequestContext, type Page } from "@playwright/test";
import { countOf, filterBox, gridTiles, shownCount, tileNames, tilePrices } from "../support/listing";
import { openCategoryFromMenu } from "../support/menu";
import { changePriceInPanel, normalize } from "../support/price-change";
import { urls } from "../support/urls";

// Bieganie > Buty biegowe: 12 products in Buty szosowe and Buty trailowe, colours and sizes 40-46, 349-699 zł
// (apps/api/fixtures/catalog.yaml).
const SHOES = "/c/bieganie/buty";

type CategoryNode = { id: string; path: string; children: CategoryNode[] };

/** The `total` of the API for the category of the path and the rest of the query: the numbers the store must show. */
async function apiTotal(request: APIRequestContext, categoryPath: string | null, query: string): Promise<number> {
  let category = "";
  if (categoryPath !== null) {
    const tree = (await (await request.get(`${urls.api}/api/v1/categories`)).json()) as CategoryNode[];
    const find = (nodes: CategoryNode[]): CategoryNode | undefined => {
      for (const node of nodes) {
        if (node.path === categoryPath) return node;
        const below = find(node.children);
        if (below !== undefined) return below;
      }
      return undefined;
    };
    category = `category=${find(tree)!.id}&`;
  }
  const response = await request.get(`${urls.api}/api/v1/products?${category}${query}&perPage=1`);
  expect(response.status()).toBe(200);
  return ((await response.json()) as { total: number }).total;
}

const activeFilters = (page: Page) => page.getByRole("list", { name: "Aktywne filtry" });
const sortList = (page: Page) => page.getByRole("combobox", { name: "Sortuj" });
const priceFrom = (page: Page) => page.getByRole("textbox", { name: "Cena od" });
const priceTo = (page: Page) => page.getByRole("textbox", { name: "Cena do" });
const robotsOf = (html: string) => /<meta name="robots" content="([^"]*)"/.exec(html)?.[1] ?? null;
const canonicalOf = (html: string) => /<link rel="canonical" href="([^"]*)"/.exec(html)?.[1] ?? null;

test("from the menu to a category: subcategories, a colour and then a size narrow the list to the counts the panel shows", async ({ page, request }) => {
  await page.goto("/");
  await openCategoryFromMenu(page, "Bieganie", "Buty biegowe");
  await expect(page).toHaveURL(new RegExp(`${SHOES}$`));
  await expect(page.getByRole("navigation", { name: "Ścieżka" }).getByRole("link")).toHaveText(["Wszystkie produkty", "Bieganie"]);
  const categories = page.getByRole("navigation", { name: "Kategorie" });
  await expect(categories.getByRole("link")).toHaveText(["Buty szosowe (6)", "Buty trailowe (6)"]);
  expect(await shownCount(page)).toBe(12);

  // The number next to a value is the number of products after adding it.
  const colour = filterBox(page, "niebieskie");
  const afterColour = await countOf(colour);
  await colour.click();
  await expect(page).toHaveURL(/\?o=Kolor%3Aniebieskie$/);
  await expect(filterBox(page, "niebieskie")).toBeChecked();
  await expect(activeFilters(page)).toContainText("Kolor: niebieskie");
  await expect.poll(() => shownCount(page)).toBe(afterColour);

  const size = filterBox(page, "42");
  const afterSize = await countOf(size);
  await size.click();
  await expect(page).toHaveURL(/\?o=Kolor%3Aniebieskie&o=Rozmiar%3A42$/);
  await expect.poll(() => shownCount(page)).toBe(afterSize);
  expect(afterSize).toBe(await apiTotal(request, "bieganie/buty", "option=Kolor:niebieskie&option=Rozmiar:42"));
  await expect(activeFilters(page).getByRole("listitem")).toHaveCount(2);

  // A subcategory link (only the ones with matching products are left) keeps the filters.
  await categories.getByRole("link").first().click();
  await expect(page).toHaveURL(/\/c\/bieganie\/buty\/(szosowe|trailowe)\?o=Kolor%3Aniebieskie&o=Rozmiar%3A42$/);
  expect(await shownCount(page)).toBe(afterSize);
});

test("every change of a filter or of the sort leaves the page behind", async ({ page }) => {
  await page.goto("/c/bieganie?page=2");
  await expect(page.getByText(/^Strona 2 z \d+$/)).toBeVisible();
  await filterBox(page, "czarne").click();
  await expect(page).toHaveURL(/\/c\/bieganie\?o=Kolor%3Aczarne$/);

  await page.goto("/c/bieganie?page=2");
  await sortList(page).selectOption("cena-malejaco");
  await expect(page).toHaveURL(/\/c\/bieganie\?sort=cena-malejaco$/);

  await page.goto("/c/bieganie?page=2");
  await page.getByRole("checkbox", { name: /^Tylko dostępne/ }).click();
  await expect(page).toHaveURL(/\/c\/bieganie\?dostepne=1$/);

  await page.goto("/c/bieganie?page=2");
  await priceFrom(page).fill("100");
  await priceFrom(page).press("Enter");
  await expect(page).toHaveURL(/\/c\/bieganie\?cena-od=100$/);
});

test("the price applies on Enter and when the field loses focus, in whole zloty, and the API's answer is shown", async ({ page, request }) => {
  await page.goto(SHOES);
  await expect(page.getByText(/^Ceny od 349,00\s*zł do 699,00\s*zł$/)).toBeVisible();

  await priceFrom(page).fill("400");
  await priceTo(page).fill("600");
  await priceTo(page).press("Enter");
  await expect(page).toHaveURL(/\?cena-od=400&cena-do=600$/);
  await expect(activeFilters(page)).toContainText("Cena od 400 zł");
  await expect(activeFilters(page)).toContainText("Cena do 600 zł");
  await expect.poll(() => shownCount(page)).toBe(await apiTotal(request, "bieganie/buty", "priceMin=40000&priceMax=60000"));
  await expect(priceFrom(page)).toHaveValue("400");

  // Leaving the field applies it too; an emptied field removes the filter. Moving to the other price field does not.
  await priceFrom(page).fill("");
  await priceTo(page).focus();
  await page.getByRole("heading", { level: 1 }).click();
  await expect(page).toHaveURL(/\?cena-do=600$/);
  await priceTo(page).fill("500");
  await priceTo(page).press("Tab");
  await expect(page).toHaveURL(/\?cena-do=500$/);
  await expect.poll(() => shownCount(page)).toBe(await apiTotal(request, "bieganie/buty", "priceMax=50000"));
});

test("a price that is not whole zloty is an invalid filter that search engines do not index", async ({ page, request }) => {
  await page.goto(SHOES);
  await priceFrom(page).fill("12,5");
  await priceFrom(page).press("Enter");
  await expect(page.getByRole("heading", { level: 1, name: "Nieprawidłowy filtr" })).toBeVisible();
  // The way back is the same listing without the filters, not only the home page.
  await page.getByRole("link", { name: "Pokaż wyniki bez filtrów" }).click();
  await expect(page).toHaveURL(new RegExp(`${SHOES}$`));
  await expect(priceFrom(page)).toHaveValue("");

  for (const query of ["cena-od=abc", "cena-do=-5", "sort=popularne", "dostepne=0", "page=0"]) {
    const html = await (await request.get(`${SHOES}?${query}`)).text();
    expect(html, query).toContain("Nieprawidłowy filtr");
    expect(robotsOf(html), query).toContain("noindex");
  }
});

test("„Tylko dostępne” navigates at once and its count is the result", async ({ page, request }) => {
  await page.goto("/");
  const box = page.getByRole("checkbox", { name: /^Tylko dostępne \(\d+\)$/ });
  const available = await countOf(box);
  expect(available).toBeGreaterThan(0);
  await box.click();
  await expect(page).toHaveURL(/\/\?dostepne=1$/);
  await expect(box).toBeChecked();
  await expect.poll(() => shownCount(page)).toBe(available);
  expect(available).toBe(await apiTotal(request, null, "available=true"));
  await expect(activeFilters(page)).toContainText("Tylko dostępne");
});

test("sorting by price puts the tiles in the order of the „od” price and the products without a price last", async ({ page }) => {
  await page.goto("/");
  const pages = Number(/z (\d+)$/.exec(await page.getByText(/^Strona 1 z \d+$/).innerText())![1]);

  await sortList(page).selectOption("cena-rosnaco");
  await expect(page).toHaveURL(/\/\?sort=cena-rosnaco$/);
  await expect(sortList(page)).toHaveValue("cena-rosnaco");
  const ascending = await tilePrices(page);
  expect(ascending.length).toBeGreaterThan(1);
  expect(ascending.every((price) => price !== null)).toBe(true);
  expect(ascending).toEqual([...ascending].sort((a, b) => a! - b!));

  // The last page ends with the products that have no price.
  await page.goto(`/?sort=cena-rosnaco&page=${pages}`);
  const lastPage = await tilePrices(page);
  const firstWithoutPrice = lastPage.findIndex((price) => price === null);
  expect(firstWithoutPrice, "the demo catalogue has a product without a price").toBeGreaterThanOrEqual(0);
  expect(lastPage.slice(firstWithoutPrice).every((price) => price === null)).toBe(true);
  const priced = lastPage.slice(0, firstWithoutPrice) as number[];
  expect(priced).toEqual([...priced].sort((a, b) => a - b));

  await page.goto("/");
  await sortList(page).selectOption("cena-malejaco");
  await expect(page).toHaveURL(/\/\?sort=cena-malejaco$/);
  await expect(sortList(page)).toHaveValue("cena-malejaco");
  const descending = await tilePrices(page);
  expect(descending).toEqual([...descending].sort((a, b) => b! - a!));
  expect(descending[0]).toBeGreaterThan(ascending[0]!);

  // The default sort leaves `sort` out of the address.
  await sortList(page).selectOption("nazwa");
  await expect(page).toHaveURL(/\/$/);
});

test("the active filters are removed one by one and all at once; the sort stays", async ({ page }) => {
  await page.goto(`${SHOES}?sort=cena-rosnaco&cena-od=300&dostepne=1&o=Kolor%3Aczarne&o=Rozmiar%3A42`);
  const chips = activeFilters(page).getByRole("listitem");
  await expect(chips).toHaveCount(4);

  await page.getByRole("link", { name: "Usuń filtr: Kolor: czarne" }).click();
  await expect(page).toHaveURL(/\?sort=cena-rosnaco&cena-od=300&dostepne=1&o=Rozmiar%3A42$/);
  await expect(chips).toHaveCount(3);
  await expect(filterBox(page, "czarne")).not.toBeChecked();

  await page.getByRole("link", { name: "Usuń filtr: Cena od 300 zł" }).click();
  await expect(page).toHaveURL(/\?sort=cena-rosnaco&dostepne=1&o=Rozmiar%3A42$/);
  await expect(priceFrom(page)).toHaveValue("");

  await page.getByRole("link", { name: "Wyczyść filtry" }).click();
  await expect(page).toHaveURL(new RegExp(`${SHOES}\\?sort=cena-rosnaco$`));
  await expect(activeFilters(page)).toHaveCount(0);
  await expect(sortList(page)).toHaveValue("cena-rosnaco");
});

test("an empty result says so and offers „Wyczyść filtry”", async ({ page }) => {
  await page.goto(`${SHOES}?cena-od=1&cena-do=2`);
  await expect(page.getByText("Nie znaleźliśmy produktów.")).toBeVisible();
  await expect(gridTiles(page)).toHaveCount(0);
  expect(await shownCount(page)).toBe(0);
  await page.getByRole("main").getByRole("link", { name: "Wyczyść filtry" }).last().click();
  await expect(page).toHaveURL(new RegExp(`${SHOES}$`));
  await expect(gridTiles(page).first()).toBeVisible();
});

test("a search is filtered by the category of the panel, which stays in the address", async ({ page }) => {
  await page.goto("/szukaj?q=buty");
  const categories = page.getByRole("navigation", { name: "Kategorie" });
  await categories.getByRole("link", { name: /^Bieganie/ }).click();
  await expect(page).toHaveURL(/\/szukaj\?q=buty&kategoria=bieganie$/);
  await expect(activeFilters(page)).toContainText("Kategoria: Bieganie");
  await filterBox(page, "czarne").click();
  await expect(page).toHaveURL(/\/szukaj\?q=buty&o=Kolor%3Aczarne&kategoria=bieganie$/);
  expect(await tileNames(page)).not.toHaveLength(0);
});

test("a sort or a filter makes the page noindex, follow with the plain listing as canonical; a page alone stays indexable", async ({ request }) => {
  const store = urls.store;
  const get = async (path: string) => (await request.get(path)).text();

  for (const [path, canonical] of [
    ["/c/bieganie?sort=cena-rosnaco&page=2", `${store}/c/bieganie`],
    ["/c/bieganie/buty?o=Kolor%3Aczarne", `${store}${SHOES}`],
    ["/c/bieganie?cena-od=100", `${store}/c/bieganie`],
    ["/c/bieganie?cena-do=900", `${store}/c/bieganie`],
    ["/c/bieganie?dostepne=1", `${store}/c/bieganie`],
    ["/?sort=nazwa", `${store}/`],
    ["/?o=Kolor%3Aczarne&page=3", `${store}/`],
  ] as const) {
    const html = await get(path);
    expect(robotsOf(html), path).toBe("noindex, follow");
    expect(canonicalOf(html), path).toBe(canonical);
  }

  const plain = await get("/c/bieganie?page=2");
  expect(robotsOf(plain)).toBeNull();
  expect(canonicalOf(plain)).toBe(`${store}/c/bieganie?page=2`);

  // The phrase of the search page means nothing on a listing.
  const ignored = await get("/c/bieganie?q=kurtka");
  expect(robotsOf(ignored)).toBeNull();
  expect(canonicalOf(ignored)).toBe(`${store}/c/bieganie`);
  expect(ignored).not.toMatch(/href="[^"]*q=kurtka/);
  expect(ignored).not.toContain('name="q" value=');
});

test.describe("without JavaScript", () => {
  test.use({ javaScriptEnabled: false });

  test("the filter panel is a form that „Pokaż wyniki” sends", async ({ page }) => {
    await page.goto(SHOES);
    await page.getByRole("checkbox", { name: /^niebieskie/ }).check();
    await page.getByRole("combobox", { name: "Sortuj" }).selectOption("cena-malejaco");
    await priceFrom(page).fill("300");
    await page.getByRole("button", { name: "Pokaż wyniki" }).click();

    await expect(page).toHaveURL(/\/c\/bieganie\/buty\?/);
    const url = new URL(page.url());
    expect(url.searchParams.getAll("o")).toEqual(["Kolor:niebieskie"]);
    expect(url.searchParams.get("sort")).toBe("cena-malejaco");
    expect(url.searchParams.get("cena-od")).toBe("300");
    await expect(page.getByRole("checkbox", { name: /^niebieskie/ })).toBeChecked();
    await expect(priceFrom(page)).toHaveValue("300");
    await expect(activeFilters(page)).toContainText("Kolor: niebieskie");
    expect(await shownCount(page)).toBe(6);
  });
});

// A demo product with the default variant only (apps/api/fixtures/catalog.yaml), in "Fitness" > "Trening".
const PRODUCT_NAME = "Hantle regulowane Lumora Para 10";
// A price change reaches the lists within the TTL of Varnish (60 s) and the reindexing.
const FILTER_WAIT_MS = 90_000;

test("a price changed in the panel changes the result of the price filter within 90 s", async ({ page, context }) => {
  test.setTimeout(FILTER_WAIT_MS + 90_000);

  const store = await context.newPage();
  const withProduct = async (path: string): Promise<boolean> => {
    await store.goto(path);
    await expect(store.getByRole("heading", { level: 1 })).toBeVisible();
    return (await tileNames(store)).includes(PRODUCT_NAME);
  };
  /** Every whole-zloty range [n, n + 1] zł holds the price of the product when n is its price rounded down. */
  const priceFilter = (grosze: number) => `/c/fitness/trening?cena-od=${Math.floor(grosze / 100)}&cena-do=${Math.floor(grosze / 100) + 1}`;

  await store.goto("/c/fitness/trening");
  const tile = gridTiles(store).filter({ has: store.getByRole("heading", { level: 2, name: PRODUCT_NAME, exact: true }) });
  const before = Number(normalize(await tile.getByTestId("price").innerText()).replace(/\D/g, ""));
  expect(await withProduct(priceFilter(before)), "the product is in the filter of its current price").toBe(true);

  const change = await changePriceInPanel(page, PRODUCT_NAME);
  const changedAt = Date.now();
  expect(change.before).toBe(before);
  const remaining = () => Math.max(1, FILTER_WAIT_MS - (Date.now() - changedAt));

  await expect
    .poll(() => withProduct(priceFilter(change.after)), { message: "the product did not reach the filter of its new price within 90 s", timeout: remaining(), intervals: [3_000] })
    .toBe(true);
  await expect
    .poll(() => withProduct(priceFilter(change.before)), { message: "the product stayed in the filter of its old price within 90 s", timeout: remaining(), intervals: [3_000] })
    .toBe(false);
});
