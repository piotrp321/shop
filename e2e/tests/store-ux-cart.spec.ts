import { expect, type Locator, type Page, test } from "@playwright/test";
import { waitForOrderEmail } from "../support/mailpit";
import { decideAtSimulator, expectSimulatorPage } from "../support/payment";
import { addVariantOf, chooseDelivery, chooseProvider, fillAddress, tileNamed } from "../support/shop";
import { cartLineOf } from "../support/variants";

// Actions given in the cart while a change is saved (docs/features/store-ux-fixes/spec.md, S1). Three demo mats of the
// category "Fitness" > "Trening" (apps/api/fixtures/catalog.yaml), 1 unit each, in the cart of a new guest; the tests
// never order them.
const CATEGORY = "/c/fitness/trening";
const PRODUCTS = ["Mata do ćwiczeń Lumora Podłoże 6", "Mata do ćwiczeń Lumora Podłoże 10", "Mata do ćwiczeń Dravik Podłoże 6"];

// "Held" in the specification: the request stops in the browser for 3 s before it reaches the server.
const HOLD_MS = 3_000;
const ITEM_URL = "**/api/cart/items/*";

type Line = { name: string; label: string };
type Writes = { PATCH: number; DELETE: number };

async function cartWithThreeLines(page: Page): Promise<[Line, Line, Line]> {
  await page.goto(CATEGORY);
  await expect(page.getByRole("heading", { level: 1, name: "Trening" })).toBeVisible();
  const lines: Line[] = [];
  for (const name of PRODUCTS) {
    const tile = tileNamed(page, name);
    await expect(tile).toHaveCount(1);
    lines.push({ name, label: await addVariantOf(page, tile, 1) });
  }
  await page.goto("/cart");
  // The first read of a cart with three lines asks the API for the offers of all of them: slower than an action.
  await expect(page.getByTestId("cart-total")).toBeVisible({ timeout: 30_000 });
  for (const line of lines) {
    await expect(quantityOf(page, line)).toHaveValue("1");
  }
  const [a, b, c] = lines;
  if (a === undefined || b === undefined || c === undefined) throw new Error("the cart needs three lines");
  return [a, b, c];
}

const rowOf = (page: Page, line: Line): Locator => cartLineOf(page, line.name, line.label);
const quantityOf = (page: Page, line: Line): Locator => rowOf(page, line).getByRole("spinbutton");
const removeOf = (page: Page, line: Line): Locator => rowOf(page, line).getByRole("button", { name: /^Usuń/ });

/** Counts the quantity changes and removals the page sends to the store's cart endpoints. */
function countWrites(page: Page): Writes {
  const writes: Writes = { PATCH: 0, DELETE: 0 };
  page.on("request", (request) => {
    const method = request.method();
    if (/\/api\/cart\/items\/[^/]+$/.test(new URL(request.url()).pathname) && (method === "PATCH" || method === "DELETE")) {
      writes[method]++;
    }
  });
  return writes;
}

/** Holds every quantity change of the cart page in the browser before it goes to the server. */
async function holdQuantityChanges(page: Page): Promise<void> {
  await page.route(ITEM_URL, async (route) => {
    if (route.request().method() === "PATCH") {
      await new Promise((resolve) => setTimeout(resolve, HOLD_MS));
    }
    await route.fallback();
  });
}

type Input = { touch: boolean };

/** Types a quantity into the field the way a customer does, without leaving it. */
async function typeQuantity(field: Locator, value: string, { touch }: Input): Promise<void> {
  if (touch) await field.tap();
  else await field.click();
  await field.fill(value);
}

async function press(button: Locator, { touch }: Input): Promise<void> {
  if (touch) await button.tap();
  else await button.click();
}

const scenarios = [
  { device: "the mouse at 1280 px", touch: false },
  { device: "touch at 375 px", touch: true },
];

