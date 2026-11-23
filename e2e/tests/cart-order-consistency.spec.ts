import { type APIRequestContext, type BrowserContext, expect, type Page, type Route, test } from "@playwright/test";
import { waitForOrderEmail } from "../support/mailpit";
import { decideAtSimulator, expectSimulatorPage } from "../support/payment";
import { chooseDelivery, chooseProvider, fillAddress } from "../support/shop";
import { urls } from "../support/urls";

// CART-ORDER-CONSISTENCY (docs/features/cart-order-consistency/spec.md, "Testy", E2E): the checkout of one tab and
// changes of the cart made in another tab of the same browser. The other tab changes the cart through the store's cart
// endpoints, as its cart page would. Each test has its own context, so a guest cart of its own.
//
// Stock (apps/api/fixtures/stock.yaml): R1 orders and pays 3 units of Buty trailowe Norvane Hala 500 and R3 1 unit; R2
// and the narrow screens order nothing (the change is refused and the test ends there).

const SHOES = { name: "Buty trailowe Norvane Hala 500", slug: "buty-trailowe-norvane-hala-500" };
// The longest product name of the demo data: the message must fit a narrow screen with it.
const MAP = { name: "Mapa turystyczna Norvane Atlas Karkonosze 1:35 000", slug: "mapa-turystyczna-norvane-atlas-karkonosze-1-35-000" };

const ORDER_BUTTON = "Zamawiam z obowiązkiem zapłaty";
const PLACING = "Składamy zamówienie…";
const CART_CHANGED = "Zawartość koszyka zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.";

type Variant = { variantId: string; label: string; gross: number; purchasable: boolean };

/** Two variants of the product that can be bought, of the same price when the product has such two. */
async function twoVariants(request: APIRequestContext, slug: string): Promise<[Variant, Variant]> {
  const product = (await (await request.get(`${urls.api}/api/v1/products/${slug}`)).json()) as { id: string };
  const offers = (await (await request.get(`${urls.api}/api/v1/product-offers`, { params: { productId: product.id } })).json()) as {
    items: { variants: Variant[] }[];
  };
  const purchasable = (offers.items[0]?.variants ?? []).filter((variant) => variant.purchasable);
  for (const first of purchasable) {
    const second = purchasable.find((other) => other !== first && other.gross === first.gross);
    if (second !== undefined) return [first, second];
  }
  const [first, second] = purchasable;
  if (first === undefined || second === undefined) throw new Error(`${slug} has no two variants to buy`);
  return [first, second];
}

/** A change of the cart sent from `page` to the store's cart endpoints, as its cart page sends it; answers whether it went through. */
function cartWrite(page: Page, method: "POST" | "PATCH", path: string, body: Record<string, unknown>): Promise<boolean> {
  return page.evaluate(
    async ({ method, path, body }) => (await fetch(path, { method, headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) })).ok,
    { method, path, body },
  );
}

/** Another tab of the browser, on the cart page, that sets the quantities of the lines. */
async function changeInAnotherTab(context: BrowserContext, quantities: [Variant, number][]): Promise<void> {
  const other = await context.newPage();
  await other.goto("/cart");
  for (const [variant, quantity] of quantities) {
    expect(await cartWrite(other, "PATCH", `/api/cart/items/${variant.variantId}`, { quantity })).toBe(true);
  }
  await other.close();
}

/** A guest's cart with `lines`, then the checkout with the whole form filled in, the standard delivery and the transfer. */
async function guestCheckout(context: BrowserContext, lines: [Variant, number][], email: string): Promise<Page> {
  const page = await context.newPage();
  await page.goto("/cart");
  for (const [variant, quantity] of lines) {
    expect(await cartWrite(page, "POST", "/api/cart/items", { variantId: variant.variantId, quantity })).toBe(true);
  }
  await page.goto("/checkout");
  await expect(page.getByLabel("E-mail")).toBeVisible();
  await page.getByLabel("E-mail").fill(email);
  await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
  await fillAddress(page);
  await chooseDelivery(page);
  await chooseProvider(page, "Szybki przelew (demo)");
  return page;
}

/** Lets every POST /api/checkout go to the server and notes its status: after a 201 the page leaves, and so does the body. */
async function watchOrders(page: Page): Promise<() => number[]> {
  const statuses: number[] = [];
  await page.route("**/api/checkout", async (route) => {
    if (route.request().method() !== "POST") return route.fallback();
    const response = await route.fetch();
    statuses.push(response.status());
    await route.fulfill({ response });
  });
  return () => [...statuses];
}

