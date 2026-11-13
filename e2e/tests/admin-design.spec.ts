import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Browser, type BrowserContext, type Locator, type Page } from "@playwright/test";
import { customers, staff } from "../support/accounts";
import { placeAndPayOrder } from "../support/orders";
import { panelMenu } from "../support/panel-menu";
import { documentWidths, expectControlsReachable, expectWithinWidth, settle, textContrast, viewports, WCAG_TAGS } from "../support/panel-layout";
import { logInAs } from "../support/shop";
import { loginStaff } from "../support/staff-login";
import { urls } from "../support/urls";

// The look and the accessibility of the screens of ADMIN-DESIGN part 1 (docs/features/admin-design/spec.md, criteria 5 to
// 8, 21 and 22) and the E2E checks of the reports #250, #251 and #346.
//
// A staff member logs in once per role (Keycloak asks for the password and the code once); every page after that opens in
// the same browser context and Keycloak's session signs it in without a question, so the tests do not wait for a new
// one-time code.

// A demo product with several saved variants (apps/api/fixtures/catalog.yaml): its form offers the removal of an existing
// variant. The tests only open its form and its dialogs; they change nothing.
const PRODUCT = "Buty trailowe Norvane Hala 500";
// The panel opens the detail of an order, so a stack without any order (a fresh `make seed`) gets one: a customer orders this
// product (50 units in the demo stock, fixtures/stock.yaml) and pays at the simulator.
const ORDERED_PRODUCT = PRODUCT;

type Role = "admin" | "marketing";
const contexts = new Map<Role, BrowserContext>();
let productId = "";
let orderId = "";
let promotionId = "";
let discountCodeId = "";
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

async function signIn(browser: Browser, role: Role): Promise<BrowserContext> {
  const context = await browser.newContext({ locale: "pl-PL" });
  context.setDefaultTimeout(10_000);
  context.setDefaultNavigationTimeout(20_000);
  const page = await context.newPage();
  await loginStaff(page, staff[role]);
  await page.close();
  return context;
}

/** A new page of the signed-in staff member at the size of the window; the caller closes it. */
async function newPanelPage(role: Role, size: { width: number; height: number }): Promise<Page> {
  const page = await contexts.get(role)!.newPage();
  await page.setViewportSize(size);
  return page;
}

/** Opens an address of the panel and waits for the page to be the panel (the sign-in by the session of Keycloak passes by itself). */
async function go(page: Page, path: string): Promise<void> {
  await page.goto(`${urls.admin}${path}`);
  await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
}

/** Places one order as Jan in the store, in a browser context of its own. */
async function placeOrder(browser: Browser): Promise<void> {
  const context = await browser.newContext({ locale: "pl-PL", baseURL: urls.store });
  context.setDefaultTimeout(10_000);
  context.setDefaultNavigationTimeout(20_000);
  try {
    const page = await context.newPage();
    await page.goto("/");
    await logInAs(page, customers.jan);
    await placeAndPayOrder(page, ORDERED_PRODUCT, "Szybki przelew (demo)");
  } finally {
    await context.close();
  }
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(300_000);
  contexts.set("admin", await signIn(browser, "admin"));
  contexts.set("marketing", await signIn(browser, "marketing"));

  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await go(page, `/products?q=${encodeURIComponent(PRODUCT)}`);
    const link = page.getByTestId("product-link").filter({ hasText: PRODUCT }).first();
    await expect(link, `the demo product „${PRODUCT}” is in the list`).toBeVisible();
    productId = (await link.getAttribute("href"))!.split("/").pop()!;
    expect(productId, "the id of the demo product").toMatch(UUID);

    await go(page, "/orders");
    await expect(page.getByTestId("order-row").or(page.getByTestId("empty")).first()).toBeVisible();
    if ((await page.getByTestId("order-row").count()) === 0) {
      await placeOrder(browser);
      await go(page, "/orders");
    }
    const number = page.getByTestId("order-row").first().getByTestId("number");
    await expect(number, "the list has an order").toBeVisible();
    const href = await number.evaluate((element) => (element.closest("a") ?? element.querySelector("a"))?.getAttribute("href") ?? "");
    orderId = href.split("/").pop()!;
    expect(orderId, "the id of an order").toMatch(UUID);

    // The edit pages of the screens that are not migrated yet: the first row of the lists of promotions and discount codes
    // (the demo data, or a row an earlier test added).
    await go(page, "/promotions");
    const promotion = page.getByTestId("promotion-link").first();
    await expect(promotion, "the list of promotions has a row").toBeVisible();
    promotionId = (await promotion.getAttribute("href"))!.split("/").pop()!;
    expect(promotionId, "the id of a promotion").toMatch(UUID);

    await go(page, "/discount-codes");
    const code = page.getByTestId("code-link").first();
    await expect(code, "the list of discount codes has a row").toBeVisible();
    discountCodeId = (await code.getAttribute("href"))!.split("/").pop()!;
    expect(discountCodeId, "the id of a discount code").toMatch(UUID);
  } finally {
    await page.close();
  }
});

