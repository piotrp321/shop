import { expect, test, type Page } from "@playwright/test";
import { staff } from "../support/accounts";
import { openMenuItem, panelMenu } from "../support/panel-menu";
import { openOrderList, openPanelAs } from "../support/panel-orders";
import { urls } from "../support/urls";

/** The query of the next request of the panel for the list of orders. */
function nextOrderListQuery(page: Page): Promise<URLSearchParams> {
  return page
    .waitForRequest((request) => new URL(request.url()).pathname === "/api/v1/admin/orders")
    .then((request) => new URL(request.url()).searchParams);
}

const refundSwitch = (page: Page) => page.locator("#refund-pending");
const current = (page: Page) => panelMenu(page).locator("[aria-current=page]");

test("the order list takes its filters from the address and the menu item „Zwroty do wykonania” switches the open list", async ({ browser }) => {
  const panel = await openPanelAs(browser, staff.admin);
  try {
    const { page } = panel;

    // A known status in the address filters the list from the first request.
    let query = nextOrderListQuery(page);
    await page.goto(`${urls.admin}/orders?status=paid`);
    expect((await query).get("status")).toBe("paid");
    await expect(page.getByRole("heading", { level: 1, name: "Zamówienia" })).toBeVisible();
    await expect(page.getByTestId("status-filter")).toContainText("Opłacone");
    await expect(refundSwitch(page)).not.toBeChecked();
    await expect(current(page)).toHaveText("Lista zamówień");
    for (const status of await page.getByTestId("order-row").getByTestId("status").allTextContents()) {
      expect(status.trim()).toBe("Opłacone");
    }

    // The refunds to do from the address.
    query = nextOrderListQuery(page);
    await page.goto(`${urls.admin}/orders?refund=pending`);
    expect((await query).get("refund")).toBe("pending");
    await expect(refundSwitch(page)).toBeChecked();
    await expect(current(page)).toHaveText("Zwroty do wykonania");
    await expect(panelMenu(page).getByRole("button", { name: "Zamówienia", exact: true })).toHaveAttribute("aria-expanded", "true");

    // An unknown value is skipped: the list is the same as without it.
    query = nextOrderListQuery(page);
    await page.goto(`${urls.admin}/orders?status=nope&refund=later`);
    const params = await query;
    expect(params.has("status")).toBe(false);
    expect(params.has("refund")).toBe(false);
    await expect(refundSwitch(page)).not.toBeChecked();
    await expect(page.getByTestId("status-filter")).toContainText("Wszystkie statusy");

    // The menu switches the list that is open, without a reload of the page.
    await page.goto(`${urls.admin}/`);
    await openOrderList(page);
    await expect(current(page)).toHaveText("Lista zamówień");
    await page.evaluate(() => {
      (window as unknown as { openPage: boolean }).openPage = true;
    });
    query = nextOrderListQuery(page);
    await openMenuItem(page, "Zwroty do wykonania", "Zamówienia");
    expect((await query).get("refund")).toBe("pending");
    await expect(page).toHaveURL(/\/orders\?refund=pending$/);
    await expect(refundSwitch(page)).toBeChecked();
    await expect(current(page)).toHaveText("Zwroty do wykonania");
    expect(await page.evaluate(() => (window as unknown as { openPage?: boolean }).openPage), "the page was not reloaded").toBe(true);

    query = nextOrderListQuery(page);
    await openMenuItem(page, "Lista zamówień", "Zamówienia");
    expect((await query).has("refund")).toBe(false);
    await expect(refundSwitch(page)).not.toBeChecked();
    await expect(current(page)).toHaveText("Lista zamówień");
    expect(await page.evaluate(() => (window as unknown as { openPage?: boolean }).openPage), "the page was not reloaded").toBe(true);

    // A filter set in the list is not in the address, yet the menu item of the current address applies it again.
    await openMenuItem(page, "Zwroty do wykonania", "Zamówienia");
    await expect(refundSwitch(page)).toBeChecked();
    query = nextOrderListQuery(page);
    await page.locator("label[for=refund-pending]").click();
    expect((await query).has("refund")).toBe(false);
    await expect(refundSwitch(page)).not.toBeChecked();
    await expect(page).toHaveURL(/\/orders\?refund=pending$/);
    query = nextOrderListQuery(page);
    await openMenuItem(page, "Zwroty do wykonania", "Zamówienia");
    expect((await query).get("refund")).toBe("pending");
    await expect(refundSwitch(page)).toBeChecked();
    await expect(current(page)).toHaveText("Zwroty do wykonania");

    await openMenuItem(page, "Lista zamówień", "Zamówienia");
    await expect(page).toHaveURL(/\/orders$/);
    await expect(refundSwitch(page)).not.toBeChecked();
    query = nextOrderListQuery(page);
    await page.locator("label[for=refund-pending]").click();
    expect((await query).get("refund")).toBe("pending");
    await expect(refundSwitch(page)).toBeChecked();
    query = nextOrderListQuery(page);
    await openMenuItem(page, "Lista zamówień", "Zamówienia");
    expect((await query).has("refund")).toBe(false);
    await expect(refundSwitch(page)).not.toBeChecked();
    await expect(current(page)).toHaveText("Lista zamówień");
    expect(await page.evaluate(() => (window as unknown as { openPage?: boolean }).openPage), "the page was not reloaded").toBe(true);
  } finally {
    await panel.close();
  }
});

test("a role without access to the orders lands on the dashboard with a message that a reload does not repeat", async ({ browser }) => {
  const panel = await openPanelAs(browser, staff.marketing);
  try {
    const { page } = panel;
    await expect(panelMenu(page).getByRole("link", { name: "Lista zamówień" })).toHaveCount(0);

    await page.goto(`${urls.admin}/orders`);

    await expect(page.getByRole("alert").filter({ hasText: "Nie masz dostępu do tej strony." })).toBeVisible();
    await expect(page).toHaveURL(`${urls.admin}/`);

    await page.reload();

    await expect(page.getByRole("heading", { level: 1, name: "Dashboard" })).toBeVisible();
    await expect(page.getByText("Nie masz dostępu do tej strony.")).toHaveCount(0);
  } finally {
    await panel.close();
  }
});
