import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page } from "@playwright/test";
import { expectTabOrderIsVisualOrder, focusablesInDomOrder, rowCount } from "../support/focus-order";
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

  test("the tab order is the visual order: the wordmark, the departments, the cart, the account, the search", async ({ page }) => {
    await page.goto("/");
    await waitForHeader(page);
    const order = await tabOrder(page, 1 + DEPARTMENTS.length + 4);
    expect(order).toEqual(["Worf", ...DEPARTMENTS, expect.stringMatching(/^Koszyk/), "Zaloguj się", "search field", "search button"]);
  });

  test("one row: wordmark, departments, cart, account, search; the panel spans the full width in four columns", async ({ page }) => {
    await page.goto("/");
    const header = page.getByRole("banner");
    await expect(header.getByRole("link", { name: "Zaloguj się" })).toBeVisible();
    const box = async (locator: ReturnType<Page["locator"]>) => (await locator.boundingBox())!;
    const brand = await box(header.getByRole("link", { name: "Worf" }));
    const search = await box(header.getByRole("search"));
    const cart = await box(header.getByRole("link", { name: /Koszyk/ }));
    const account = await box(header.getByRole("link", { name: "Zaloguj się" }));
    for (const part of [search, cart, account]) expect(Math.abs(part.y + part.height / 2 - (brand.y + brand.height / 2))).toBeLessThan(40);
    expect(cart.x).toBeLessThan(account.x);
    expect(account.x).toBeLessThan(search.x);
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

// The order of the document is the order on the screen at every width (WCAG 2.4.3), without a CSS feature that reorders
// the focus: Chromium has such a feature, so the Tab key alone would not tell, the document order against the position does.
const ORDER_WIDTHS = [375, 1024, 1440];
// The row of the header from 1024 px: the widths of laptops and desktops.
const ROW_WIDTHS = [1024, 1280, 1366, 1440, 1920];
const ROW_HEIGHT_LIMIT = 100;

async function settleHeader(page: Page, who: "guest" | "jan"): Promise<void> {
  await page.goto("/");
  if (who === "jan") {
    await logInAsJan(page);
    await expect(page.getByRole("banner").getByRole("button", { name: "Wyloguj się" })).toBeVisible();
  } else {
    await waitForHeader(page);
  }
  await page.evaluate(() => document.fonts.ready);
}

for (const who of ["guest", "jan"] as const) {
  test.describe(`the header as ${who}`, () => {
    test(`the document order and the Tab key follow the visual order at ${ORDER_WIDTHS.join(", ")} px`, async ({ page }) => {
      await settleHeader(page, who);
      for (const width of ORDER_WIDTHS) {
        await page.setViewportSize({ width, height: width === 375 ? 812 : 900 });
        const labels = await expectTabOrderIsVisualOrder(page, page.getByRole("banner"));
        // The search is the last of the row on a wide screen (at 1024 px its button) and the last of the header on a phone.
        expect(labels.at(-1) ?? "", `${width} px`).toMatch(/^button:(Szukaj|Wyszukiwarka)$/);
      }
    });

    test(`one row of the header at ${ROW_WIDTHS.join(", ")} px: every control on one line, below ${ROW_HEIGHT_LIMIT} px, no horizontal scroll`, async ({ page }) => {
      await settleHeader(page, who);
      const header = page.getByRole("banner");
      for (const width of ROW_WIDTHS) {
        await page.setViewportSize({ width, height: 900 });
        const height = Math.round((await header.boundingBox())!.height);
        const tops = await header.getByRole("navigation", { name: "Działy" }).getByRole("button").evaluateAll((buttons) =>
          buttons.filter((button) => button.checkVisibility()).map((button) => Math.round(button.getBoundingClientRect().top)),
        );
        expect(tops, `${width} px: every department button is visible`).toHaveLength(DEPARTMENTS.length);
        expect(new Set(tops).size, `${width} px: the departments are in one row`).toBe(1);
        const controls = await focusablesInDomOrder(header);
        expect(rowCount(controls), `${width} px: ${controls.map((control) => control.label).join(" | ")}`).toBe(1);
        expect(height, `${width} px: the height of the header`).toBeLessThan(ROW_HEIGHT_LIMIT);
        expect(await scrollWidth(page), `${width} px: horizontal scroll`).toBeLessThanOrEqual(width);
        for (const item of controls) expect(item.x, `${width} px: ${item.label} is on the screen`).toBeGreaterThanOrEqual(0);
      }
    });
  });
}

test.describe("the search of the header where the row has no room for the field (1024 to 1279 px)", () => {
  const COMPACT = { width: 1024, height: 800 };
  test.use({ viewport: COMPACT });

  test("a button opens the same form under the header, the field gets the focus, Escape closes it and gives the focus back", async ({ page }) => {
    await page.goto("/");
    await waitForHeader(page);
    const header = page.getByRole("banner");
    const toggle = header.getByRole("button", { name: "Wyszukiwarka" });
    const field = header.getByRole("searchbox", { name: "Szukaj produktów" });
    await expect(toggle).toHaveAttribute("aria-expanded", "false");
    await expect(field).toBeHidden();
    // The form is in the HTML once (here it is closed, so not in the accessibility tree).
    await expect(header.locator("form[role=search]")).toHaveCount(1);

    await toggle.click();
    await expect(toggle).toHaveAttribute("aria-expanded", "true");
    await expect(field).toBeFocused();
    const panel = (await header.getByRole("search").boundingBox())!;
    const bar = (await header.boundingBox())!;
    expect(panel.y).toBeGreaterThanOrEqual(bar.y + bar.height - 1);
    expect(panel.width).toBeGreaterThanOrEqual(COMPACT.width - 2);
    // It does not push the page down: the main content is where it was.
    expect((await page.getByRole("main").boundingBox())!.y).toBeLessThan(panel.y + panel.height);

    await page.keyboard.press("Escape");
    await expect(toggle).toHaveAttribute("aria-expanded", "false");
    await expect(field).toBeHidden();
    await expect(toggle).toBeFocused();
  });

  test("a phrase sent from the panel opens the results, closes the panel and is in the field of /szukaj", async ({ page }) => {
    await page.goto("/");
    await waitForHeader(page);
    const header = page.getByRole("banner");
    await header.getByRole("button", { name: "Wyszukiwarka" }).click();
    await header.getByRole("searchbox", { name: "Szukaj produktów" }).fill("asfalt");
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/\/szukaj\?q=asfalt/);
    await expect(header.getByRole("button", { name: "Wyszukiwarka" })).toHaveAttribute("aria-expanded", "false");
    await expect(header.getByRole("searchbox", { name: "Szukaj produktów" })).toBeHidden();
    await header.getByRole("button", { name: "Wyszukiwarka" }).click();
    await expect(header.getByRole("searchbox", { name: "Szukaj produktów" })).toHaveValue("asfalt");
  });

  test("after a submit that remounts the box (from any page to /szukaj) the focus is on the button", async ({ page }) => {
    await page.goto("/");
    await waitForHeader(page);
    const header = page.getByRole("banner");
    const toggle = header.getByRole("button", { name: "Wyszukiwarka" });
    await toggle.click();
    await header.getByRole("searchbox", { name: "Szukaj produktów" }).fill("kajak");
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/\/szukaj\?q=kajak/);
    await expect(toggle).toHaveAttribute("aria-expanded", "false");
    await expect(toggle).toBeFocused();
  });

  test("a second search sent from /szukaj gives the focus to the button too, and the focus is not taken again by a later box", async ({ page }) => {
    await page.goto("/");
    await waitForHeader(page);
    const header = page.getByRole("banner");
    const toggle = header.getByRole("button", { name: "Wyszukiwarka" });
    const field = header.getByRole("searchbox", { name: "Szukaj produktów" });
    await toggle.click();
    await field.fill("kajak");
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/\/szukaj\?q=kajak/);

    // The box stays (the same page of the slot), so the new search only has the focus to give.
    await toggle.click();
    await field.fill("asfalt");
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/\/szukaj\?q=asfalt/);
    await expect(toggle).toBeFocused();

    // Nothing may be left over for a box that a later navigation mounts: going back to the page before /szukaj must not
    // move the focus to the button.
    await page.goBack();
    await page.goBack();
    await expect(page).toHaveURL(/\/$/);
    await page.waitForTimeout(300);
    expect(await page.evaluate(() => document.activeElement?.id)).not.toBe("header-search-toggle");
  });

  test("hovering a department while the search panel is open closes the panel, so the department panel is not covered", async ({ page }) => {
    await page.goto("/");
    await waitForHeader(page);
    const header = page.getByRole("banner");
    const toggle = header.getByRole("button", { name: "Wyszukiwarka" });
    await toggle.click();
    await expect(toggle).toHaveAttribute("aria-expanded", "true");
    const department = page.getByRole("navigation", { name: /Działy/ }).getByRole("button").first();
    await department.hover();
    await expect(department).toHaveAttribute("aria-expanded", "true");
    await expect(toggle).toHaveAttribute("aria-expanded", "false");
    await expect(header.getByRole("searchbox", { name: "Szukaj produktów" })).toBeHidden();
    const link = page.getByRole("link", { name: /Wszystko z działu/ }).first();
    await expect(link).toBeVisible();
    const box = (await link.boundingBox())!;
    const top = await page.evaluate(({ x, y }) => document.elementFromPoint(x, y)?.closest("a")?.textContent ?? "", { x: box.x + 4, y: box.y + box.height / 2 });
    expect(top).toContain("Wszystko z działu");
  });

  test("without JavaScript the form is a row of the header under the first one, and the button is not shown", async ({ browser }) => {
    const context = await browser.newContext({ viewport: COMPACT, javaScriptEnabled: false, locale: "pl-PL" });
    const page = await context.newPage();
    await page.goto("/");
    const header = page.getByRole("banner");
    await expect(header.getByRole("button", { name: "Wyszukiwarka" })).toBeHidden();
    await expect(header.getByRole("searchbox", { name: "Szukaj produktów" })).toBeVisible();
    const form = (await header.getByRole("search").boundingBox())!;
    const brand = (await header.getByRole("link", { name: "Worf" }).boundingBox())!;
    const main = (await page.getByRole("main").boundingBox())!;
    expect(form.y).toBeGreaterThanOrEqual(brand.y + brand.height);
    expect(form.y + form.height).toBeLessThanOrEqual(main.y + 1);
    await context.close();
  });

  test("from 1280 px the field is in the row and there is no button", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto("/");
    const header = page.getByRole("banner");
    await expect(header.getByRole("searchbox", { name: "Szukaj produktów" })).toBeVisible();
    await expect(header.getByRole("button", { name: "Wyszukiwarka" })).toBeHidden();
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