test.afterAll(async () => {
  await Promise.all([...contexts.values()].map((context) => context.close()));
  contexts.clear();
});

// ---------------------------------------------------------------------------------------------------------------------
// The screens and windows of the epic.

type PanelState = {
  name: string;
  role: Role;
  /** Opens the screen (and the window); returns the window as the element the controls of the check are in, or null for a plain screen. */
  open: (page: Page) => Promise<Locator | null>;
  /** The widths the state exists at: the drawer of the menu replaces the sidebar below 1024 px only. */
  minWidth?: number;
  maxWidth?: number;
  /** A window or a menu: the width checks of the phone (criterion 8) apply. */
  overlay?: boolean;
};

const dialogByName = (page: Page, name: string | RegExp) => page.getByRole("alertdialog", { name });

const states: PanelState[] = [
  {
    name: "the dashboard",
    role: "admin",
    open: async (page) => {
      await go(page, "/");
      await expect(page.getByRole("heading", { level: 1, name: "Pulpit" })).toBeVisible();
      await expect(page.getByTestId("product-count")).toHaveText(/^[\d\s ]+$/);
      await expect(page.getByTestId("updated-at")).toBeVisible();
      return null;
    },
  },
  {
    name: "the dashboard with the message of no access",
    role: "marketing",
    open: async (page) => {
      await page.goto(`${urls.admin}/system`);
      await expect(page.getByRole("alert").filter({ hasText: "Nie masz dostępu do tej strony." })).toBeVisible();
      await expect(page.getByTestId("product-count")).toHaveText(/^[\d\s ]+$/);
      return null;
    },
  },
  {
    name: "„Stan systemu”",
    role: "admin",
    open: async (page) => {
      await go(page, "/system");
      await expect(page.getByRole("heading", { level: 1, name: "Stan systemu" })).toBeVisible();
      await expect(page.getByTestId("service")).toHaveCount(8);
      await expect(page.getByTestId("updated-at")).toBeVisible();
      return null;
    },
  },
  {
    name: "the list of products",
    role: "admin",
    open: async (page) => {
      await go(page, "/products");
      await expect(page.getByTestId("product-row").first()).toBeVisible();
      return null;
    },
  },
  {
    name: "the form of a new product",
    role: "admin",
    open: async (page) => {
      await go(page, "/products/new");
      await expect(page.getByRole("heading", { level: 1, name: "Nowy produkt" })).toBeVisible();
      await expect(page.getByRole("region", { name: "Zapis formularza" })).toBeVisible();
      return null;
    },
  },
  {
    name: "the form of a product",
    role: "admin",
    open: async (page) => {
      await openProduct(page);
      return null;
    },
  },
  {
    name: "the form of a product with the window of the removal of a variant",
    role: "admin",
    overlay: true,
    open: async (page) => {
      await openProduct(page);
      await page.getByTestId("variant-remove").first().click();
      const dialog = dialogByName(page, /^Usunąć wariant/);
      await expect(dialog).toBeVisible();
      return dialog;
    },
  },
  {
    name: "the form of a product with the window of unsaved changes",
    role: "admin",
    overlay: true,
    open: async (page) => {
      await openProduct(page);
      await page.getByTestId("name").fill(`${PRODUCT} (zmiana)`);
      await expect(page.getByRole("region", { name: "Zapis formularza" })).toBeVisible();
      // The breadcrumbs lead away at every width, the menu only at the wide ones.
      await page.getByRole("navigation", { name: "Okruszki" }).getByRole("link", { name: "Pulpit" }).click();
      const dialog = dialogByName(page, "Masz niezapisane zmiany");
      await expect(dialog).toBeVisible();
      return dialog;
    },
  },
  {
    name: "the drawer of the menu",
    role: "admin",
    overlay: true,
    maxWidth: 1023,
    open: async (page) => {
      await go(page, "/products");
      await page.getByRole("button", { name: "Menu", exact: true }).click();
      const drawer = page.getByRole("dialog", { name: "Menu" });
      await expect(drawer).toBeVisible();
      await expect(drawer.getByRole("navigation", { name: "Menu główne" })).toBeVisible();
      return drawer;
    },
  },
  {
    name: "the menu of the account",
    role: "admin",
    overlay: true,
    open: async (page) => {
      await go(page, "/products");
      await page.getByTestId("account").click();
      const menu = page.getByRole("menu");
      await expect(menu).toBeVisible();
      await expect(menu.getByRole("menuitem", { name: "Wyloguj" })).toBeVisible();
      return menu;
    },
  },
];