for (const { device, touch } of scenarios) {
  test.describe(`Usuń right after typing a quantity, with ${device} (criterion 1)`, () => {
    test.use(touch ? { viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true } : { viewport: { width: 1280, height: 800 } });

    for (const held of [false, true]) {
      test(`removes the chosen line after the change ${held ? "held for 3 s" : "without a delay"}: one PATCH and one DELETE each time`, async ({ page }) => {
        test.setTimeout(90_000);
        const [a, b, c] = await cartWithThreeLines(page);
        const writes = countWrites(page);
        if (held) await holdQuantityChanges(page);

        // Usuń of the line just typed into: that line goes, the other one stays.
        await typeQuantity(quantityOf(page, a), "3", { touch });
        await press(removeOf(page, a), { touch });
        if (held) await expect(removeOf(page, a)).toHaveAttribute("aria-disabled", "true");
        await expect(rowOf(page, a)).toHaveCount(0, { timeout: HOLD_MS + 10_000 });
        await expect(quantityOf(page, b)).toHaveValue("1");
        expect(writes).toEqual({ PATCH: 1, DELETE: 1 });

        // Usuń of another line: the typed quantity is saved and the other line goes.
        await typeQuantity(quantityOf(page, b), "3", { touch });
        await press(removeOf(page, c), { touch });
        await expect(rowOf(page, c)).toHaveCount(0, { timeout: HOLD_MS + 10_000 });
        await expect(quantityOf(page, b)).toHaveValue("3");
        await expect(page.getByTestId("cart-item-count")).toHaveText("3");
        expect(writes).toEqual({ PATCH: 2, DELETE: 2 });

        // The server has what the page shows.
        await page.unroute(ITEM_URL);
        await page.reload();
        await expect(quantityOf(page, b)).toHaveValue("3");
        await expect(rowOf(page, a)).toHaveCount(0);
        await expect(rowOf(page, c)).toHaveCount(0);
        expect(writes).toEqual({ PATCH: 2, DELETE: 2 });
      });
    }
  });
}

test("coming back to the cart while the actions of the left page still run shows the cart after them", async ({ page }) => {
  test.setTimeout(90_000);
  const [a, b] = await cartWithThreeLines(page);
  const writes = countWrites(page);
  await holdQuantityChanges(page);

  await typeQuantity(quantityOf(page, a), "3", { touch: false });
  await removeOf(page, b).click();
  // Leave by a link of the store and come back by the cart link of the header before the held change is answered.
  await page.getByRole("banner").getByRole("link", { name: "Worf" }).click();
  await page.waitForURL((url) => url.pathname === "/");
  await page.getByRole("banner").getByRole("link", { name: /^Koszyk/ }).click();
  await page.waitForURL((url) => url.pathname === "/cart");

  await expect(quantityOf(page, a)).toHaveValue("3", { timeout: HOLD_MS + 10_000 });
  await expect(rowOf(page, b)).toHaveCount(0);
  await expect(page.getByTestId("cart-item-count")).toHaveText("4");
  // Nothing more comes later: the page stays as it is.
  await page.waitForTimeout(1_000);
  await expect(quantityOf(page, a)).toHaveValue("3");
  await expect(rowOf(page, b)).toHaveCount(0);
  expect(writes).toEqual({ PATCH: 1, DELETE: 1 });

  await page.unroute(ITEM_URL);
  await page.reload();
  await expect(quantityOf(page, a)).toHaveValue("3");
  await expect(rowOf(page, b)).toHaveCount(0);
});

// Focus, the status region and the retry of the load of the cart page (docs/features/store-ux-fixes/spec.md, S3).
const CART_READ = (url: URL): boolean => url.pathname === "/api/cart";
const REGION = "cart-status";
const LOADING_TEXT = "Wczytywanie koszyka…";

/** Adds `count` of the demo mats, 1 unit each, to the cart of a new guest and opens the cart page. */
async function cartWithLines(page: Page, count: number): Promise<Line[]> {
  await page.goto(CATEGORY);
  await expect(page.getByRole("heading", { level: 1, name: "Trening" })).toBeVisible();
  const lines: Line[] = [];
  for (const name of PRODUCTS.slice(0, count)) {
    const tile = tileNamed(page, name);
    await expect(tile).toHaveCount(1);
    lines.push({ name, label: await addVariantOf(page, tile, 1) });
  }
  await page.goto("/cart");
  await expect(page.getByTestId("cart-total")).toBeVisible({ timeout: 30_000 });
  return lines;
}

