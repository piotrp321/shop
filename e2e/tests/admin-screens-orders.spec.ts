import { expect, test, type Browser, type Locator, type Page } from "@playwright/test";
import { confirmWithNote, ensurePaidOrder, listHasRows, ORDERED_PRODUCT, placeOrderAsJan } from "../support/panel-orders";
import { documentWidths, expectControlsReachable, expectWithinWidth, viewports, WCAG_TAGS } from "../support/panel-layout";
import { closePanelContexts, go, inState, newPanelPage, type PanelState, signInPanelRoles, violationsOf } from "../support/panel-states";

// The list and the detail of the orders of the panel (docs/features/admin-screens/spec.md, „Zamówienia”): the look and the
// accessibility of the list (a table, cards on a phone), of the refunds to do and of the detail with its window of a
// change, the number in one line (#261), the date as a line of the cell of the number, the timeline and the copying of the
// payment id, and the shipment of a shipped order (the card „Przesyłka”, the field of the number in the window of a
// shipment, the window of the number). The orders of the tests are the ones the stack has: a stack without a paid order,
// without a refund to do or without a shipped order with a number gets one from the customer and the panel. The tests change
// nothing, except the two of „a change in flight” (docs/features/admin-ux-fixes/spec.md, K3): each changes the status of an
// order it places itself; the other changes of an order are in order-management.spec.ts and delivery-tracking.spec.ts.

const SEARCH = "Szukaj po numerze zamówienia lub e-mailu";

let paidOrderId = "";
let refundOrderId = "";
let shippedOrderId = "";

/** The id of the order of the first row of a list at `address`, the list being the one that has rows (the caller made sure). */
async function firstOrderOf(page: Page, address: string): Promise<{ id: string; number: string }> {
  await go(page, address);
  const link = page.getByTestId("order-row").first().getByTestId("number");
  await expect(link, `the list ${address} has an order`).toBeVisible();
  const number = (await link.innerText()).trim();
  return { id: (await link.getAttribute("href"))!.split("/").pop()!, number };
}

test.beforeAll(async ({ browser }) => {
  test.setTimeout(360_000);
  await signInPanelRoles(browser, ["admin"]);
  const page = await newPanelPage("admin", viewports.desktop);
  try {
    await ensurePaidOrder(browser, page);
    paidOrderId = (await firstOrderOf(page, "/orders?status=paid")).id;

    if (!(await listHasRows(page, "/orders?refund=pending"))) {
      // A paid order of its own, cancelled by the admin: a refund is due.
      const number = await placeOrderAsJan(browser, ORDERED_PRODUCT);
      await go(page, `/orders?q=${encodeURIComponent(number)}`);
      await page.getByTestId("order-row").getByTestId("number").click();
      await expect(page.getByRole("heading", { level: 1, name: `Zamówienie ${number}` })).toBeVisible();
      await page.getByTestId("action-cancel").click();
      await confirmWithNote(page, "E2E: zwrot do wykonania");
    }
    refundOrderId = (await firstOrderOf(page, "/orders?refund=pending")).id;

    // A shipped order with a number: the shipment is the card „Przesyłka” and the number the window of the correction.
    if (!(await listHasRows(page, "/orders?status=shipped"))) {
      const number = await placeOrderAsJan(browser, ORDERED_PRODUCT);
      await go(page, `/orders?q=${encodeURIComponent(number)}`);
      await page.getByTestId("order-row").getByTestId("number").click();
      await expect(page.getByRole("heading", { level: 1, name: `Zamówienie ${number}` })).toBeVisible();
      await page.getByTestId("action-ship").click();
      await confirmWithNote(page, "E2E: wysłane do przeglądu szczegółów");
    }
    shippedOrderId = (await firstOrderOf(page, "/orders?status=shipped")).id;
    await go(page, `/orders/${shippedOrderId}`);
    await expect(page.getByTestId("status")).toHaveText("Wysłane");
    if ((await page.getByTestId("shipment").count()) === 0) {
      await page.getByTestId("change-tracking-number").click();
      const window = page.getByTestId("tracking-dialog");
      await window.getByTestId("tracking-number-input").fill(`E2E-${Date.now()}`);
      await window.getByTestId("tracking-submit").click();
      await expect(window).toBeHidden();
      await expect(page.getByTestId("shipment")).toBeVisible();
    }
  } finally {
    await page.close();
  }
});

