import { expect, test, type Page } from "@playwright/test";
import { customers, staff } from "../support/accounts";
import { waitForOrderEmail } from "../support/mailpit";
import { historyEntry, openOrderHistory, placeAndPayOrder } from "../support/orders";
import { confirmWithNote, openOrderDetail, openOrderList, openPanelAs } from "../support/panel-orders";
import { WEBHOOK_TIMEOUT_MS } from "../support/payment";
import { logInAs } from "../support/shop";

// The tracking number of a shipment (docs/features/delivery/spec.md, W8, W8a, W10; criteria 9, 10): the warehouse ships an
// order with a number in the panel, the customer sees the number and the link in the store and in the e-mail, the staff
// correct and remove the number after the shipment, and the store shows it at once. The method of the orders is the standard
// courier of the seed (the template https://tracking.example.com/{numer}). Each test takes one unit of Tempo 600 for good
// (the shipment commits the reservation), like order-management.spec.ts; the product has 45 units (fixtures/stock.yaml).
const PRODUCT = "Buty szosowe Norvane Tempo 600";
const METHOD = "Kurier standardowy";
const TRACKING_URL = "https://tracking.example.com/";
const LINK = "Śledź przesyłkę (otwiera się w nowej karcie)";
const ORDER_FLOW_TIMEOUT_MS = WEBHOOK_TIMEOUT_MS + 2 * 15_000 + 30_000 + 30_000 + 150_000;

/** A number of its own for each run, in the shape the API accepts (ASCII letters, digits and hyphens, at most 40). */
const uniqueNumber = (suffix: string): string => `E2E-${Date.now()}-${suffix}`;

/** Opens the detail of the order in "Moje zamówienia" of the customer (the page is logged in and shows the history). */
async function openCustomerOrder(page: Page, number: string): Promise<void> {
  await openOrderHistory(page);
  await historyEntry(page, number).getByRole("link", { name: number }).click();
  await expect(page.getByRole("heading", { level: 1, name: `Zamówienie ${number}` })).toBeVisible();
}

test("the warehouse ships with a number, the customer sees it with its link in the store and in the e-mail, and a correction and a removal reach the store", async ({
  page,
  browser,
}) => {
  test.setTimeout(ORDER_FLOW_TIMEOUT_MS);
  const first = uniqueNumber("A");
  const corrected = uniqueNumber("B");

  await page.goto("/");
  await logInAs(page, customers.jan);
  const { number } = await placeAndPayOrder(page, PRODUCT, "Karta płatnicza (demo)");
  await openCustomerOrder(page, number);
  await expect(page.getByTestId("order-delivery"), "the delivery of the order with its method").toContainText(`Dostawa (${METHOD})`);
  await expect(page.getByRole("group", { name: "Przesyłka" }), "a paid order has no shipment").toHaveCount(0);

  const panel = await openPanelAs(browser, staff.warehouse);
  try {
    const admin = panel.page;
    await openOrderList(admin);
    await openOrderDetail(admin, number);
    await expect(admin.getByTestId("delivery-line")).toContainText(`Dostawa (${METHOD})`);
    await expect(admin.getByTestId("change-tracking-number"), "an order that is not shipped has no number to change").toHaveCount(0);

    // A number of the wrong shape is refused at its field; the window stays open and the field has the focus.
    await admin.getByTestId("action-ship").click();
    const dialog = admin.getByTestId("change-dialog");
    await expect(dialog.getByLabel("Numer przesyłki (opcjonalnie)")).toBeVisible();
    await dialog.getByTestId("change-tracking-number-field").fill("Żółć 1");
    await dialog.getByTestId("change-submit").click();
    await expect(dialog.getByTestId("change-tracking-number-field-error")).toHaveText(
      "Numer przesyłki może mieć 1–40 znaków: litery bez polskich znaków, cyfry i myślniki.",
    );
    await expect(dialog.getByTestId("change-tracking-number-field")).toBeFocused();
    await expect(admin.getByTestId("status"), "nothing was shipped").toHaveText("Opłacone");

    await dialog.getByTestId("change-tracking-number-field").fill(first);
    await confirmWithNote(admin, "E2E: kurier z numerem");
    await expect(admin.getByTestId("status")).toHaveText("Wysłane");
    const shipment = admin.getByTestId("shipment");
    await expect(shipment.getByTestId("tracking-number")).toHaveText(first);
    await expect(shipment.getByRole("link", { name: LINK })).toHaveAttribute("href", `${TRACKING_URL}${first}`);
    await expect(shipment.getByRole("link", { name: LINK })).toHaveAttribute("target", "_blank");

    // The store shows the number and a link that opens in a new tab, which the keyboard reaches.
    await openCustomerOrder(page, number);
    await expect(page.getByTestId("order-status")).toHaveText("Wysłane");
    const group = page.getByRole("group", { name: "Przesyłka" });
    await expect(group.getByTestId("tracking-number")).toHaveText(first);
    const link = group.getByRole("link", { name: LINK });
    await expect(link).toHaveAttribute("href", `${TRACKING_URL}${first}`);
    await expect(link).toHaveAttribute("target", "_blank");
    await expect(link).toHaveAttribute("rel", "noopener noreferrer");
    await link.focus();
    await expect(link).toBeFocused();

    // On a phone the page does not scroll sideways.
    await page.setViewportSize({ width: 375, height: 800 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth), "no horizontal scroll at 375 px").toBeLessThanOrEqual(375);
    await page.setViewportSize({ width: 1280, height: 720 });

    // The e-mail about the shipment has the method, the number and the link, and no line of a carrier.
    const email = await waitForOrderEmail(customers.jan.email, number, "shipped");
    expect(email.text).toContain(`Sposób dostawy: ${METHOD}`);
    expect(email.text).toContain(`Numer przesyłki: ${first}`);
    expect(email.text).toContain(`Śledzenie przesyłki: ${TRACKING_URL}${first}`);
    expect(email.text).not.toContain("Przewoźnik");

    // The correction: a small window on the field, a refusal at the field, a saved number, the focus back on the action.
    const action = admin.getByTestId("change-tracking-number");
    await expect(action).toHaveText("Zmień numer przesyłki");
    await action.click();
    const window = admin.getByTestId("tracking-dialog");
    await expect(window.getByRole("heading", { name: `Zmienić numer przesyłki zamówienia ${number}?` })).toBeVisible();
    await expect(window.getByLabel("Numer przesyłki")).toHaveValue(first);
    await expect(window.getByLabel("Numer przesyłki")).toBeFocused();
    await window.getByTestId("tracking-number-input").fill("zły numer");
    await window.getByTestId("tracking-submit").click();
    await expect(window.getByTestId("tracking-number-error")).toBeVisible();
    await expect(window).toBeVisible();
    await window.getByTestId("tracking-number-input").fill(corrected);
    await window.getByTestId("tracking-submit").click();
    await expect(window).toBeHidden();
    await expect(admin.getByRole("region", { name: "Powiadomienia", exact: true })).toContainText("Numer przesyłki zapisany.");
    await expect(shipment.getByTestId("tracking-number")).toHaveText(corrected);
    await expect(action, "the focus is back on the action").toBeFocused();

    // The customer reads the corrected number after a reload (the e-mail that went out is not sent again).
    await page.reload();
    await expect(page.getByRole("group", { name: "Przesyłka" }).getByTestId("tracking-number")).toHaveText(corrected);
    await expect(page.getByRole("group", { name: "Przesyłka" }).getByRole("link", { name: LINK })).toHaveAttribute("href", `${TRACKING_URL}${corrected}`);

    // An empty field removes the number.
    await action.click();
    await window.getByTestId("tracking-number-input").fill("");
    await window.getByTestId("tracking-submit").click();
    await expect(window).toBeHidden();
    await expect(admin.getByRole("region", { name: "Powiadomienia", exact: true })).toContainText("Numer przesyłki usunięty.");
    await expect(admin.getByTestId("shipment")).toHaveCount(0);
    await expect(action).toHaveText("Dodaj numer przesyłki");
    await page.reload();
    await expect(page.getByTestId("order-status")).toHaveText("Wysłane");
    await expect(page.getByRole("group", { name: "Przesyłka" })).toHaveCount(0);
    await expect(page.getByTestId("order-delivery"), "the method stays without a number").toContainText(`Dostawa (${METHOD})`);
  } finally {
    await panel.close();
  }
});

