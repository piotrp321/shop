import { expect, type Browser, type Locator, type Page } from "@playwright/test";
import { customers, staff } from "./accounts";
import { placeAndPayOrder } from "./orders";
import { openMenuItem } from "./panel-menu";
import { logInAs } from "./shop";
import { loginStaff } from "./staff-login";
import { urls } from "./urls";

/**
 * A panel session of a staff member in a browser context of its own: the customer of the test stays logged in to the
 * store in the context of the test, and the cookies of the store, the panel and Keycloak (all on localhost) do not mix.
 */
export async function openPanelAs(browser: Browser, account: (typeof staff)[keyof typeof staff]): Promise<{ page: Page; close: () => Promise<void> }> {
  const context = await browser.newContext({ locale: "pl-PL" });
  // A new context does not get the timeouts of the project.
  context.setDefaultTimeout(10_000);
  context.setDefaultNavigationTimeout(20_000);
  const page = await context.newPage();
  try {
    await loginStaff(page, account);
  } catch (error) {
    await context.close();
    throw error;
  }
  return { page, close: () => context.close() };
}

/** The list of orders, reached from the menu of the panel (the page the staff member is on has the menu). */
export async function openOrderList(page: Page): Promise<void> {
  await openMenuItem(page, "Lista zamówień", "Zamówienia");
  await expect(page.getByRole("heading", { level: 1, name: "Zamówienia" })).toBeVisible();
}

/**
 * Searches the list for the exact order number and waits until it shows the order and only it. The list holds the
 * orders of every earlier run, so the test always looks for its own order by the number, never by position.
 */
export async function searchOrder(page: Page, number: string): Promise<Locator> {
  await page.getByRole("searchbox", { name: "Szukaj po numerze zamówienia lub e-mailu" }).fill(number);
  await page.getByRole("searchbox", { name: "Szukaj po numerze zamówienia lub e-mailu" }).press("Enter");
  const rows = page.getByTestId("order-row");
  await expect(rows).toHaveCount(1);
  const row = rows.first();
  await expect(row.getByTestId("number")).toHaveText(number);
  return row;
}

/** Opens the detail of the order from the list found by its number. */
export async function openOrderDetail(page: Page, number: string): Promise<void> {
  const row = await searchOrder(page, number);
  await row.getByTestId("number").click();
  await expect(page.getByRole("heading", { level: 1, name: `Zamówienie ${number}` })).toBeVisible();
}

/** Confirms the dialog of a change of the order (or of the completion of the refund) with a note. */
export async function confirmWithNote(page: Page, note: string): Promise<void> {
  const dialog = page.getByTestId("change-dialog");
  await dialog.getByTestId("change-note").fill(note);
  await dialog.getByTestId("change-submit").click();
  await expect(dialog, "the change was rejected (validation, permission, conflict or API error)").toBeHidden();
  await expect(page.getByTestId("conflict")).toHaveCount(0);
}

/** Goes back from the detail of an order to the list with the crumbs (there is no „Wróć do listy”). */
export async function backToOrders(page: Page): Promise<void> {
  await page.getByRole("navigation", { name: "Okruszki" }).getByRole("link", { name: "Zamówienia", exact: true }).click();
  await expect(page.getByRole("heading", { level: 1, name: "Zamówienia" })).toBeVisible();
}

/** A customer (Jan) orders one unit of `product` in the store and pays at the simulator, in a browser context of its own; returns the number. */
export async function placeOrderAsJan(browser: Browser, product: string): Promise<string> {
  const context = await browser.newContext({ locale: "pl-PL", baseURL: urls.store });
  context.setDefaultTimeout(10_000);
  context.setDefaultNavigationTimeout(20_000);
  try {
    const page = await context.newPage();
    await page.goto("/");
    await logInAs(page, customers.jan);
    return (await placeAndPayOrder(page, product, "Szybki przelew (demo)")).number;
  } finally {
    await context.close();
  }
}
