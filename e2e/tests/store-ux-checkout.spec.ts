import { expect, type Page, type Route, test } from "@playwright/test";
import { addVariantOf, chooseDelivery, chooseProvider, fillAddress, tileNamed } from "../support/shop";

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
 * Opens the checkout with the first read of `url` failed. The reads after that (the retries; `/auth/session` is also
 * read by the header, which is why the first read is not counted but the moment the failure is shown) are held until
 * the returned `release()` and then go on to the server (`success`) or fail again.
 */
async function openFailedCheckout(page: Page, url: string, outcome: "success" | "failure") {
  const held = gate();
  let armed = false;
  let requests = 0;
  await page.route(url, async (route) => {
    if (!armed) return failure(route);
    requests++;
    await held.opened;
    return outcome === "success" ? route.continue() : failure(route);
  });
  await page.goto("/checkout");
  await expect(loadFailure(page)).toBeVisible();
  armed = true;
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
      const reads = await openFailedCheckout(page, url, "success");
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
      expect(reads.requests()).toBe(1);

      reads.release();
      await expect(page.getByRole("heading", { level: 1, name: "Kasa" })).toBeFocused();
      await expect(retryButton(page)).toHaveCount(0);
      await expect(statusRegion(page)).toHaveText("");
    });

    test("shows the message again and keeps the focus on the button when the second read fails too", async ({ page }) => {
      const reads = await openFailedCheckout(page, url, "failure");

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
      const reads = await openFailedCheckout(page, url, "success");

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
  const reads = await openFailedCheckout(page, "**/api/cart", "success");
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
      const reads = await openFailedCheckout(page, "**/api/cart", "failure");
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

// S5 (placing the order): the form is locked while POST /api/checkout runs (criterion 16), the focus after a refusal
// stays where the customer moved it (criterion 18), and one click shows both missing choices (criterion 20). The test
// answers every held order with an error itself, so these tests place no order and take no stock.

const ORDER_BUTTON = "Zamawiam z obowiązkiem zapłaty";
const PLACING = "Składamy zamówienie…";
const SUBMIT_FAILED = "Nie udało się złożyć zamówienia. Spróbuj ponownie.";
const PHONE_INVALID = "Podaj 9-cyfrowy numer telefonu.";
const TEXT_FIELDS = ["E-mail", "Imię i nazwisko", "Telefon", "Ulica i numer", "Kod pocztowy", "Miejscowość", "Kod rabatowy"];

const orderButton = (page: Page) => page.getByRole("button", { name: ORDER_BUTTON });
const deliveryGroup = (page: Page) => page.getByRole("group", { name: "Sposób dostawy" });
const providerGroup = (page: Page) => page.getByRole("radiogroup", { name: "Sposób płatności" });
const deliveryOption = (page: Page, name: string) => deliveryGroup(page).getByRole("radio", { name: new RegExp(`^${name}`) });
const providerOption = (page: Page, name: string) => providerGroup(page).getByRole("radio", { name });
const orderFailure = (page: Page) => page.getByRole("alert").filter({ hasText: SUBMIT_FAILED });
const overflows = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);
const focusedId = (page: Page) => page.evaluate(() => document.activeElement?.id ?? "");

/** A guest's cart with one pair of the shoes, then the checkout with the form empty. */
async function openGuestCheckout(page: Page) {
  await page.goto("/");
  const tile = tileNamed(page, PRODUCT);
  await expect(tile).toBeVisible();
  await addVariantOf(page, tile, 1);
  await page.goto("/checkout");
  await expect(page.getByLabel("E-mail")).toBeVisible();
}

/** The whole form of a guest, the standard delivery, the fast transfer and a code typed in but not applied. */
async function fillGuestCheckout(page: Page) {
  await page.getByLabel("E-mail").fill(`e2e-blokada+${Date.now()}@example.com`);
  await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
  await fillAddress(page);
  await chooseDelivery(page);
  await chooseProvider(page, "Szybki przelew (demo)");
  await page.getByLabel("Kod rabatowy").fill("LATO15");
}

/**
 * Holds every POST /api/checkout until `release(answer)`, which then answers it without the server: no order is placed.
 * `posts()` counts the POSTs that left the page, `codeChecks()` the reads of the cart with a code.
 */
async function holdOrders(page: Page) {
  const held = gate();
  let answer: (route: Route) => Promise<void> = failure;
  let posts = 0;
  let codeChecks = 0;
  await page.route("**/api/checkout", async (route) => {
    if (route.request().method() !== "POST") return route.continue();
    posts++;
    await held.opened;
    await answer(route);
  });
  page.on("request", (request) => {
    if (request.url().includes("/api/cart?") && request.url().includes("discountCode")) codeChecks++;
  });
  return {
    release: (respond: (route: Route) => Promise<void>) => {
      answer = respond;
      held.open();
    },
    posts: () => posts,
    codeChecks: () => codeChecks,
  };
}

const invalidPhone = (route: Route) =>
  route.fulfill({
    status: 422,
    contentType: "application/json",
    body: JSON.stringify({ detail: "Nieprawidłowe dane.", violations: [{ propertyPath: "phone", title: PHONE_INVALID }] }),
  });

const valuesOf = (page: Page) => Promise.all(TEXT_FIELDS.map((label) => page.getByLabel(label).inputValue()));

test.describe("the checkout while the order is placed", () => {
  test.use({ hasTouch: true });

  test("keeps every field, choice and button as it was, with the focus on the order button, and sends one order", async ({ page }) => {
    await openGuestCheckout(page);
    await fillGuestCheckout(page);
    const before = await valuesOf(page);
    const orders = await holdOrders(page);

    await orderButton(page).click();
    await expect.poll(orders.posts).toBe(1);
    await expect(orderButton(page)).toBeFocused();

    // Space, Enter and a click on the button send nothing more, and the focus stays on it.
    await page.keyboard.press("Space");
    await page.keyboard.press("Enter");
    await orderButton(page).click({ force: true });
    await settle(page);
    await expect(orderButton(page)).toBeFocused();
    expect(orders.posts()).toBe(1);

    // Typing changes no field, the code included; Enter in a field sends nothing.
    for (const [index, label] of TEXT_FIELDS.entries()) {
      const input = page.getByLabel(label);
      await input.focus();
      await page.keyboard.type("X");
      await expect(input).toHaveValue(before[index]);
      await page.keyboard.press("Backspace");
      await expect(input).toHaveValue(before[index]);
      await expect(input).toHaveAttribute("aria-disabled", "true");
    }
    await page.getByLabel("Miejscowość").focus();
    await page.keyboard.press("Enter");
    await settle(page);
    expect(await valuesOf(page)).toEqual(before);
    expect(orders.posts()).toBe(1);

    // The delivery and the provider do not change with the mouse, a tap, the arrows or Space.
    for (const [chosen, other] of [
      [deliveryOption(page, "Kurier standardowy"), deliveryOption(page, "Kurier ekspresowy")],
      [providerOption(page, "Szybki przelew (demo)"), providerOption(page, "Karta płatnicza (demo)")],
    ] as const) {
      await expect(other).toHaveAttribute("aria-disabled", "true");
      await other.click({ force: true });
      await other.tap({ force: true });
      await chosen.focus();
      await page.keyboard.press("ArrowDown");
      await page.keyboard.press("ArrowUp");
      await other.focus();
      await page.keyboard.press("Space");
      await settle(page);
      await expect(chosen).toBeChecked();
      await expect(other).not.toBeChecked();
    }

    // „Zastosuj” checks no code.
    await page.getByRole("button", { name: "Zastosuj" }).click({ force: true });
    await settle(page);
    expect(orders.codeChecks()).toBe(0);
    expect(orders.posts()).toBe(1);

    // The button is blocked without `disabled`, and the region says what the screen is doing.
    await expect(orderButton(page)).toHaveAttribute("aria-disabled", "true");
    await expect(orderButton(page)).not.toHaveAttribute("disabled");
    await expect(page.getByRole("button", { name: "Zastosuj" })).toHaveAttribute("aria-disabled", "true");
    await expect(statusRegion(page)).toHaveText(PLACING);

    orders.release(failure);
    await expect(orderFailure(page)).toBeVisible();
    await expect(orderButton(page)).not.toHaveAttribute("aria-disabled", "true");
    await expect(statusRegion(page)).toHaveText("");
    expect(await valuesOf(page)).toEqual(before);
    await expect(deliveryOption(page, "Kurier standardowy")).toBeChecked();
    await expect(providerOption(page, "Szybki przelew (demo)")).toBeChecked();
    expect(orders.posts()).toBe(1);
  });

  test("an order sent with Enter from „Miejscowość” leaves the focus where Tab moved it during the request", async ({ page }) => {
    await openGuestCheckout(page);
    await fillGuestCheckout(page);
    const orders = await holdOrders(page);

    await page.getByLabel("Miejscowość").focus();
    await page.keyboard.press("Enter");
    await expect.poll(orders.posts).toBe(1);
    await expect(page.getByLabel("Miejscowość")).toBeFocused();
    await page.keyboard.press("Tab");
    await expect(page.getByLabel("Miejscowość")).not.toBeFocused();
    const moved = await focusedId(page);
    expect(moved).not.toBe("");

    orders.release(failure);
    await expect(orderFailure(page)).toBeVisible();
    await settle(page);
    expect(await focusedId(page)).toBe(moved);
  });

  test("an order sent with Enter from „Miejscowość” puts the focus on the first wrong field after a 422 when it stayed in the field", async ({ page }) => {
    await openGuestCheckout(page);
    await fillGuestCheckout(page);
    const orders = await holdOrders(page);

    await page.getByLabel("Miejscowość").focus();
    await page.keyboard.press("Enter");
    await expect.poll(orders.posts).toBe(1);

    orders.release(invalidPhone);
    await expect(page.getByLabel("Telefon")).toBeFocused();
    await expect(page.getByLabel("Telefon")).toHaveAttribute("aria-invalid", "true");
  });
});

test.describe("375 px with touch", () => {
  test.use({ viewport: { width: 375, height: 800 }, hasTouch: true });

  test("an empty form with two delivery methods shows both missing choices with one tap, the focus on the first method in view, and orders nothing", async ({ page }) => {
    await openGuestCheckout(page);
    const orders = await holdOrders(page);
    await expect(deliveryGroup(page).getByRole("radio")).toHaveCount(2);

    await orderButton(page).tap();
    const deliveryMessage = deliveryGroup(page).getByText("Wybierz sposób dostawy.");
    const providerMessage = providerGroup(page).getByText("Wybierz sposób płatności.");
    await expect(deliveryMessage).toBeVisible();
    await expect(providerMessage).toBeVisible();
    await expect(deliveryGroup(page)).toHaveAttribute("aria-invalid", "true");
    await expect(deliveryGroup(page)).toHaveAttribute("aria-describedby", (await deliveryMessage.getAttribute("id")) ?? "");
    await expect(providerGroup(page)).toHaveAttribute("aria-invalid", "true");
    await expect(providerGroup(page)).toHaveAttribute("aria-describedby", (await providerMessage.getAttribute("id")) ?? "");
    await expect(deliveryOption(page, "Kurier standardowy")).toBeFocused();
    await expect(deliveryOption(page, "Kurier standardowy")).toBeInViewport();
    await settle(page);
    expect(orders.posts()).toBe(0);
  });
});

for (const width of [320, 375]) {
  test.describe(`${width} px, placing the order`, () => {
    test.use({ viewport: { width, height: 800 } });

    test("the text of the order being placed and both messages of the choices cause no horizontal scrolling", async ({ page }) => {
      await openGuestCheckout(page);
      const orders = await holdOrders(page);

      await orderButton(page).click();
      await expect(deliveryGroup(page).getByText("Wybierz sposób dostawy.")).toBeVisible();
      await expect(providerGroup(page).getByText("Wybierz sposób płatności.")).toBeVisible();
      expect(await overflows(page)).toBe(false);

      await fillGuestCheckout(page);
      await orderButton(page).click();
      await expect(statusRegion(page)).toHaveText(PLACING);
      expect(await overflows(page)).toBe(false);

      orders.release(failure);
      await expect(orderFailure(page)).toBeVisible();
      expect(await overflows(page)).toBe(false);
    });
  });
}
