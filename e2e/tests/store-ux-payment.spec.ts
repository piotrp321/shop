import { type Browser, type BrowserContext, expect, type Locator, type Page, type Route, test } from "@playwright/test";
import { decideAtSimulator, expectSimulatorPage, WEBHOOK_TIMEOUT_MS } from "../support/payment";
import { addVariantOf, chooseDelivery, chooseProvider, fillAddress, tileNamed } from "../support/shop";

// Confirmation page of an unpaid order (STORE-UX-FIXES, S6): "Sprawdź ponownie" keeps its focus, the sentences of the
// payment status are in one persistent region. Every order of this file reserves 1 unit of the product below
// (fixtures/stock.yaml) and is never paid, except the one of the region test, which pays at the simulator.
const PRODUCT = "Buty trailowe Norvane Hala 500";
const READ_FAILED = "Nie udało się sprawdzić statusu płatności.";

type StorageState = Awaited<ReturnType<BrowserContext["storageState"]>>;
interface UnpaidOrder {
  storageState: StorageState;
  /** The session of the payment provider: paying there makes the webhook come. */
  sessionUrl: string;
}

/** A guest orders 1 unit and stops on the page of the provider; the cookies of the order are kept for the tests. */
async function placeUnpaidOrder(browser: Browser): Promise<UnpaidOrder> {
  const context = await browser.newContext();
  const page = await context.newPage();
  await page.goto("/");
  const tile = tileNamed(page, PRODUCT);
  await expect(tile).toBeVisible();
  await addVariantOf(page, tile, 1);
  await page.goto("/checkout");
  await page.getByLabel("E-mail").fill(`e2e-status+${Date.now()}@example.com`);
  await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
  await fillAddress(page);
  await chooseDelivery(page);
  await chooseProvider(page, "Szybki przelew (demo)");
  await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();
  await expectSimulatorPage(page, "demo-transfer");
  const order = { storageState: await context.storageState(), sessionUrl: page.url() };
  await context.close();
  return order;
}

const checkButton = (page: Page): Locator => page.getByRole("button", { name: "Sprawdź ponownie" });
const heading = (page: Page): Locator => page.getByRole("heading", { level: 1 });
const region = (page: Page): Locator => page.getByTestId("payment-status");

/**
 * Opens the confirmation page of the order in a new tab of a new context (`width` px wide) and lets the 30 seconds of
 * refreshing pass on the fake clock, so the page offers "Sprawdź ponownie". The clock jumps again until it does, because
 * the timers exist only after the page hydrated.
 */
async function openWithWindowClosed(browser: Browser, order: UnpaidOrder, width = 1280): Promise<Page> {
  const context = await browser.newContext({ storageState: order.storageState, viewport: { width, height: 800 } });
  const page = await context.newPage();
  await page.clock.install();
  await page.goto("/checkout/confirmation");
  await expect(page.getByText("Czekamy na potwierdzenie płatności…")).toBeVisible();
  await expect(async () => {
    await page.clock.fastForward(31_000);
    await expect(checkButton(page)).toBeVisible({ timeout: 1_000 });
  }).toPass();
  return page;
}

/** Holds the reads of the status (`GET /api/checkout/payment`) until `release` is called; then they get `answer`. */
async function holdReads(page: Page, answer: (route: Route) => Promise<void>) {
  const requests: string[] = [];
  let release: () => void = () => {};
  const gate = new Promise<void>((resolve) => (release = resolve));
  await page.route("**/api/checkout/payment", async (route) => {
    if (route.request().method() !== "GET") return route.continue();
    requests.push(route.request().url());
    await gate;
    await answer(route);
  });
  return { requests, release };
}

/** The real answer of the API, with the changes of `change` (a real order cannot be paid in the middle of a read). */
const realAnswerWith = (change: Record<string, unknown>) => async (route: Route) => {
  const response = await route.fetch();
  await route.fulfill({ response, json: { ...(await response.json()), ...change } });
};