/** What the status region says after the removal of the line: the name, then the label of the variant when it has one. */
const removedText = ({ name, label }: Line): string => `Usunięto z koszyka: ${label === "" ? name : `${name}, ${label}`}.`;

/**
 * Answers the first `failures` reads of the cart with a 500 at once and holds the next one until the test calls
 * `release`; it is then answered with a 500 when `state.outcome` is "failure", otherwise by the server. Later reads go
 * to the server. `state.reads` counts the reads that reached the network layer of the browser.
 */
async function controlCartReads(page: Page, { failures }: { failures: number }) {
  const state = { reads: 0, outcome: "cart" as "cart" | "failure" };
  let release: () => void = () => {};
  const gate = new Promise<void>((resolve) => (release = resolve));
  await page.route(CART_READ, async (route) => {
    state.reads++;
    if (state.reads <= failures) return route.fulfill({ status: 500, contentType: "application/json", body: "{}" });
    if (state.reads === failures + 1) {
      await gate;
      if (state.outcome === "failure") return route.fulfill({ status: 500, contentType: "application/json", body: "{}" });
    }
    return route.fallback();
  });
  return { state, release: () => release() };
}

const retryButton = (page: Page): Locator => page.getByRole("button", { name: "Spróbuj ponownie" });
const failedMessage = (page: Page): Locator => page.getByText("Nie udało się wczytać koszyka");
const cartTitle = (page: Page): Locator => page.getByRole("heading", { level: 1, name: "Koszyk" });

test.describe("retry of the load of the cart (criterion 13)", () => {
  /** The cart failed to load (a 500); the retry button has the focus and Enter started the retry, held by the test. */
  async function startRetry(page: Page, outcome: "cart" | "failure") {
    const reads = await controlCartReads(page, { failures: 1 });
    reads.state.outcome = outcome;
    await page.goto("/cart");
    await expect(failedMessage(page)).toBeVisible();
    await retryButton(page).focus();
    await page.keyboard.press("Enter");
    await expect.poll(() => reads.state.reads).toBe(2);
    return reads;
  }

  test("keeps the button on the screen with the focus while the cart is read again, and a second Enter sends nothing", async ({ page }) => {
    const reads = await startRetry(page, "cart");

    await expect(retryButton(page)).toBeFocused();
    await expect(retryButton(page)).toHaveAttribute("aria-disabled", "true");
    // Playwright counts aria-disabled as disabled: the attribute `disabled` is what would drop the focus.
    expect(await retryButton(page).evaluate((button) => (button as HTMLButtonElement).disabled)).toBe(false);
    await page.keyboard.press("Enter");
    await page.keyboard.press("Space");
    await page.waitForTimeout(500);
    expect(reads.state.reads).toBe(2);

    reads.release();
    await expect(cartTitle(page)).toBeFocused();
  });

  test("says in the status region that the cart is loading and takes the failure message away for that time", async ({ page }) => {
    const reads = await startRetry(page, "cart");

    await expect(page.getByTestId(REGION)).toHaveText(LOADING_TEXT);
    await expect(failedMessage(page)).toHaveCount(0);
    await expect(page.getByRole("main").getByRole("alert")).toHaveCount(0);

    reads.release();
    await expect(page.getByText("Twój koszyk jest pusty")).toBeVisible();
    await expect(page.getByTestId(REGION)).toHaveText("");
  });

  test("puts the focus on the title Koszyk when the retry works", async ({ page }) => {
    const reads = await startRetry(page, "cart");

    reads.release();

    await expect(page.getByText("Twój koszyk jest pusty")).toBeVisible();
    await expect(cartTitle(page)).toBeFocused();
  });

  test("keeps the focus on the button and shows the message again when the retry fails", async ({ page }) => {
    const reads = await startRetry(page, "failure");

    reads.release();

    await expect(failedMessage(page)).toBeVisible();
    await expect(page.getByRole("main").getByRole("alert")).toContainText("Nie udało się wczytać koszyka");
    await expect(retryButton(page)).toBeFocused();
    await expect(retryButton(page)).not.toHaveAttribute("aria-disabled", "true");
    await expect(page.getByTestId(REGION)).toHaveText("");
  });

  test("leaves the focus where the customer moved it during the retry", async ({ page }) => {
    const reads = await startRetry(page, "cart");
    const logo = page.getByRole("banner").getByRole("link", { name: "Worf" });

    await logo.focus();
    reads.release();

    await expect(page.getByText("Twój koszyk jest pusty")).toBeVisible();
    await expect(logo).toBeFocused();
  });
});