async function openProduct(page: Page): Promise<void> {
  await go(page, `/products/${productId}`);
  await expect(page.getByRole("heading", { level: 1, name: PRODUCT })).toBeVisible();
  await expect(page.getByTestId("variant-row").first()).toBeVisible();
}

const widthsOf = (state: PanelState, candidates: { width: number; height: number }[]) =>
  candidates.filter(({ width }) => width >= (state.minWidth ?? 0) && width <= (state.maxWidth ?? Infinity));

/** Opens the state at the size, runs the check, and closes the page (a page with unsaved changes would ask before it leaves). */
async function inState(state: PanelState, size: { width: number; height: number }, check: (page: Page, overlay: Locator | null) => Promise<void>): Promise<void> {
  const page = await newPanelPage(state.role, size);
  try {
    const overlay = await state.open(page);
    await settle(page);
    await check(page, overlay);
  } finally {
    await page.close();
  }
}

async function violationsOf(page: Page, tags: string[] | null, rules?: string[]): Promise<string[]> {
  let builder = new AxeBuilder({ page });
  if (tags !== null) builder = builder.withTags(tags);
  if (rules !== undefined) builder = builder.withRules(rules);
  const results = await builder.analyze();
  return results.violations.map((violation) => `${violation.id} (${violation.impact}): ${violation.nodes.map((node) => node.target.join(" ")).join(", ")}`);
}

test.describe("criterion 5: axe with the rules of WCAG 2.2 AA", () => {
  for (const state of states) {
    test(`${state.name} has no violation at 1280 and 768 px`, async () => {
      test.setTimeout(90_000);
      for (const size of widthsOf(state, [viewports.desktop, viewports.tablet])) {
        await inState(state, size, async (page) => {
          expect(await violationsOf(page, WCAG_TAGS), `axe at ${size.width} px`).toEqual([]);
        });
      }
    });
  }
});

test.describe("criterion 21: reflow at 320 x 640 px", () => {
  for (const state of states) {
    test(`${state.name} does not scroll sideways and every control is reachable and wholly visible`, async () => {
      test.setTimeout(90_000);
      if (state.minWidth !== undefined && state.minWidth > viewports.reflow.width) test.skip();
      await inState(state, viewports.reflow, async (page, overlay) => {
        const widths = await documentWidths(page);
        expect(widths.scrollWidth, "the document does not scroll sideways").toBe(widths.clientWidth);
        if (state.name === "the menu of the account") {
          // The items of a menu are moved with the arrow keys (roving focus), so they are checked where they stand.
          for (const item of await overlay!.getByRole("menuitem").all()) await expectWithinWidth(item, viewports.reflow.width, "an item of the menu");
        } else {
          await expectControlsReachable(page, overlay);
        }
        // Scrolling the controls into view must not have made the document scroll sideways either.
        const after = await documentWidths(page);
        expect(after.scrollWidth).toBe(after.clientWidth);
      });
    });
  }

  test("the menu opens from the drawer on every screen of the epic", async () => {
    test.setTimeout(90_000);
    for (const path of ["/", "/system", "/products", "/products/new", `/products/${productId}`]) {
      const page = await newPanelPage("admin", viewports.reflow);
      try {
        await go(page, path);
        await expect(page.getByRole("complementary"), "the sidebar is not in the layout").toBeHidden();
        await page.getByRole("button", { name: "Menu", exact: true }).click();
        await expect(page.getByRole("dialog", { name: "Menu" }).getByRole("link", { name: "Pulpit", exact: true })).toBeVisible();
      } finally {
        await page.close();
      }
    }
  });
});

