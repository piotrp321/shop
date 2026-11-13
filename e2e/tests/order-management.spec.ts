import { expect, test } from "@playwright/test";
import { customers, staff } from "../support/accounts";
import { waitForOrderEmail } from "../support/mailpit";
import { historyEntry, openOrderHistory, placeAndPayOrder } from "../support/orders";
import { openMenuItem } from "../support/panel-menu";
import { confirmWithNote, openOrderDetail, openOrderList, openPanelAs, searchOrder } from "../support/panel-orders";
import { WEBHOOK_TIMEOUT_MS } from "../support/payment";
import { logInAs } from "../support/shop";

// Two products of the checkout tests, for the units each run takes (fixtures/stock.yaml): the shipped order takes its unit
// for good, the cancelled one gives it back.
const SHIPPED_PRODUCT = "Buty szosowe Norvane Tempo 600";
const CANCELLED_PRODUCT = "Buty trailowe Norvane Hala 500";
// The limit of a test: the payment webhook, the panel login (two attempts and the wait for the next TOTP window of the
// staff login), the wait for an e-mail in Mailpit and 90 s for the rest of the steps.
const ORDER_FLOW_TIMEOUT_MS = WEBHOOK_TIMEOUT_MS + 2 * 15_000 + 30_000 + 30_000 + 90_000;

test("Jan pays an order and finds it in his history, warehouse ships it in the panel, and the history and the e-mail say it was shipped", async ({
  page,
  browser,
}) => {
  test.setTimeout(ORDER_FLOW_TIMEOUT_MS);

  await page.goto("/");
  await logInAs(page, customers.jan);
  const { number, total } = await placeAndPayOrder(page, SHIPPED_PRODUCT, "Karta płatnicza (demo)");

  // "Moje zamówienia" lists the order as paid, newest first, and opens its detail.
  await openOrderHistory(page);
  const entry = historyEntry(page, number);
  await expect(entry.getByText("Opłacone", { exact: true })).toBeVisible();
  await expect(entry.getByText("1 szt.")).toBeVisible();
  await expect(entry.getByText(total)).toBeVisible();
  await expect(page.getByRole("main").getByRole("listitem").first(), "the newest order is the first one").toContainText(number);
  await entry.getByRole("link", { name: number }).click();
  await expect(page).toHaveURL(/\/konto\/zamowienia\/[0-9a-f-]+$/);
  await expect(page.getByRole("heading", { level: 1, name: `Zamówienie ${number}` })).toBeVisible();
  await expect(page.getByTestId("order-status")).toHaveText("Opłacone");
  await expect(page.getByRole("list", { name: "Pozycje zamówienia" }).getByText(SHIPPED_PRODUCT)).toBeVisible();
  await expect(page.getByTestId("total-gross")).toHaveText(total);
  const dates = page.getByRole("group", { name: "Daty zamówienia" });
  await expect(dates.getByText("Złożone", { exact: true })).toBeVisible();
  await expect(dates.getByText("Opłacone", { exact: true })).toBeVisible();
  await expect(dates.getByText("Wysłane", { exact: true })).toHaveCount(0);

  // warehouse finds the order by its number and marks it as shipped.
  const panel = await openPanelAs(browser, staff.warehouse);
  try {
    await openOrderList(panel.page);
    await openOrderDetail(panel.page, number);
    await expect(panel.page.getByTestId("status")).toHaveText("Opłacone");
    await expect(panel.page.getByTestId("action-cancel"), "warehouse cannot cancel").toHaveCount(0);
    await panel.page.getByTestId("action-ship").click();
    await confirmWithNote(panel.page, "E2E: paczka u kuriera");
    await expect(panel.page.getByTestId("status")).toHaveText("Wysłane");
    const lastChange = panel.page.getByTestId("history-row").last();
    await expect(lastChange.getByTestId("history-change")).toHaveText("Opłacone → Wysłane");
    await expect(lastChange.getByTestId("history-actor")).toHaveText(staff.warehouse.username);
    await expect(lastChange.getByTestId("history-note")).toHaveText("E2E: paczka u kuriera");
  } finally {
    await panel.close();
  }

  // The history of the customer shows the new status, in the list and in the detail.
  await openOrderHistory(page);
  await expect(historyEntry(page, number).getByText("Wysłane", { exact: true })).toBeVisible();
  await historyEntry(page, number).getByRole("link", { name: number }).click();
  await expect(page.getByTestId("order-status")).toHaveText("Wysłane");
  await expect(page.getByRole("group", { name: "Daty zamówienia" }).getByText("Wysłane", { exact: true })).toBeVisible();

  const email = await waitForOrderEmail(customers.jan.email, number, "shipped");
  expect(email.subject).toBe(`Zamówienie ${number} wysłane`);
  expect(email.text).toContain("Zamówienie zostało wysłane na adres:");
});