test.describe("focus and the status region after removing a line with the keyboard (criterion 12)", () => {
  test("moves the focus to Usuń of the next line, of the previous one after the last, and to the empty cart after the only one", async ({ page }) => {
    test.setTimeout(90_000);
    const [a, b, c] = await cartWithThreeLines(page);
    const region = page.getByTestId(REGION);

    // One of several: the next line.
    await removeOf(page, a).focus();
    await page.keyboard.press("Enter");
    await expect(rowOf(page, a)).toHaveCount(0);
    await expect(removeOf(page, b)).toBeFocused();
    await expect(region).toHaveText(removedText(a));

    // The last of the list: the previous line.
    await removeOf(page, c).focus();
    await page.keyboard.press("Space");
    await expect(rowOf(page, c)).toHaveCount(0);
    await expect(removeOf(page, b)).toBeFocused();
    await expect(region).toHaveText(removedText(c));

    // The only line: the text that the cart is empty, which is in the page once.
    await page.keyboard.press("Enter");
    const empty = page.getByText("Twój koszyk jest pusty");
    await expect(empty).toHaveCount(1);
    await expect(empty).toBeFocused();
    await expect(region).toHaveText(removedText(b));
    await expect(page.getByRole("main").getByRole("listitem")).toHaveCount(0);
  });

  test("keeps the focus on the button of the held removal, and on the control the customer moved to", async ({ page }) => {
    test.setTimeout(90_000);
    const [a, b] = await cartWithThreeLines(page);
    let release: () => void = () => {};
    const gate = new Promise<void>((resolve) => (release = resolve));
    await page.route(ITEM_URL, async (route) => {
      if (route.request().method() === "DELETE") await gate;
      await route.fallback();
    });

    await removeOf(page, a).focus();
    await page.keyboard.press("Enter");
    await expect(removeOf(page, a)).toHaveAttribute("aria-disabled", "true");
    await expect(removeOf(page, a)).toBeFocused();
    await quantityOf(page, b).focus();
    release();

    await expect(rowOf(page, a)).toHaveCount(0);
    await expect(quantityOf(page, b)).toBeFocused();
  });
});

test.describe("the status region is one node of the page (criterion 24)", () => {
  test("is the same attached node through loading, the content and the empty cart, with its text changed", async ({ page }) => {
    test.setTimeout(90_000);
    const [only] = await cartWithLines(page, 1);
    if (only === undefined) throw new Error("the cart needs a line");
    let release: () => void = () => {};
    const gate = new Promise<void>((resolve) => (release = resolve));
    await page.route(CART_READ, async (route) => {
      await gate;
      await route.fallback();
    });

    // Reading the cart again: the region is there from the first render, with the loading text.
    await page.reload();
    const region = page.getByTestId(REGION);
    await expect(region).toHaveText(LOADING_TEXT);
    const loading = await region.elementHandle();
    if (loading === null) throw new Error("the status region is missing");
    const sameNode = async (): Promise<boolean> => {
      const now = await page.getByTestId(REGION).elementHandle();
      return page.evaluate(([before, after]) => before === after && before instanceof Node && before.isConnected, [loading, now]);
    };

    release();
    await expect(page.getByTestId("cart-total")).toBeVisible();
    await expect(region).toHaveText("");
    expect(await sameNode()).toBe(true);

    await removeOf(page, only).click();
    await expect(page.getByText("Twój koszyk jest pusty")).toBeVisible();
    await expect(region).toHaveText(removedText(only));
    expect(await sameNode()).toBe(true);
    await expect(page.getByText(removedText(only))).toHaveCount(1);
  });
});

