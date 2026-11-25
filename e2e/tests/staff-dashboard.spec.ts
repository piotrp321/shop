import { expect, type Locator, type Page, test } from "@playwright/test";
import { staff } from "../support/accounts";
import { documentWidths, viewports } from "../support/panel-layout";
import { openMenuItem, panelMenu } from "../support/panel-menu";
import { closePanelContexts, newPanelPage, signInPanelRoles } from "../support/panel-states";
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
  // Structure only: what is in `failed` depends on the tests that ran before in the shard.
  await expect(page.getByRole("heading", { level: 2, name: "Wiadomości" })).toBeVisible();
  await expect(page.getByTestId("failed-count")).toHaveText(/^(\d+|1000\+|Brak)$/);
  const transports = page.getByRole("region", { name: "Kolejki w bazie danych" }).getByRole("row");
  await expect(transports).toHaveCount(6);
  await expect(page.getByTestId("transport-name")).toHaveText([
    "Zdarzenia do wysłania",
    "Komendy opóźnione",
    "Webhooki symulatora płatności",
    "Granice promocji",
    "Zadania w tle",
  ]);
  await expect(page.getByTestId("updated-at")).toContainText("Dane z");
});

// An amount of the panel in the pl-PL format of Intl, or „—” for today between 0:00 and 1:00. Intl groups thousands only
// from 10 000 (minimumGroupingDigits 2): „1148,00 zł”, but „12 345,50 zł”.
const MONEY = /^((\d{1,4}|\d{1,3}([\s\u00a0\u202f]\d{3})+),\d{2}[\s\u00a0]zł|—)$/;

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