test.afterAll(closePanelContexts);

async function openList(page: Page, address: string, title: string): Promise<void> {
  await go(page, address);
  await expect(page.getByRole("heading", { level: 1, name: title })).toBeVisible();
  await expect(page.getByTestId("order-row").first()).toBeVisible();
}

const states: PanelState[] = [
  {
    name: "the list of orders",
    role: "admin",
    open: async (page) => {
      await openList(page, "/orders", "Zamówienia");
      return null;
    },
  },
  {
    name: "the refunds to do",
    role: "admin",
    open: async (page) => {
      await openList(page, "/orders?refund=pending", "Zwroty do wykonania");
      return null;
    },
  },
  {
    name: "the detail of a paid order with the window of a change of the status",
    role: "admin",
    overlay: true,
    open: async (page) => {
      await go(page, `/orders/${paidOrderId}`);
      await expect(page.getByTestId("status")).toHaveText("Opłacone");
      await page.getByTestId("action-ship").click();
      const dialog = page.getByTestId("change-dialog");
      await expect(dialog).toBeVisible();
      return dialog;
    },
  },
  {
    name: "the detail of an order with a refund to do",
    role: "admin",
    open: async (page) => {
      await go(page, `/orders/${refundOrderId}`);
      await expect(page.getByTestId("refund")).toBeVisible();
      return null;
    },
  },
  {
    name: "the detail of a shipped order with its shipment and the delivery",
    role: "admin",
    open: async (page) => {
      await go(page, `/orders/${shippedOrderId}`);
      await expect(page.getByTestId("shipment").getByTestId("tracking-number")).toBeVisible();
      return null;
    },
  },
  {
    name: "the detail of a shipped order with the window of the tracking number",
    role: "admin",
    overlay: true,
    open: async (page) => {
      await go(page, `/orders/${shippedOrderId}`);
      await expect(page.getByTestId("status")).toHaveText("Wysłane");
      await page.getByTestId("change-tracking-number").click();
      const dialog = page.getByTestId("tracking-dialog");
      await expect(dialog).toBeVisible();
      return dialog;
    },
  },
];