for (const width of [320, 375]) {
  test.describe(`the text of a removal at ${width} px`, () => {
    test.use({ viewport: { width, height: 812 } });

    test("does not scroll the page sideways", async ({ page }) => {
      test.setTimeout(90_000);
      const [a] = await cartWithLines(page, 2);
      if (a === undefined) throw new Error("the cart needs lines");

      await removeOf(page, a).click();

      const region = page.getByTestId(REGION);
      await expect(region).toHaveText(removedText(a));
      await expect(region).toBeVisible();
      const overflow = await page.evaluate(() => {
        const node = document.querySelector('[data-testid="cart-status"]');
        return {
          page: document.documentElement.scrollWidth - document.documentElement.clientWidth,
          region: node === null ? -1 : Math.max(0, node.scrollWidth - node.clientWidth),
        };
      });
      expect(overflow).toEqual({ page: 0, region: 0 });
    });
  });
}

// "Przejdź do kasy" while changes of the cart are saved (docs/features/store-ux-fixes/spec.md, S2). The tests of
// criterion 8 use two products of "Fitness" > "Trening" that no other test orders: the mat Dravik Podłoże 10 (32 units
// of every variant, apps/api/fixtures/stock.yaml) changed to 3 units, and the dumbbells Dravik Para 24 (1 unit). The
// click at 1280 px orders them as a guest and rejects the payment at the simulator: the unpaid order expires and gives
// the units back.
const CHECKOUT_PRODUCTS = ["Mata do ćwiczeń Dravik Podłoże 10", "Hantle regulowane Dravik Para 24"];
const SAVING_TEXT = "Zapisujemy zmiany w koszyku…";
const NOT_SAVED_TEXT = "Nie udało się zapisać zmian w koszyku. Sprawdź pozycje i spróbuj ponownie.";

async function cartWithProducts(page: Page, names: string[]): Promise<Line[]> {
  await page.goto(CATEGORY);
  await expect(page.getByRole("heading", { level: 1, name: "Trening" })).toBeVisible();
  const lines: Line[] = [];
  for (const name of names) {
    const tile = tileNamed(page, name);
    await expect(tile).toHaveCount(1);
    lines.push({ name, label: await addVariantOf(page, tile, 1) });
  }
  await page.goto("/cart");
  await expect(page.getByTestId("cart-total")).toBeVisible({ timeout: 30_000 });
  return lines;
}

const checkoutLink = (page: Page): Locator => page.getByRole("main").getByRole("link", { name: "Przejdź do kasy" });
const notSavedAlert = (page: Page): Locator => page.getByRole("main").getByRole("alert").filter({ hasText: NOT_SAVED_TEXT });
const isOnPath = (page: Page, path: string): boolean => new URL(page.url()).pathname === path;
/** "1 234,56 zł" as 123456. */
const cents = (text: string): number => Number(text.replace(/\D/g, ""));

/**
 * Holds every quantity change in the browser for at least 3 s and until the test releases it; `release` lets it go to
 * the server, `fail` answers it with a 500.
 */
async function holdQuantityChangesUntilReleased(page: Page): Promise<{ release: () => void; fail: () => void }> {
  let settle: (failure: boolean) => void = () => {};
  const released = new Promise<boolean>((resolve) => (settle = resolve));
  await page.route(ITEM_URL, async (route) => {
    if (route.request().method() !== "PATCH") return route.fallback();
    const [failure] = await Promise.all([released, new Promise((resolve) => setTimeout(resolve, HOLD_MS))]);
    if (failure) return route.fulfill({ status: 500, contentType: "application/problem+json", body: "{}" });
    return route.fallback();
  });
  return { release: () => settle(false), fail: () => settle(true) };
}

/** Presses Tab until the link has the focus, as a keyboard user gets to it from a field above. */
async function tabTo(page: Page, target: Locator): Promise<void> {
  for (let presses = 0; presses < 12; presses++) {
    if (await target.evaluate((element) => element === document.activeElement)) return;
    await page.keyboard.press("Tab");
  }
  await expect(target).toBeFocused();
}

