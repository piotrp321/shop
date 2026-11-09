import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { logInAsJan } from "../support/shop";

// Fonts, header, footer and the 404 page of the visual direction (VISUAL-DESIGN-5).

// Demo data (apps/api/fixtures/catalog.yaml).
const LISTING_PATH = "/c/bieganie/buty/szosowe";
const PRODUCT_PATH = "/p/buty-szosowe-tarvik-asfalt-200";
const DEPARTMENTS = ["Bieganie", "Fitness", "Rower", "Sporty drużynowe", "Sporty wodne", "Sporty zimowe", "Turystyka"];
const POLISH = "ąćęłńóśźż";

const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1440, height: 900 };

const scrollWidth = (page: Page): Promise<number> => page.evaluate(() => document.documentElement.scrollWidth);

test.describe("no request leaves the stack", () => {
  for (const path of ["/", LISTING_PATH, PRODUCT_PATH]) {
    test(`${path}: every request of the browser goes to a stack host`, async ({ page }) => {
      const hosts = new Set<string>();
      page.on("request", (request) => {
        if (request.url().startsWith("data:") || request.url().startsWith("blob:")) return;
        hosts.add(new URL(request.url()).hostname);
      });
      await page.goto(path);
      await page.evaluate(() => document.fonts.ready);
      await page.waitForLoadState("networkidle");
      // The stack publishes every service (store, imgproxy, ...) on localhost; a font host or a CDN would be another name.
      expect([...hosts]).toEqual(["localhost"]);
    });
  }
});

test.describe("the font of the direction", () => {
  test("the Polish letters are drawn in Outfit from the files of the store", async ({ page }) => {
    await page.goto("/");
    const report = await page.evaluate(async (polish) => {
      await document.fonts.load('600 16px "Outfit"', polish);
      await document.fonts.load('400 16px "Outfit"', polish);
      await document.fonts.ready;
      const faces = [...document.fonts].filter((face) => face.family.replaceAll('"', "") === "Outfit");
      return {
        checked: document.fonts.check('600 16px "Outfit"', polish) && document.fonts.check('400 16px "Outfit"', polish),
        loaded: faces.filter((face) => face.status === "loaded").length,
        errors: faces.filter((face) => face.status === "error").length,
        family: getComputedStyle(document.body).fontFamily,
        heading: getComputedStyle(document.querySelector("header a")!).fontFamily,
      };
    }, POLISH);
    expect(report.family).toMatch(/Outfit/);
    expect(report.heading).toMatch(/Outfit/);
    expect(report.errors).toBe(0);
    // `latin` (ó, Latin text) and `latin-ext` (ąćęłńśźż) of the page text.
    expect(report.loaded).toBeGreaterThanOrEqual(2);
    expect(report.checked).toBe(true);
  });
});