// „Ostatnie zamówienia” in the card (docs/features/admin-ux-fixes/spec.md, K5; #509). The demo data has no orders, so the
// answer of the list is a stub: five orders, the first with the longest label of a status („Czeka na płatność”). The
// checks measure the layout in the browser: the width of the scrolling region, the boxes of the labels and of the shadows.
test.describe("„Ostatnie zamówienia” in the card", () => {
  const PLACED = ["2026-11-05T12:30:00+00:00", "2026-11-04T09:05:00+00:00", "2026-11-03T18:45:00+00:00", "2026-11-02T07:15:00+00:00", "2026-11-01T21:00:00+00:00"];
  const NAMES = ["Anna Nowak", "Jan Kowalski-Wiśniewski", "Maria Zielińska", "Piotr Lewandowski", "Ewa Dąbrowska-Kaczmarek"];
  const STATUSES = ["new", "paid", "shipped", "delivered", "cancelled"];
  const ORDERS = {
    items: STATUSES.map((status, index) => ({
      orderId: `00000000-0000-4000-8000-00000000000${index + 1}`,
      number: `R-10000${index + 1}`,
      placedAt: PLACED[index],
      status,
      email: `klient${index + 1}@example.com`,
      fullName: NAMES[index],
      totalGross: 39998 + index * 100_000,
      currency: "PLN",
      itemCount: index + 1,
      refund: null,
    })),
    nextCursor: null,
  };

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    await signInPanelRoles(browser, ["admin"]);
  });
  test.afterAll(closePanelContexts);

  /** A page of the administrator at the size, with the list of the card answered by the stub; the dashboard is open. */
  async function openDashboard(size: { width: number; height: number }) {
    const page = await newPanelPage("admin", size);
    await page.route(
      (url) => url.pathname === "/api/v1/admin/orders" && url.searchParams.get("perPage") === "5",
      (route) => route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify(ORDERS) }),
    );
    await page.goto(urls.admin);
    const card = page.getByTestId("recent-orders");
    await expect(card.getByTestId("recent-order")).toHaveCount(5);
    return { page, card, region: card.getByRole("region", { name: "Ostatnie zamówienia" }) };
  }

  const widthsOf = (region: Locator) => region.evaluate((element) => ({ scrollWidth: element.scrollWidth, clientWidth: element.clientWidth }));

  async function boxOf(locator: Locator) {
    const box = await locator.boundingBox();
    expect(box, "the element has a box").not.toBeNull();
    return box!;
  }

  for (const size of [
    { width: 1280, height: 720 },
    { width: 1440, height: 900 },
  ]) {
    test(`the table fits in the card next to „Do obsługi” at ${size.width} px`, async () => {
      test.setTimeout(60_000);
      const { page, card, region } = await openDashboard(size);
      try {
        // The card stands beside „Do obsługi”, not under it.
        const cardBox = await boxOf(card);
        const todoBox = await boxOf(page.getByRole("heading", { level: 2, name: "Do obsługi" }));
        expect(todoBox.x + todoBox.width, "„Do obsługi” is left of the card").toBeLessThanOrEqual(cardBox.x);
        expect(todoBox.y, "„Do obsługi” is on the height of the card").toBeLessThan(cardBox.y + cardBox.height);

        const { scrollWidth, clientWidth } = await widthsOf(region);
        expect(scrollWidth, `the region does not scroll sideways (scrollWidth ${scrollWidth}, clientWidth ${clientWidth})`).toBeLessThanOrEqual(clientWidth);

        const regionBox = await boxOf(region);
        const statuses = card.getByTestId("order-status");
        await expect(statuses).toHaveCount(5);
        await expect(statuses.first()).toHaveText("Czeka na płatność");
        for (let index = 0; index < 5; index++) {
          const box = await boxOf(statuses.nth(index));
          expect(box.x, `the status of the row ${index + 1} starts in the region`).toBeGreaterThanOrEqual(regionBox.x);
          expect(box.x + box.width, `the status of the row ${index + 1} ends in the region`).toBeLessThanOrEqual(regionBox.x + regionBox.width + 0.5);
        }
      } finally {
        await page.close();
      }
    });
  }

  test("each row shows the number, the customer, the date, the amount and the status, the units once", async () => {
    test.setTimeout(60_000);
    const { page, card, region } = await openDashboard(viewports.desktop);
    try {
      const rows = card.getByTestId("recent-order");
      for (let index = 0; index < 5; index++) {
        const row = rows.nth(index);
        const name = row.getByTestId("order-customer");
        const date = row.getByTestId("order-date");
        await expect(row.getByRole("link", { name: `R-10000${index + 1}` }), "the number").toBeVisible();
        await expect(name).toHaveText(NAMES[index]!);
        await expect(date, "the date").toBeVisible();
        await expect(row.getByTestId("order-total"), "the amount").toBeVisible();
        await expect(row.getByTestId("order-status"), "the status").toBeVisible();
        // The date is the second line of the cell of the customer: under the name, in the same cell.
        const nameBox = await boxOf(name);
        const dateBox = await boxOf(date);
        expect(dateBox.y, "the date is under the name").toBeGreaterThanOrEqual(nameBox.y + nameBox.height - 1);
        expect(dateBox.x, "the date is in the column of the name").toBeLessThan(nameBox.x + 4);
        // The units are in the column „Pozycje” or in a line of the cell of the customer, never in both and never lost.
        const column = row.getByTestId("order-items");
        const line = row.getByTestId("order-items-line");
        const shown = [await column.isVisible(), await line.isVisible()];
        expect(shown.filter(Boolean), `the units of the row ${index + 1} are shown once (column, line: ${shown})`).toHaveLength(1);
        await expect(shown[0] ? column : line).toContainText(String(index + 1));
      }
      const { scrollWidth, clientWidth } = await widthsOf(region);
      expect(scrollWidth).toBeLessThanOrEqual(clientWidth);
    } finally {
      await page.close();
    }
  });

  test("the units are a column of a wide card and a line of the customer in a narrow one", async () => {
    test.setTimeout(60_000);
    const { page, card, region } = await openDashboard({ width: 1920, height: 1080 });
    try {
      await expect(card.getByRole("columnheader", { name: "Pozycje" })).toBeVisible();
      await expect(card.getByTestId("order-items").first()).toBeVisible();
      await expect(card.getByTestId("order-items-line").first()).toBeHidden();
      const wide = await widthsOf(region);
      expect(wide.scrollWidth, "the wide card does not scroll").toBeLessThanOrEqual(wide.clientWidth);

      await page.setViewportSize(viewports.desktop);
      await expect(card.getByRole("columnheader", { name: "Pozycje" })).toBeHidden();
      await expect(card.getByTestId("order-items-line").first()).toBeVisible();
    } finally {
      await page.close();
    }
  });

  for (const size of [viewports.phone, viewports.reflow]) {
    test(`at ${size.width} px the page does not scroll sideways and the region shows the shadow of the edge with more behind it`, async () => {
      test.setTimeout(60_000);
      const { page, card, region } = await openDashboard(size);
      try {
        const document = await documentWidths(page);
        expect(document.scrollWidth, "the page does not scroll sideways").toBeLessThanOrEqual(document.clientWidth);

        const { scrollWidth, clientWidth } = await widthsOf(region);
        test.info().annotations.push({ type: `region at ${size.width} px`, description: `scrollWidth ${scrollWidth}, clientWidth ${clientWidth}` });
        console.log(`region at ${size.width} px: scrollWidth ${scrollWidth}, clientWidth ${clientWidth}`);

        const start = card.getByTestId("scroll-edge-start");
        const end = card.getByTestId("scroll-edge-end");
        if (scrollWidth <= clientWidth) {
          // Nothing behind an edge: no shadow. The shadow is then checked at 320 px.
          expect((await start.isVisible()) || (await end.isVisible()), "a table that fits has no shadow").toBe(false);
          test.skip(size.width === viewports.phone.width, "the table fits at 375 px: the shadow is checked at 320 px");
          expect(scrollWidth, "the table scrolls at 320 px, so there is a shadow to check").toBeGreaterThan(clientWidth);
        }

        const regionBox = await boxOf(region);
        // At the start: more behind the end, nothing before the start.
        await expect(end, "the shadow at the end is shown").toBeVisible();
        await expect(start, "no shadow at the start before scrolling").toBeHidden();
        const endBox = await boxOf(end);
        expect(Math.abs(endBox.x + endBox.width - (regionBox.x + regionBox.width)), "the shadow at the end sits on the end edge").toBeLessThanOrEqual(2);
        expect(endBox.height, "the shadow at the end covers the height of the region").toBeGreaterThanOrEqual(regionBox.height - 2);

        // Scrolled to the end: more behind the start, nothing behind the end.
        await region.evaluate((element) => {
          element.scrollLeft = element.scrollWidth;
        });
        await expect(start, "the shadow at the start is shown at the end of the scroll").toBeVisible();
        await expect(end, "no shadow at the end after scrolling to it").toBeHidden();
        const startBox = await boxOf(start);
        expect(Math.abs(startBox.x - regionBox.x), "the shadow at the start sits on the start edge").toBeLessThanOrEqual(2);
      } finally {
        await page.close();
      }
    });
  }
});