type Way = {
  way: string;
  use: Parameters<typeof test.use>[0];
  touch: boolean;
  /** The first activation of the link, from the quantity field the customer typed into without leaving it. */
  activate: (page: Page, link: Locator) => Promise<void>;
  /** The control activated again while the link waits; Playwright waits for an aria-disabled control to be active. */
  again: (page: Page, control: Locator) => Promise<void>;
  orders: boolean;
};

const DESKTOP = { viewport: { width: 1280, height: 800 } };
const ways: Way[] = [
  {
    way: "a click at 1280 px",
    use: DESKTOP,
    touch: false,
    activate: (_page, link) => link.click(),
    again: (_page, control) => control.click({ force: true }),
    orders: true,
  },
  {
    way: "a tap at 375 px",
    use: { viewport: { width: 375, height: 812 }, hasTouch: true, isMobile: true },
    touch: true,
    activate: (_page, link) => link.tap(),
    again: (_page, control) => control.tap({ force: true }),
    orders: false,
  },
  {
    way: "Tab and Enter",
    use: DESKTOP,
    touch: false,
    activate: async (page, link) => {
      await tabTo(page, link);
      await page.keyboard.press("Enter");
    },
    again: async (page, control) => {
      await control.focus();
      await page.keyboard.press("Enter");
    },
    orders: false,
  },
];

for (const { way, use, touch, activate, again, orders } of ways) {
  test.describe(`"Przejdź do kasy" with ${way} while a change is held (criterion 8)`, () => {
    test.use(use);

    test(`waits on the cart with the link inactive and focused, then the checkout shows the saved quantity${orders ? ", and the order has it" : ""}`, async ({ page }) => {
      test.setTimeout(120_000);
      const [a, b] = await cartWithProducts(page, CHECKOUT_PRODUCTS);
      if (a === undefined || b === undefined) throw new Error("the cart needs two lines");
      const unitPrice = cents(await rowOf(page, a).getByTestId("unit-price").innerText());
      const writes = countWrites(page);
      const held = await holdQuantityChangesUntilReleased(page);
      const link = checkoutLink(page);

      await typeQuantity(quantityOf(page, a), "3", { touch });
      await activate(page, link);

      await expect(link).toHaveAttribute("aria-disabled", "true");
      await expect(link).toHaveAttribute("href", "/checkout");
      await expect(link).toBeFocused();
      expect(isOnPath(page, "/cart")).toBe(true);
      await expect(page.getByTestId(REGION)).toHaveText(SAVING_TEXT);
      await expect(page.getByText(SAVING_TEXT)).toHaveCount(1);
      expect(writes).toEqual({ PATCH: 1, DELETE: 0 });

      // An entry in another field, its Usuń and a second activation of the link change nothing.
      await quantityOf(page, b).focus();
      await page.keyboard.type("5");
      await page.keyboard.press("Tab");
      await expect(quantityOf(page, b)).toHaveValue("1");
      await again(page, removeOf(page, b));
      await again(page, link);
      await page.waitForTimeout(300);
      expect(isOnPath(page, "/cart")).toBe(true);
      await expect(link).toHaveAttribute("aria-disabled", "true");
      await expect(rowOf(page, b)).toHaveCount(1);
      expect(writes).toEqual({ PATCH: 1, DELETE: 0 });

      held.release();

      await expect(page).toHaveURL(/\/checkout$/, { timeout: HOLD_MS + 15_000 });
      await expect(page.getByRole("heading", { level: 1, name: "Kasa" })).toBeVisible();
      const summary = page.getByRole("region", { name: "Podsumowanie zamówienia" });
      const lineA = summary.getByRole("listitem").filter({ hasText: a.name });
      await expect(lineA.getByTestId("checkout-line-quantity")).toHaveText("3 szt.");
      expect(cents(await lineA.getByTestId("checkout-line-total").innerText())).toBe(3 * unitPrice);
      await expect(summary.getByRole("listitem").filter({ hasText: b.name }).getByTestId("checkout-line-quantity")).toHaveText("1 szt.");
      expect(writes).toEqual({ PATCH: 1, DELETE: 0 });
      if (!orders) return;

      const email = `e2e-kasa-po-zapisie+${Date.now()}@example.com`;
      await page.getByLabel("E-mail").fill(email);
      await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
      await fillAddress(page);
      await chooseDelivery(page);
      await chooseProvider(page, "Szybki przelew (demo)");
      await page.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }).click();
      await expectSimulatorPage(page, "demo-transfer");
      await decideAtSimulator(page, "Odrzuć płatność");
      const number = (await page.getByTestId("order-number").innerText()).trim();
      expect(number).toMatch(/^R-\d{6,}$/);

      const confirmation = await waitForOrderEmail(email, number);
      expect(confirmation.text).toContain(`- ${a.label === "" ? a.name : `${a.name}, ${a.label}`} × 3: `);
      expect(confirmation.text).toContain(`- ${b.label === "" ? b.name : `${b.name}, ${b.label}`} × 1: `);
    });
  });
}