test.describe("the header on a phone", () => {
  test.use({ viewport: PHONE, hasTouch: true });

  test("the tab order is the wordmark, Menu, cart, account, then the search; no horizontal scroll", async ({ page }) => {
    await page.goto("/");
    // The account link appears after the session is read in the browser.
    await expect(page.getByRole("banner").getByRole("link", { name: "Zaloguj się" })).toBeVisible();
    await expect(page.getByTestId("cart-count")).toBeVisible();
    const focused = async (): Promise<string> =>
      page.evaluate(() => {
        const element = document.activeElement as HTMLElement;
        if (element.matches("input[type=search]")) return "search field";
        if (element.matches("button[type=submit]")) return "search button";
        return (element.innerText || element.getAttribute("aria-label") || "").trim().replace(/\s+/g, " ");
      });
    const order: string[] = [];
    await page.locator("body").press("Tab");
    for (let step = 0; step < 6; step++) {
      order.push(await focused());
      await page.keyboard.press("Tab");
    }
    expect(order).toEqual(["Worf", "Menu", expect.stringMatching(/^Koszyk/), "Zaloguj się", "search field", "search button"]);
    expect(await scrollWidth(page)).toBeLessThanOrEqual(PHONE.width);
  });

  test("two rows: the wordmark, Menu, cart and account in the first, the search on the full width under them", async ({ page }) => {
    await page.goto("/");
    const header = page.getByRole("banner");
    const box = async (locator: ReturnType<Page["locator"]>) => (await locator.boundingBox())!;
    const brand = await box(header.getByRole("link", { name: "Worf" }));
    const menu = await box(header.getByRole("button", { name: "Menu" }));
    const cart = await box(header.getByRole("link", { name: /Koszyk/ }));
    const search = await box(header.getByRole("search"));
    expect(Math.abs(menu.y - brand.y)).toBeLessThan(30);
    expect(Math.abs(cart.y - brand.y)).toBeLessThan(30);
    expect(brand.x).toBeLessThan(menu.x);
    expect(menu.x).toBeLessThan(cart.x);
    expect(search.y).toBeGreaterThan(menu.y + menu.height);
    expect(search.width).toBeGreaterThan(PHONE.width - 40);
  });

  test("a logged-in customer keeps the first row on the screen: orders and logout are reachable, no horizontal scroll", async ({ page }) => {
    await page.goto("/");
    await logInAsJan(page);
    const header = page.getByRole("banner");
    for (const control of [header.getByRole("link", { name: "Moje zamówienia" }), header.getByRole("button", { name: "Wyloguj się" }), header.getByRole("link", { name: /Koszyk/ })]) {
      const box = (await control.boundingBox())!;
      expect(box.x).toBeGreaterThanOrEqual(0);
      expect(box.x + box.width).toBeLessThanOrEqual(PHONE.width);
      expect(box.width).toBeGreaterThanOrEqual(24);
    }
    expect(await scrollWidth(page)).toBeLessThanOrEqual(PHONE.width);
    await header.getByRole("button", { name: "Wyloguj się" }).click();
    await expect(header.getByRole("link", { name: "Zaloguj się" })).toBeVisible();
  });

  test("Menu opens the list of departments under the header and closes with Escape", async ({ page }) => {
    await page.goto("/");
    const menu = page.getByRole("button", { name: "Menu" });
    await expect(menu).toHaveAttribute("aria-expanded", "false");
    await menu.click();
    await expect(menu).toHaveAttribute("aria-expanded", "true");
    const list = page.locator(`[id="${await menu.getAttribute("aria-controls")}"]`);
    await expect(list.getByRole("link", { name: "Turystyka", exact: true })).toBeVisible();
    expect(await scrollWidth(page)).toBeLessThanOrEqual(PHONE.width);
    await page.keyboard.press("Escape");
    await expect(menu).toHaveAttribute("aria-expanded", "false");
    await expect(menu).toBeFocused();
  });
});

test.describe("the header on a desktop", () => {
  test.use({ viewport: DESKTOP });

  test("one row: wordmark, departments, search, cart, account; the panel spans the full width in four columns", async ({ page }) => {
    await page.goto("/");
    const header = page.getByRole("banner");
    await expect(header.getByRole("link", { name: "Zaloguj się" })).toBeVisible();
    const box = async (locator: ReturnType<Page["locator"]>) => (await locator.boundingBox())!;
    const brand = await box(header.getByRole("link", { name: "Worf" }));
    const search = await box(header.getByRole("search"));
    const cart = await box(header.getByRole("link", { name: /Koszyk/ }));
    const account = await box(header.getByRole("link", { name: "Zaloguj się" }));
    for (const part of [search, cart, account]) expect(Math.abs(part.y + part.height / 2 - (brand.y + brand.height / 2))).toBeLessThan(40);
    expect(search.x).toBeLessThan(cart.x);
    expect(cart.x).toBeLessThan(account.x);
    await expect(header.getByRole("button", { name: "Menu" })).toBeHidden();

    await header.getByRole("button", { name: "Turystyka", exact: true }).click();
    const panel = page.locator(`[id="${await header.getByRole("button", { name: "Turystyka", exact: true }).getAttribute("aria-controls")}"]`);
    await expect(panel).toBeVisible();
    const panelBox = (await panel.boundingBox())!;
    expect(panelBox.x).toBeLessThanOrEqual(1);
    expect(panelBox.width).toBeGreaterThanOrEqual(DESKTOP.width - 2);
    const columns = await panel.locator(":scope > div > ul > li").evaluateAll((items) => new Set(items.map((item) => Math.round(item.getBoundingClientRect().left))).size);
    expect(columns).toBe(4);
  });
});

