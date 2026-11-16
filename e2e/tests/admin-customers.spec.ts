import { expect, test, type Page } from "@playwright/test";
import { staff } from "../support/accounts";
import { documentWidths, expectControlsReachable, viewports, WCAG_TAGS } from "../support/panel-layout";
import { panelMenu } from "../support/panel-menu";
import { closePanelContexts, go, inState, newPanelPage, type PanelState, signInPanelRoles, violationsOf } from "../support/panel-states";
import { ORDERED_PRODUCT, openPanelAs, placeOrderAsJan } from "../support/panel-orders";
import { urls } from "../support/urls";

// The customers of the panel (docs/features/admin-features/spec.md, „Klienci”): the list with the search and the sort, the
// card of a customer reached from an order and back, the look and the accessibility of both, and the roles that may not
// open them. The customer of the tests is a store account (Jan) that places an order of its own in `beforeAll`, so the
// file does not depend on the orders other files leave (it may run in a shard of its own); the tests change nothing.

const SEARCH = "Szukaj klienta po e-mailu lub nazwisku";

let orderNumber = "";
let orderId = "";
let customerId = "";
let customerEmail = "";
let customerName = "";

/** Opens the detail of the order of the test as a staff member who may see the orders. */
async function openOrder(page: Page): Promise<void> {
  await go(page, `/orders/${orderId}`);
  await expect(page.getByRole("heading", { level: 1, name: `Zamówienie ${orderNumber}` })).toBeVisible();
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(360_000);
  await signInPanelRoles(browser, ["admin", "support"]);
  orderNumber = await placeOrderAsJan(browser, ORDERED_PRODUCT);
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await go(page, `/orders?q=${encodeURIComponent(orderNumber)}`);
    const link = page.getByTestId("order-row").getByTestId("number");
    await expect(link).toHaveText(orderNumber);
    orderId = (await link.getAttribute("href"))!.split("/").pop()!;

    await openOrder(page);
    const card = page.getByTestId("customer-card");
    await expect(card, "the order of a store account links to the card of its customer").toBeVisible();
    customerId = (await card.getAttribute("href"))!.split("/").pop()!;

    await card.click();
    await expect(page.getByTestId("contact-email")).toBeVisible();
    customerEmail = (await page.getByTestId("contact-email").innerText()).trim();
    customerName = (await page.getByRole("heading", { level: 1 }).innerText()).trim();
  } finally {
    await page.close();
  }
});

test.afterAll(closePanelContexts);

test("support opens the customer from an order, sees the card with that order and goes back to it", async () => {
  test.setTimeout(90_000);
  const page = await newPanelPage("support", viewports.desktop);
  try {
    await openOrder(page);
    await page.getByTestId("customer-card").click();

    await expect(page).toHaveURL(`${urls.admin}/customers/${customerId}`);
    await expect(page.getByRole("heading", { level: 1, name: customerName })).toBeVisible();
    await expect(panelMenu(page).getByRole("link", { name: "Klienci", exact: true }), "the menu item of the customers is the current one").toHaveAttribute("aria-current", "page");

    // The side column: the contact with mailto: and tel:, the summary, the addresses.
    await expect(page.getByTestId("contact-email")).toHaveAttribute("href", `mailto:${customerEmail}`);
    await expect(page.getByTestId("contact-phone")).toHaveAttribute("href", /^tel:\+?\d+$/);
    await expect(page.getByRole("heading", { level: 2, name: "Podsumowanie" })).toBeVisible();
    expect(Number(await page.getByTestId("summary-orders").innerText())).toBeGreaterThanOrEqual(1);
    await expect(page.getByRole("heading", { level: 2, name: "Adresy" })).toBeVisible();
    await expect(page.getByTestId("address").first()).toBeVisible();

    // The side column stands before the main one in the DOM, so that Tab starts there.
    const side2main = await page.evaluate(() => {
      const side = document.querySelector("[data-testid=customer-side]")!;
      const main = document.querySelector("[data-testid=customer-main]")!;
      return Boolean(side.compareDocumentPosition(main) & Node.DOCUMENT_POSITION_FOLLOWING);
    });
    expect(side2main).toBe(true);

    // The main column lists the order, which leads back to its detail.
    const row = page.getByTestId("customer-main").getByTestId("order-row").filter({ hasText: orderNumber });
    await expect(row).toHaveCount(1);
    await row.getByTestId("number").click();
    await expect(page).toHaveURL(`${urls.admin}/orders/${orderId}`);
    await expect(page.getByRole("heading", { level: 1, name: `Zamówienie ${orderNumber}` })).toBeVisible();

    // The browser's Back leads to the card again.
    await page.goBack();
    await expect(page.getByRole("heading", { level: 1, name: customerName })).toBeVisible();
  } finally {
    await page.close();
  }
});

