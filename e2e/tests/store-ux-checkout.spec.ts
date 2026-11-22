import { expect, type Page, type Route, test } from "@playwright/test";
import { addVariantOf, tileNamed } from "../support/shop";

// STORE-UX-FIXES, S4 (the checkout): "Spróbuj ponownie" stays on the screen while the checkout is read again and the
// focus lands in a predictable place (criterion 14), the status region is one node through every state of the screen
// (criterion 24), and the login link follows the switches of the deployment (criterion 15).

const PRODUCT = "Buty trailowe Norvane Hala 500";
const LOAD_FAILED = "Nie udało się wczytać kasy";
const LOGIN_LINK = "Zaloguj się";

/** A gate a test opens when it wants the held request to go on. */
function gate(): { opened: Promise<void>; open: () => void } {
  let open!: () => void;
  const opened = new Promise<void>((resolve) => {
    open = resolve;
  });
  return { opened, open };
}

const failure = (route: Route) => route.fulfill({ status: 500, contentType: "application/json", body: "{}" });

/**
 * The two reads of the checkout. The first read of `url` fails; the read again (the second request) is held until the
 * returned `release()` and then goes on to the server (`success`) or fails again.
 */
async function failFirstHoldSecond(page: Page, url: string, outcome: "success" | "failure") {
  const held = gate();
  let requests = 0;
  await page.route(url, async (route) => {
    requests++;
    if (requests === 1) return failure(route);
    await held.opened;
    return outcome === "success" ? route.continue() : failure(route);
  });
  return { release: held.open, requests: () => requests };
}

/** Two frames of the browser after a key was pressed: what a handler would have sent has been sent by now. */
const settle = (page: Page) =>
  page.evaluate(() => new Promise<void>((resolve) => requestAnimationFrame(() => requestAnimationFrame(() => resolve()))));

const statusRegion = (page: Page) => page.getByTestId("checkout-status");
const retryButton = (page: Page) => page.getByRole("button", { name: "Spróbuj ponownie" });
const loadFailure = (page: Page) => page.getByRole("alert").filter({ hasText: LOAD_FAILED });

const READS = [
  { name: "the cart", url: "**/api/cart" },
  { name: "the session", url: "**/auth/session" },
];

for (const { name, url } of READS) {
  test.describe(`a retry of the load of the checkout when ${name} cannot be read`, () => {
    test("stays on the screen, ignores a second Enter, and ends on the heading", async ({ page }) => {
      const reads = await failFirstHoldSecond(page, url, "success");
      await page.goto("/checkout");
      await expect(loadFailure(page)).toBeVisible();
      await expect(statusRegion(page)).toHaveText("");

      await retryButton(page).focus();
      await page.keyboard.press("Enter");

      await expect(statusRegion(page)).toHaveText("Wczytywanie…");
      await expect(retryButton(page)).toBeVisible();
      await expect(retryButton(page)).toHaveAttribute("aria-disabled", "true");
      await expect(retryButton(page)).toBeFocused();
      await expect(loadFailure(page)).toHaveCount(0);

      await page.keyboard.press("Enter");
      await settle(page);
      expect(reads.requests()).toBe(2);

      reads.release();
      await expect(page.getByRole("heading", { level: 1, name: "Kasa" })).toBeFocused();
      await expect(retryButton(page)).toHaveCount(0);
      await expect(statusRegion(page)).toHaveText("");
    });

    test("shows the message again and keeps the focus on the button when the second read fails too", async ({ page }) => {
      const reads = await failFirstHoldSecond(page, url, "failure");
      await page.goto("/checkout");
      await expect(loadFailure(page)).toBeVisible();

      await retryButton(page).focus();
      await page.keyboard.press("Enter");
      await expect(loadFailure(page)).toHaveCount(0);
      await expect(statusRegion(page)).toHaveText("Wczytywanie…");

      reads.release();
      await expect(loadFailure(page)).toBeVisible();
      await expect(retryButton(page)).toBeFocused();
      await expect(retryButton(page)).not.toHaveAttribute("aria-disabled", "true");
      await expect(statusRegion(page)).toHaveText("");
    });

    test("leaves the focus where the customer moved it during the read", async ({ page }) => {
      const reads = await failFirstHoldSecond(page, url, "success");
      await page.goto("/checkout");
      await expect(loadFailure(page)).toBeVisible();

      await retryButton(page).focus();
      await page.keyboard.press("Enter");
      await expect(statusRegion(page)).toHaveText("Wczytywanie…");
      const elsewhere = page.getByRole("banner").getByRole("link").first();
      await elsewhere.focus();
      await expect(elsewhere).toBeFocused();

      reads.release();
      await expect(retryButton(page)).toHaveCount(0);
      await expect(statusRegion(page)).toHaveText("");
      await expect(elsewhere).toBeFocused();
    });
  });
}

