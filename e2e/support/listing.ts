import { expect, type Locator, type Page } from "@playwright/test";

/** The tiles of the product grid of a listing, found by the grid's own test id, whatever the level of the names (h3 on the default home page). */
export const gridTiles = (page: Page): Locator => page.getByTestId("product-grid").getByRole("listitem");

export const tileNames = (page: Page): Promise<string[]> => gridTiles(page).getByRole("heading").allInnerTexts();

/** The number next to the title: „12 produktów”. */
export async function shownCount(page: Page): Promise<number> {
  const text = await page.getByRole("main").getByText(/^\d+ (produkt|produkty|produktów)$/).innerText();
  return Number.parseInt(text, 10);
}

/** A checkbox of the filter panel by the value, whatever the count after it: „czarne (4)”. */
export const filterBox = (page: Page, value: string): Locator => page.getByRole("checkbox", { name: new RegExp(`^${value} \\(\\d+\\)$`) });

/** The count after the label of a checkbox of the panel („czarne (4)” gives 4). */
export async function countOf(box: Locator): Promise<number> {
  const label = await box.evaluate((input: HTMLInputElement) => input.labels?.[0]?.textContent ?? "");
  const match = /\((\d+)\)$/.exec(label.trim());
  if (match === null) throw new Error(`no count in the label "${label}"`);
  return Number(match[1]);
}

/** The "from" prices of the tiles in grosze, `null` for a tile without a price (it shows no digits). */
export async function tilePrices(page: Page): Promise<(number | null)[]> {
  const tiles = gridTiles(page);
  const total = await tiles.count();
  const prices: (number | null)[] = [];
  for (let index = 0; index < total; index++) {
    // A product that cannot be bought has no price element or says "Cena niedostępna".
    const price = tiles.nth(index).getByTestId("price");
    const text = (await price.count()) === 0 ? "" : await price.first().innerText();
    prices.push(/\d/.test(text) ? Number(text.replace(/\D/g, "")) : null);
  }
  return prices;
}
