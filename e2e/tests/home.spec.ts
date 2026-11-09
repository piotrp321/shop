import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";

// The home page of the visual direction (VISUAL-DESIGN-6): hero, department tiles and responsive marketing photos.

const DEPARTMENTS = ["Bieganie", "Fitness", "Rower", "Sporty drużynowe", "Sporty wodne", "Sporty zimowe", "Turystyka"];
const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1440, height: 900 };

const scrollWidth = (page: Page): Promise<number> => page.evaluate(() => document.documentElement.scrollWidth);
const tiles = (page: Page) => page.getByRole("region", { name: "Działy sklepu" });

test.describe("the default state of /", () => {
  for (const path of ["/", "/?utm_source=x", "/?page=1", "/?sort=nazwa"]) {
    test(`${path} has the hero with one h1 and a tile of every department, the listing under them`, async ({ page }) => {
      await page.goto(path);
      await expect(page.getByRole("heading", { level: 1 })).toHaveText("Sprzęt na każdy szlak");
      await expect(page.getByRole("heading", { level: 1 })).toHaveCount(1);
      await expect(page.getByRole("link", { name: "Zobacz Turystykę" })).toHaveAttribute("href", "/c/turystyka");
      await expect(tiles(page).getByRole("heading", { level: 2 })).toHaveText(DEPARTMENTS);
      await expect(tiles(page).getByRole("link", { name: "Rower" })).toHaveAttribute("href", "/c/rower");
      await expect(page.getByRole("heading", { level: 2, name: "Wszystkie produkty", exact: true })).toBeVisible();
      await expect(page.getByTestId("product-grid").getByRole("heading", { level: 3 }).first()).toBeVisible();
      await expect(page.getByTestId("product-grid").getByRole("heading", { level: 2 })).toHaveCount(0);
    });
  }

  for (const path of ["/?page=2", "/?sort=cena-rosnaco", "/?sort=nazwa&dostepne=1"]) {
    test(`${path} is only the listing: h1 „Wszystkie produkty”, no hero, no tiles`, async ({ page }) => {
      await page.goto(path);
      await expect(page.getByRole("heading", { level: 1 })).toHaveText("Wszystkie produkty");
      await expect(page.getByRole("heading", { level: 1 })).toHaveCount(1);
      await expect(tiles(page)).toHaveCount(0);
      await expect(page.getByRole("link", { name: "Zobacz Turystykę" })).toHaveCount(0);
      await expect(page.getByTestId("product-grid").getByRole("heading", { level: 2 }).first()).toBeVisible();
    });
  }

  test("a department tile leads to its category page", async ({ page }) => {
    await page.goto("/");
    await tiles(page).getByRole("link", { name: "Fitness" }).click();
    await expect(page).toHaveURL(/\/c\/fitness$/);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Fitness");
  });

  test("every image of the page has srcset, sizes, width and height; the tiles are decoration with alt=\"\", only the hero has priority", async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto("/");
    const images = await page.getByRole("main").locator("img").evaluateAll((nodes) =>
      (nodes as HTMLImageElement[]).map((image) => ({
        alt: image.getAttribute("alt"),
        srcset: !!image.getAttribute("srcset"),
        sizes: !!image.getAttribute("sizes"),
        width: !!image.getAttribute("width"),
        height: !!image.getAttribute("height"),
        priority: image.getAttribute("fetchpriority"),
        loading: image.getAttribute("loading"),
        inHero: image.closest("picture") !== null,
      })),
    );
    expect(images.length).toBeGreaterThan(DEPARTMENTS.length);
    for (const image of images) expect(image).toMatchObject({ srcset: true, sizes: true, width: true, height: true });
    const hero = images.filter((image) => image.inHero);
    expect(hero).toHaveLength(1);
    expect(hero[0]).toMatchObject({ priority: "high", alt: expect.stringContaining("Turyści") });
    expect(images.filter((image) => image.priority === "high")).toHaveLength(1);
    const department = images.filter((image) => !image.inHero).slice(0, DEPARTMENTS.length);
    for (const image of department) expect(image).toMatchObject({ alt: "", loading: "lazy" });
  });

  test("at 1440 px the hero loads the wide crop (1600 px) and no phone crop", async ({ browser }) => {
    const context = await browser.newContext({ viewport: DESKTOP, deviceScaleFactor: 1 });
    const page = await context.newPage();
    const loaded: string[] = [];
    page.on("request", (request) => request.resourceType() === "image" && loaded.push(request.url()));
    await page.goto("/");
    await page.waitForLoadState("networkidle");
    const hero = loaded.filter((url) => /\/hero-/.test(url));
    expect(hero.map((url) => /hero-(wide|phone)-(\d+)/.exec(url)!.slice(1, 3).join("-"))).toEqual(["wide-1600"]);
    await context.close();
  });

  for (const scale of [2, 3]) {
    test(`at 375 px and DPR ${scale} the hero is the phone crop of at most 828 px and no wide-screen file is loaded`, async ({ browser }) => {
      // Not `isMobile`: Chromium's mobile emulation evaluates the media query of the `<source>` in the preload scanner
      // with a wider layout viewport and fetches the wide file as well, which a window of 375 px does not.
      const context = await browser.newContext({ viewport: PHONE, deviceScaleFactor: scale, hasTouch: true });
      const page = await context.newPage();
      const loaded: string[] = [];
      page.on("request", (request) => request.resourceType() === "image" && loaded.push(request.url()));
      await page.goto("/");
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
      await page.waitForLoadState("networkidle");
      const hero = loaded.filter((url) => /\/hero-/.test(url)).map((url) => /hero-(wide|phone)-(\d+)/.exec(url)!);
      expect(hero.length, "the hero was requested").toBeGreaterThan(0);
      expect(hero.filter((match) => match[1] === "wide")).toEqual([]);
      for (const match of hero) expect(Number(match[2])).toBeLessThanOrEqual(828);
      expect(await scrollWidth(page)).toBeLessThanOrEqual(PHONE.width);
      await context.close();
    });
  }

  for (const viewport of [DESKTOP, PHONE]) {
    test(`axe finds no serious or critical violation at ${viewport.width} px and the page does not scroll sideways`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await page.goto("/");
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
      await expect(page.getByTestId("product-grid")).toBeVisible();
      await page.evaluate(() => document.fonts.ready);
      const results = await new AxeBuilder({ page }).exclude("nextjs-portal").analyze();
      const serious = results.violations.filter((violation) => violation.impact === "serious" || violation.impact === "critical");
      expect(serious.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
      expect(await scrollWidth(page)).toBeLessThanOrEqual(viewport.width);
    });
  }
});