test.describe("criterion 8: the windows fit in a phone", () => {
  for (const state of states.filter((candidate) => candidate.overlay)) {
    test(`${state.name} lies in 375 px`, async () => {
      test.setTimeout(60_000);
      await inState(state, viewports.phone, async (_page, overlay) => {
        await expectWithinWidth(overlay!, viewports.phone.width, state.name);
        await expect.poll(async () => (await documentWidths(_page)).scrollWidth).toBeLessThanOrEqual(viewports.phone.width);
      });
    });
  }
});

// ---------------------------------------------------------------------------------------------------------------------
// The shell: the skip link, the current item of the menu and the drawer.

/** The routes of the panel the staff member `admin` sees, with the item of the menu that is the current page on each. */
function routes(): { path: string; current: string }[] {
  return [
    { path: "/", current: "Pulpit" },
    { path: "/products", current: "Lista produktów" },
    { path: "/products/new", current: "Dodaj produkt" },
    { path: `/products/${productId}`, current: "Lista produktów" },
    { path: "/prices", current: "Ceny" },
    { path: "/inventory", current: "Stany magazynowe" },
    { path: "/orders", current: "Lista zamówień" },
    { path: "/orders?refund=pending", current: "Zwroty do wykonania" },
    { path: `/orders/${orderId}`, current: "Lista zamówień" },
    { path: "/promotions", current: "Promocje" },
    { path: "/promotions/new", current: "Promocje" },
    { path: `/promotions/${promotionId}`, current: "Promocje" },
    { path: "/discount-codes", current: "Kody rabatowe" },
    { path: "/discount-codes/new", current: "Kody rabatowe" },
    { path: `/discount-codes/${discountCodeId}`, current: "Kody rabatowe" },
    { path: "/system", current: "Stan systemu" },
  ];
}