test("an order shipped without a number has no shipment in the store and the e-mail names the method only; the warehouse adds the number later and support cannot", async ({
  page,
  browser,
}) => {
  test.setTimeout(ORDER_FLOW_TIMEOUT_MS);
  const added = uniqueNumber("C");

  await page.goto("/");
  await logInAs(page, customers.jan);
  const { number } = await placeAndPayOrder(page, PRODUCT, "Szybki przelew (demo)");

  const panel = await openPanelAs(browser, staff.warehouse);
  try {
    const admin = panel.page;
    await openOrderList(admin);
    await openOrderDetail(admin, number);
    await admin.getByTestId("action-ship").click();
    await confirmWithNote(admin, "E2E: kurier bez numeru");
    await expect(admin.getByTestId("status")).toHaveText("Wysłane");
    await expect(admin.getByTestId("shipment"), "no number, no shipment").toHaveCount(0);
    await expect(admin.getByTestId("delivery-line")).toContainText(`Dostawa (${METHOD})`);

    await openCustomerOrder(page, number);
    await expect(page.getByTestId("order-status")).toHaveText("Wysłane");
    await expect(page.getByRole("group", { name: "Przesyłka" })).toHaveCount(0);
    await expect(page.getByRole("link", { name: /Śledź przesyłkę/ })).toHaveCount(0);
    const email = await waitForOrderEmail(customers.jan.email, number, "shipped");
    expect(email.text).toContain(`Sposób dostawy: ${METHOD}`);
    expect(email.text).not.toContain("Numer przesyłki");
    expect(email.text).not.toContain("Śledzenie przesyłki");

    // Adding the number afterwards: the same window, the title of an addition.
    const action = admin.getByTestId("change-tracking-number");
    await expect(action).toHaveText("Dodaj numer przesyłki");
    await action.click();
    const window = admin.getByTestId("tracking-dialog");
    await expect(window.getByRole("heading", { name: `Dodać numer przesyłki zamówienia ${number}?` })).toBeVisible();
    await window.getByTestId("tracking-number-input").fill(added);
    await window.getByTestId("tracking-number-input").press("Enter");
    await expect(window).toBeHidden();
    await expect(admin.getByTestId("shipment").getByTestId("tracking-number")).toHaveText(added);

    await page.reload();
    await expect(page.getByRole("group", { name: "Przesyłka" }).getByTestId("tracking-number")).toHaveText(added);
  } finally {
    await panel.close();
  }

  // Support follows the order up but does not ship, so it has no action of the number either.
  const support = await openPanelAs(browser, staff.support);
  try {
    await openOrderList(support.page);
    await openOrderDetail(support.page, number);
    await expect(support.page.getByTestId("shipment").getByTestId("tracking-number")).toHaveText(added);
    await expect(support.page.getByTestId("change-tracking-number")).toHaveCount(0);
  } finally {
    await support.close();
  }
});
