import { type APIRequestContext, type BrowserContext, expect, type Page, type Route, test } from "@playwright/test";
import { customers } from "../support/accounts";
import { waitForOrderEmail } from "../support/mailpit";
import { decideAtSimulator, expectSimulatorPage } from "../support/payment";
import { chooseDelivery, chooseProvider, emptyCart, fillAddress, logInAs } from "../support/shop";
import { urls } from "../support/urls";

// CART-ORDER-CONSISTENCY (docs/features/cart-order-consistency/spec.md, "Testy", E2E): the checkout of one tab and
// changes of the cart made in another tab of the same browser. The other tab changes the cart through the store's cart
// endpoints, as its cart page would. Each test has its own context, so a guest cart of its own.
//
// Stock (apps/api/fixtures/stock.yaml): R1 orders and pays 3 units of Buty trailowe Norvane Hala 500, R3, R4, R5 and
// R7 1 unit each; R2, R6 and the narrow screens order nothing (the change is refused and the test ends there).
//
// R4 to R7 change the buyer in the other tab with the demo accounts Jan and Anna, which the whole suite shares:
// each of them prepares the carts it needs, and both carts are emptied after it, also after a failure.

const SHOES = { name: "Buty trailowe Norvane Hala 500", slug: "buty-trailowe-norvane-hala-500" };
// The longest product name of the demo data: the message must fit a narrow screen with it.
const MAP = { name: "Mapa turystyczna Norvane Atlas Karkonosze 1:35 000", slug: "mapa-turystyczna-norvane-atlas-karkonosze-1-35-000" };

const ORDER_BUTTON = "Zamawiam z obowiązkiem zapłaty";
const PLACING = "Składamy zamówienie…";
const CART_CHANGED = "Zawartość koszyka zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.";
const SIGNED_IN_AS = (email: string) =>
  `Od wczytania kasy zmieniło się konto: teraz to ${email}. Nie złożyliśmy zamówienia. Zamówienie będzie zamówieniem tego konta: sprawdź dane i podsumowanie, potem zamów ponownie.`;
// The same after a retry of an uncertain result: the attempt before may have placed the order.
const MAYBE_SIGNED_IN_AS = (email: string) =>
  `Od wczytania kasy zmieniło się konto: teraz to ${email}. Wcześniejsza próba mogła złożyć zamówienie. Sprawdź pocztę, zanim zamówisz ponownie. Zamówienie będzie zamówieniem tego konta: sprawdź dane i podsumowanie, potem zamów ponownie.`;
