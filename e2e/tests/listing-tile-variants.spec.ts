import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Locator, type Page } from "@playwright/test";
import { gridTiles } from "../support/listing";

// The variant choice on the tile and the listing pages in the direction „Granit” (VISUAL-DESIGN-7).

// Demo data (apps/api/fixtures/catalog.yaml): Kajaki have one axis (Kolor); the road shoes Tarvik Asfalt 200 have two
// (Kolor × Rozmiar: 2 colours × 7 sizes = 14 variants).
const KAYAKS_PATH = "/c/sporty-wodne/kajaki";
const SHOES_PATH = "/c/bieganie/buty/szosowe";
const SHOES_NAME = "Buty szosowe Tarvik Asfalt 200";

const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1440, height: 900 };

const scrollWidth = (page: Page): Promise<number> => page.evaluate(() => document.documentElement.scrollWidth);

/**
 * The first tile of the grid that offers a choice (it has „Wybierz wariant”), or the one of the product `name`. The tile is
 * found once and then by its name, because after the click it no longer has that button.
 */
async function choiceTile(page: Page, name?: string): Promise<Locator> {
  const tiles = name === undefined ? gridTiles(page) : gridTiles(page).filter({ has: page.getByRole("heading", { name, exact: true }) });
  const first = tiles.filter({ has: page.getByRole("button", { name: "Wybierz wariant" }) }).first();
  const productName = (await first.getByRole("heading").innerText()).trim();
  return gridTiles(page).filter({ has: page.getByRole("heading", { name: productName, exact: true }) });
}