/** Holds every read of the cart (GET /api/cart) of the page from now on until `release()`; `reads()` counts them. */
async function holdCartReads(page: Page): Promise<{ release: () => void; reads: () => number }> {
  let release!: () => void;
  const released = new Promise<void>((resolve) => (release = resolve));
  let reads = 0;
  await page.route("**/api/cart", async (route: Route) => {
    if (route.request().method() !== "GET") return route.fallback();
    reads++;
    await released;
    await route.fallback();
  });
  return { release, reads: () => reads };
}

type FirstFrame = { quantities: string[]; total: string | null };

/** Notes what the summary shows in the frame in which the message above the form first appears. */
const watchFirstMessage = (page: Page): Promise<void> =>
  page.evaluate(() => {
    const store = window as unknown as { firstMessage: FirstFrame | null };
    store.firstMessage = null;
    new MutationObserver(() => {
      if (store.firstMessage !== null || document.getElementById("checkout-failure") === null) return;
      store.firstMessage = {
        quantities: Array.from(document.querySelectorAll('[data-testid="checkout-line-quantity"]'), (node) => node.textContent ?? ""),
        total: document.querySelector('[data-testid="checkout-total"]')?.textContent ?? null,
      };
    }).observe(document.body, { subtree: true, childList: true, characterData: true });
  });
const firstMessage = (page: Page): Promise<FirstFrame | null> =>
  page.evaluate(() => (window as unknown as { firstMessage: FirstFrame | null }).firstMessage);

const orderButton = (page: Page) => page.getByRole("button", { name: ORDER_BUTTON });
/** The message above the form, whatever it says. */
const orderMessage = (page: Page) => page.getByRole("main").getByRole("alert");
const quantities = (page: Page) => page.getByTestId("checkout-line-quantity");
const overflows = (page: Page) => page.evaluate(() => document.documentElement.scrollWidth > document.documentElement.clientWidth);

test("R1: two quantities swapped in another tab at the same total are not ordered; the message names both, and the next click orders the new summary", async ({ context, request }) => {
  const [a, b] = await twoVariants(request, SHOES.slug);
  const email = `e2e-spojnosc-r1+${Date.now()}@example.com`;
  const checkout = await guestCheckout(context, [[a, 2], [b, 1]], email);
  await expect(quantities(checkout)).toHaveText(["2 szt.", "1 szt."]);
  const total = (await checkout.getByTestId("checkout-total").innerText()).trim();
  const orders = await watchOrders(checkout);

  await changeInAnotherTab(context, [[a, 1], [b, 2]]);
  await orderButton(checkout).click();

  const message = orderMessage(checkout);
  await expect(message).toContainText(CART_CHANGED);
  expect(orders()).toEqual([409]);
  await expect(message.getByRole("listitem")).toHaveText([`${SHOES.name}, ${a.label}: było 2 szt., jest 1 szt.`, `${SHOES.name}, ${b.label}: było 1 szt., jest 2 szt.`]);
  await expect(message.getByRole("link", { name: "Przejdź do koszyka" })).toHaveAttribute("href", "/cart");
  await expect(quantities(checkout)).toHaveText(["1 szt.", "2 szt."]);
  await expect(checkout.getByTestId("checkout-total")).toHaveText(total);
  await expect(checkout.getByLabel("E-mail")).toHaveValue(email);

  // The customer saw the new summary: the next click orders it.
  await orderButton(checkout).click();
  await expectSimulatorPage(checkout, "demo-transfer");
  expect(orders()).toEqual([409, 201]);
  await decideAtSimulator(checkout, "Zapłać");
  const number = (await checkout.getByTestId("order-number").innerText()).trim();
  const confirmation = await waitForOrderEmail(email, number);
  expect(confirmation.text).toContain(`${SHOES.name}, ${a.label} × 1: `);
  expect(confirmation.text).toContain(`${SHOES.name}, ${b.label} × 2: `);
});