test("the status region of the checkout is one node through loading, the error and the content", async ({ page }) => {
  const reads = await failFirstHoldSecond(page, "**/api/cart", "success");
  await page.goto("/checkout");
  await expect(loadFailure(page)).toBeVisible();
  const region = await statusRegion(page).elementHandle();
  expect(region).not.toBeNull();
  const sameNode = () =>
    region!.evaluate((node) => node.isConnected && node === document.querySelector('[data-testid="checkout-status"]'));
  expect(await sameNode()).toBe(true);

  await retryButton(page).focus();
  await page.keyboard.press("Enter");
  await expect(statusRegion(page)).toHaveText("Wczytywanie…");
  expect(await sameNode()).toBe(true);

  reads.release();
  await expect(page.getByText("Twój koszyk jest pusty.")).toBeVisible();
  await expect(statusRegion(page)).toHaveText("");
  expect(await sameNode()).toBe(true);
  // The text is in the region only: no other node says it.
  await expect(page.getByText("Wczytywanie…")).toHaveCount(0);
});

test("the status region of the checkout is there from the first render, while the first read is held", async ({ page }) => {
  const held = gate();
  await page.route("**/api/cart", async (route) => {
    await held.opened;
    await route.continue();
  });
  await page.goto("/checkout", { waitUntil: "commit" });
  await expect(statusRegion(page)).toHaveText("Wczytywanie…");
  await expect(statusRegion(page)).toBeVisible();
  await expect(page.getByText("Wczytywanie…")).toHaveCount(1);
  const region = await statusRegion(page).elementHandle();

  held.open();
  await expect(page.getByText("Twój koszyk jest pusty.")).toBeVisible();
  expect(await region!.evaluate((node) => node === document.querySelector('[data-testid="checkout-status"]'))).toBe(true);
});

for (const width of [320, 375]) {
  test.describe(`${width} px`, () => {
    test.use({ viewport: { width, height: 800 } });

    test("the retry and its status text cause no horizontal scrolling", async ({ page }) => {
      const reads = await failFirstHoldSecond(page, "**/api/cart", "failure");
      await page.goto("/checkout");
      await expect(loadFailure(page)).toBeVisible();
      const overflows = () => page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
      expect(await overflows()).toBe(false);

      await retryButton(page).focus();
      await page.keyboard.press("Enter");
      await expect(statusRegion(page)).toHaveText("Wczytywanie…");
      expect(await overflows()).toBe(false);

      reads.release();
      await expect(loadFailure(page)).toBeVisible();
      expect(await overflows()).toBe(false);
    });
  });
}

/** Puts a product in the cart of a guest, then holds the read of the switches of the checkout page. */
async function checkoutWithSwitches(page: Page, answer: (route: Route) => Promise<void>) {
  await page.goto("/");
  const tile = tileNamed(page, PRODUCT);
  await expect(tile).toBeVisible();
  await addVariantOf(page, tile, 1);

  const held = gate();
  await page.route("**/api/site", async (route) => {
    await held.opened;
    await answer(route);
  });
  await page.goto("/checkout");
  await expect(page.getByLabel("E-mail")).toBeVisible();
  return held.open;
}

const loginLinkOfCheckout = (page: Page) => page.getByRole("main").getByRole("link", { name: LOGIN_LINK });

test.describe("the login link of the checkout follows the switches", () => {
  test("a store without accounts has no link before the answer and after it", async ({ page }) => {
    const release = await checkoutWithSwitches(page, (route) =>
      route.fulfill({ json: { demoNotice: true, customerAccounts: false }, headers: { "Cache-Control": "no-store" } }),
    );
    await expect(loginLinkOfCheckout(page)).toHaveCount(0);
    await expect(page.getByText("Masz konto?")).toHaveCount(0);

    release();
    // The bar of the demo comes with the same answer: once it is shown, the switches are applied.
    await expect(page.getByRole("note")).toBeVisible();
    await expect(loginLinkOfCheckout(page)).toHaveCount(0);
    await expect(page.getByText("Masz konto?")).toHaveCount(0);
    await expect(page.getByLabel("E-mail")).toBeVisible();
  });

  test("a store with accounts shows the link only after the answer", async ({ page }) => {
    const release = await checkoutWithSwitches(page, (route) =>
      route.fulfill({ json: { demoNotice: true, customerAccounts: true }, headers: { "Cache-Control": "no-store" } }),
    );
    await expect(loginLinkOfCheckout(page)).toHaveCount(0);

    release();
    await expect(page.getByRole("note")).toBeVisible();
    await expect(loginLinkOfCheckout(page)).toHaveAttribute("href", "/auth/login?returnTo=%2Fcheckout");
  });

  test("a failed read of the switches leaves the link, as in the header", async ({ page }) => {
    const release = await checkoutWithSwitches(page, (route) => failure(route));
    await expect(loginLinkOfCheckout(page)).toHaveCount(0);

    release();
    await expect(loginLinkOfCheckout(page)).toHaveAttribute("href", "/auth/login?returnTo=%2Fcheckout");
  });
});
