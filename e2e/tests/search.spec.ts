import { expect, test, type Page } from "@playwright/test";
import { gridTiles, tileNames } from "../support/listing";

// Demo product with variants (apps/api/fixtures/catalog.yaml); "asfalt" is a word of its name only.
const PRODUCT_NAME = "Buty szosowe Tarvik Asfalt 200";
const PRODUCT_PATH = "/p/buty-szosowe-tarvik-asfalt-200";

const headerSearch = (page: Page) => page.getByRole("banner").getByRole("searchbox", { name: "Szukaj produktów" });

async function searchFromHeader(page: Page, phrase: string): Promise<void> {
  await headerSearch(page).fill(phrase);
  await headerSearch(page).press("Enter");
}

test("a phrase from the header leads to the results, the product page and the cart", async ({ page }) => {
  await page.goto("/");
  await searchFromHeader(page, "asfalt");

  await expect(page).toHaveURL(/\/szukaj\?q=asfalt$/);
  await expect(page.getByRole("heading", { level: 1, name: "Wyniki dla „asfalt”" })).toBeVisible();
  await expect(page).toHaveTitle("Wyniki dla „asfalt” | Rytm");
  await expect(headerSearch(page)).toHaveValue("asfalt");
  await expect(page.getByRole("navigation", { name: "Ścieżka" }).getByRole("link")).toHaveText(["Wszystkie produkty"]);
  expect(await tileNames(page)).toContain(PRODUCT_NAME);

  await page.getByRole("main").getByRole("heading", { level: 2, name: PRODUCT_NAME, exact: true }).getByRole("link").click();
  await expect(page).toHaveURL(new RegExp(`${PRODUCT_PATH}$`));
  await expect(page.getByRole("heading", { level: 1, name: PRODUCT_NAME })).toBeVisible();

  const choose = (name: string) => page.getByRole("main").getByRole("button", { name, exact: true });
  await choose("czarne").click();
  await choose("42").click();
  await page.getByRole("button", { name: "Do koszyka" }).click();
  await expect(page.getByText("Dodano do koszyka")).toBeVisible();
  await expect(page.getByTestId("cart-count")).toHaveText("1");
});

// A word of the name of the products the phrase must find (the demo catalogue: apps/api/fixtures/catalog.yaml).
const PHRASES = [
  { phrase: "kurtki", why: "an inflected form finds the singular", stem: /kurtk/i },
  { phrase: "spiwor", why: "missing Polish characters", stem: /śpiwór/i },
  { phrase: "plecal", why: "a typo", stem: /plecak/i },
] as const;

for (const { phrase, why, stem } of PHRASES) {
  test(`the phrase „${phrase}” finds products (${why})`, async ({ page }) => {
    await page.goto("/");
    await searchFromHeader(page, phrase);
    await expect(page).toHaveURL(new RegExp(`/szukaj\\?q=${phrase}$`));
    await expect(page.getByRole("heading", { level: 1, name: `Wyniki dla „${phrase}”` })).toBeVisible();
    await expect(gridTiles(page).first()).toBeVisible();
    const names = await tileNames(page);
    // The best matches (the word in the name) come first; a lower one may match only a word of a description.
    expect(names[0]).toMatch(stem);
    expect(names.filter((name) => stem.test(name)).length).toBeGreaterThanOrEqual(6);
  });
}

test("two words, one of which matches nothing, find nothing and offer a way out", async ({ page }) => {
  await page.goto("/szukaj?q=kurtka+zzzzzz");
  await expect(page.getByText("Nie znaleźliśmy produktów.")).toBeVisible();
  await expect(gridTiles(page)).toHaveCount(0);
  await page.getByRole("link", { name: "Zobacz wszystkie produkty" }).click();
  await expect(page).toHaveURL(/\/$/);
  await expect(page.getByRole("heading", { level: 1, name: "Wszystkie produkty" })).toBeVisible();
});

test("a phrase shorter than 2 characters shows the message instead of results", async ({ page }) => {
  await page.goto("/");
  await searchFromHeader(page, "k");
  await expect(page).toHaveURL(/\/szukaj\?q=k$/);
  await expect(page.getByText("Wpisz co najmniej 2 znaki.")).toBeVisible();
  await expect(gridTiles(page)).toHaveCount(0);
  await expect(page).toHaveTitle("Wyszukiwanie | Rytm");

  await page.goto("/szukaj");
  await expect(page.getByText("Wpisz co najmniej 2 znaki.")).toBeVisible();
});

test("the search page is noindex, follow and has no canonical address", async ({ request }) => {
  const html = await (await request.get("/szukaj?q=kurtki&sort=cena-rosnaco")).text();
  expect(html).toMatch(/<meta name="robots" content="noindex, follow"\/?>/);
  expect(html).not.toContain('rel="canonical"');

  const short = await (await request.get("/szukaj?q=k")).text();
  expect(short).toMatch(/<meta name="robots" content="noindex, follow"\/?>/);
  expect(short).not.toContain('rel="canonical"');
});

test("a category narrows a search and an unknown one is an invalid filter", async ({ page, request }) => {
  await page.goto("/szukaj?q=buty&kategoria=bieganie/buty/trailowe");
  await expect(page.getByRole("heading", { level: 1, name: "Wyniki dla „buty”" })).toBeVisible();
  const names = await tileNames(page);
  expect(names.length).toBeGreaterThan(0);
  for (const name of names) expect(name).toMatch(/trailowe/i);
  await expect(page.getByRole("list", { name: "Aktywne filtry" })).toContainText("Kategoria: Buty trailowe");
  await page.getByRole("link", { name: "Usuń filtr: Kategoria: Buty trailowe" }).click();
  await expect(page).toHaveURL(/\/szukaj\?q=buty$/);

  const response = await request.get("/szukaj?q=buty&kategoria=nie-ma");
  const html = await response.text();
  expect(html).toContain("Nieprawidłowy filtr");
  expect(html).toMatch(/<meta name="robots" content="noindex"\/?>/);
});