test.describe("criterion 6: the shell", () => {
  test("the first Tab on every page of the panel goes to „Przejdź do treści” and Enter moves the focus to <main>", async () => {
    test.setTimeout(180_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      for (const { path } of routes()) {
        await go(page, path);
        await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
        await page.keyboard.press("Tab");
        await expect(page.getByRole("link", { name: "Przejdź do treści" }), `the first Tab on ${path}`).toBeFocused();
        await page.keyboard.press("Enter");
        await expect(page.locator("main"), `Enter on the link on ${path}`).toBeFocused();
        expect(page.url(), "the link does not change the address").toBe(`${urls.admin}${path}`);
      }
    } finally {
      await page.close();
    }
  });

  test("#251 F15: on every route only its own item of the menu is the current page, and „Pulpit” only on the dashboard", async () => {
    test.setTimeout(180_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      for (const { path, current } of routes()) {
        await go(page, path);
        const items = panelMenu(page).locator("[aria-current=page]");
        await expect(items, `the current item on ${path}`).toHaveCount(1);
        await expect(items, `the current item on ${path}`).toHaveText(current);
        const dashboardItem = panelMenu(page).getByRole("link", { name: "Pulpit", exact: true });
        if (path === "/") await expect(dashboardItem).toHaveAttribute("aria-current", "page");
        else await expect(dashboardItem, `„Pulpit” is not the current page on ${path}`).not.toHaveAttribute("aria-current", /.*/);
      }
    } finally {
      await page.close();
    }
  });

  test("at 768 px there is no sidebar, „Menu” opens the drawer, Escape closes it and the focus returns to „Menu”", async () => {
    const page = await newPanelPage("admin", viewports.tablet);
    try {
      await go(page, "/products");
      await expect(page.getByRole("complementary")).toBeHidden();
      await expect(page.getByRole("navigation", { name: "Menu główne" })).toBeHidden();

      const menuButton = page.getByRole("button", { name: "Menu", exact: true });
      await menuButton.click();
      const drawer = page.getByRole("dialog", { name: "Menu" });
      await expect(drawer).toBeVisible();
      await expect(drawer.getByRole("navigation", { name: "Menu główne" })).toBeVisible();

      await page.keyboard.press("Escape");
      await expect(drawer).toBeHidden();
      await expect(menuButton).toBeFocused();
    } finally {
      await page.close();
    }
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// The list of products on a narrow screen (criteria 7 and 22).

test.describe("the list of products on narrow screens", () => {
  test("criterion 7: at 768 px the actions and the status of the first row are wholly visible and the page does not scroll sideways", async () => {
    const page = await newPanelPage("admin", viewports.tablet);
    try {
      await go(page, "/products");
      const row = page.getByTestId("product-row").first();
      await expect(row).toBeVisible();

      await expectWithinWidth(row.getByTestId("product-status"), viewports.tablet.width, "the status");
      const actions = row.getByRole("link", { name: /^(Edytuj|Podgląd w sklepie): / });
      expect(await actions.count(), "the row has its actions").toBeGreaterThan(0);
      for (const action of await actions.all()) await expectWithinWidth(action, viewports.tablet.width, `the action ${await action.getAttribute("aria-label")}`);

      const widths = await documentWidths(page);
      expect(widths.scrollWidth).toBe(widths.clientWidth);
      const region = page.getByRole("region", { name: "Lista produktów" });
      const regionWidths = await region.evaluate((element) => ({ scrollWidth: element.scrollWidth, clientWidth: element.clientWidth }));
      expect(regionWidths.scrollWidth, "the table does not scroll in its region").toBeLessThanOrEqual(regionWidths.clientWidth);
    } finally {
      await page.close();
    }
  });

  test("criterion 22: at 768 and 320 px the category and the last change of the first row are in its main cell", async () => {
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, "/products");
      const row = page.getByTestId("product-row").first();
      await expect(row.getByTestId("product-category")).toBeVisible();
      const category = (await row.getByTestId("product-category").innerText()).trim();
      expect(category).not.toBe("");

      for (const size of [viewports.tablet, viewports.reflow]) {
        await page.setViewportSize(size);
        await expect(row.getByTestId("product-category"), `the column of the category is gone at ${size.width} px`).toBeHidden();
        const main = row.getByTestId("product-link").locator("xpath=ancestor::td[1]");
        await expect(main.getByTestId("line-category"), `the category at ${size.width} px`).toHaveText(`Kategoria: ${category}`);
        await expect(main.getByTestId("line-updated"), `the last change at ${size.width} px`).toHaveText(/^Ostatnia zmiana: \d{2}\.\d{2}\.\d{4}/);
        for (const line of ["line-category", "line-updated"]) {
          await expect(main.getByTestId(line)).toBeVisible();
          await expectWithinWidth(main.getByTestId(line), size.width, line);
        }
      }
    } finally {
      await page.close();
    }
  });
});

// ---------------------------------------------------------------------------------------------------------------------
// The reports closed by the epic (the column „Weryfikacja” of the section „Zgłoszenia zamykane przez epik”).

test.describe("the verification of the reports", () => {
  test("#250: axe finds no color-contrast violation and no button without a name on the list of products and on the form of a product", async () => {
    test.setTimeout(90_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      for (const path of ["/products", "/products/new", `/products/${productId}`]) {
        await go(page, path);
        await expect(page.getByRole("heading", { level: 1 })).toBeVisible();
        await expect(page.locator("[aria-busy=true]")).toHaveCount(0);
        await settle(page);
        expect(await violationsOf(page, null, ["color-contrast", "button-name"]), `axe on ${path}`).toEqual([]);
      }
    } finally {
      await page.close();
    }
  });

  test("#346: the text „Dane z …” has a contrast of at least 4.5:1 and axe finds no color-contrast violation on the dashboard and on „Stan systemu”", async () => {
    test.setTimeout(90_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      for (const path of ["/", "/system"]) {
        await go(page, path);
        const freshness = page.getByTestId("updated-at");
        await expect(freshness).toContainText("Dane z");
        await settle(page);
        const contrast = await textContrast(freshness);
        expect(contrast, `the contrast of „Dane z …” on ${path}`).toBeGreaterThanOrEqual(4.5);
        expect(await violationsOf(page, null, ["color-contrast"]), `axe on ${path}`).toEqual([]);
      }
    } finally {
      await page.close();
    }
  });
});
