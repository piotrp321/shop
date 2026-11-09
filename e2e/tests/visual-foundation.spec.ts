import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { logInAsJan } from "../support/shop";

// Fonts, header, footer and the 404 page of the visual direction (VISUAL-DESIGN-5).

// Demo data (apps/api/fixtures/catalog.yaml).
const LISTING_PATH = "/c/bieganie/buty/szosowe";
const PRODUCT_PATH = "/p/buty-szosowe-tarvik-asfalt-200";
const DEPARTMENTS = ["Bieganie", "Fitness", "Rower", "Sporty drużynowe", "Sporty wodne", "Sporty zimowe", "Turystyka"];

const PHONE = { width: 375, height: 812 };
const DESKTOP = { width: 1440, height: 900 };

const scrollWidth = (page: Page): Promise<number> => page.evaluate(() => document.documentElement.scrollWidth);

/** The label of the focused element: the search controls by role, the rest by their text. */
const focusedLabel = (page: Page): Promise<string> =>
  page.evaluate(() => {
    const element = document.activeElement as HTMLElement;
    if (element.matches("input[type=search]")) return "search field";
    if (element.matches("button[type=submit]")) return "search button";
    return (element.innerText || element.getAttribute("aria-label") || "").trim().replace(/\s+/g, " ");
  });

/** Presses Tab `steps` times from the top of the page and returns what had the focus after each press. */
async function tabOrder(page: Page, steps: number): Promise<string[]> {
  const order: string[] = [];
  await page.locator("body").press("Tab");
  for (let step = 0; step < steps; step++) {
    order.push(await focusedLabel(page));
    await page.keyboard.press("Tab");
  }
  return order;
}

/** Lets the header settle: the cart count and the account link are loaded in the browser. */
async function waitForHeader(page: Page): Promise<void> {
  await expect(page.getByRole("banner").getByRole("link", { name: "Zaloguj się" })).toBeVisible();
  await expect(page.getByTestId("cart-count")).toBeVisible();
}

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
  test("the page is drawn in Outfit from the files of the store, the Polish letters from the latin-ext file", async ({ page }) => {
    // The category name carries Polish letters, so the page needs the `latin-ext` file.
    await page.goto("/c/sporty-druzynowe");
    await expect(page.getByRole("heading", { level: 1, name: "Sporty drużynowe" })).toBeVisible();
    const report = await page.evaluate(async () => {
      await document.fonts.ready;
      // The first family of the computed `font-family` is the one the browser tries first.
      const first = (element: Element): string => (getComputedStyle(element).fontFamily.split(",")[0] ?? "").trim().replaceAll('"', "");
      const faces = [...document.fonts].map((face) => ({
        family: face.family.replaceAll('"', ""),
        status: face.status,
        unicodeRange: face.unicodeRange,
      }));
      return {
        body: first(document.body),
        heading: first(document.querySelector("h1")!),
        faces,
      };
    });
    expect(report.body).not.toBe("");
    expect(report.heading).toBe(report.body);
    expect(report.body).toMatch(/Outfit/);
    // The family the page asks for is a face of the document that finished loading (not the fallback of next/font).
    const own = report.faces.filter((face) => face.family === report.body);
    expect(own.length).toBeGreaterThan(0);
    expect(own.filter((face) => face.status === "error")).toEqual([]);
    expect(own.some((face) => face.status === "loaded")).toBe(true);
    // The Polish letters (U+0104 and on) are in the `latin-ext` file, which the page text made the browser load.
    const latinExt = own.filter((face) => /U\+0*100-/i.test(face.unicodeRange));
    expect(latinExt.length).toBeGreaterThan(0);
    expect(latinExt.every((face) => face.status === "loaded")).toBe(true);
  });
});

test.describe("the header on a phone", () => {
  test.use({ viewport: PHONE, hasTouch: true });

  test("the tab order is the wordmark, Menu, cart, account, then the search; no horizontal scroll", async ({ page }) => {
    await page.goto("/");
    await waitForHeader(page);
    const order = await tabOrder(page, 6);
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

  test("the tab order is the visual order: the wordmark, the departments, the search, the cart, the account", async ({ page }) => {
    await page.goto("/");
    await waitForHeader(page);
    const order = await tabOrder(page, 1 + DEPARTMENTS.length + 4);
    expect(order).toEqual(["Worf", ...DEPARTMENTS, "search field", "search button", expect.stringMatching(/^Koszyk/), "Zaloguj się"]);
  });

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
    expect(await footer.getByRole("link", { name: "Worf" }).evaluate((link) => getComputedStyle(link).fontSize)).toBe("28px");
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
    expect(await footer.getByRole("link", { name: "Worf" }).evaluate((link) => getComputedStyle(link).fontSize)).toBe("24px");
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
  const cases: [string, string][] = [
    ["an unknown address", "/nie-ma-takiej-strony"],
    ["an unknown product", "/p/nie-ma-takiego-produktu"],
  ];
  for (const [what, path] of cases) {
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

test.describe("the notice of a guest cart that did not fit into the customer's", () => {
  const NOTICE = "Część produktów z koszyka gościa nie zmieściła się w koszyku";

  for (const viewport of [PHONE, DESKTOP]) {
    test(`at ${viewport.width} px it is in the flow of the header: above the page, not over it, no horizontal scroll`, async ({ page }) => {
      await page.setViewportSize(viewport);
      await page.route("**/api/cart/summary", (route) => route.fulfill({ json: { itemCount: 3, notice: "merge_truncated" } }));
      await page.goto("/");
      const notice = page.getByRole("banner").getByRole("status");
      await expect(notice).toHaveText(NOTICE);
      const box = (await notice.boundingBox())!;
      const main = (await page.getByRole("main").boundingBox())!;
      const header = (await page.getByRole("banner").boundingBox())!;
      // The notice is the last row of the header and the page starts under it.
      expect(box.y + box.height).toBeLessThanOrEqual(main.y + 0.5);
      expect(box.y + box.height).toBeLessThanOrEqual(header.y + header.height + 0.5);
      expect(box.x).toBeGreaterThanOrEqual(0);
      expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
      expect(await scrollWidth(page)).toBeLessThanOrEqual(viewport.width);
      // The first row keeps its place: the wordmark and the cart stay on one line.
      const brand = (await page.getByRole("banner").getByRole("link", { name: "Worf" }).boundingBox())!;
      const cart = (await page.getByRole("banner").getByRole("link", { name: /Koszyk/ }).boundingBox())!;
      expect(Math.abs(cart.y + cart.height / 2 - (brand.y + brand.height / 2))).toBeLessThan(40);
      expect(box.y).toBeGreaterThanOrEqual(Math.max(brand.y + brand.height, cart.y + cart.height));
    });
  }
});