// 60 characters without a space or a hyphen: the address must wrap on a narrow screen. The demo accounts have short ones.
const LONG_EMAIL = "bardzodlugiadresklientasklepunorvanetestowykonto@example.com";

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

    test("the message of a changed buyer with a long address of the account causes no horizontal scrolling", async ({ context, request }) => {
      const [a] = await twoVariants(request, MAP.slug);
      const checkout = await guestCheckout(context, [[a, 1]], `e2e-spojnosc-waski-kupujacy+${Date.now()}@example.com`);
      // The order is refused for a changed buyer without reaching the server (nothing is ordered), and the session read
      // after it is of an account with a long address.
      await checkout.route("**/api/checkout", (route) =>
        route.request().method() === "POST"
          ? route.fulfill({
              status: 409,
              json: { type: "tag:piotrp321.github.io,2026:shop/problems/checkout-buyer-changed", detail: "Od wczytania kasy zmienił się kupujący. Nie złożyliśmy zamówienia." },
            })
          : route.fallback(),
      );
      await checkout.route("**/auth/session", (route) => route.fulfill({ json: { name: LONG_EMAIL, email: LONG_EMAIL, buyer: "buyer-tag-of-the-account" } }));
      if (touch) await orderButton(checkout).tap();
      else await orderButton(checkout).click();

      await expect(orderMessage(checkout)).toHaveText(SIGNED_IN_AS(LONG_EMAIL));
      await expect(checkout.getByTestId("account-email")).toHaveText(LONG_EMAIL);
      expect(await overflows(checkout)).toBe(false);
    });

    test("long texts without a space from the data (product, variant, delivery method, code) cause no horizontal scrolling in the summary and in the message of the changes", async ({ context, request }) => {
      const [a] = await twoVariants(request, SHOES.slug);
      const page = await context.newPage();
      await page.goto("/cart");
      expect(await cartWrite(page, "POST", "/api/cart/items", { variantId: a.variantId, quantity: 1 })).toBe(true);
      // The demo data has no such texts: every read of the cart gets them, and after the refusal also another quantity
      // and another cost of the delivery, so that the message lists the line and the method.
      const reads = { refused: false };
      await page.route("**/api/cart", async (route) => {
        if (route.request().method() !== "GET") return route.fallback();
        const response = await route.fetch();
        const view = (await response.json()) as LongCart;
        lengthen(view, reads.refused);
        return route.fulfill({ response, json: view });
      });
      // The order is refused for a changed cart without reaching the server: nothing is ordered.
      await page.route("**/api/checkout", (route) => {
        if (route.request().method() !== "POST") return route.fallback();
        reads.refused = true;
        return route.fulfill({ status: 409, json: { type: "tag:piotrp321.github.io,2026:shop/problems/cart-changed", detail: "Zawartość koszyka się zmieniła." } });
      });
      await page.goto("/checkout");
      await expect(page.getByLabel("E-mail")).toBeVisible();
      await page.getByLabel("E-mail").fill(`e2e-spojnosc-dlugie+${Date.now()}@example.com`);
      await page.getByLabel("Imię i nazwisko").fill("Anna Testowa");
      await fillAddress(page);
      await chooseDelivery(page, LONG_METHOD);
      await chooseProvider(page, "Szybki przelew (demo)");
      await expect(page.getByTestId("checkout-discount")).toContainText(LONG_CODE);
      expect(await overflows(page)).toBe(false);

      if (touch) await orderButton(page).tap();
      else await orderButton(page).click();
      const message = orderMessage(page);
      await expect(message).toContainText(CART_CHANGED);
      await expect(message.getByRole("listitem")).toHaveText([
        new RegExp(`^${LONG_PRODUCT}, ${LONG_LABEL}: było 1 szt., jest 2 szt.$`),
        new RegExp(`^Dostawa „${LONG_METHOD}”: `),
        /^Do zapłaty: /,
      ]);
      expect(await overflows(page)).toBe(false);
    });
  });
}

// Texts of the data without a space, longer than a narrow screen: the product name of 78 characters of the review (U1).
const LONG_PRODUCT = "Superdluganazwaproduktubezspacjiwogolenienadajacasiedozawinieciaanijednejlinii";
const LONG_LABEL = "Wariantobardzodlugiejetykieciebezspacjiktorytezniezmiesciesiewjednejlinii";
const LONG_METHOD = "Kurierstandardowyzbardzodlugananazwabezspacjiktoranieznajdziemiejscawlinii";
// The longest code the field takes (64 characters).
const LONG_CODE = "KODRABATOWYBEZSPACJIDOKLADNIESZESCDZIESIATCZTERYZNAKIDLUGOSCI123";

type LongCart = {
  lines: { quantity: number; product: { name: string; variantLabel: string | null } | null }[];
  totals: { discountGross: number };
  discount: unknown;
  delivery: { options: { name: string; description: string | null; costGross: number; totalGross: number }[] } | null;
};

/** Gives a read of the cart the long texts (and an applied code of the longest length); `changed`: after the refusal. */
function lengthen(view: LongCart, changed: boolean): void {
  for (const line of view.lines) {
    if (line.product !== null) line.product = { ...line.product, name: LONG_PRODUCT, variantLabel: LONG_LABEL };
    if (changed) line.quantity = 2;
  }
  view.totals.discountGross = 1000;
  view.discount = { status: "applied", code: LONG_CODE, amountGross: 1000, kind: "amount", value: 1000 };
  const standard = view.delivery?.options[0];
  if (standard !== undefined) {
    standard.name = LONG_METHOD;
    standard.description = LONG_LABEL;
    if (changed) {
      standard.costGross += 1000;
      standard.totalGross += 1000;
    }
  }
}