test.describe("the footer", () => {
  test("desktop: the wordmark on the left, the department links on the right in two columns, one line under them", async ({ page }) => {
    await page.setViewportSize(DESKTOP);
    await page.goto("/");
    const footer = page.getByRole("contentinfo");
    await expect(footer.getByRole("navigation", { name: "Linki w stopce" }).getByRole("link")).toHaveText(DEPARTMENTS);
    const brand = (await footer.getByRole("link", { name: "Worf" }).boundingBox())!;
    const links = await footer.getByRole("navigation").getByRole("link").evaluateAll((items) => items.map((item) => item.getBoundingClientRect()));
    expect(brand.x).toBeLessThan(DESKTOP.width / 4);
    expect(Math.min(...links.map((rect) => rect.x))).toBeGreaterThan(DESKTOP.width / 2);
    expect(new Set(links.map((rect) => Math.round(rect.x))).size).toBe(2);
    await expect(footer).toContainText(`© ${new Date().getFullYear()} Worf · Zdjęcia: Unsplash`);
    expect(await scrollWidth(page)).toBeLessThanOrEqual(DESKTOP.width);
  });

  test("phone: the wordmark above the links, two columns, targets 44 px tall", async ({ page }) => {
    await page.setViewportSize(PHONE);
    await page.goto("/");
    const footer = page.getByRole("contentinfo");
    await expect(footer.getByRole("navigation", { name: "Linki w stopce" }).getByRole("link")).toHaveText(DEPARTMENTS);
    const brand = (await footer.getByRole("link", { name: "Worf" }).boundingBox())!;
    const links = await footer.getByRole("navigation").getByRole("link").evaluateAll((items) => items.map((item) => item.getBoundingClientRect()));
    expect(brand.y + brand.height).toBeLessThanOrEqual(Math.min(...links.map((rect) => rect.y)));
    expect(new Set(links.map((rect) => Math.round(rect.x))).size).toBe(2);
    for (const rect of links) expect(rect.height).toBeGreaterThanOrEqual(44);
    await expect(footer).toContainText("Zdjęcia: Unsplash");
    expect(await scrollWidth(page)).toBeLessThanOrEqual(PHONE.width);
  });

  test("a department link leads to its category", async ({ page }) => {
    await page.goto("/");
    await page.getByRole("contentinfo").getByRole("link", { name: "Rower", exact: true }).click();
    await expect(page).toHaveURL(/\/c\/rower$/);
    await expect(page.getByRole("heading", { level: 1, name: "Rower" })).toBeVisible();
  });
});

test.describe("the 404 page", () => {
  for (const [what, path] of [
    ["an unknown address", "/nie-ma-takiej-strony"],
    ["an unknown product", "/p/nie-ma-takiego-produktu"],
  ]) {
    test(`${what} shows the message, the way back, the header, the footer and noindex`, async ({ page }) => {
      const response = await page.goto(path);
      expect(response?.status()).toBe(404);
      await expect(page.getByRole("heading", { level: 1, name: "Nie znaleźliśmy tej strony." })).toBeVisible();
      await expect(page.getByRole("banner").getByRole("link", { name: "Worf" })).toBeVisible();
      await expect(page.getByRole("contentinfo")).toBeVisible();
      await expect(page.locator('meta[name="robots"]')).toHaveAttribute("content", /noindex/);
      const back = page.getByRole("main").getByRole("link", { name: "Wróć do sklepu" });
      await expect(back).toHaveAttribute("href", "/");
      await back.click();
      await expect(page).toHaveURL(/\/$/);
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
    });
  }

  for (const viewport of [DESKTOP, PHONE]) {
    test(`axe finds no serious or critical violation at ${viewport.width} px`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await page.goto("/nie-ma-takiej-strony");
      await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
      await page.evaluate(() => document.fonts.ready);
      const results = await new AxeBuilder({ page }).exclude("nextjs-portal").analyze();
      const serious = results.violations.filter((violation) => violation.impact === "serious" || violation.impact === "critical");
      expect(serious.map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`)).toEqual([]);
      expect(await scrollWidth(page)).toBeLessThanOrEqual(viewport.width);
    });
  }
});
