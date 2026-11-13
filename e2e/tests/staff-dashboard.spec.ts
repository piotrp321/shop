import { expect, test } from "@playwright/test";
import { staff } from "../support/accounts";
import { openMenuItem, panelMenu } from "../support/panel-menu";
import { loginStaff } from "../support/staff-login";
import { urls } from "../support/urls";

test("admin sees the dashboard and opens „Stan systemu” from the menu", async ({ page }) => {
  await loginStaff(page, staff.admin);

  await expect(page.getByRole("heading", { level: 1, name: "Pulpit" })).toBeVisible();
  await expect(page.getByRole("heading", { level: 2, name: "Produkty", exact: true })).toBeVisible();
  await expect(page.getByTestId("product-count")).toHaveText(/^[\d\s ]+$/);
  await expect(page.getByRole("heading", { level: 2, name: "Do obsługi" })).toBeVisible();
  await expect(page.getByRole("heading", { level: 2, name: "Ostatnie zamówienia" })).toBeVisible();
  // The system status has its own screen: the dashboard does not show it.
  await expect(page.getByTestId("service")).toHaveCount(0);

  await openMenuItem(page, "Stan systemu");

  await expect(page).toHaveURL(`${urls.admin}/system`);
  await expect(page.getByRole("heading", { level: 1, name: "Stan systemu" })).toBeVisible();
  await expect(page.getByTestId("service")).toHaveCount(8);
  for (const status of await page.getByTestId("service-tag").allTextContents()) {
    expect(["Działa", "Nie działa"]).toContain(status.trim());
  }
  await expect(page.getByRole("heading", { level: 2, name: "Zdarzenia czekające na wysłanie" })).toBeVisible();
  await expect(page.getByTestId("outbox-pending")).toHaveText(/^\d+$/);
  await expect(page.getByTestId("updated-at")).toContainText("Dane z");
});

test("support sees the tasks of the orders and the latest orders, and no system status", async ({ page }) => {
  await loginStaff(page, staff.support);

  await expect(page.getByTestId("product-count")).toHaveText(/^[\d\s ]+$/);
  await expect(page.getByTestId("todo-label")).toHaveText(["Opłacone do wysłania", "Czekają na płatność", "Zwroty do wykonania"]);
  await expect(page.getByRole("heading", { level: 2, name: "Ostatnie zamówienia" })).toBeVisible();
  await expect(panelMenu(page).getByRole("link", { name: "Stan systemu" })).toHaveCount(0);

  // The route is guarded: the dashboard with a message that a reload does not repeat.
  await page.goto(`${urls.admin}/system`);
  await expect(page.getByRole("alert").filter({ hasText: "Nie masz dostępu do tej strony." })).toBeVisible();
  await expect(page).toHaveURL(`${urls.admin}/`);
  await expect(page.getByRole("heading", { level: 1, name: "Pulpit" })).toBeVisible();
});

test("marketing sees the promotions and the codes, and does not ask for the orders", async ({ page }) => {
  const orderRequests: string[] = [];
  page.on("request", (request) => {
    if (new URL(request.url()).pathname === "/api/v1/admin/orders") orderRequests.push(request.url());
  });

  await loginStaff(page, staff.marketing);

  await expect(page.getByTestId("product-count")).toHaveText(/^[\d\s ]+$/);
  await expect(page.getByTestId("todo-label")).toHaveText(["Aktywne promocje", "Kody rabatowe"]);
  await expect(page.getByRole("heading", { level: 2, name: "Ostatnie zamówienia" })).toHaveCount(0);
  expect(orderRequests).toEqual([]);

  const filtered = page.waitForRequest((request) => {
    const url = new URL(request.url());
    return url.pathname === "/api/v1/admin/promotions" && url.searchParams.get("status") === "active";
  });
  await page.getByTestId("todo-item").filter({ hasText: "Aktywne promocje" }).click();
  await filtered;
  await expect(page).toHaveURL(`${urls.admin}/promotions?status=active`);
  await expect(page.getByRole("heading", { level: 1, name: "Promocje" })).toBeVisible();
  await expect(page.getByTestId("status-filter")).toContainText("Aktywne");
  for (const status of await page.getByTestId("promotion-status").allTextContents()) {
    expect(status.trim()).toBe("Aktywna");
  }
});