const SESSION_EXPIRED = "Twoja sesja wygasła. Zaloguj się ponownie.";

type Account = (typeof customers)[keyof typeof customers];
type CartLine = { variantId: string; quantity: number };

/** Signs out of the store when signed in; ends on the home page with the login link. */
async function logOut(page: Page): Promise<void> {
  await page.goto("/");
  const login = page.getByRole("banner").getByRole("link", { name: "Zaloguj się" });
  const logout = page.getByRole("banner").getByRole("button", { name: "Wyloguj się" });
  await expect(login.or(logout)).toBeVisible();
  if (await logout.isVisible()) await logout.click();
  await expect(login).toBeVisible();
}

/** Signs in as `account`, signing out of any other account first. */
async function signInAs(page: Page, account: Account): Promise<void> {
  await logOut(page);
  await logInAs(page, account);
}

/** Adds the lines to the cart of the page (the account's when signed in) through the store's cart endpoints. */
async function addLines(page: Page, lines: [Variant, number][]): Promise<void> {
  await page.goto("/cart");
  for (const [variant, quantity] of lines) {
    expect(await cartWrite(page, "POST", "/api/cart/items", { variantId: variant.variantId, quantity })).toBe(true);
  }
}

/** The lines of the cart of the page as the store's cart endpoint answers them. */
const cartLinesOf = (page: Page): Promise<CartLine[]> =>
  page.evaluate(async () => {
    const view = (await (await fetch("/api/cart", { cache: "no-store" })).json()) as { lines: CartLine[] };
    return view.lines.map(({ variantId, quantity }) => ({ variantId, quantity }));
  });

/** The checkout of the signed-in customer of the page, with the address, the standard delivery and the transfer. */
async function customerCheckout(page: Page, account: Account): Promise<void> {
  await page.goto("/checkout");
  await expect(page.getByTestId("account-email")).toHaveText(account.email);
  await page.getByLabel("Imię i nazwisko").fill("Klient Testowy");
  await fillAddress(page);
  await chooseDelivery(page);
  await chooseProvider(page, "Szybki przelew (demo)");
}

/** The header names the account `name` and offers its logout, not the login. */
async function expectHeaderSignedInAs(page: Page, name: string): Promise<void> {
  const banner = page.getByRole("banner");
  await expect(banner).toContainText(`Zalogowany jako ${name}`);
  await expect(banner.getByRole("button", { name: "Wyloguj się" })).toBeVisible();
  await expect(banner.getByRole("link", { name: "Zaloguj się" })).toHaveCount(0);
}

/** How many order confirmations Mailpit has for `to`. */
async function confirmationCount(to: string): Promise<number> {
  const query = `to:"${to}" subject:"Potwierdzenie zamówienia"`;
  const response = await fetch(`${urls.mailpit}/api/v1/search?query=${encodeURIComponent(query)}`);
  expect(response.ok).toBe(true);
  return ((await response.json()) as { messages_count: number }).messages_count;
}