test.describe("the choice on the tile", () => {
  test("„Wybierz wariant” moves the focus to the first available variant and announces the expansion and the choice", async ({ page }) => {
    await page.goto(KAYAKS_PATH);
    const tile = await choiceTile(page);
    const live = tile.getByTestId("tile-live");
    await expect(live).toHaveAttribute("aria-live", "polite");
    await expect(live).toHaveText("");
    await expect(tile.getByRole("button", { name: "Do koszyka" })).toHaveCount(0);

    await tile.getByRole("button", { name: "Wybierz wariant" }).click();

    await expect(tile.getByRole("button", { name: "Wybierz wariant" })).toHaveCount(0);
    const firstAvailable = tile.locator("input[type=radio]:not([disabled])").first();
    await expect(firstAvailable).toBeFocused();
    await expect(live).toContainText("Rozwinięto wybór wariantu: Kolor.");
    await expect(tile.getByRole("button", { name: "Do koszyka" })).toHaveCount(0);

    await firstAvailable.check();
    await expect(live).toContainText("Wybrano wariant:");
    await expect(tile.getByRole("button", { name: "Do koszyka" })).toBeEnabled();
    await expect(tile.getByTestId("availability")).toBeVisible();
  });

  test("works from the keyboard: Enter on the button, the arrows choose, Tab reaches „Do koszyka”", async ({ page }) => {
    await page.goto(KAYAKS_PATH);
    const tile = await choiceTile(page);
    await tile.getByRole("button", { name: "Wybierz wariant" }).focus();

    await page.keyboard.press("Enter");
    const radios = tile.locator("input[type=radio]:not([disabled])");
    await expect(radios.first()).toBeFocused();

    await page.keyboard.press("ArrowDown");
    // From a group without a choice the arrow moves to the next variant and chooses it.
    await expect(tile.locator("input[type=radio]:checked")).toHaveCount(1);
    await expect(tile.getByRole("button", { name: "Do koszyka" })).toBeEnabled();
    await page.keyboard.press("Tab");
    await expect(tile.getByRole("button", { name: "Do koszyka" })).toBeFocused();
  });

  test("an unavailable variant says „niedostępny” and cannot be chosen", async ({ page }) => {
    await page.goto(KAYAKS_PATH);
    // One of the four kayaks has a colour out of stock (the mockup: „pomarańczowy” of Dravik Rzeka 2).
    let found = 0;
    for (const tile of await gridTiles(page).all()) {
      const expand = tile.getByRole("button", { name: "Wybierz wariant" });
      if ((await expand.count()) === 0) continue;
      await expand.click();
      const disabled = tile.locator("input[type=radio][disabled]");
      for (const radio of await disabled.all()) {
        found++;
        await expect(radio.locator("xpath=..")).toContainText("niedostępny");
        await expect(radio).toBeDisabled();
      }
    }
    expect(found, "the demo data has an unavailable kayak colour").toBeGreaterThan(0);
  });

  test("a tile of the home page offers the same choice", async ({ page }) => {
    await page.goto("/");
    const tile = await choiceTile(page);
    await tile.getByRole("button", { name: "Wybierz wariant" }).click();
    await expect(tile.locator("input[type=radio]:not([disabled])").first()).toBeFocused();
    await expect(tile.getByTestId("tile-live")).toContainText("Rozwinięto wybór wariantu");
  });

  test.describe("at 375 px", () => {
    test.use({ viewport: PHONE, hasTouch: true });

    test("one axis: the buttons fit without sideways scroll", async ({ page }) => {
      await page.goto(KAYAKS_PATH);
      const tile = await choiceTile(page);
      await tile.getByRole("button", { name: "Wybierz wariant" }).click();
      await expect(tile.getByTestId("variant-options")).toHaveAttribute("data-form", "buttons");
      await tile.locator("input[type=radio]:not([disabled])").first().check();
      expect(await scrollWidth(page)).toBeLessThanOrEqual(PHONE.width);
    });

    test("two axes: 14 variants as a list fit without sideways scroll, each one inside the tile", async ({ page }) => {
      await page.goto(SHOES_PATH);
      const tile = await choiceTile(page, SHOES_NAME);
      await tile.getByRole("button", { name: "Wybierz wariant" }).click();
      const options = tile.getByTestId("variant-options");
      await expect(options).toHaveAttribute("data-form", "list");
      const radios = tile.getByRole("radio");
      await expect(radios).toHaveCount(14);
      expect(await scrollWidth(page)).toBeLessThanOrEqual(PHONE.width);
      const tileBox = (await tile.boundingBox())!;
      for (const radio of await radios.all()) {
        const box = (await radio.boundingBox())!;
        expect(box.x).toBeGreaterThanOrEqual(tileBox.x - 1);
        expect(box.x + box.width).toBeLessThanOrEqual(tileBox.x + tileBox.width + 1);
        expect(box.height).toBeGreaterThanOrEqual(40);
      }
      await radios.nth(1).check();
      await expect(tile.getByRole("button", { name: "Do koszyka" })).toBeEnabled();
      expect(await scrollWidth(page)).toBeLessThanOrEqual(PHONE.width);
    });
  });
});

test.describe("listing pages", () => {
  for (const [name, path] of [
    ["a category page", KAYAKS_PATH],
    ["the search", "/szukaj?q=kajak"],
  ] as const) {
    for (const viewport of [DESKTOP, PHONE]) {
      test(`axe finds no serious or critical violation on ${name} at ${viewport.width} px, also with a tile expanded, and the page does not scroll sideways`, async ({ page }) => {
        await page.setViewportSize(viewport);
        await page.goto(path);
        await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
        await expect(page.getByTestId("product-grid")).toBeVisible();
        await page.evaluate(() => document.fonts.ready);
        const serious = async () =>
          (await new AxeBuilder({ page }).exclude("nextjs-portal").analyze()).violations
            .filter((violation) => violation.impact === "serious" || violation.impact === "critical")
            .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`);
        expect(await serious()).toEqual([]);
        expect(await scrollWidth(page)).toBeLessThanOrEqual(viewport.width);

        const tile = await choiceTile(page);
        await tile.getByRole("button", { name: "Wybierz wariant" }).click();
        await tile.locator("input[type=radio]:not([disabled])").first().check();
        expect(await serious()).toEqual([]);
        expect(await scrollWidth(page)).toBeLessThanOrEqual(viewport.width);
      });
    }
  }
});