test.describe("the confirmation page of an unpaid order", () => {
  let order: UnpaidOrder;

  test.beforeAll(async ({ browser }) => {
    test.setTimeout(120_000);
    order = await placeUnpaidOrder(browser);
  });

  test("keeps the focus on „Sprawdź ponownie” while the read runs, and ignores a second Enter", async ({ browser }) => {
    const page = await openWithWindowClosed(browser, order);
    const reads = await holdReads(page, async (route) => route.continue());
    await checkButton(page).focus();
    await page.keyboard.press("Enter");

    await expect(checkButton(page)).toHaveAttribute("aria-disabled", "true");
    await expect(checkButton(page)).toBeFocused();
    await page.keyboard.press("Enter");
    await page.waitForTimeout(300);
    expect(reads.requests).toHaveLength(1);

    reads.release();
    // The order is still unpaid, so the button stays and keeps the focus.
    await expect(checkButton(page)).not.toHaveAttribute("aria-disabled", "true");
    await expect(checkButton(page)).toBeFocused();
    await page.context().close();
  });

  test("puts the focus on the heading when the answer takes the button away", async ({ browser }) => {
    const page = await openWithWindowClosed(browser, order);
    const reads = await holdReads(
      page,
      realAnswerWith({ orderStatus: "paid", canStartPayment: false, latestPayment: { provider: "demo-transfer", status: "succeeded", resumeUrl: null } }),
    );
    await checkButton(page).focus();
    await page.keyboard.press("Enter");
    await expect(checkButton(page)).toBeFocused();

    reads.release();
    await expect(region(page)).toContainText("Płatność przyjęta.");
    await expect(checkButton(page)).toHaveCount(0);
    await expect(heading(page)).toBeFocused();
    await page.context().close();
  });

  test("keeps the button, the focus and says so in an alert when the read fails with 500", async ({ browser }) => {
    const page = await openWithWindowClosed(browser, order);
    const reads = await holdReads(page, (route) => route.fulfill({ status: 500, json: { title: "Błąd" } }));
    await checkButton(page).focus();
    await page.keyboard.press("Enter");
    await expect(checkButton(page)).toBeFocused();

    reads.release();
    await expect(page.getByRole("alert").filter({ hasText: READ_FAILED })).toBeVisible();
    await expect(checkButton(page)).toBeFocused();
    await expect(checkButton(page)).not.toHaveAttribute("aria-disabled", "true");
    await page.context().close();
  });

  test("leaves the focus where the customer moved it while the read ran", async ({ browser }) => {
    const page = await openWithWindowClosed(browser, order);
    const reads = await holdReads(
      page,
      realAnswerWith({ orderStatus: "paid", canStartPayment: false, latestPayment: { provider: "demo-transfer", status: "succeeded", resumeUrl: null } }),
    );
    await checkButton(page).focus();
    await page.keyboard.press("Enter");
    const shop = page.getByRole("link", { name: "Wróć do sklepu" });
    await shop.focus();

    reads.release();
    await expect(region(page)).toContainText("Płatność przyjęta.");
    await expect(shop).toBeFocused();
    await page.context().close();
  });

  for (const width of [320, 375]) {
    test(`shows the region and the message of a failed read at ${width} px without a horizontal scroll`, async ({ browser }) => {
      const page = await openWithWindowClosed(browser, order, width);
      const scrolls = () => page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
      await expect(region(page)).toContainText("Nie mamy jeszcze potwierdzenia płatności.");
      expect(await scrolls()).toBe(false);

      const reads = await holdReads(page, (route) => route.fulfill({ status: 500, json: { title: "Błąd" } }));
      await checkButton(page).focus();
      await page.keyboard.press("Enter");
      reads.release();
      await expect(page.getByRole("alert").filter({ hasText: READ_FAILED })).toBeVisible();
      expect(await scrolls()).toBe(false);
      await page.context().close();
    });
  }
});

test("the status region is the same connected node when the order gets paid while the page is open", async ({ browser }) => {
  const own = await placeUnpaidOrder(browser);
  const context = await browser.newContext({ storageState: own.storageState });
  const page = await context.newPage();
  await page.goto("/checkout/confirmation");
  await expect(region(page)).toContainText("Czekamy na potwierdzenie płatności…");
  const before = await region(page).elementHandle();
  if (!before) throw new Error("The status region is not on the page.");

  // Another tab pays at the simulator; the webhook reaches the store and the open page finds it by refreshing.
  const payer = await context.newPage();
  await payer.goto(own.sessionUrl);
  await expectSimulatorPage(payer, "demo-transfer");
  await decideAtSimulator(payer, "Zapłać");

  await expect(region(page)).toContainText("Płatność przyjęta.", { timeout: WEBHOOK_TIMEOUT_MS });
  await expect(region(page)).not.toContainText("Czekamy na potwierdzenie płatności…");
  const after = await region(page).elementHandle();
  if (!after) throw new Error("The status region is not on the page.");
  expect(await page.evaluate(([a, b]) => a === b && a.isConnected, [before, after] as const)).toBe(true);
  await context.close();
});