test("support cancels Anna's paid order with a note, the panel shows the refund to do and it leaves the list once it is done", async ({
  page,
  browser,
}) => {
  test.setTimeout(ORDER_FLOW_TIMEOUT_MS);

  await page.goto("/");
  await logInAs(page, customers.anna);
  const { number, total } = await placeAndPayOrder(page, CANCELLED_PRODUCT, "Szybki przelew (demo)");

  const panel = await openPanelAs(browser, staff.support);
  try {
    const { page: admin } = panel;
    await openOrderList(admin);
    await openOrderDetail(admin, number);
    await expect(admin.getByTestId("status")).toHaveText("Opłacone");
    await expect(admin.getByTestId("action-ship"), "support cannot ship").toHaveCount(0);
    await expect(admin.getByTestId("refund"), "a paid order has no refund").toHaveCount(0);

    // The note is required.
    await admin.getByTestId("action-cancel").click();
    await admin.getByTestId("change-dialog").getByTestId("change-submit").click();
    await expect(admin.getByTestId("change-note-error")).toBeVisible();
    await confirmWithNote(admin, "E2E: klient zrezygnował");

    await expect(admin.getByTestId("status")).toHaveText("Anulowane");
    const lastChange = admin.getByTestId("history-row").last();
    await expect(lastChange.getByTestId("history-change")).toHaveText("Opłacone → Anulowane");
    await expect(lastChange.getByTestId("history-actor")).toHaveText(staff.support.username);
    await expect(lastChange.getByTestId("history-note")).toHaveText("E2E: klient zrezygnował");
    await expect(admin.getByTestId("cancelled-at")).toContainText("Anulowane przez pracownika");
    const refund = admin.getByTestId("refund");
    await expect(refund.getByTestId("refund-state")).toHaveText("Do wykonania");
    await expect(refund.getByTestId("refund-amount")).toHaveText(total);
    await expect(refund.getByTestId("refund-payment-id")).not.toHaveText("");
    await expect(refund.getByTestId("refund-overdue")).toHaveCount(0);
    await expect(admin.getByTestId("action-cancel")).toHaveCount(0);

    // "Zwroty do wykonania" lists the order (found by its number among the refunds of earlier runs) with the amount.
    await admin.getByTestId("back").click();
    await openMenuItem(admin, "Zwroty do wykonania", "Zamówienia");
    const row = await searchOrder(admin, number);
    await expect(row.getByTestId("status")).toHaveText("Anulowane");
    await expect(row.getByTestId("refund-amount")).toHaveText(total);

    // The refund is made by hand outside the system; the staff member marks it done.
    await row.getByTestId("number").click();
    await expect(admin.getByRole("heading", { level: 1, name: `Zamówienie ${number}` })).toBeVisible();
    await admin.getByTestId("complete-refund").click();
    await confirmWithNote(admin, "E2E: przelew zwrotny wykonany");
    await expect(admin.getByTestId("refund-state")).toHaveText("Wykonany");
    await expect(admin.getByTestId("refund-completed")).toContainText(staff.support.username);
    await expect(admin.getByTestId("refund-note")).toHaveText("E2E: przelew zwrotny wykonany");
    await expect(admin.getByTestId("complete-refund")).toHaveCount(0);
    await expect(admin.getByTestId("status"), "completing the refund does not change the status").toHaveText("Anulowane");

    // It is gone from the refunds to do (the menu item opens the list with the switch on, from the address).
    await admin.getByTestId("back").click();
    await openMenuItem(admin, "Zwroty do wykonania", "Zamówienia");
    await admin.getByRole("searchbox", { name: "Szukaj po numerze zamówienia lub e-mailu" }).fill(number);
    await admin.getByRole("searchbox", { name: "Szukaj po numerze zamówienia lub e-mailu" }).press("Enter");
    await expect(admin.getByTestId("empty")).toHaveText("Nie znaleziono zamówień.");
    await expect(admin.getByTestId("order-row")).toHaveCount(0);
  } finally {
    await panel.close();
  }

  // The customer sees the cancelled order and gets the e-mail about the cancellation by the staff.
  await openOrderHistory(page);
  await expect(historyEntry(page, number).getByText("Anulowane", { exact: true })).toBeVisible();
  const email = await waitForOrderEmail(customers.anna.email, number, "cancelled");
  expect(email.subject).toBe(`Zamówienie ${number} anulowane`);
  expect(email.text).toContain("Zwrócimy płatność.");
});