test.describe("the buyer changed in another tab", () => {
  test.afterEach(async ({ context }) => {
    // The demo accounts are shared by the whole suite: no line of these tests stays in their carts.
    const page = await context.newPage();
    for (const account of [customers.jan, customers.anna]) {
      await signInAs(page, account);
      await emptyCart(page);
    }
    await logOut(page);
  });

  test("R4: a guest's checkout is not ordered after Jan logs in in another tab; the message names his account, the form keeps its data without the e-mail, and the next click orders for his account", async ({ context, request }) => {
    const [a] = await twoVariants(request, SHOES.slug);
    // Jan starts with an empty cart: after his login his cart is the guest's, merged.
    const setupPage = await context.newPage();
    await signInAs(setupPage, customers.jan);
    await emptyCart(setupPage);
    await logOut(setupPage);
    await setupPage.close();
    const email = `e2e-spojnosc-r4+${Date.now()}@example.com`;
    const checkout = await guestCheckout(context, [[a, 1]], email);
    const total = (await checkout.getByTestId("checkout-total").innerText()).trim();
    const orders = await watchOrders(checkout);

    const other = await context.newPage();
    await other.goto("/");
    await logInAs(other, customers.jan);
    await orderButton(checkout).click();

    const message = orderMessage(checkout);
    await expect(message).toHaveText(SIGNED_IN_AS(customers.jan.email));
    expect(orders()).toEqual([409]);
    await expect(checkout.getByTestId("account-email")).toHaveText(customers.jan.email);
    await expect(checkout.getByLabel("E-mail")).toHaveCount(0);
    // The header shows the same account as the checkout.
    await expectHeaderSignedInAs(checkout, "Jan Kowalski");
    await expect(checkout.getByLabel("Imię i nazwisko")).toHaveValue("Anna Testowa");
    await expect(checkout.getByLabel("Miejscowość")).toHaveValue("Warszawa");
    await expect(checkout.getByRole("radio", { name: "Szybki przelew (demo)" })).toBeChecked();
    await expect(quantities(checkout)).toHaveText(["1 szt."]);
    await expect(checkout.getByTestId("checkout-total")).toHaveText(total);

    // The customer saw the account: the next click orders for it.
    await orderButton(checkout).click();
    await expectSimulatorPage(checkout, "demo-transfer");
    expect(orders()).toEqual([409, 201]);
    await decideAtSimulator(checkout, "Zapłać");
    const number = (await checkout.getByTestId("order-number").innerText()).trim();
    const confirmation = await waitForOrderEmail(customers.jan.email, number);
    expect(confirmation.text).toContain(`${SHOES.name}, ${a.label} × 1: `);
    expect(await confirmationCount(email)).toBe(0);
  });

  test("R5: Jan's checkout is not ordered after another tab signs out and in as Anna with the same lines and total; neither account orders, Anna's cart stays, and the next click orders for Anna", async ({ context, request }) => {
    const [a] = await twoVariants(request, SHOES.slug);
    const page = await context.newPage();
    // Anna's cart has the same line as Jan's checkout: the same lines and the same total.
    await signInAs(page, customers.anna);
    await emptyCart(page);
    await addLines(page, [[a, 1]]);
    await signInAs(page, customers.jan);
    await emptyCart(page);
    await addLines(page, [[a, 1]]);
    await customerCheckout(page, customers.jan);
    const total = (await page.getByTestId("checkout-total").innerText()).trim();
    const orders = await watchOrders(page);

    const other = await context.newPage();
    await signInAs(other, customers.anna);
    await orderButton(page).click();

    const message = orderMessage(page);
    await expect(message).toHaveText(SIGNED_IN_AS(customers.anna.email));
    expect(orders()).toEqual([409]);
    await expect(page.getByTestId("account-email")).toHaveText(customers.anna.email);
    await expectHeaderSignedInAs(page, "Anna Nowak");
    await expect(page.getByRole("banner")).not.toContainText("Jan Kowalski");
    await expect(quantities(page)).toHaveText(["1 szt."]);
    await expect(page.getByTestId("checkout-total")).toHaveText(total);
    await expect(page.getByLabel("Imię i nazwisko")).toHaveValue("Klient Testowy");
    // Nothing was ordered: Anna's cart is as it was.
    expect(await cartLinesOf(other)).toEqual([{ variantId: a.variantId, quantity: 1 }]);

    await orderButton(page).click();
    await expectSimulatorPage(page, "demo-transfer");
    expect(orders()).toEqual([409, 201]);
    await decideAtSimulator(page, "Zapłać");
    const number = (await page.getByTestId("order-number").innerText()).trim();
    const confirmation = await waitForOrderEmail(customers.anna.email, number);
    expect(confirmation.text).toContain(`${SHOES.name}, ${a.label} × 1: `);

    // Jan's cart was never ordered: an order empties the cart it is placed from.
    await signInAs(other, customers.jan);
    expect(await cartLinesOf(other)).toEqual([{ variantId: a.variantId, quantity: 1 }]);
  });

  test("R7: Jan's checkout whose answer was lost, retried after another tab signs in as Anna, does not say that nothing was ordered: the lost attempt placed Jan's order", async ({ context, request }) => {
    const [a] = await twoVariants(request, SHOES.slug);
    const page = await context.newPage();
    await signInAs(page, customers.anna);
    await emptyCart(page);
    await signInAs(page, customers.jan);
    await emptyCart(page);
    await addLines(page, [[a, 1]]);
    await customerCheckout(page, customers.jan);

    // The first order reaches the server, which places it and starts its payment, but its answer never reaches the
    // page (the connection fails): an uncertain result, retried with the same key.
    const keys: string[] = [];
    const statuses: number[] = [];
    const lost: { answer?: { number: string; redirectUrl: string | null } } = {};
    await page.route("**/api/checkout", async (route) => {
      if (route.request().method() !== "POST") return route.fallback();
      keys.push(route.request().headers()["idempotency-key"] ?? "");
      const response = await route.fetch();
      statuses.push(response.status());
      if (lost.answer === undefined) {
        lost.answer = (await response.json()) as { number: string; redirectUrl: string | null };
        return route.abort("failed");
      }
      return route.fulfill({ response });
    });
    await orderButton(page).click();
    await expect(orderMessage(page)).toHaveText("Nie wiemy, czy zamówienie zostało złożone. Spróbuj ponownie: tego samego zamówienia nie złożymy drugi raz.");

    // Jan's order was placed: it is paid at once, in another tab, so the test leaves no unpaid order whatever follows.
    // The retry below is refused before the API, so the payment does not change it.
    const placed = lost.answer;
    if (placed?.redirectUrl == null) throw new Error("the lost attempt placed no order with a payment to decide");
    const other = await context.newPage();
    await other.goto(placed.redirectUrl);
    await decideAtSimulator(other, "Zapłać");

    await signInAs(other, customers.anna);
    await orderButton(page).click();

    await expect(orderMessage(page)).toHaveText(MAYBE_SIGNED_IN_AS(customers.anna.email));
    expect(statuses).toEqual([201, 409]);
    expect(keys[1]).toBe(keys[0]);
    // Anna's cart is empty: the checkout shows it instead of the form, so the next click cannot order anything.
    await expect(page.getByText("Twój koszyk jest pusty.")).toBeVisible();
    // The message is true: the lost attempt placed Jan's order.
    await waitForOrderEmail(customers.jan.email, placed.number);
    await waitForOrderEmail(customers.jan.email, placed.number, "payment");
  });

  test("R6: Jan's checkout after a logout in another tab shows the expired session and places no order, not even one of the guest cart the browser has", async ({ context, request }) => {
    const [a] = await twoVariants(request, SHOES.slug);
    const page = await context.newPage();
    await signInAs(page, customers.jan);
    await emptyCart(page);
    await addLines(page, [[a, 1]]);
    await customerCheckout(page, customers.jan);
    const orders = await watchOrders(page);

    const other = await context.newPage();
    await logOut(other);
    // A guest cart with the same line: a guest order of it would be possible.
    await addLines(other, [[a, 1]]);
    expect((await context.cookies()).map((cookie) => cookie.name)).toContain("store_cart");
    await orderButton(page).click();

    await expect(orderMessage(page)).toContainText(SESSION_EXPIRED);
    expect(orders()).toEqual([401]);
    // The header shows nobody signed in, as the checkout does.
    await expect(page.getByRole("banner").getByRole("link", { name: "Zaloguj się" })).toBeVisible();
    await expect(page.getByRole("banner").getByRole("button", { name: "Wyloguj się" })).toHaveCount(0);
    await expect(page).toHaveURL(/\/checkout$/);
    // Neither cart was ordered: the guest cart keeps its line, and Jan's has both after the merge of his login.
    expect(await cartLinesOf(other)).toEqual([{ variantId: a.variantId, quantity: 1 }]);
    await signInAs(other, customers.jan);
    expect(await cartLinesOf(other)).toEqual([{ variantId: a.variantId, quantity: 2 }]);
  });
});