test.describe("leaving the cart while the link waits for the changes (criterion 11)", () => {
  test.use(DESKTOP);

  test("stays on the page the customer went to, and the server has the changes", async ({ page }) => {
    test.setTimeout(90_000);
    const [a, b] = await cartWithLines(page, 2);
    if (a === undefined || b === undefined) throw new Error("the cart needs two lines");
    const writes = countWrites(page);
    const held = await holdQuantityChangesUntilReleased(page);

    await typeQuantity(quantityOf(page, a), "3", { touch: false });
    await removeOf(page, b).click();
    await checkoutLink(page).click();
    await expect(checkoutLink(page)).toHaveAttribute("aria-disabled", "true");
    await page.getByRole("banner").getByRole("link", { name: "Worf" }).click();
    await page.waitForURL((url) => url.pathname === "/");

    const removed = page.waitForResponse((response) => response.request().method() === "DELETE" && /\/api\/cart\/items\//.test(response.url()));
    held.release();
    await removed;
    // Nothing navigates later.
    await page.waitForTimeout(1_000);
    expect(isOnPath(page, "/")).toBe(true);
    expect(writes).toEqual({ PATCH: 1, DELETE: 1 });

    const server = (await (await page.request.get("/api/cart")).json()) as { lines: { quantity: number; product: { name: string; variantLabel: string } | null }[] };
    const named = (line: Line) => server.lines.filter((saved) => saved.product?.name === line.name && saved.product.variantLabel === line.label);
    expect(named(a).map((saved) => saved.quantity)).toEqual([3]);
    expect(named(b)).toEqual([]);
  });
});

for (const width of [320, 375]) {
  test.describe(`the texts of the waiting link at ${width} px`, () => {
    test.use({ viewport: { width, height: 812 } });

    test("do not scroll the page sideways: the status while saving and the message after a failure", async ({ page }) => {
      test.setTimeout(90_000);
      const [a] = await cartWithLines(page, 2);
      if (a === undefined) throw new Error("the cart needs lines");
      const held = await holdQuantityChangesUntilReleased(page);
      const overflow = () =>
        page.evaluate((text) => {
          const sideways = (node: Element | null | undefined) => (node ? Math.max(0, node.scrollWidth - node.clientWidth) : -1);
          const alert = [...document.querySelectorAll('[role="alert"]')].find((node) => node.textContent === text);
          return {
            page: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            region: sideways(document.querySelector('[data-testid="cart-status"]')),
            alert: alert === undefined ? null : sideways(alert),
          };
        }, NOT_SAVED_TEXT);

      await typeQuantity(quantityOf(page, a), "3", { touch: false });
      await checkoutLink(page).click();
      await expect(page.getByTestId(REGION)).toHaveText(SAVING_TEXT);
      await expect(page.getByTestId(REGION)).toBeVisible();
      expect(await overflow()).toEqual({ page: 0, region: 0, alert: null });

      held.fail();
      await expect(notSavedAlert(page)).toBeVisible({ timeout: HOLD_MS + 10_000 });
      expect(isOnPath(page, "/cart")).toBe(true);
      await expect(checkoutLink(page)).toBeFocused();
      expect(await overflow()).toEqual({ page: 0, region: 0, alert: 0 });
    });
  });
}