test.describe("axe with the rules of WCAG 2.2 AA", () => {
  for (const state of states) {
    test(`#260: ${state.name} has no violation at 1280 and 768 px, contrast included`, async () => {
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

test.describe("at 375 px", () => {
  test("the window of a change lies in the screen", async () => {
    test.setTimeout(60_000);
    await inState(states[2]!, viewports.phone, async (page, overlay) => {
      await expectWithinWidth(overlay!, viewports.phone.width, "the window of a change of the status");
      await expect.poll(async () => (await documentWidths(page)).scrollWidth).toBeLessThanOrEqual(viewports.phone.width);
    });
  });

  test("the window of the tracking number and the field of a shipment lie in the screen", async () => {
    test.setTimeout(60_000);
    await inState(states[5]!, viewports.phone, async (page, overlay) => {
      await expectWithinWidth(overlay!, viewports.phone.width, "the window of the tracking number");
      await expect.poll(async () => (await documentWidths(page)).scrollWidth).toBeLessThanOrEqual(viewports.phone.width);
    });
    await inState(states[2]!, viewports.phone, async (page, overlay) => {
      await expect(overlay!.getByLabel("Numer przesyłki (opcjonalnie)")).toBeVisible();
      await expectWithinWidth(overlay!.getByLabel("Numer przesyłki (opcjonalnie)"), viewports.phone.width, "the field of the number of a shipment");
    });
  });

  test("the shipment and the delivery of a shipped order do not scroll the detail sideways at 375 px", async () => {
    test.setTimeout(60_000);
    await inState(states[4]!, viewports.phone, async (page) => {
      await expect(page.getByTestId("delivery-line")).toBeVisible();
      await expectWithinWidth(page.getByTestId("shipment"), viewports.phone.width, "the card of the shipment");
      const widths = await documentWidths(page);
      expect(widths.scrollWidth, "the detail does not scroll sideways").toBeLessThanOrEqual(viewports.phone.width);
    });
  });

  test("the list is cards with the number, the status and the amount in view, one order-row per order", async () => {
    test.setTimeout(60_000);
    await inState(states[0]!, viewports.phone, async (page) => {
      await expect(page.getByRole("list", { name: "Zamówienia" })).toBeVisible();
      await expect(page.locator("table")).toHaveCount(0);
      const rows = page.getByTestId("order-row");
      expect(await rows.count(), "one row per order: the number of rows is the number of links").toBe(await page.getByTestId("number").count());
      const first = rows.first();
      for (const id of ["number", "status", "total"]) {
        await expect(first.getByTestId(id)).toBeVisible();
        await expectWithinWidth(first.getByTestId(id), viewports.phone.width, `the ${id} of the first card`);
      }
      const widths = await documentWidths(page);
      expect(widths.scrollWidth, "the list does not scroll sideways").toBeLessThanOrEqual(viewports.phone.width);
    });
  });

  test("the detail does not scroll sideways, and the table of the lines shows the product, the quantity and the value", async () => {
    test.setTimeout(60_000);
    await inState(states[3]!, viewports.phone, async (page) => {
      const widths = await documentWidths(page);
      expect(widths.scrollWidth, "the detail does not scroll sideways").toBeLessThanOrEqual(viewports.phone.width);
      const row = page.getByTestId("line-row").first();
      for (const id of ["line-name", "line-quantity", "line-gross"]) {
        await expect(row.getByTestId(id)).toBeVisible();
        await expectWithinWidth(row.getByTestId(id), viewports.phone.width, `the ${id} of the first line`);
      }
      await expect(row.getByTestId("line-meta-unit"), "the price is a line of the product cell").toBeVisible();
      await expect(page.getByRole("columnheader", { name: "Cena brutto" })).toBeHidden();
    });
  });
});

test.describe("the columns of lower priority", () => {
  test("at 768 px the number, the status and the amount of the first row are in view, and the date is a line of the cell of the number", async () => {
    test.setTimeout(60_000);
    await inState(states[0]!, viewports.tablet, async (page) => {
      const row = page.getByTestId("order-row").first();
      for (const id of ["number", "status", "total"]) {
        await expectWithinWidth(row.getByTestId(id), viewports.tablet.width, `the ${id} of the first row`);
      }
      await expect(row.getByTestId("line-placedAt")).toBeVisible();
      await expect(row.getByTestId("placed-at")).toBeHidden();
      const region = page.getByRole("region", { name: "Zamówienia" });
      const { scrollWidth, clientWidth } = await region.evaluate((element) => ({ scrollWidth: element.scrollWidth, clientWidth: element.clientWidth }));
      expect(scrollWidth, "the table of 768 px does not scroll sideways").toBeLessThanOrEqual(clientWidth);
    });
  });

  test("at 320 px the date is on the card of the order", async () => {
    test.setTimeout(60_000);
    await inState(states[0]!, viewports.reflow, async (page) => {
      await expect(page.getByTestId("order-row").first().getByTestId("placed-at")).toBeVisible();
    });
  });

  test("at 1280 px the date and the number of items are columns, and their lines are hidden", async () => {
    test.setTimeout(60_000);
    await inState(states[0]!, viewports.desktop, async (page) => {
      const row = page.getByTestId("order-row").first();
      await expect(row.getByTestId("placed-at")).toBeVisible();
      await expect(row.getByTestId("items")).toBeVisible();
      await expect(row.getByTestId("line-placedAt")).toBeHidden();
    });
  });
});

/** The height of the element and the line height of its text, in px. */
async function heightAndLine(locator: Locator): Promise<{ height: number; line: number }> {
  return locator.evaluate((element) => {
    const style = getComputedStyle(element);
    const fontSize = parseFloat(style.fontSize);
    return { height: element.getBoundingClientRect().height, line: style.lineHeight === "normal" ? fontSize * 1.2 : parseFloat(style.lineHeight) };
  });
}

test.describe("#261: the number of an order is in one line at 1280 px", () => {
  for (const [address, title] of [
    ["/orders", "Zamówienia"],
    ["/orders?refund=pending", "Zwroty do wykonania"],
  ] as const) {
    test(`on ${address} the height of the number is at most 1.5 times its line height`, async () => {
      test.setTimeout(60_000);
      const page = await newPanelPage("admin", viewports.desktop);
      try {
        await openList(page, address, title);
        const numbers = page.getByTestId("number");
        const count = Math.min(await numbers.count(), 10);
        expect(count).toBeGreaterThan(0);
        for (let index = 0; index < count; index++) {
          const { height, line } = await heightAndLine(numbers.nth(index));
          expect(height, `the number ${await numbers.nth(index).innerText()} is one line (${height} px of ${line} px)`).toBeLessThanOrEqual(line * 1.5);
        }
      } finally {
        await page.close();
      }
    });
  }
});

test.describe("the detail", () => {
  test("has the crumbs instead of a way back, the side column before the main one, and the timeline of the history", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, `/orders/${paidOrderId}`);
      await expect(page.getByTestId("back")).toHaveCount(0);
      const crumbs = page.getByRole("navigation", { name: "Okruszki" });
      await expect(crumbs.getByRole("link", { name: "Zamówienia", exact: true })).toBeVisible();
      await expect(crumbs.locator("[aria-current=page]")).toHaveText(/^R-\d+$/);

      // From 1280 px the side column stands on the right and the main one on the left, though the side one comes first in the source.
      const side = await page.getByTestId("order-side").boundingBox();
      const main = await page.getByTestId("order-main").boundingBox();
      expect(side!.x, "the side column is on the right").toBeGreaterThan(main!.x + main!.width - 1);
      const sourceOrder = await page.evaluate(() => {
        const side = document.querySelector("[data-testid=order-side]")!;
        const main = document.querySelector("[data-testid=order-main]")!;
        return Boolean(side.compareDocumentPosition(main) & Node.DOCUMENT_POSITION_FOLLOWING);
      });
      expect(sourceOrder, "the side column is first in the source").toBe(true);

      const timeline = page.getByRole("list").filter({ has: page.getByTestId("history-row") });
      await expect(timeline.getByTestId("history-row")).not.toHaveCount(0);
      const first = timeline.getByTestId("history-row").first();
      await expect(first.locator("time[datetime]")).toBeVisible();
      await expect(first.getByTestId("history-actor")).toHaveText("Klient");
      await expect(timeline.getByTestId("history-change").last()).toHaveText(/→ Opłacone$/);
    } finally {
      await page.close();
    }
  });

  test("copies the payment id, announces it, and the link „Inne zamówienia klienta” opens the search of the list", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, `/orders/${paidOrderId}`);
      await page.context().grantPermissions(["clipboard-read", "clipboard-write"], { origin: new URL(page.url()).origin });
      const paymentId = (await page.getByTestId("payment-id").innerText()).trim();
      expect(paymentId).toMatch(/^[0-9a-f-]{36}$/);

      await page.getByRole("button", { name: "Kopiuj identyfikator płatności" }).click();

      await expect(page.getByTestId("copy-status")).toHaveText("Skopiowano");
      expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(paymentId);

      const email = await page.getByTestId("contact").getByRole("link").innerText();
      await page.getByRole("link", { name: "Inne zamówienia klienta" }).click();
      await expect.poll(() => new URL(page.url()).pathname).toBe("/orders");
      expect(new URL(page.url()).searchParams.get("q")).toBe(email.trim());
      await expect(page.getByRole("searchbox", { name: SEARCH })).toHaveValue(email.trim());
      await expect(page.getByTestId("order-row").first()).toBeVisible();
    } finally {
      await page.close();
    }
  });

  test("the window of a change has the title with the number, the field „Notatka” in focus, and „Wróć” gives the focus back to the button", async () => {
    test.setTimeout(60_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await go(page, `/orders/${paidOrderId}`);
      await expect(page.getByRole("heading", { level: 1 })).toHaveText(/^Zamówienie R-\d+$/);
      const number = (await page.getByRole("heading", { level: 1 }).innerText()).replace("Zamówienie ", "").trim();
      const cancel = page.getByTestId("action-cancel");
      await cancel.focus();
      await page.keyboard.press("Enter");

      const dialog = page.getByRole("dialog", { name: `Anulować zamówienie ${number}?` });
      await expect(dialog).toBeVisible();
      await expect(dialog.getByTestId("change-note")).toBeFocused();
      await expect(dialog.getByTestId("change-submit")).toHaveText("Anuluj zamówienie");

      await dialog.getByRole("button", { name: "Wróć" }).click();
      await expect(dialog).toBeHidden();
      await expect(cancel).toBeFocused();
    } finally {
      await page.close();
    }
  });
});

