import { expect, type Page, test } from "@playwright/test";
import { staff } from "../support/accounts";
import { openMenuItem, panelMenu } from "../support/panel-menu";
import { loginStaff } from "../support/staff-login";
import { urls } from "../support/urls";

// The count of a queue: a number, or „1000+” when the API stopped counting at its limit.
const COUNT = /^(\d+|1000\+)$/;

const ROWS = {
  paid: "Opłacone do wysłania",
  new: "Czekają na płatność",
  refunds: "Zwroty do wykonania",
  overdue: "Zwroty po terminie",
  inventory: "Stany magazynowe",
  low: "Niski stan",
  images: "Produkty bez zdjęć",
  promotions: "Aktywne promocje",
  codes: "Kody rabatowe",
};

const row = (page: Page, label: string) => page.getByTestId("todo-item").filter({ has: page.getByTestId("todo-label").getByText(label, { exact: true }) });

/** The rows of „Do obsługi” with a queue show a count, the two links to promotions and codes do not. */
async function expectCounts(page: Page, labels: string[]) {
  for (const label of labels) {
    await expect(row(page, label).getByTestId("todo-count"), label).toHaveText(COUNT);
  }
}

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

// An amount of the panel („12 345,50 zł”, with the spaces of Intl), or „—” for today between 0:00 and 1:00.
const MONEY = /^(\d{1,3}([\s\u00a0\u202f]\d{3})*,\d{2}[\s\u00a0]zł|—)$/;

test("admin sees the sales of today, 7 and 30 days up to the last full hour", async ({ page }) => {
  await loginStaff(page, staff.admin);

  const sales = page.getByRole("region", { name: "Sprzedaż" });
  await expect(sales.getByRole("heading", { level: 2 })).toHaveText(["Sprzedaż dziś", "Sprzedaż 7 dni", "Sprzedaż 30 dni"]);
  for (const value of await sales.getByTestId("kpi-value").allTextContents()) {
    expect(value.trim()).toMatch(MONEY);
  }
  // The 7 and 30 days always have a completed hour: their orders and their comparison are there.
  const tiles = sales.getByTestId("kpi-tile");
  for (const index of [1, 2]) {
    await expect(tiles.nth(index).getByTestId("kpi-footer")).toContainText(/Zamówienia: \d/);
    await expect(tiles.nth(index).getByTestId("kpi-trend")).toHaveText(/(vs poprzednie (7|30) dni|brak danych do porównania)$/);
  }
  await expect(tiles.first().getByTestId("kpi-description")).toHaveText(/^(Od północy do pełnej godziny, wg godzin zegara\.|Pierwsze dane po 1:00)$/);
  await expect(sales.getByTestId("sales-until")).toHaveText(/^Dane do \d{2}:00$/);
});

test("support sees the tasks of the orders and the latest orders, and no system status", async ({ page }) => {
  await loginStaff(page, staff.support);

  await expect(page.getByTestId("product-count")).toHaveText(/^[\d\s ]+$/);
  await expect(page.getByTestId("todo-label")).toHaveText([ROWS.paid, ROWS.new, ROWS.refunds, ROWS.overdue]);
  await expectCounts(page, [ROWS.paid, ROWS.new, ROWS.refunds, ROWS.overdue]);
  // The queues of the others are null for support: no tile of the products without images, no footer of „Produkty”.
  await expect(page.getByTestId("kpi-tile")).toHaveCount(2);
  await expect(page.getByTestId("products-without-images")).toHaveCount(0);
  // The sales only for an administrator.
  await expect(page.getByRole("region", { name: "Sprzedaż" })).toHaveCount(0);
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
  await expect(page.getByTestId("todo-label")).toHaveText([ROWS.images, ROWS.promotions, ROWS.codes]);
  await expectCounts(page, [ROWS.images]);
  await expect(page.getByTestId("products-without-images")).toHaveText(COUNT);
  await expect(page.getByRole("region", { name: "Sprzedaż" })).toHaveCount(0);
  await expect(page.getByRole("heading", { level: 2, name: "Ostatnie zamówienia" })).toHaveCount(0);
  expect(orderRequests).toEqual([]);

  const filtered = page.waitForRequest((request) => {
    const url = new URL(request.url());
    return url.pathname === "/api/v1/admin/promotions" && url.searchParams.get("status") === "active";
  });
  await row(page, ROWS.promotions).click();
  await filtered;
  await expect(page).toHaveURL(`${urls.admin}/promotions?status=active`);
  await expect(page.getByRole("heading", { level: 1, name: "Promocje" })).toBeVisible();
  await expect(page.getByRole("combobox", { name: "Status" }), "the Select „Status” shows the status of the address").toHaveText("Aktywne");
  for (const status of await page.getByTestId("promotion-status").allTextContents()) {
    expect(status.trim()).toBe("Aktywna");
  }
});

test("warehouse sees the stock queues and follows them to the filtered lists", async ({ page }) => {
  await loginStaff(page, staff.warehouse);

  await expect(page.getByTestId("todo-label")).toHaveText([ROWS.paid, ROWS.inventory, ROWS.low]);
  await expectCounts(page, [ROWS.paid, ROWS.inventory, ROWS.low]);
  await expect(page.getByTestId("kpi-tile")).toHaveCount(2);
  await expect(page.getByRole("region", { name: "Sprzedaż" })).toHaveCount(0);

  const lowStock = page.waitForRequest((request) => {
    const url = new URL(request.url());
    return url.pathname === "/api/v1/admin/stock" && url.searchParams.get("level") === "low_stock";
  });
  await row(page, ROWS.low).click();
  await lowStock;
  await expect(page).toHaveURL(`${urls.admin}/inventory?level=low_stock`);
  await expect(page.getByRole("heading", { level: 1, name: "Stany magazynowe" })).toBeVisible();
  await expect(page.getByTestId("level-filter"), "the Select „Poziom” shows the level of the address").toHaveText("Niski stan");
});

test("every queue of the administrator opens its filtered list", async ({ page }) => {
  await loginStaff(page, staff.admin);
  await expect(page.getByTestId("todo-label")).toHaveText(Object.values(ROWS));

  const lists = [
    { label: ROWS.paid, url: "/orders?status=paid", heading: "Zamówienia" },
    { label: ROWS.new, url: "/orders?status=new", heading: "Zamówienia" },
    { label: ROWS.refunds, url: "/orders?refund=pending", heading: "Zwroty do wykonania" },
    { label: ROWS.overdue, url: "/orders?refund=overdue", heading: "Zwroty do wykonania" },
    { label: ROWS.inventory, url: "/inventory?level=out_of_stock", heading: "Stany magazynowe" },
    { label: ROWS.low, url: "/inventory?level=low_stock", heading: "Stany magazynowe" },
    { label: ROWS.images, url: "/products?images=none", heading: "Produkty" },
  ];
  for (const list of lists) {
    await row(page, list.label).click();
    await expect(page, list.label).toHaveURL(`${urls.admin}${list.url}`);
    await expect(page.getByRole("heading", { level: 1, name: list.heading, exact: true }), list.label).toBeVisible();
    await page.goBack();
    await expect(page.getByRole("heading", { level: 1, name: "Pulpit" })).toBeVisible();
  }

  // The tile „Bez zdjęć” leads to the same list, and the products in it have no image.
  await page.getByRole("link", { name: "Bez zdjęć", exact: true }).click();
  await expect(page).toHaveURL(`${urls.admin}/products?images=none`);
  await expect(page.getByTestId("images-filter")).toHaveText("Bez zdjęć");
});