test("R2: a quantity changed in another tab is told with the new summary in the same frame, after the cart is read", async ({ context, request }) => {
  const [a] = await twoVariants(request, SHOES.slug);
  const checkout = await guestCheckout(context, [[a, 1]], `e2e-spojnosc-r2+${Date.now()}@example.com`);
  const shownTotal = (await checkout.getByTestId("checkout-total").innerText()).trim();
  const orders = await watchOrders(checkout);
  await changeInAnotherTab(context, [[a, 3]]);
  const reads = await holdCartReads(checkout);
  await watchFirstMessage(checkout);

  await orderButton(checkout).click();
  await expect.poll(orders).toEqual([409]);
  await expect.poll(reads.reads).toBe(1);

  // The cart is being read: no message yet, the form still says it places the order and shows what it showed.
  await expect(checkout.getByTestId("checkout-status")).toHaveText(PLACING);
  await expect(orderMessage(checkout)).toHaveCount(0);
  await expect(quantities(checkout)).toHaveText(["1 szt."]);
  await expect(checkout.getByLabel("Telefon")).toHaveAttribute("aria-disabled", "true");

  reads.release();
  const message = orderMessage(checkout);
  await expect(message).toContainText(CART_CHANGED);
  const newTotal = (await checkout.getByTestId("checkout-total").innerText()).trim();
  expect(newTotal).not.toBe(shownTotal);
  expect(await firstMessage(checkout)).toEqual({ quantities: ["3 szt."], total: newTotal });
  await expect(message.getByRole("listitem")).toHaveText([`${SHOES.name}, ${a.label}: było 1 szt., jest 3 szt.`, `Do zapłaty: było ${shownTotal}, jest ${newTotal}`]);
  await expect(message.getByRole("link", { name: "Przejdź do koszyka" })).toHaveAttribute("href", "/cart");
  await expect(checkout.getByTestId("checkout-status")).toHaveText("");
  await expect(checkout.getByLabel("Telefon")).not.toHaveAttribute("aria-disabled", "true");
  expect(orders()).toEqual([409]);
});

test("R3 (the boundary): a change of the cart page held on its way when another tab orders does not change the order", async ({ context, request }) => {
  const [a] = await twoVariants(request, SHOES.slug);
  const email = `e2e-spojnosc-r3+${Date.now()}@example.com`;
  const cartTab = await context.newPage();
  await cartTab.goto("/cart");
  expect(await cartWrite(cartTab, "POST", "/api/cart/items", { variantId: a.variantId, quantity: 1 })).toBe(true);
  await cartTab.reload();
  const field = cartTab.getByRole("main").getByRole("spinbutton");
  await expect(field).toHaveValue("1");

  // The quantity 3 typed on the cart page is held in the browser on its way to the server.
  let release!: () => void;
  const released = new Promise<void>((resolve) => (release = resolve));
  const patches: number[] = [];
  await cartTab.route("**/api/cart/items/*", async (route) => {
    if (route.request().method() !== "PATCH") return route.fallback();
    await released;
    const response = await route.fetch();
    patches.push(response.status());
    await route.fulfill({ response });
  });
  await field.fill("3");
  await field.press("Enter");

  const checkout = await context.newPage();
  await checkout.goto("/checkout");
  await expect(quantities(checkout)).toHaveText(["1 szt."]);
  await checkout.getByLabel("E-mail").fill(email);
  await checkout.getByLabel("Imię i nazwisko").fill("Anna Testowa");
  await fillAddress(checkout);
  await chooseDelivery(checkout);
  await chooseProvider(checkout, "Szybki przelew (demo)");
  await orderButton(checkout).click();
  await expectSimulatorPage(checkout, "demo-transfer");

  // The late change finds the cart emptied by the order: the line is gone.
  release();
  await expect.poll(() => patches).toEqual([404]);

  await decideAtSimulator(checkout, "Zapłać");
  const number = (await checkout.getByTestId("order-number").innerText()).trim();
  const confirmation = await waitForOrderEmail(email, number);
  expect(confirmation.text).toContain(`${SHOES.name}, ${a.label} × 1: `);
});

for (const { width, touch } of [
  { width: 375, touch: true },
  { width: 320, touch: false },
]) {
  test.describe(`${width} px${touch ? " with touch" : ""}`, () => {
    test.use({ viewport: { width, height: 800 }, hasTouch: touch });

    test("the message of R1 with the longest product name causes no horizontal scrolling, and its link to the cart works from the keyboard and by touch", async ({ context, request }) => {
      const [a, b] = await twoVariants(request, MAP.slug);
      const checkout = await guestCheckout(context, [[a, 2], [b, 1]], `e2e-spojnosc-waski+${Date.now()}@example.com`);
      await changeInAnotherTab(context, [[a, 1], [b, 2]]);
      if (touch) await orderButton(checkout).tap();
      else await orderButton(checkout).click();

      const message = orderMessage(checkout);
      await expect(message).toContainText(CART_CHANGED);
      await expect(message.getByRole("listitem").first()).toHaveText(`${MAP.name}, ${a.label}: było 2 szt., jest 1 szt.`);
      expect(await overflows(checkout)).toBe(false);

      // The focus is on the message: Tab goes to its link.
      await expect(message).toBeFocused();
      const link = message.getByRole("link", { name: "Przejdź do koszyka" });
      await checkout.keyboard.press("Tab");
      await expect(link).toBeFocused();
      await expect(link).toBeInViewport();
      if (touch) await link.tap();
      else await checkout.keyboard.press("Enter");
      await expect(checkout).toHaveURL(/\/cart$/);
    });
  });
}