test.describe("a change in flight", () => {
  const CHANGES = "**/api/v1/admin/orders/*/status-changes";

  /** A paid order of the test itself (the change of the status must not touch the shared ones); opens its detail from the list. */
  async function openOwnOrder(browser: Browser, page: Page): Promise<string> {
    const number = await placeOrderAsJan(browser, ORDERED_PRODUCT);
    await go(page, `/orders?q=${encodeURIComponent(number)}`);
    await page.getByTestId("order-row").getByTestId("number").click();
    await expect(page.getByRole("heading", { level: 1, name: `Zamówienie ${number}` })).toBeVisible();
    await expect(page.getByTestId("status")).toHaveText("Opłacone");
    return number;
  }

  test("the window of a status change stays open with the focus on its button while the answer is held, and ends with the toast and the focus on the card", async ({ browser }) => {
    test.setTimeout(120_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openOwnOrder(browser, page);
      await page.route(CHANGES, async (route) => {
        await new Promise((done) => setTimeout(done, 3_000));
        await route.continue();
      });
      await page.getByTestId("action-ship").click();
      const dialog = page.getByTestId("change-dialog");
      const note = dialog.getByTestId("change-note");
      await note.fill("E2E: zapis w toku");

      const submit = dialog.getByTestId("change-submit");
      await submit.focus();
      await page.keyboard.press("Enter");
      await expect(submit).toHaveAttribute("aria-disabled", "true");
      await expect(submit).toBeFocused();

      await page.keyboard.press("Escape");
      await expect(dialog).toBeVisible();
      await expect(submit).toBeFocused();
      // Typing has to focus the field first, so it comes after the focus checks.
      await note.pressSequentially("x");
      await expect(note).toHaveValue("E2E: zapis w toku");

      const toasts = page.getByRole("region", { name: "Powiadomienia", exact: true });
      await expect(toasts).toContainText("Status zamówienia zmieniony: Wysłane.");
      await expect(dialog).toBeHidden();
      await expect(page.getByRole("heading", { level: 2, name: "Akcje" })).toBeFocused();
    } finally {
      await page.close();
    }
  });

  // A control, not a test of the change: the way out of a held save is Back of the browser, which the window does not block.
  test("Back of the browser leaves the screen of the order while the answer is held, and the answer that comes later brings no toast", async ({ browser }) => {
    test.setTimeout(120_000);
    const page = await newPanelPage("admin", viewports.desktop);
    try {
      await openOwnOrder(browser, page);
      let release!: () => void;
      const gate = new Promise<void>((done) => {
        release = done;
      });
      await page.route(CHANGES, async (route) => {
        await gate;
        await route.continue();
      });
      await page.getByTestId("action-ship").click();
      const dialog = page.getByTestId("change-dialog");
      const sent = page.waitForRequest((request) => request.method() === "POST" && request.url().includes("/status-changes"));
      await dialog.getByTestId("change-submit").click();
      await sent;

      await page.goBack();
      await expect(page.getByRole("heading", { level: 1, name: "Zamówienia" })).toBeVisible();
      await expect(dialog).toBeHidden();

      release();
      // The answer arrives at a screen that has left the order: no toast, whatever the server did with the change.
      await page.waitForTimeout(1_500);
      await expect(page.getByRole("region", { name: "Powiadomienia", exact: true })).not.toContainText("Status zamówienia zmieniony");
    } finally {
      await page.close();
    }
  });
});