test("the list finds the customer by the e-mail, and the sorts other than the last order are off while the search is applied", async () => {
  test.setTimeout(90_000);
  const page = await newPanelPage("support", viewports.desktop);
  try {
    await go(page, "/customers");
    await expect(page.getByRole("heading", { level: 1, name: "Klienci" })).toBeVisible();
    await expect(page.getByTestId("customer-row").first()).toBeVisible();
    await expect(page.getByRole("columnheader", { name: "Ostatnie zamówienie" })).toHaveAttribute("aria-sort", "descending");

    const search = page.getByRole("searchbox", { name: SEARCH });
    await search.fill("ab");
    await expect(page.getByTestId("search-hint")).toHaveText("Wpisz co najmniej 3 znaki, aby wyszukać.");
    await search.press("Enter");
    await expect(page).toHaveURL(`${urls.admin}/customers`);

    await search.fill(customerEmail);
    await search.press("Enter");
    await expect(page).toHaveURL((url) => url.pathname === "/customers" && url.searchParams.get("q") === customerEmail);
    const row = page.getByTestId("customer-row").filter({ has: page.locator(`a[href="/customers/${customerId}"]`) });
    await expect(row).toHaveCount(1);
    await expect(row.getByTestId("email")).toHaveText(customerEmail);
    await expect(row.getByTestId("full-name")).toHaveText(customerName);
    await expect(page.getByTestId("range")).toContainText("Pokazano");

    await page.getByTestId("sort").click();
    await expect(page.getByRole("option", { name: /Nazwa A–Z/ })).toHaveAttribute("aria-disabled", "true");
    await expect(page.getByTestId("sort-hint")).toHaveText("Wyszukiwanie działa tylko z sortowaniem po ostatnim zamówieniu.");
    await page.keyboard.press("Escape");

    // Without the search the other sorts work; the sorted column says so.
    await page.getByTestId("clear-filters").click();
    await expect(page).toHaveURL(`${urls.admin}/customers`);
    await page.getByTestId("sort").click();
    await page.getByRole("option", { name: /Nazwa A–Z/ }).click();
    await expect(page).toHaveURL(`${urls.admin}/customers?sort=name`);
    await expect(page.getByRole("columnheader", { name: "Klient" })).toHaveAttribute("aria-sort", "ascending");

    // The card is reached from the name in the list.
    await page.getByTestId("sort").click();
    await page.getByRole("option", { name: /Ostatnie zamówienie/ }).click();
    await search.fill(customerEmail);
    await search.press("Enter");
    await row.getByTestId("full-name").click();
    await expect(page).toHaveURL(`${urls.admin}/customers/${customerId}`);
  } finally {
    await page.close();
  }
});

test("a card of an unknown customer says that it was not found", async () => {
  test.setTimeout(60_000);
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await go(page, "/customers/does-not-exist");
    await expect(page.getByRole("heading", { level: 1, name: "Nie znaleziono klienta" })).toBeVisible();
    await expect(page.getByTestId("not-found")).toContainText("Nie znaleźliśmy klienta.");
    await page.getByTestId("to-list").click();
    await expect(page.getByRole("heading", { level: 1, name: "Klienci" })).toBeVisible();
  } finally {
    await page.close();
  }
});

const states: PanelState[] = [
  {
    name: "the list of customers",
    role: "support",
    open: async (page) => {
      await go(page, "/customers");
      await expect(page.getByTestId("customer-row").first()).toBeVisible();
      return null;
    },
  },
  {
    name: "the card of a customer",
    role: "admin",
    open: async (page) => {
      await go(page, `/customers/${customerId}`);
      await expect(page.getByTestId("customer-main").getByTestId("order-row").first()).toBeVisible();
      return null;
    },
  },
];

test.describe("axe with the rules of WCAG 2.2 AA", () => {
  for (const state of states) {
    test(`${state.name} has no violation at 1280 and 768 px, contrast included`, async () => {
      test.setTimeout(90_000);
      for (const size of [viewports.desktop, viewports.tablet]) {
        await inState(state, size, async (page) => {
          expect(await violationsOf(page, WCAG_TAGS), `axe at ${size.width} px`).toEqual([]);
        });
      }
    });
  }
});

test.describe("reflow at 320 x 640 px", () => {
  for (const state of states) {
    test(`${state.name} does not scroll sideways and every control is reachable and wholly visible`, async () => {
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

test("a warehouse member lands on the dashboard with the denied message, has no menu item and no link to the card in the order", async ({ browser }) => {
  test.setTimeout(120_000);
  const panel = await openPanelAs(browser, staff.warehouse);
  try {
    const { page } = panel;
    await expect(panelMenu(page).getByRole("button", { name: "Zamówienia", exact: true })).toBeVisible();
    await expect(panelMenu(page).getByRole("link", { name: "Klienci", exact: true })).toHaveCount(0);

    await page.goto(`${urls.admin}/customers`);
    await expect(page.getByRole("alert").filter({ hasText: "Nie masz dostępu do tej strony." })).toBeVisible();
    await expect(page).toHaveURL(`${urls.admin}/`);

    await page.goto(`${urls.admin}/customers/${customerId}`);
    await expect(page.getByRole("alert").filter({ hasText: "Nie masz dostępu do tej strony." })).toBeVisible();
    await expect(page).toHaveURL(`${urls.admin}/`);

    // The warehouse reads the orders, but the order of a customer has no link to its card.
    await page.goto(`${urls.admin}/orders/${orderId}`);
    await expect(page.getByRole("heading", { level: 1, name: `Zamówienie ${orderNumber}` })).toBeVisible();
    await expect(page.getByTestId("other-orders")).toBeVisible();
    await expect(page.getByTestId("customer-card")).toHaveCount(0);
  } finally {
    await panel.close();
  }
});
