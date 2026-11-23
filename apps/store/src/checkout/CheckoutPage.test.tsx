// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { type CartView, loadAccountSession, loadCart, type PlaceOrderResult, placeOrder } from "@/api/client";
import { runActions } from "@/cart/cart-actions";
import { subscribeToCartChanges } from "@/cart/events";
import { formatPrice } from "@/catalog/format-price";
import { useSiteSwitches } from "@/components/SiteSwitches";
import { CheckoutPage } from "./CheckoutPage";
import { navigateTo } from "./navigate";
import type { PaymentProvider } from "./payment-api";

vi.mock("@/api/client", () => ({ loadCart: vi.fn(), loadAccountSession: vi.fn(), placeOrder: vi.fn() }));
vi.mock("./navigate", () => ({ navigateTo: vi.fn() }));
// The switches are read by the provider of the layout, which is not rendered here; a test sets what it says.
vi.mock("@/components/SiteSwitches", () => ({ useSiteSwitches: vi.fn() }));

const push = vi.fn();
vi.mock("next/navigation", () => ({ useRouter: () => ({ push }) }));

const A = "00000000-0000-4000-8000-00000000000a";
const B = "00000000-0000-4000-8000-00000000000b";
const PRODUCT = "00000000-0000-4000-8000-0000000000f1";
const price = (gross: number) => formatPrice(gross, "PLN");

type Line = CartView["lines"][number];

const line = (variantId: string, name: string, variantLabel: string, quantity: number, lineGross: number, overrides: Partial<Line> = {}): Line => ({
  variantId,
  product: { productId: PRODUCT, name, variantLabel, image: null },
  quantity,
  unitPrice: { gross: lineGross / quantity, currency: "PLN" },
  lineTotal: { gross: lineGross, currency: "PLN" },
  state: "purchasable",
  ...overrides,
});

type DeliveryOption = NonNullable<CartView["delivery"]>["options"][number];

const STANDARD = "00000000-0000-4000-8000-0000000000d1";
const EXPRESS = "00000000-0000-4000-8000-0000000000d2";

/** A delivery option of the API; its `totalGross` is the API's, the page never adds it up. */
const option = (methodId: string, name: string, costGross: number, totalGross: number, overrides: Partial<DeliveryOption> = {}): DeliveryOption => ({
  methodId,
  name,
  description: "Dostawa w 2–3 dni robocze",
  costGross,
  freeFromGross: null,
  missingToFreeGross: null,
  totalGross,
  ...overrides,
});

const delivery = (options: DeliveryOption[]): NonNullable<CartView["delivery"]> => ({
  fromGross: options.length === 0 ? null : Math.min(...options.map((o) => o.costGross)),
  missingToFreeGross: null,
  options,
});

/**
 * A cart of the API. With a line to buy it has one free delivery method (so the total to pay is `gross`), without one
 * no delivery (`null`), as the API answers.
 */
const cart = (gross = 39998, lines: Line[] = [line(A, "Buty trailowe", "czarne, 42", 2, 39998)], hasExcludedLines = false): CartView => ({
  id: "c1",
  lines,
  totals: { subtotalGross: gross, discountGross: 0, gross, currency: "PLN", itemCount: lines.reduce((sum, l) => sum + l.quantity, 0) },
  hasExcludedLines,
  discount: null,
  delivery: lines.some((l) => l.state === "purchasable") ? delivery([option(STANDARD, "Kurier standardowy", 0, gross)]) : null,
});

const PROVIDERS: PaymentProvider[] = [
  { code: "demo-transfer", name: "Szybki przelew (demo)" },
  { code: "demo-card", name: "Karta płatnicza (demo)" },
];
const REDIRECT_URL = "http://localhost:8000/payment-simulator/demo-transfer/pay/7d3f0a8e-4a1b-4c55-9f1e-2b3c4d5e6f70";

const SWITCHES_ON = { demoNotice: false, customerAccounts: true, loaded: true };

const JAN = { name: "Jan Kowalski", email: "jan.kowalski@example.com", buyer: "buyer-tag-of-jan" };

const announced: number[] = [];
let unsubscribe: () => void;
let keys: number;

beforeEach(() => {
  vi.mocked(loadCart).mockReset();
  vi.mocked(loadAccountSession).mockReset();
  vi.mocked(placeOrder).mockReset();
  vi.mocked(navigateTo).mockReset();
  vi.mocked(useSiteSwitches).mockReset();
  vi.mocked(useSiteSwitches).mockReturnValue(SWITCHES_ON);
  push.mockReset();
  keys = 0;
  vi.spyOn(crypto, "randomUUID").mockImplementation(() => `00000000-0000-4000-8000-${String(++keys).padStart(12, "0")}` as `${string}-${string}-${string}-${string}-${string}`);
  announced.length = 0;
  unsubscribe = subscribeToCartChanges((count) => announced.push(count));
});

afterEach(() => {
  unsubscribe();
  cleanup();
  vi.restoreAllMocks();
});

const key = (n: number) => `00000000-0000-4000-8000-${String(n).padStart(12, "0")}`;

type PageProps = Parameters<typeof CheckoutPage>[0];

/**
 * Renders the checkout of `view`. Unless `chooseDelivery` is false, the customer then chooses the first delivery method
 * of the cart, as every order needs one.
 */
async function renderCheckout(
  view: CartView = cart(),
  session: { name: string; email: string; buyer: string } | null = null,
  props: PageProps = { providers: PROVIDERS, pendingOrderNumber: null },
  chooseDelivery = true,
) {
  vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: view });
  vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session });
  render(<CheckoutPage {...props} />);
  await screen.findByRole("heading", { level: 1, name: "Kasa" });
  await act(async () => {});
  const first = view.delivery?.options[0];
  if (chooseDelivery && first !== undefined && view.lines.length > 0 && !view.hasExcludedLines) {
    fireEvent.click(deliveryRadio(first.name));
  }
}

const deliveryGroup = () => screen.getByRole("group", { name: "Sposób dostawy" });
/** The radio of a delivery method; its label starts with the name of the method. */
const deliveryRadio = (name: string) => within(deliveryGroup()).getByRole("radio", { name: new RegExp(`^${name}`) }) as HTMLInputElement;

const field = (label: string) => screen.getByLabelText(label) as HTMLInputElement;
const type = (label: string, value: string) => fireEvent.change(field(label), { target: { value } });
const submitButton = () => screen.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }) as HTMLButtonElement;

const choose = (name: string) => fireEvent.click(screen.getByRole("radio", { name }));

function fillGuestForm() {
  type("E-mail", "anna.nowak@example.com");
  fillContact("Anna Nowak");
}

function fillContact(fullName?: string) {
  if (fullName !== undefined) type("Imię i nazwisko", fullName);
  type("Telefon", "600 100 200");
  type("Ulica i numer", "ul. Długa 5/3");
  type("Kod pocztowy", "00-950");
  type("Miejscowość", "Warszawa");
  choose("Szybki przelew (demo)");
}

const GUEST_INPUT = {
  email: "anna.nowak@example.com",
  fullName: "Anna Nowak",
  phone: "600 100 200",
  street: "ul. Długa 5/3",
  postalCode: "00-950",
  city: "Warszawa",
  expectedTotalGross: 39998,
  provider: "demo-transfer",
  deliveryMethodId: STANDARD,
  expectedLines: [{ variantId: A, quantity: 2 }],
  buyer: "guest",
};

async function submit(result: PlaceOrderResult) {
  vi.mocked(placeOrder).mockResolvedValueOnce(result);
  await act(async () => {
    fireEvent.click(submitButton());
  });
}

const SUBMIT_FAILED = "Nie udało się złożyć zamówienia. Spróbuj ponownie.";

describe("CheckoutPage loading", () => {
  it("says it is loading until the cart and the session arrive", async () => {
    vi.mocked(loadCart).mockReturnValue(new Promise(() => {}));
    vi.mocked(loadAccountSession).mockReturnValue(new Promise(() => {}));
    render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);

    expect(await screen.findByText("Wczytywanie…")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeNull();
  });

  it("has one status region from the first render, the same node through loading and content", async () => {
    let answer: (result: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart).mockReturnValue(new Promise((resolve) => (answer = resolve)));
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);

    const region = screen.getByTestId("checkout-status");
    expect(region.getAttribute("role")).toBe("status");
    expect(region.textContent).toBe("Wczytywanie…");
    expect(screen.getAllByText("Wczytywanie…")).toHaveLength(1);

    await act(async () => answer({ status: "ok", data: cart(0, []) }));

    expect(screen.getByText("Twój koszyk jest pusty.")).toBeTruthy();
    expect(screen.getByTestId("checkout-status")).toBe(region);
    expect(region.isConnected).toBe(true);
    expect(region.textContent).toBe("");
  });

  it("reads the cart only after the actions given on a cart page of the tab are sent, loading till then", async () => {
    // A change given on the cart page while the navigation to the checkout was on its way (the loop of that page goes on
    // after it is left).
    let sent: () => void = () => {};
    runActions(() => new Promise<void>((resolve) => (sent = resolve)));
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart() });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);
    await act(async () => {});

    expect(loadCart).not.toHaveBeenCalled();
    expect(screen.getByTestId("checkout-status").textContent).toBe("Wczytywanie…");
    expect(screen.queryByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeNull();

    await act(async () => sent());

    expect(loadCart).toHaveBeenCalledTimes(1);
    expect(screen.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeTruthy();
  });

  it("reads the cart at once when the actions of the cart page were dropped or failed", async () => {
    runActions(() => Promise.reject(new Error("the session expired")));
    vi.mocked(loadCart).mockResolvedValue({ status: "unauthorized" });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);
    await act(async () => {});

    expect(loadCart).toHaveBeenCalledTimes(1);
    expect(screen.getByText(/Twoja sesja wygasła/)).toBeTruthy();
  });

  it("reads the cart at once without actions of a cart page", async () => {
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart() });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);
    await act(async () => {});

    expect(loadCart).toHaveBeenCalledTimes(1);
    expect(screen.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeTruthy();
  });

  it.each([
    ["the cart", () => vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" })],
    ["the session", () => vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "unavailable" })],
  ])("keeps the retry button on the screen while it reads again when %s cannot be loaded", async (_name, fail) => {
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart() });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    fail();
    render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);
    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(screen.getByRole("alert").textContent).toBe("Nie udało się wczytać kasy");
    const region = screen.getByTestId("checkout-status");
    expect(region.textContent).toBe("");

    // The second read is held back.
    let answer: (result: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
    const button = screen.getByRole("button", { name: "Spróbuj ponownie" });
    button.focus();
    fireEvent.click(button);
    await act(async () => {});

    expect(button.isConnected).toBe(true);
    expect(button.getAttribute("aria-disabled")).toBe("true");
    expect((button as HTMLButtonElement).disabled).toBe(false);
    expect(document.activeElement).toBe(button);
    expect(screen.queryByRole("alert")).toBeNull();
    expect(region.textContent).toBe("Wczytywanie…");
    const reads = vi.mocked(loadCart).mock.calls.length;
    fireEvent.click(button);
    await act(async () => {});
    expect(vi.mocked(loadCart).mock.calls.length).toBe(reads);

    await act(async () => answer({ status: "ok", data: cart() }));

    expect(screen.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeTruthy();
    expect(screen.getByTestId("checkout-status")).toBe(region);
    expect(region.textContent).toBe("");
    expect(document.activeElement).toBe(screen.getByRole("heading", { level: 1, name: "Kasa" }));
  });

  it("shows the message again and keeps the focus on the button when the second read fails too", async () => {
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);
    await screen.findByRole("alert");

    let answer: (result: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
    const button = screen.getByRole("button", { name: "Spróbuj ponownie" });
    button.focus();
    fireEvent.click(button);
    await act(async () => {});
    expect(screen.queryByRole("alert")).toBeNull();

    await act(async () => answer({ status: "failed" }));

    expect(screen.getByRole("alert").textContent).toBe("Nie udało się wczytać kasy");
    expect(screen.getByTestId("checkout-status").textContent).toBe("");
    expect(button.isConnected).toBe(true);
    expect(button.getAttribute("aria-disabled")).toBeNull();
    expect(document.activeElement).toBe(button);
  });

  it.each([
    ["succeeds", { status: "ok", data: cart() } as const],
    ["fails", { status: "failed" } as const],
  ])("leaves the focus where the customer moved it while the read again %s", async (_name, outcome) => {
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);
    await screen.findByRole("alert");
    const elsewhere = document.createElement("input");
    document.body.append(elsewhere);

    let answer: (result: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
    const button = screen.getByRole("button", { name: "Spróbuj ponownie" });
    button.focus();
    fireEvent.click(button);
    await act(async () => {});
    elsewhere.focus();

    await act(async () => answer(outcome));

    expect(document.activeElement).toBe(elsewhere);
    elsewhere.remove();
  });

  it("puts the focus back on the retry button when it was lost during the failed read", async () => {
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);
    await screen.findByRole("alert");

    let answer: (result: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
    const button = screen.getByRole("button", { name: "Spróbuj ponownie" });
    button.focus();
    fireEvent.click(button);
    await act(async () => {});
    button.blur();
    expect(document.activeElement).toBe(document.body);

    await act(async () => answer({ status: "failed" }));

    expect(document.activeElement).toBe(button);
  });

  it("says the session expired when the cart answers 401", async () => {
    vi.mocked(loadCart).mockResolvedValue({ status: "unauthorized" });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: JAN });
    render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);

    expect(await screen.findByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcheckout");
  });
});

describe("CheckoutPage content", () => {
  it("shows the summary of the API: lines with name, variant, quantity and value, and the gross total", async () => {
    await renderCheckout(
      cart(12345, [line(A, "Buty trailowe", "czarne, 42", 2, 39998), line(B, "Baton energetyczny", "", 1, 899)]),
    );

    const summary = screen.getByRole("region", { name: "Podsumowanie zamówienia" });
    const rows = within(summary).getAllByRole("listitem");
    expect(rows).toHaveLength(2);
    expect(within(rows[0]).getByText("Buty trailowe")).toBeTruthy();
    expect(within(rows[0]).getByText("czarne, 42")).toBeTruthy();
    expect(within(rows[0]).getByTestId("checkout-line-quantity").textContent).toBe("2 szt.");
    expect(within(rows[0]).getByTestId("checkout-line-total").textContent).toBe(price(39998));
    expect(within(rows[1]).getByText("Baton energetyczny")).toBeTruthy();
    expect(within(rows[1]).queryByTestId("checkout-line-variant")).toBeNull();
    // Nothing is computed: the total is the one of the API, even when it is not the sum of the lines.
    expect(screen.getByTestId("checkout-total").textContent).toBe(price(12345));
  });

  it("asks a guest for the e-mail and offers the login that comes back to the checkout", async () => {
    await renderCheckout();

    expect(field("E-mail").value).toBe("");
    expect(field("Imię i nazwisko").value).toBe("");
    for (const label of ["Telefon", "Ulica i numer", "Kod pocztowy", "Miejscowość"]) {
      expect(field(label)).toBeTruthy();
    }
    expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcheckout");
    expect(submitButton().disabled).toBe(false);
  });

  it("offers the login of a guest only after the switches are read, and only with the accounts on", async () => {
    // The read of /api/site is under way.
    vi.mocked(useSiteSwitches).mockReturnValue({ demoNotice: false, customerAccounts: true, loaded: false });
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart() });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    const { rerender } = render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);
    await screen.findByRole("heading", { level: 1, name: "Kasa" });
    await act(async () => {});
    expect(field("E-mail")).toBeTruthy();
    expect(screen.queryByRole("link", { name: "Zaloguj się" })).toBeNull();

    // The answer: the accounts are off.
    vi.mocked(useSiteSwitches).mockReturnValue({ demoNotice: false, customerAccounts: false, loaded: true });
    rerender(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);
    expect(field("E-mail")).toBeTruthy();
    expect(screen.queryByRole("link", { name: "Zaloguj się" })).toBeNull();
    expect(screen.queryByText("Masz konto?")).toBeNull();

    // The accounts are on.
    vi.mocked(useSiteSwitches).mockReturnValue(SWITCHES_ON);
    rerender(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);
    expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcheckout");
  });

  it("shows a customer the e-mail of the account as text and fills the name in from the account", async () => {
    await renderCheckout(cart(), JAN);

    expect(screen.queryByLabelText("E-mail")).toBeNull();
    expect(screen.getByTestId("account-email").textContent).toBe("jan.kowalski@example.com");
    expect(field("Imię i nazwisko").value).toBe("Jan Kowalski");
    expect(screen.queryByRole("link", { name: "Zaloguj się" })).toBeNull();
  });

  it("does not fill the name in with the e-mail of an account without a name", async () => {
    await renderCheckout(cart(), { ...JAN, name: JAN.email });

    expect(field("Imię i nazwisko").value).toBe("");
  });

  it("shows an empty cart with a link to the cart instead of the form", async () => {
    await renderCheckout(cart(0, []));

    expect(screen.getByText("Twój koszyk jest pusty.")).toBeTruthy();
    expect(screen.getByRole("link", { name: "Przejdź do koszyka" }).getAttribute("href")).toBe("/cart");
    expect(screen.queryByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeNull();
    expect(screen.queryByLabelText("Telefon")).toBeNull();
  });

  it("shows a cart with lines that cannot be bought with a link to the cart instead of the form", async () => {
    await renderCheckout(
      cart(1000, [line(A, "Buty trailowe", "", 1, 1000), line(B, "Baton", "", 1, 0, { state: "no_price", unitPrice: null, lineTotal: null })], true),
    );

    expect(screen.getByText("Część pozycji w koszyku nie może zostać kupiona. Usuń albo popraw oznaczone pozycje.")).toBeTruthy();
    expect(screen.getByRole("link", { name: "Przejdź do koszyka" }).getAttribute("href")).toBe("/cart");
    expect(screen.queryByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeNull();
  });
});

describe("CheckoutPage placing the order", () => {
  it("sends the form of a guest with the total it showed and the key made on entry, then goes to the confirmation", async () => {
    await renderCheckout();
    fillGuestForm();
    const current = key(keys);
    await submit({ status: "ok", number: "R-100001", redirectUrl: null });

    expect(placeOrder).toHaveBeenCalledWith(GUEST_INPUT, current);
    expect(announced).toEqual([0]);
    expect(push).toHaveBeenCalledWith("/checkout/confirmation");
  });

  it("sends a customer's form without an e-mail", async () => {
    await renderCheckout(cart(), JAN);
    fillContact();
    await submit({ status: "ok", number: "R-100002", redirectUrl: null });

    expect(placeOrder).toHaveBeenCalledWith(
      {
        fullName: "Jan Kowalski",
        phone: "600 100 200",
        street: "ul. Długa 5/3",
        postalCode: "00-950",
        city: "Warszawa",
        expectedTotalGross: 39998,
        provider: "demo-transfer",
        deliveryMethodId: STANDARD,
        expectedLines: [{ variantId: A, quantity: 2 }],
        buyer: JAN.buyer,
      },
      expect.any(String),
    );
    expect(push).toHaveBeenCalledWith("/checkout/confirmation");
  });

  it("sends the lines of the summary it showed as the expected lines, in the order of the summary, and nothing else of them", async () => {
    const lines = [line(B, "Czapka", "", 1, 4999), line(A, "Buty trailowe", "czarne, 42", 2, 39998)];
    await renderCheckout(cart(44997, lines));
    fillGuestForm();
    await submit({ status: "ok", number: "R-100001", redirectUrl: null });

    expect(vi.mocked(placeOrder).mock.calls[0][0].expectedLines).toEqual([
      { variantId: B, quantity: 1 },
      { variantId: A, quantity: 2 },
    ]);
  });

  it("on a 409 of a changed content says what changed with a link to the cart, loads the new summary and keeps the form; the next order has a new key and the lines of the new summary", async () => {
    await renderCheckout();
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart(19999, [line(A, "Buty trailowe", "czarne, 42", 1, 19999)]) });
    await submit({ status: "conflict", reason: "cart_changed", detail: "Zawartość koszyka się zmieniła. Sprawdź podsumowanie." });

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain("Zawartość koszyka zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.");
    expect(alert.textContent).toContain("Buty trailowe, czarne, 42: było 2 szt., jest 1 szt.");
    expect(within(alert).getByRole("link", { name: "Przejdź do koszyka" }).getAttribute("href")).toBe("/cart");
    expect(screen.getByTestId("checkout-total").textContent).toBe(price(19999));
    expect(field("Telefon").value).toBe("600 100 200");
    expect(placeOrder).toHaveBeenCalledTimes(1);
    expect(push).not.toHaveBeenCalled();

    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    const [first, second] = vi.mocked(placeOrder).mock.calls;
    expect(first[0].expectedLines).toEqual([{ variantId: A, quantity: 2 }]);
    expect(second[0]).toMatchObject({ expectedTotalGross: 19999, expectedLines: [{ variantId: A, quantity: 1 }] });
    expect(second[1]).not.toBe(first[1]);
  });

  it("after a 409 of a changed total sends the lines of the summary read again", async () => {
    await renderCheckout();
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart(59997, [line(A, "Buty trailowe", "czarne, 42", 3, 59997)]) });
    await submit({ status: "conflict", reason: "total_changed", detail: "Kwota zamówienia się zmieniła. Sprawdź podsumowanie." });
    await submit({ status: "ok", number: "R-100001", redirectUrl: null });

    expect(vi.mocked(placeOrder).mock.calls[1][0]).toMatchObject({ expectedTotalGross: 59997, expectedLines: [{ variantId: A, quantity: 3 }] });
  });

  it("locks the button while the request runs: aria-disabled, not disabled, and a second click orders nothing", async () => {
    await renderCheckout();
    fillGuestForm();
    let answer: (result: PlaceOrderResult) => void = () => {};
    vi.mocked(placeOrder).mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
    await act(async () => {
      fireEvent.click(submitButton());
    });

    expect(submitButton().getAttribute("aria-disabled")).toBe("true");
    expect(submitButton().disabled).toBe(false);
    fireEvent.click(submitButton());
    expect(placeOrder).toHaveBeenCalledTimes(1);

    await act(async () => answer({ status: "failed" }));
    expect(submitButton().getAttribute("aria-disabled")).toBeNull();
    expect(submitButton().disabled).toBe(false);
  });

  it("uses the key made on entry and the same key again on a retry after a failure", async () => {
    await renderCheckout();
    const entryKey = key(keys);
    fillGuestForm();
    const formKey = key(keys);
    expect(formKey).not.toBe(entryKey);

    await submit({ status: "failed" });
    expect(screen.getByRole("alert").textContent).toContain(SUBMIT_FAILED);
    await submit({ status: "ok", number: "R-100001", redirectUrl: null });

    const used = vi.mocked(placeOrder).mock.calls.map((call) => call[1]);
    expect(used).toEqual([formKey, formKey]);
  });

  it("makes a new key when the form changes", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({ status: "failed" });
    type("Telefon", "600 100 201");
    await submit({ status: "failed" });

    const [first, second] = vi.mocked(placeOrder).mock.calls.map((call) => call[1]);
    expect(second).not.toBe(first);
    expect(vi.mocked(placeOrder).mock.calls[1][0].phone).toBe("600 100 201");
  });

  it("shows the messages of a 422 at their fields and keeps the key", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({
      status: "invalid",
      violations: [
        { propertyPath: "phone", title: "Podaj 9-cyfrowy numer telefonu." },
        { propertyPath: "postalCode", title: "Podaj kod pocztowy w formacie 00-000." },
      ],
    });

    expect(field("Telefon").getAttribute("aria-invalid")).toBe("true");
    expect(screen.getByText("Podaj 9-cyfrowy numer telefonu.").id).toBe(field("Telefon").getAttribute("aria-describedby"));
    expect(field("Kod pocztowy").getAttribute("aria-invalid")).toBe("true");
    expect(screen.getByText("Podaj kod pocztowy w formacie 00-000.")).toBeTruthy();
    expect(field("Miejscowość").getAttribute("aria-invalid")).toBeNull();
    expect(field("Telefon").value).toBe("600 100 200");
  });

  it("moves the focus to the first wrong field, in the order of the form, after a 422 of fields", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({
      status: "invalid",
      violations: [
        { propertyPath: "postalCode", title: "Podaj kod pocztowy w formacie 00-000." },
        { propertyPath: "phone", title: "Podaj 9-cyfrowy numer telefonu." },
      ],
    });

    expect(document.activeElement).toBe(field("Telefon"));
  });

  it.each<[string, PlaceOrderResult]>([
    ["a 422 that names no field", { status: "invalid", detail: "Nieprawidłowe dane.", violations: [] }],
    ["a 409", { status: "conflict", reason: "cart_empty", detail: "Koszyk jest pusty." }],
    ["a 404", { status: "not_found" }],
    ["a 401", { status: "unauthorized" }],
    ["a failure", { status: "failed" }],
  ])("moves the focus from the button to the message after %s", async (_name, result) => {
    await renderCheckout();
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart() });
    submitButton().focus();
    await submit(result);

    const alert = screen.getByRole("alert");
    expect(alert.getAttribute("tabindex")).toBe("-1");
    expect(document.activeElement).toBe(alert);
  });

  it("shows a 422 that names no field of the form in the message", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({ status: "invalid", detail: "Nieprawidłowe dane.", violations: [] });

    expect(screen.getByRole("alert").textContent).toContain("Nieprawidłowe dane.");
  });

  it("on a 409 of a changed total says what changed with a link to the cart, loads the new summary and keeps the form; the next order has a new key and the new total", async () => {
    await renderCheckout();
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart(41000) });
    await submit({ status: "conflict", reason: "total_changed", detail: "Kwota zamówienia się zmieniła. Sprawdź podsumowanie." });

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain("Kwota zamówienia zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.");
    expect(alert.textContent).toContain(`Do zapłaty: było ${price(39998)}, jest ${price(41000)}`);
    expect(within(alert).getByRole("link", { name: "Przejdź do koszyka" }).getAttribute("href")).toBe("/cart");
    expect(screen.getByTestId("checkout-total").textContent).toBe(price(41000));
    expect(field("Telefon").value).toBe("600 100 200");
    expect(submitButton().disabled).toBe(false);
    expect(placeOrder).toHaveBeenCalledTimes(1);

    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    const [first, second] = vi.mocked(placeOrder).mock.calls;
    expect(second[0].expectedTotalGross).toBe(41000);
    expect(second[1]).not.toBe(first[1]);
  });

  it("on a 409 of a changed total with a cart that cannot be ordered any more shows the message of the summary instead of the form, with one link to the cart", async () => {
    await renderCheckout();
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart(0, []) });
    await submit({ status: "conflict", reason: "total_changed", detail: "Kwota zamówienia się zmieniła. Sprawdź podsumowanie." });

    expect(within(screen.getByRole("alert")).queryByRole("link")).toBeNull();
    expect(screen.getByText("Twój koszyk jest pusty.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeNull();
    expect(screen.getAllByRole("link", { name: /koszyka/ })).toHaveLength(1);
  });

  it.each<[string, "cart_empty" | "guest_order_too_large" | "cart_not_orderable" | "insufficient_stock", string]>([
    ["CartEmpty", "cart_empty", "Koszyk jest pusty."],
    ["GuestOrderTooLarge", "guest_order_too_large", "Zamówienie bez konta może mieć najwyżej 10 różnych produktów, po 10 sztuk każdego. Zmniejsz ilości w koszyku."],
    ["CartNotOrderable", "cart_not_orderable", "Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk."],
    ["InsufficientStockForOrder", "insufficient_stock", "Nie mamy już tylu sztuk części produktów. Sprawdź koszyk."],
  ])("on a 409 %s shows the detail with a link back to the cart, keeps the form and the key, and does not order again by itself", async (_name, reason, detail) => {
    await renderCheckout();
    fillGuestForm();
    const used = key(keys);
    await submit({ status: "conflict", reason, detail });
    await act(async () => {});

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain(detail);
    expect(within(alert).getByRole("link", { name: "Wróć do koszyka" }).getAttribute("href")).toBe("/cart");
    expect(field("Telefon").value).toBe("600 100 200");
    expect(push).not.toHaveBeenCalled();
    expect(placeOrder).toHaveBeenCalledTimes(1);

    await submit({ status: "failed" });
    expect(vi.mocked(placeOrder).mock.calls.map((call) => call[1])).toEqual([used, used]);
  });

  it("on a 409 that empties the cart shows the detail and the message of the summary instead of the form, with one link to the cart", async () => {
    await renderCheckout();
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart(0, []) });
    await submit({ status: "conflict", reason: "cart_empty", detail: "Koszyk jest pusty." });

    expect(screen.getByRole("alert").textContent).toBe("Koszyk jest pusty.");
    expect(screen.getByText("Twój koszyk jest pusty.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeNull();
    expect(screen.getAllByRole("link", { name: /koszyka/ })).toHaveLength(1);
    expect(announced).toEqual([0]);
  });

  it.each<["cart_not_orderable" | "insufficient_stock", string]>([
    ["cart_not_orderable", "Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk."],
    ["insufficient_stock", "Nie mamy już tylu sztuk części produktów. Sprawdź koszyk."],
  ])("on a 409 %s whose reloaded cart has excluded lines shows the detail and the message of the summary instead of the form, with one link to the cart", async (reason, detail) => {
    await renderCheckout();
    fillGuestForm();
    const excluded = cart(1000, [line(A, "Buty", "", 1, 1000), line(B, "Baton", "", 1, 0, { state: "no_price", unitPrice: null, lineTotal: null })], true);
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: excluded });
    await submit({ status: "conflict", reason, detail });
    await act(async () => {});

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toBe(detail);
    expect(within(alert).queryByRole("link")).toBeNull();
    expect(screen.getByText("Część pozycji w koszyku nie może zostać kupiona. Usuń albo popraw oznaczone pozycje.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeNull();
    expect(screen.getAllByRole("link", { name: /koszyka/ })).toHaveLength(1);
  });

  it("on a 409 of a reused key shows the detail without a link, keeps the form and orders with a new key on the next click", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({ status: "conflict", reason: "key_reused", detail: "Ten klucz żądania był już użyty dla innego zamówienia." });
    await act(async () => {});

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain("Ten klucz żądania był już użyty dla innego zamówienia.");
    expect(within(alert).queryByRole("link")).toBeNull();
    expect(field("E-mail").value).toBe("anna.nowak@example.com");
    expect(placeOrder).toHaveBeenCalledTimes(1);

    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    const [first, second] = vi.mocked(placeOrder).mock.calls;
    expect(second[1]).not.toBe(first[1]);
    expect(second[0]).toEqual(first[0]);
    expect(push).toHaveBeenCalledWith("/checkout/confirmation");
  });

  it("on a 409 of too many unpaid orders shows the detail without a link, keeps the form and the key, and does not order again by itself", async () => {
    const detail = "Nie możemy teraz przyjąć kolejnego zamówienia, bo z tych danych albo z tej sieci czeka już kilka nieopłaconych. Spróbuj ponownie za ok. 35 minut.";
    await renderCheckout();
    fillGuestForm();
    const used = key(keys);
    const reads = vi.mocked(loadCart).mock.calls.length;
    await submit({ status: "conflict", reason: "too_many_unpaid_orders", detail });
    await act(async () => {});

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toBe(detail);
    expect(within(alert).queryByRole("link")).toBeNull();
    expect(document.activeElement).toBe(alert);
    expect(field("E-mail").value).toBe("anna.nowak@example.com");
    expect(screen.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeTruthy();
    expect(vi.mocked(loadCart).mock.calls.length).toBe(reads);
    expect(push).not.toHaveBeenCalled();
    expect(placeOrder).toHaveBeenCalledTimes(1);

    await submit({ status: "failed" });
    expect(vi.mocked(placeOrder).mock.calls.map((call) => call[1])).toEqual([used, used]);
  });

  it.each<[string, string | undefined, string]>([
    ["with a detail", "Coś poszło nie tak.", "Coś poszło nie tak."],
    ["without a detail", undefined, SUBMIT_FAILED],
  ])("on a 409 of an unknown type %s shows the text with a link back to the cart and keeps the key; it does not order again by itself", async (_name, detail, shown) => {
    await renderCheckout();
    fillGuestForm();
    const used = key(keys);
    await submit({ status: "conflict", reason: "unknown", detail });
    await act(async () => {});

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain(shown);
    expect(within(alert).getByRole("link", { name: "Wróć do koszyka" }).getAttribute("href")).toBe("/cart");
    expect(field("Telefon").value).toBe("600 100 200");
    expect(placeOrder).toHaveBeenCalledTimes(1);

    await submit({ status: "failed" });
    expect(vi.mocked(placeOrder).mock.calls.map((call) => call[1])).toEqual([used, used]);
  });

  it("on a 404 says the cart is gone, with a link to the store", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({ status: "not_found", detail: "Koszyk nie jest już dostępny." });

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain("Koszyk nie jest już dostępny.");
    expect(within(alert).getByRole("link", { name: "Wróć do sklepu" }).getAttribute("href")).toBe("/");
  });

  it("on a 401 says the session expired, with a login that comes back to the checkout", async () => {
    await renderCheckout(cart(), JAN);
    fillContact();
    await submit({ status: "unauthorized" });

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain("Twoja sesja wygasła. Zaloguj się ponownie.");
    expect(within(alert).getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcheckout");
  });

  it("on a 429 says there were too many attempts, keeps the form and orders with the same key on the next click", async () => {
    await renderCheckout();
    fillGuestForm();
    const formKey = key(keys);
    await submit({ status: "rate_limited" });

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toBe("Zbyt wiele prób złożenia zamówienia. Spróbuj ponownie za kilka minut.");
    expect(document.activeElement).toBe(alert);
    expect(field("E-mail").value).toBe("anna.nowak@example.com");
    expect(field("Telefon").value).toBe("600 100 200");
    expect((screen.getByRole("radio", { name: "Szybki przelew (demo)" }) as HTMLInputElement).checked).toBe(true);
    expect(submitButton().disabled).toBe(false);
    expect(push).not.toHaveBeenCalled();
    expect(announced).toEqual([]);

    await submit({ status: "ok", number: "R-100001", redirectUrl: REDIRECT_URL });
    expect(vi.mocked(placeOrder).mock.calls.map((call) => call[1])).toEqual([formKey, formKey]);
    expect(vi.mocked(placeOrder).mock.calls[1][0]).toEqual(GUEST_INPUT);
  });

  it("on a 403, 503, 5xx or network error asks to try again", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({ status: "failed" });

    expect(screen.getByRole("alert").textContent).toBe(SUBMIT_FAILED);
    expect(push).not.toHaveBeenCalled();
    expect(announced).toEqual([]);
  });
});

describe("CheckoutPage after a 409 of a changed cart or total (#852)", () => {
  const PLACING = "Składamy zamówienie…";
  const FIRST = {
    cart_changed: "Zawartość koszyka zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.",
    total_changed: "Kwota zamówienia zmieniła się od wczytania kasy. Nie złożyliśmy zamówienia.",
  } as const;
  const DETAIL = {
    cart_changed: "Zawartość koszyka się zmieniła. Sprawdź podsumowanie.",
    total_changed: "Kwota zamówienia się zmieniła. Sprawdź podsumowanie.",
  } as const;
  const LAST = "Sprawdź podsumowanie i zamów ponownie.";
  const READ_FAILED = "Koszyk albo kwota zamówienia się zmieniły, ale nie udało się wczytać aktualnego podsumowania. Spróbuj ponownie.";
  type Reason = keyof typeof FIRST;
  type Read = Awaited<ReturnType<typeof loadCart>>;

  /** The cart after the change in another tab: three pairs instead of two, the total of the API. */
  const three = (): CartView => cart(59997, [line(A, "Buty trailowe", "czarne, 42", 3, 59997)]);
  const conflict = (reason: Reason): PlaceOrderResult => ({ status: "conflict", reason, detail: DETAIL[reason] });
  /** The message above the form (the code may have an alert of its own). */
  const alert = () => {
    const message = screen.getAllByRole("alert").find((node) => node.id === "checkout-failure");
    if (message === undefined) throw new Error("no message above the form");
    return message;
  };
  const items = () => within(alert()).queryAllByRole("listitem").map((item) => item.textContent);
  const providerRadio = (name: string) => screen.getByRole("radio", { name }) as HTMLInputElement;
  const codeField = () => screen.getByLabelText("Kod rabatowy") as HTMLInputElement;
  const status = () => screen.getByTestId("checkout-status").textContent;

  /** Holds the next read of the cart; the returned function gives its answer. */
  function holdRead(): (answer: Read) => Promise<void> {
    let release: (answer: Read) => void = () => {};
    vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (release = resolve)));
    return (answer) => act(async () => release(answer));
  }

  /**
   * Notes what the summary shows when the message first appears in the DOM: the message and the summary come with one
   * render, so the first frame of the message already has the summary of the read.
   */
  function watchFirstMessage(): () => { quantities: string[]; total: string | null } | null {
    let seen: { quantities: string[]; total: string | null } | null = null;
    const observer = new MutationObserver(() => {
      if (seen !== null || document.getElementById("checkout-failure") === null) return;
      seen = {
        quantities: Array.from(document.querySelectorAll('[data-testid="checkout-line-quantity"]'), (node) => node.textContent ?? ""),
        total: document.querySelector('[data-testid="checkout-total"]')?.textContent ?? null,
      };
    });
    observer.observe(document.body, { subtree: true, childList: true, characterData: true });
    return () => {
      observer.disconnect();
      return seen;
    };
  }

  /** A guest's form filled in, a code typed but not applied, and the order sent from the focused button, refused. */
  async function refuseHeld(reason: Reason, view: CartView = cart()): Promise<(answer: Read) => Promise<void>> {
    await renderCheckout(view);
    fillGuestForm();
    fireEvent.change(codeField(), { target: { value: "LATO15" } });
    const release = holdRead();
    submitButton().focus();
    await submit(conflict(reason));
    return release;
  }

  it.each<Reason>(["cart_changed", "total_changed"])("after %s keeps the form locked and shows no message until the cart is read", async (reason) => {
    await refuseHeld(reason);

    expect(screen.queryByRole("alert")).toBeNull();
    expect(status()).toBe(PLACING);
    expect(loadCart).toHaveBeenLastCalledWith();
    for (const label of ["E-mail", "Imię i nazwisko", "Telefon", "Ulica i numer", "Kod pocztowy", "Miejscowość", "Kod rabatowy"]) {
      expect(field(label).getAttribute("aria-disabled")).toBe("true");
      expect(field(label).readOnly).toBe(true);
    }
    for (const radio of [deliveryRadio("Kurier standardowy"), providerRadio("Szybki przelew (demo)"), providerRadio("Karta płatnicza (demo)")]) {
      expect(radio.getAttribute("aria-disabled")).toBe("true");
    }
    expect(submitButton().getAttribute("aria-disabled")).toBe("true");
    expect(screen.getByRole("button", { name: "Zastosuj" }).getAttribute("aria-disabled")).toBe("true");

    // Nothing the customer does meanwhile changes the form or sends anything.
    type("Telefon", "600 100 201");
    fireEvent.change(codeField(), { target: { value: "LATO20" } });
    fireEvent.click(providerRadio("Karta płatnicza (demo)"));
    await act(async () => {
      fireEvent.click(submitButton());
      fireEvent.click(screen.getByRole("button", { name: "Zastosuj" }));
    });
    expect(field("Telefon").value).toBe("600 100 200");
    expect(codeField().value).toBe("LATO15");
    expect(providerRadio("Szybki przelew (demo)").checked).toBe(true);
    expect(placeOrder).toHaveBeenCalledTimes(1);
    expect(vi.mocked(loadCart).mock.calls.filter((call) => call.length > 0)).toEqual([]);
    expect(screen.getAllByTestId("checkout-line-quantity").map((node) => node.textContent)).toEqual(["2 szt."]);
  });

  it.each<Reason>(["cart_changed", "total_changed"])("after %s shows the message with the changes and the summary of the read in one render, and unlocks the form with its data", async (reason) => {
    const release = await refuseHeld(reason);
    const firstMessage = watchFirstMessage();
    await release({ status: "ok", data: three() });

    expect(firstMessage()).toEqual({ quantities: ["3 szt."], total: price(59997) });
    const paragraphs = Array.from(alert().querySelectorAll("p"), (node) => node.textContent);
    expect(paragraphs[0]).toBe(FIRST[reason]);
    expect(items()).toEqual(["Buty trailowe, czarne, 42: było 2 szt., jest 3 szt.", `Do zapłaty: było ${price(39998)}, jest ${price(59997)}`]);
    expect(paragraphs.at(-1)).toBe(`${LAST} Przejdź do koszyka`);
    expect(within(alert()).getByRole("link", { name: "Przejdź do koszyka" }).getAttribute("href")).toBe("/cart");
    expect(alert().textContent).not.toContain(DETAIL[reason]);
    expect(document.activeElement).toBe(alert());
    expect(status()).toBe("");
    expect(submitButton().getAttribute("aria-disabled")).toBeNull();
    for (const [label, value] of [["E-mail", "anna.nowak@example.com"], ["Telefon", "600 100 200"], ["Miejscowość", "Warszawa"], ["Kod rabatowy", "LATO15"]]) {
      expect(field(label).readOnly).toBe(false);
      expect(field(label).value).toBe(value);
    }
    expect(deliveryRadio("Kurier standardowy").checked).toBe(true);
    expect(providerRadio("Szybki przelew (demo)").checked).toBe(true);
    expect(placeOrder).toHaveBeenCalledTimes(1);

    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    const [first, second] = vi.mocked(placeOrder).mock.calls;
    expect(second[1]).not.toBe(first[1]);
    expect(second[0]).toMatchObject({ expectedTotalGross: 59997, expectedLines: [{ variantId: A, quantity: 3 }] });
  });

  it("leaves the focus where the customer moved it during the read; the message is an alert", async () => {
    const release = await refuseHeld("cart_changed");
    field("Kod pocztowy").focus();
    await release({ status: "ok", data: three() });

    expect(document.activeElement).toBe(field("Kod pocztowy"));
    expect(alert().getAttribute("role")).toBe("alert");
    expect(items()).toHaveLength(2);
  });

  it("moves the focus to the message when it was lost during the read", async () => {
    const release = await refuseHeld("cart_changed");
    submitButton().blur();
    await release({ status: "ok", data: three() });

    expect(document.activeElement).toBe(alert());
  });

  it("moves the focus to the message after an order sent with Enter from „Miejscowość” when it stayed there", async () => {
    await renderCheckout();
    fillGuestForm();
    const release = holdRead();
    field("Miejscowość").focus();
    vi.mocked(placeOrder).mockResolvedValueOnce(conflict("cart_changed"));
    await act(async () => {
      fireEvent.submit(submitButton().form as HTMLFormElement);
    });
    expect(document.activeElement).toBe(field("Miejscowość"));
    await release({ status: "ok", data: three() });

    expect(document.activeElement).toBe(alert());
  });

  it("says only that nothing was ordered when the read finds the summary that was shown", async () => {
    const release = await refuseHeld("cart_changed");
    await release({ status: "ok", data: cart() });

    expect(Array.from(alert().querySelectorAll("p"), (node) => node.textContent)).toEqual([FIRST.cart_changed, `${LAST} Przejdź do koszyka`]);
    expect(items()).toEqual([]);
    expect(alert().querySelector("ul")).toBeNull();
  });

  it("shows the empty cart instead of the form, with the changes and one link to the cart", async () => {
    const release = await refuseHeld("cart_changed");
    await release({ status: "ok", data: cart(0, []) });

    expect(alert().textContent).toContain(FIRST.cart_changed);
    expect(items()).toEqual(["Buty trailowe, czarne, 42: usunięto z koszyka"]);
    expect(screen.getByText("Twój koszyk jest pusty.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeNull();
    expect(screen.getAllByRole("link", { name: /koszyka/ })).toHaveLength(1);
    expect(status()).toBe("");
  });

  it("shows the lines that cannot be bought instead of the form, with one link to the cart", async () => {
    const release = await refuseHeld("total_changed");
    const excluded = cart(39998, [line(A, "Buty trailowe", "czarne, 42", 2, 39998), line(B, "Baton", "", 1, 0, { state: "no_price", unitPrice: null, lineTotal: null })], true);
    await release({ status: "ok", data: excluded });

    expect(alert().textContent).toContain(FIRST.total_changed);
    expect(items()).toEqual(["Baton: dodano, 1 szt."]);
    expect(screen.getByText("Część pozycji w koszyku nie może zostać kupiona. Usuń albo popraw oznaczone pozycje.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeNull();
    expect(screen.getAllByRole("link", { name: /koszyka/ })).toHaveLength(1);
  });

  it("says the session expired when the read answers 401", async () => {
    const release = await refuseHeld("cart_changed");
    await release({ status: "unauthorized" });

    expect(alert().textContent).toContain("Twoja sesja wygasła. Zaloguj się ponownie.");
    expect(alert().textContent).not.toContain(FIRST.cart_changed);
    expect(status()).toBe("");
  });

  it("shows the code rejected after the change at its field, and the lost discount in the list", async () => {
    await renderCheckout();
    const discounted: CartView = {
      ...cart(35998),
      totals: { subtotalGross: 39998, discountGross: 4000, gross: 35998, currency: "PLN", itemCount: 2 },
      discount: { status: "applied", code: "WITAJ10", amountGross: 4000, kind: "percent", value: 10 },
    };
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: discounted });
    fireEvent.change(codeField(), { target: { value: "witaj10" } });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Zastosuj" }));
    });
    fillGuestForm();
    const below = "Ten kod działa przy zamówieniu od 300,00 zł.";
    const one: CartView = {
      ...cart(19999, [line(A, "Buty trailowe", "czarne, 42", 1, 19999)]),
      discount: { status: "rejected", code: "WITAJ10", reason: "below_minimum", detail: below, minimumOrderGross: 30000 },
    };
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: one });
    await submit(conflict("cart_changed"));

    expect(loadCart).toHaveBeenLastCalledWith("witaj10");
    const messages = screen.getAllByRole("alert");
    expect(messages.map((node) => node.textContent)).toContain(below);
    expect(codeField().getAttribute("aria-describedby")).toBe(messages.find((node) => node.textContent === below)?.id);
    expect(items()).toEqual([
      "Buty trailowe, czarne, 42: było 2 szt., jest 1 szt.",
      `Rabat: był ${price(4000)}, jest ${price(0)}`,
      `Do zapłaty: było ${price(35998)}, jest ${price(19999)}`,
    ]);
    expect(screen.queryByTestId("checkout-discount")).toBeNull();
  });

  it("lists the chosen delivery method that is gone and drops the choice", async () => {
    const twoMethods: CartView = { ...cart(), delivery: delivery([option(STANDARD, "Kurier standardowy", 0, 39998), option(EXPRESS, "Kurier ekspresowy", 1999, 41997)]) };
    const release = await refuseHeld("total_changed", twoMethods);
    await release({ status: "ok", data: { ...cart(), delivery: delivery([option(EXPRESS, "Kurier ekspresowy", 1999, 41997)]) } });

    expect(items()).toEqual(["Dostawa „Kurier standardowy” nie jest już dostępna. Wybierz sposób dostawy."]);
    expect(deliveryRadio("Kurier ekspresowy").checked).toBe(false);
    expect(screen.getByTestId("checkout-total").textContent).toBe("Wybierz sposób dostawy");
  });

  it("announces a changed delivery in the live region of the summary, as after every read", async () => {
    const paid = (gross: number, cost: number): CartView => ({ ...cart(gross), delivery: delivery([option(STANDARD, "Kurier standardowy", cost, gross + cost, { freeFromGross: 30000, missingToFreeGross: cost === 0 ? null : 30000 - gross })]) });
    const release = await refuseHeld("cart_changed", paid(39998, 0));
    await release({ status: "ok", data: { ...paid(19999, 1499), lines: [line(A, "Buty trailowe", "czarne, 42", 1, 19999)] } });

    expect(screen.getByTestId("checkout-summary-status").textContent).toBe(
      `Dostawa „Kurier standardowy” nie jest już darmowa, kosztuje ${price(1499)}. Do darmowej dostawy brakuje ${price(10001)}.`,
    );
  });

  describe("when the cart cannot be read after the refusal", () => {
    async function refuseUnread(reason: Reason = "cart_changed") {
      const release = await refuseHeld(reason);
      await release({ status: "failed" });
    }

    it("says so, keeps the summary that was shown and unlocks the form", async () => {
      await refuseUnread();

      expect(alert().textContent).toBe(READ_FAILED);
      expect(document.activeElement).toBe(alert());
      expect(screen.getAllByTestId("checkout-line-quantity").map((node) => node.textContent)).toEqual(["2 szt."]);
      expect(status()).toBe("");
      expect(field("Telefon").readOnly).toBe(false);
      expect(submitButton().getAttribute("aria-disabled")).toBeNull();
    });

    it("reads the cart with the next click instead of ordering, locked meanwhile, then shows the changes", async () => {
      await refuseUnread("total_changed");
      const release = holdRead();
      const reads = vi.mocked(loadCart).mock.calls.length;
      submitButton().focus();
      await act(async () => {
        fireEvent.click(submitButton());
      });

      expect(vi.mocked(loadCart).mock.calls.length).toBe(reads + 1);
      expect(placeOrder).toHaveBeenCalledTimes(1);
      expect(screen.queryByRole("alert")).toBeNull();
      expect(status()).toBe("Wczytywanie…");
      expect(field("Telefon").getAttribute("aria-disabled")).toBe("true");
      expect(submitButton().getAttribute("aria-disabled")).toBe("true");
      await act(async () => {
        fireEvent.click(submitButton());
      });
      expect(vi.mocked(loadCart).mock.calls.length).toBe(reads + 1);

      const firstMessage = watchFirstMessage();
      await release({ status: "ok", data: three() });

      expect(firstMessage()).toEqual({ quantities: ["3 szt."], total: price(59997) });
      expect(alert().querySelector("p")?.textContent).toBe(FIRST.total_changed);
      expect(items()).toEqual(["Buty trailowe, czarne, 42: było 2 szt., jest 3 szt.", `Do zapłaty: było ${price(39998)}, jest ${price(59997)}`]);
      expect(document.activeElement).toBe(alert());
      expect(placeOrder).toHaveBeenCalledTimes(1);
      expect(status()).toBe("");

      // Only the click after it orders, with a new key and the summary read.
      await submit({ status: "ok", number: "R-100001", redirectUrl: null });
      const [first, second] = vi.mocked(placeOrder).mock.calls;
      expect(second[1]).not.toBe(first[1]);
      expect(second[0]).toMatchObject({ expectedTotalGross: 59997, expectedLines: [{ variantId: A, quantity: 3 }] });
    });

    it("stays out of date when the read fails again: the message comes back and the next click reads again", async () => {
      await refuseUnread();
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" });
      await act(async () => {
        fireEvent.click(submitButton());
      });

      expect(alert().textContent).toBe(READ_FAILED);
      const reads = vi.mocked(loadCart).mock.calls.length;
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: three() });
      await act(async () => {
        fireEvent.click(submitButton());
      });

      expect(vi.mocked(loadCart).mock.calls.length).toBe(reads + 1);
      expect(placeOrder).toHaveBeenCalledTimes(1);
      expect(items()).toHaveLength(2);
    });

    it("says the session expired when the read of the click answers 401", async () => {
      await refuseUnread();
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "unauthorized" });
      await act(async () => {
        fireEvent.click(submitButton());
      });

      expect(alert().textContent).toContain("Twoja sesja wygasła. Zaloguj się ponownie.");
      expect(placeOrder).toHaveBeenCalledTimes(1);
    });
  });

  // The rule of the summary out of date: until the customer has seen the changes against the summary of the refused
  // order (or the message without a list when there are none), no click orders and the message on the screen is true.
  // A read of the code is a read of the summary too.
  describe("when the summary out of date is read again by the discount code", () => {
    const applied = (lines: CartView["lines"], subtotal: number, amount: number): CartView => ({
      ...cart(subtotal - amount, lines),
      totals: { subtotalGross: subtotal, discountGross: amount, gross: subtotal - amount, currency: "PLN", itemCount: lines.reduce((sum, l) => sum + l.quantity, 0) },
      discount: { status: "applied", code: "WITAJ10", amountGross: amount, kind: "percent", value: 10 },
    });
    const twoPairs = () => [line(A, "Buty trailowe", "czarne, 42", 2, 39998)];
    const threePairs = () => [line(A, "Buty trailowe", "czarne, 42", 3, 59997)];
    const QUANTITY = "Buty trailowe, czarne, 42: było 2 szt., jest 3 szt.";
    const BELOW = "Ten kod działa przy zamówieniu od 1 000,00 zł.";

    /** A refusal for a changed cart whose read failed: the summary of the order (with a code, or without) is out of date. */
    async function outOfDate(withCode: boolean) {
      await renderCheckout();
      if (withCode) {
        vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: applied(twoPairs(), 39998, 4000) });
        fireEvent.change(codeField(), { target: { value: "witaj10" } });
        await act(async () => {
          fireEvent.click(screen.getByRole("button", { name: "Zastosuj" }));
        });
      }
      fillGuestForm();
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" });
      await submit(conflict("cart_changed"));
      expect(alert().textContent).toBe(READ_FAILED);
    }

    async function applyCode(code: string, ...reads: Read[]) {
      for (const read of reads) vi.mocked(loadCart).mockResolvedValueOnce(read);
      fireEvent.change(codeField(), { target: { value: code } });
      await act(async () => {
        fireEvent.click(screen.getByRole("button", { name: "Zastosuj" }));
      });
    }

    async function removeCode(read: Read) {
      vi.mocked(loadCart).mockResolvedValueOnce(read);
      await act(async () => {
        fireEvent.click(screen.getByRole("button", { name: "Usuń kod" }));
      });
    }

    it.each<[string, boolean, () => Promise<void>, string[]]>([
      [
        "a code applied",
        false,
        () => applyCode("witaj10", { status: "ok", data: applied(threePairs(), 59997, 6000) }),
        [QUANTITY, `Rabat: był ${price(0)}, jest ${price(6000)}`, `Do zapłaty: było ${price(39998)}, jest ${price(53997)}`],
      ],
      [
        "the code removed",
        true,
        () => removeCode({ status: "ok", data: three() }),
        [QUANTITY, `Rabat: był ${price(4000)}, jest ${price(0)}`, `Do zapłaty: było ${price(35998)}, jest ${price(59997)}`],
      ],
      [
        "a code rejected by the summary",
        false,
        () => applyCode("lato15", { status: "ok", data: { ...three(), discount: { status: "rejected", code: "LATO15", reason: "below_minimum", detail: BELOW, minimumOrderGross: 100000 } } }),
        [QUANTITY, `Do zapłaty: było ${price(39998)}, jest ${price(59997)}`],
      ],
      [
        "a code refused with a 400 (the summary read without it)",
        false,
        () => applyCode("x".repeat(10), { status: "invalid", detail: "Kod rabatowy może mieć najwyżej 64 znaki." }, { status: "ok", data: three() }),
        [QUANTITY, `Do zapłaty: było ${price(39998)}, jest ${price(59997)}`],
      ],
    ])("after %s tells the changes against the summary of the refused order at once, and only then a click orders", async (_name, withCode, read, changes) => {
      await outOfDate(withCode);
      await read();

      expect(screen.getAllByTestId("checkout-line-quantity").map((node) => node.textContent)).toEqual(["3 szt."]);
      expect(alert().textContent).not.toContain(READ_FAILED);
      expect(alert().querySelector("p")?.textContent).toBe(FIRST.cart_changed);
      expect(items()).toEqual(changes);
      expect(placeOrder).toHaveBeenCalledTimes(1);

      // The customer has seen the changes: the next click orders the summary shown, with a new key.
      await submit({ status: "ok", number: "R-100001", redirectUrl: null });
      const [first, second] = vi.mocked(placeOrder).mock.calls;
      expect(second[0].expectedLines).toEqual([{ variantId: A, quantity: 3 }]);
      expect(second[1]).not.toBe(first[1]);
    });

    it.each<[string, Read]>([
      ["fails", { status: "failed" }],
      ["answers 401", { status: "unauthorized" }],
    ])("stays out of date when the check of a code %s: the next click reads the cart and orders nothing", async (_name, answer) => {
      await outOfDate(false);
      await applyCode("witaj10", answer);

      expect(screen.getAllByTestId("checkout-line-quantity").map((node) => node.textContent)).toEqual(["2 szt."]);
      const reads = vi.mocked(loadCart).mock.calls.length;
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: three() });
      await act(async () => {
        fireEvent.click(submitButton());
      });

      expect(vi.mocked(loadCart).mock.calls.length).toBe(reads + 1);
      expect(placeOrder).toHaveBeenCalledTimes(1);
      expect(items()).toEqual([QUANTITY, `Do zapłaty: było ${price(39998)}, jest ${price(59997)}`]);
    });

    // The delivery method is not locked while a code is checked: the list and the announcement are of the method the
    // customer has chosen when the answer comes, the one the summary and the next order have.
    const withMethods = (view: CartView, standard: number, express: number): CartView => ({
      ...view,
      delivery: delivery([option(STANDARD, "Kurier standardowy", standard, view.totals.gross + standard), option(EXPRESS, "Kurier ekspresowy", express, view.totals.gross + express)]),
    });

    /** Out of date with the standard method chosen, then a code applied with its read held; answers the release. */
    async function holdCodeOutOfDate(): Promise<(answer: Read) => Promise<void>> {
      await renderCheckout(withMethods(cart(), 0, 1999));
      fillGuestForm();
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" });
      await submit(conflict("cart_changed"));
      expect(alert().textContent).toBe(READ_FAILED);
      const release = holdRead();
      fireEvent.change(codeField(), { target: { value: "witaj10" } });
      await act(async () => {
        fireEvent.click(screen.getByRole("button", { name: "Zastosuj" }));
      });
      return release;
    }

    it("tells the total to pay of the method chosen while the code was checked", async () => {
      const release = await holdCodeOutOfDate();
      fireEvent.click(deliveryRadio("Kurier ekspresowy"));
      await release({ status: "ok", data: withMethods(three(), 0, 1999) });

      expect(deliveryRadio("Kurier ekspresowy").checked).toBe(true);
      expect(screen.getByTestId("checkout-total").textContent).toBe(price(61996));
      expect(items()).toEqual([QUANTITY, `Do zapłaty: było ${price(41997)}, jest ${price(61996)}`]);
    });

    it("does not tell a method as gone when the customer chose another one while the code was checked", async () => {
      const release = await holdCodeOutOfDate();
      fireEvent.click(deliveryRadio("Kurier ekspresowy"));
      const onlyExpress: CartView = { ...three(), delivery: delivery([option(EXPRESS, "Kurier ekspresowy", 1999, 61996)]) };
      await release({ status: "ok", data: onlyExpress });

      expect(deliveryRadio("Kurier ekspresowy").checked).toBe(true);
      expect(items()).toEqual([QUANTITY, `Do zapłaty: było ${price(41997)}, jest ${price(61996)}`]);
    });

    it("tells the changes when a code is applied after the failed read and before the next render", async () => {
      // The read after the refusal fails; in the same turn, before React renders that answer, the customer applies a
      // code: its read of the summary is a read of a summary out of date too.
      await renderCheckout();
      fillGuestForm();
      fireEvent.change(codeField(), { target: { value: "LATO15" } });
      let answerRead: (answer: Read) => void = () => {};
      vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (answerRead = resolve)));
      await submit(conflict("cart_changed"));
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: three() });
      await act(async () => {
        answerRead({ status: "failed" });
        for (let tick = 0; tick < 20; tick++) await Promise.resolve();
        fireEvent.click(screen.getByRole("button", { name: "Zastosuj" }));
      });
      await act(async () => {});

      expect(vi.mocked(loadCart).mock.calls.at(-1)).toEqual(["LATO15"]);
      expect(screen.getAllByTestId("checkout-line-quantity").map((node) => node.textContent)).toEqual(["3 szt."]);
      expect(alert().textContent).not.toContain(READ_FAILED);
      expect(items()).toEqual([QUANTITY, `Do zapłaty: było ${price(39998)}, jest ${price(59997)}`]);
      expect(placeOrder).toHaveBeenCalledTimes(1);
    });
  });

  // The message tells the difference at the moment of the refused order; once the customer changes the summary
  // (a code applied or removed, another delivery method), it would contradict the summary next to it (QA F5).
  describe("when the customer changes the summary after the message", () => {
    const failure = () => document.getElementById("checkout-failure");
    const discounted = (view: CartView, amount: number): CartView => ({
      ...view,
      totals: { ...view.totals, discountGross: amount, gross: view.totals.gross - amount },
      discount: { status: "applied", code: "WITAJ10", amountGross: amount, kind: "percent", value: 10 },
      delivery: view.delivery && { ...view.delivery, options: view.delivery.options.map((o) => ({ ...o, totalGross: o.totalGross - amount })) },
    });

    /** A guest's order of `view` refused for a changed total, the cart read as `after`: the message with the changes. */
    async function changed(view: CartView, after: CartView) {
      await renderCheckout(view);
      fillGuestForm();
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: after });
      await submit(conflict("total_changed"));
      expect(alert().querySelector("p")?.textContent).toBe(FIRST.total_changed);
    }

    async function clickApply(code: string, ...reads: Read[]) {
      for (const read of reads) vi.mocked(loadCart).mockResolvedValueOnce(read);
      fireEvent.change(codeField(), { target: { value: code } });
      const apply = screen.getByRole("button", { name: "Zastosuj" });
      apply.focus();
      await act(async () => {
        fireEvent.click(apply);
      });
    }

    it("goes away when a code is applied; the focus and the announcement of the code are as always", async () => {
      await changed(cart(), three());
      await clickApply("witaj10", { status: "ok", data: discounted(three(), 6000) });

      expect(failure()).toBeNull();
      expect(screen.getByTestId("checkout-total").textContent).toBe(price(53997));
      expect(document.activeElement).toBe(screen.getByRole("button", { name: "Usuń kod" }));
      expect(screen.getByTestId("checkout-code-status").textContent).toBe("Zastosowano kod WITAJ10.");
    });

    it("goes away when the code is removed", async () => {
      await renderCheckout();
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: discounted(cart(), 4000) });
      fireEvent.change(codeField(), { target: { value: "witaj10" } });
      await act(async () => {
        fireEvent.click(screen.getByRole("button", { name: "Zastosuj" }));
      });
      fillGuestForm();
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: discounted(three(), 6000) });
      await submit(conflict("total_changed"));
      expect(alert().querySelector("p")?.textContent).toBe(FIRST.total_changed);

      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: three() });
      const remove = screen.getByRole("button", { name: "Usuń kod" });
      remove.focus();
      await act(async () => {
        fireEvent.click(remove);
      });

      expect(failure()).toBeNull();
      expect(screen.getByTestId("checkout-total").textContent).toBe(price(59997));
      expect(document.activeElement).toBe(codeField());
      expect(screen.getByTestId("checkout-code-status").textContent).toBe("Usunięto kod rabatowy.");
    });

    it("goes away when another delivery method is chosen, and the focus stays on it", async () => {
      await changed(withDeliveries(0, 1999, 39998), withDeliveries(0, 1999, 59997));
      deliveryRadio("Kurier ekspresowy").focus();
      fireEvent.click(deliveryRadio("Kurier ekspresowy"));

      expect(failure()).toBeNull();
      expect(screen.getByTestId("checkout-total").textContent).toBe(price(61996));
      expect(document.activeElement).toBe(deliveryRadio("Kurier ekspresowy"));
    });

    it.each<[string, () => Promise<void> | void]>([
      [
        "a field is edited",
        () => {
          type("Telefon", "600 100 201");
        },
      ],
      [
        "a payment provider is chosen",
        () => {
          choose("Karta płatnicza (demo)");
        },
      ],
      [
        "a code is rejected by the summary",
        () => clickApply("lato15", { status: "ok", data: { ...three(), discount: { status: "rejected", code: "LATO15", reason: "invalid", detail: "Ten kod nie istnieje.", minimumOrderGross: null } } }),
      ],
      ["a code is refused with a 400", () => clickApply("x".repeat(10), { status: "invalid", detail: "Kod rabatowy może mieć najwyżej 64 znaki." }, { status: "ok", data: three() })],
      ["a code cannot be checked", () => clickApply("witaj10", { status: "failed" })],
    ])("stays when %s", async (_name, action) => {
      await changed(cart(), three());
      const text = alert().textContent;
      await act(async () => {
        await action();
      });

      expect(alert().textContent).toBe(text);
    });

    it("leaves a message of another kind when a code is applied", async () => {
      await renderCheckout();
      fillGuestForm();
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
      await submit({ status: "conflict", reason: "insufficient_stock", detail: "Brak wystarczającej liczby sztuk." });
      await clickApply("witaj10", { status: "ok", data: discounted(cart(), 4000) });

      expect(alert().textContent).toContain("Brak wystarczającej liczby sztuk.");
    });
  });

  it("announces the delivery of the method chosen while a code was checked, not of the one chosen before", async () => {
    // Standard is free and express paid; with the code the goods are below the threshold: standard is paid, express free.
    await renderCheckout(withDeliveries(0, 1999, 39998));
    const release = holdRead();
    fireEvent.change(codeField(), { target: { value: "witaj10" } });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Zastosuj" }));
    });
    fireEvent.click(deliveryRadio("Kurier ekspresowy"));
    await release({ status: "ok", data: withDeliveries(1999, 0, 35998) });

    expect(deliveryRadio("Kurier ekspresowy").checked).toBe(true);
    expect(screen.getByTestId("checkout-summary-status").textContent).toBe("Dostawa „Kurier ekspresowy” jest teraz darmowa.");
  });
});

/** A cart of the goods `gross` with the standard and the express method at the given costs; the totals are the API's. */
function withDeliveries(standard: number, express: number, gross: number): CartView {
  return {
    ...cart(gross),
    delivery: delivery([option(STANDARD, "Kurier standardowy", standard, gross + standard), option(EXPRESS, "Kurier ekspresowy", express, gross + express)]),
  };
}

// docs/features/cart-order-consistency/spec.md, Z4, „Kasa po checkout-buyer-changed”.
describe("CheckoutPage after a 409 of a changed buyer", () => {
  const PLACING = "Składamy zamówienie…";
  const ANNA = { name: "Anna Nowak", email: "anna.nowak@example.com", buyer: "buyer-tag-of-anna" };
  const SIGNED_IN_AS = (email: string) =>
    `Od wczytania kasy zmieniło się konto: teraz to ${email}. Nie złożyliśmy zamówienia. Zamówienie będzie zamówieniem tego konta: sprawdź dane i podsumowanie, potem zamów ponownie.`;
  const SIGNED_OUT = "Od wczytania kasy nastąpiło wylogowanie. Nie złożyliśmy zamówienia. Podaj adres e-mail, sprawdź dane i podsumowanie, potem zamów ponownie.";
  const NOT_READ = "Kupujący się zmienił, ale nie udało się wczytać kasy. Spróbuj ponownie.";
  type CartRead = Awaited<ReturnType<typeof loadCart>>;
  type SessionRead = Awaited<ReturnType<typeof loadAccountSession>>;

  const buyerChanged: PlaceOrderResult = { status: "conflict", reason: "buyer_changed", detail: "Od wczytania kasy zmienił się kupujący. Nie złożyliśmy zamówienia." };
  /** The cart of the account after the change: one pair, the total of the API. */
  const one = (): CartView => cart(19999, [line(A, "Buty trailowe", "czarne, 42", 1, 19999)]);
  const alert = () => {
    const message = document.getElementById("checkout-failure");
    if (message === null) throw new Error("no message above the form");
    return message;
  };
  const status = () => screen.getByTestId("checkout-status").textContent;
  const providerRadio = (name: string) => screen.getByRole("radio", { name }) as HTMLInputElement;

  /** Holds the next read of the cart; the returned function gives its answer. */
  function holdCart(): (answer: CartRead) => Promise<void> {
    let release: (answer: CartRead) => void = () => {};
    vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (release = resolve)));
    return (answer) => act(async () => release(answer));
  }

  /** Holds the next read of the session; the returned function gives its answer. */
  function holdSession(): (answer: SessionRead) => Promise<void> {
    let release: (answer: SessionRead) => void = () => {};
    vi.mocked(loadAccountSession).mockReturnValueOnce(new Promise((resolve) => (release = resolve)));
    return (answer) => act(async () => release(answer));
  }

  /** Notes the buyer and the summary shown when the message first appears in the DOM. */
  function watchFirstMessage(): () => { account: string | null; emailField: boolean; quantities: string[] } | null {
    let seen: { account: string | null; emailField: boolean; quantities: string[] } | null = null;
    const observer = new MutationObserver(() => {
      if (seen !== null || document.getElementById("checkout-failure") === null) return;
      seen = {
        account: document.querySelector('[data-testid="account-email"]')?.textContent ?? null,
        emailField: document.getElementById("checkout-email") !== null,
        quantities: Array.from(document.querySelectorAll('[data-testid="checkout-line-quantity"]'), (node) => node.textContent ?? ""),
      };
    });
    observer.observe(document.body, { subtree: true, childList: true, characterData: true });
    return () => {
      observer.disconnect();
      return seen;
    };
  }

  /** The guest's form filled in and the order sent from the focused button, refused for a changed buyer, both reads held. */
  async function refuseGuestHeld() {
    await renderCheckout();
    fillGuestForm();
    const session = holdSession();
    const read = holdCart();
    submitButton().focus();
    await submit(buyerChanged);
    return { session, read };
  }

  it("keeps the form locked and shows no message until both the session and the cart are read again", async () => {
    const { session } = await refuseGuestHeld();

    expect(document.getElementById("checkout-failure")).toBeNull();
    expect(status()).toBe(PLACING);
    expect(loadAccountSession).toHaveBeenCalledTimes(2);
    expect(loadCart).toHaveBeenLastCalledWith();
    for (const label of ["E-mail", "Imię i nazwisko", "Telefon", "Ulica i numer", "Kod pocztowy", "Miejscowość", "Kod rabatowy"]) {
      expect(field(label).getAttribute("aria-disabled")).toBe("true");
    }
    expect(deliveryRadio("Kurier standardowy").getAttribute("aria-disabled")).toBe("true");
    expect(providerRadio("Karta płatnicza (demo)").getAttribute("aria-disabled")).toBe("true");
    expect(submitButton().getAttribute("aria-disabled")).toBe("true");

    // Nothing the customer does meanwhile changes the form or sends anything.
    type("Telefon", "600 100 201");
    fireEvent.click(providerRadio("Karta płatnicza (demo)"));
    await act(async () => {
      fireEvent.click(submitButton());
    });
    expect(field("Telefon").value).toBe("600 100 200");
    expect(providerRadio("Szybki przelew (demo)").checked).toBe(true);
    expect(placeOrder).toHaveBeenCalledTimes(1);

    // The session alone is not shown: the buyer and the summary come together.
    await session({ status: "ok", session: JAN });
    expect(document.getElementById("checkout-failure")).toBeNull();
    expect(screen.queryByTestId("account-email")).toBeNull();
    expect(field("E-mail").value).toBe("anna.nowak@example.com");
    expect(status()).toBe(PLACING);
  });

  it("shows the message with the account, the buyer and the summary of the read in one render, keeps the data, and the next click orders for that buyer with a new key", async () => {
    const { session, read } = await refuseGuestHeld();
    const firstMessage = watchFirstMessage();
    await session({ status: "ok", session: JAN });
    await read({ status: "ok", data: one() });

    expect(firstMessage()).toEqual({ account: JAN.email, emailField: false, quantities: ["1 szt."] });
    expect(alert().getAttribute("role")).toBe("alert");
    expect(alert().textContent).toBe(SIGNED_IN_AS(JAN.email));
    expect(document.activeElement).toBe(alert());
    expect(status()).toBe("");
    expect(screen.queryByLabelText("E-mail")).toBeNull();
    for (const [label, value] of [["Imię i nazwisko", "Anna Nowak"], ["Telefon", "600 100 200"], ["Ulica i numer", "ul. Długa 5/3"], ["Kod pocztowy", "00-950"], ["Miejscowość", "Warszawa"]]) {
      expect(field(label).value).toBe(value);
      expect(field(label).readOnly).toBe(false);
    }
    expect(deliveryRadio("Kurier standardowy").checked).toBe(true);
    expect(providerRadio("Szybki przelew (demo)").checked).toBe(true);
    expect(submitButton().getAttribute("aria-disabled")).toBeNull();
    expect(placeOrder).toHaveBeenCalledTimes(1);

    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    const [first, second] = vi.mocked(placeOrder).mock.calls;
    expect(first[0].buyer).toBe("guest");
    expect(second[1]).not.toBe(first[1]);
    expect(second[0]).not.toHaveProperty("email");
    expect(second[0]).toEqual({ ...GUEST_INPUT, email: undefined, buyer: JAN.buyer, expectedTotalGross: 19999, expectedLines: [{ variantId: A, quantity: 1 }] });
  });

  it("names account B after the checkout of account A, and the next click carries the tag of B", async () => {
    await renderCheckout(cart(), JAN);
    fillContact();
    vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "ok", session: ANNA });
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
    await submit(buyerChanged);

    expect(alert().textContent).toBe(SIGNED_IN_AS(ANNA.email));
    expect(screen.getByTestId("account-email").textContent).toBe(ANNA.email);
    // The name typed for the order stays; it is not taken again from the other account.
    expect(field("Imię i nazwisko").value).toBe("Jan Kowalski");

    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    const [first, second] = vi.mocked(placeOrder).mock.calls;
    expect(first[0].buyer).toBe(JAN.buyer);
    expect(second[0].buyer).toBe(ANNA.buyer);
    expect(second[0]).not.toHaveProperty("email");
    expect(second[1]).not.toBe(first[1]);
  });

  it("tells the logout after the checkout of an account, shows the e-mail field, and the next click orders as the guest", async () => {
    await renderCheckout(cart(), JAN);
    fillContact();
    vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "ok", session: null });
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
    await submit(buyerChanged);

    expect(alert().textContent).toBe(SIGNED_OUT);
    expect(screen.queryByTestId("account-email")).toBeNull();
    expect(field("E-mail").value).toBe("");
    expect(field("Telefon").value).toBe("600 100 200");
    type("E-mail", "jan.prywatny@example.com");

    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    const second = vi.mocked(placeOrder).mock.calls[1][0];
    expect(second.buyer).toBe("guest");
    expect(second.email).toBe("jan.prywatny@example.com");
  });

  it("gives a second 409 and a second read when the account changes again between the read and the next click", async () => {
    await renderCheckout(cart(), JAN);
    fillContact();
    vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "ok", session: ANNA });
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
    await submit(buyerChanged);
    vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "ok", session: JAN });
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: one() });
    await submit(buyerChanged);

    expect(loadAccountSession).toHaveBeenCalledTimes(3);
    expect(placeOrder).toHaveBeenCalledTimes(2);
    expect(vi.mocked(placeOrder).mock.calls.map(([input]) => input.buyer)).toEqual([JAN.buyer, ANNA.buyer]);
    expect(alert().textContent).toBe(SIGNED_IN_AS(JAN.email));
    expect(screen.getAllByTestId("checkout-line-quantity").map((node) => node.textContent)).toEqual(["1 szt."]);

    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    const third = vi.mocked(placeOrder).mock.calls[2];
    expect(third[0]).toMatchObject({ buyer: JAN.buyer, expectedTotalGross: 19999, expectedLines: [{ variantId: A, quantity: 1 }] });
    expect(new Set(vi.mocked(placeOrder).mock.calls.map(([, key]) => key)).size).toBe(3);
  });

  it("reads the cart with the applied code", async () => {
    await renderCheckout();
    const discounted: CartView = {
      ...cart(35998),
      totals: { subtotalGross: 39998, discountGross: 4000, gross: 35998, currency: "PLN", itemCount: 2 },
      discount: { status: "applied", code: "WITAJ10", amountGross: 4000, kind: "percent", value: 10 },
    };
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: discounted });
    fireEvent.change(field("Kod rabatowy"), { target: { value: "witaj10" } });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Zastosuj" }));
    });
    fillGuestForm();
    vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "ok", session: JAN });
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: discounted });
    await submit(buyerChanged);

    expect(loadCart).toHaveBeenLastCalledWith("witaj10");
    expect(screen.getByTestId("checkout-discount").textContent).toContain("WITAJ10");
    expect(alert().textContent).toBe(SIGNED_IN_AS(JAN.email));
  });

  it("leaves the focus where the customer moved it during the read", async () => {
    const { session, read } = await refuseGuestHeld();
    field("Kod pocztowy").focus();
    await session({ status: "ok", session: JAN });
    await read({ status: "ok", data: one() });

    expect(document.activeElement).toBe(field("Kod pocztowy"));
    expect(alert().getAttribute("role")).toBe("alert");
    expect(alert().textContent).toBe(SIGNED_IN_AS(JAN.email));
  });

  it("shows the empty cart of the account instead of the form, with the message", async () => {
    const { session, read } = await refuseGuestHeld();
    await session({ status: "ok", session: JAN });
    await read({ status: "ok", data: cart(0, []) });

    expect(alert().textContent).toBe(SIGNED_IN_AS(JAN.email));
    expect(screen.getByText("Twój koszyk jest pusty.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeNull();
  });

  it("says the session expired when the read of the cart answers 401", async () => {
    const { session, read } = await refuseGuestHeld();
    await session({ status: "ok", session: JAN });
    await read({ status: "unauthorized" });

    expect(loadAccountSession).toHaveBeenCalledTimes(2);
    expect(alert().textContent).toContain("Twoja sesja wygasła. Zaloguj się ponownie.");
    expect(status()).toBe("");
  });

  // A retry of an uncertain result goes with the same key, and the attempt before it may have placed the order (the
  // answer was lost): the BFF refuses the retry before the API, so the checkout does not know (QA F3).
  describe("on the key of an attempt whose result was uncertain", () => {
    const MAY_HAVE_PLACED = "Poprzednia próba mogła złożyć zamówienie dla poprzedniego kupującego. Sprawdź pocztę, zanim zamówisz ponownie.";
    const MAYBE_SIGNED_IN_AS = (email: string) =>
      `Od wczytania kasy zmieniło się konto: teraz to ${email}. ${MAY_HAVE_PLACED} Zamówienie będzie zamówieniem tego konta: sprawdź dane i podsumowanie, potem zamów ponownie.`;
    const MAYBE_SIGNED_OUT = `Od wczytania kasy nastąpiło wylogowanie. ${MAY_HAVE_PLACED} Podaj adres e-mail, sprawdź dane i podsumowanie, potem zamów ponownie.`;

    /** The checkout of Jan sent once with an uncertain result (the answer lost), then the session and cart read next. */
    async function janUncertain(next: SessionRead) {
      await renderCheckout(cart(), JAN);
      fillContact();
      await submit({ status: "failed" });
      expect(alert().textContent).toBe(SUBMIT_FAILED);
      vi.mocked(loadAccountSession).mockResolvedValueOnce(next);
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
    }

    it("does not claim that nothing was placed when another account signed in before the retry", async () => {
      await janUncertain({ status: "ok", session: ANNA });
      await submit(buyerChanged);

      const [first, second] = vi.mocked(placeOrder).mock.calls;
      expect(second[1]).toBe(first[1]);
      expect(alert().textContent).toBe(MAYBE_SIGNED_IN_AS(ANNA.email));
      expect(screen.getByTestId("account-email").textContent).toBe(ANNA.email);
      expect(document.activeElement).toBe(alert());
    });

    it("does not claim that nothing was placed when the account signed out before the retry", async () => {
      await janUncertain({ status: "ok", session: null });
      await submit(buyerChanged);

      expect(alert().textContent).toBe(MAYBE_SIGNED_OUT);
      expect(field("E-mail").value).toBe("");
    });

    it("does not claim it for the guest checkout either", async () => {
      await renderCheckout();
      fillGuestForm();
      await submit({ status: "failed" });
      vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "ok", session: JAN });
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
      await submit(buyerChanged);

      expect(alert().textContent).toBe(MAYBE_SIGNED_IN_AS(JAN.email));
    });

    it("still does not claim it after a retry that kept the key without placing anything (a 429)", async () => {
      await janUncertain({ status: "ok", session: ANNA });
      await submit({ status: "rate_limited" });
      expect(alert().textContent).toBe("Zbyt wiele prób złożenia zamówienia. Spróbuj ponownie za kilka minut.");
      await submit(buyerChanged);

      expect(new Set(vi.mocked(placeOrder).mock.calls.map(([, key]) => key)).size).toBe(1);
      expect(alert().textContent).toBe(MAYBE_SIGNED_IN_AS(ANNA.email));
    });

    it("tells it after the read that failed, with the click that reads the session and the cart again", async () => {
      await renderCheckout(cart(), JAN);
      fillContact();
      await submit({ status: "failed" });
      vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "unavailable" });
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
      await submit(buyerChanged);
      expect(alert().textContent).toBe(NOT_READ);

      vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "ok", session: ANNA });
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
      await act(async () => {
        fireEvent.click(submitButton());
      });

      expect(placeOrder).toHaveBeenCalledTimes(2);
      expect(alert().textContent).toBe(MAYBE_SIGNED_IN_AS(ANNA.email));
    });

    it("says nothing was placed when the key is new: the form changed after the uncertain attempt", async () => {
      await janUncertain({ status: "ok", session: ANNA });
      type("Telefon", "600 100 201");
      await submit(buyerChanged);

      const [first, second] = vi.mocked(placeOrder).mock.calls;
      expect(second[1]).not.toBe(first[1]);
      expect(alert().textContent).toBe(SIGNED_IN_AS(ANNA.email));
    });

    it("says nothing was placed by the attempt after the message: it has a new key", async () => {
      await janUncertain({ status: "ok", session: ANNA });
      await submit(buyerChanged);
      vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "ok", session: JAN });
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
      await submit(buyerChanged);

      expect(new Set(vi.mocked(placeOrder).mock.calls.map(([, key]) => key)).size).toBe(2);
      expect(alert().textContent).toBe(SIGNED_IN_AS(JAN.email));
    });
  });

  describe("when the session or the cart cannot be read after the refusal", () => {
    it.each<[string, SessionRead, CartRead]>([
      ["the session", { status: "unavailable" }, { status: "ok", data: one() }],
      ["the cart", { status: "ok", session: JAN }, { status: "failed" }],
    ])("says so when %s cannot be read, keeps the buyer and the summary that were shown and unlocks the form", async (_name, sessionAnswer, cartAnswer) => {
      const { session, read } = await refuseGuestHeld();
      await session(sessionAnswer);
      await read(cartAnswer);

      expect(alert().textContent).toBe(NOT_READ);
      expect(document.activeElement).toBe(alert());
      expect(field("E-mail").value).toBe("anna.nowak@example.com");
      expect(screen.getAllByTestId("checkout-line-quantity").map((node) => node.textContent)).toEqual(["2 szt."]);
      expect(status()).toBe("");
      expect(submitButton().getAttribute("aria-disabled")).toBeNull();
    });

    it("reads the session and the cart with the next click instead of ordering, locked meanwhile, then tells the buyer; only the click after it orders", async () => {
      const { session, read } = await refuseGuestHeld();
      await session({ status: "unavailable" });
      await read({ status: "ok", data: cart() });
      const sessionAgain = holdSession();
      const readAgain = holdCart();
      submitButton().focus();
      await act(async () => {
        fireEvent.click(submitButton());
      });

      expect(loadAccountSession).toHaveBeenCalledTimes(3);
      expect(placeOrder).toHaveBeenCalledTimes(1);
      expect(document.getElementById("checkout-failure")).toBeNull();
      expect(status()).toBe("Wczytywanie…");
      expect(field("Telefon").getAttribute("aria-disabled")).toBe("true");
      await act(async () => {
        fireEvent.click(submitButton());
      });
      expect(loadAccountSession).toHaveBeenCalledTimes(3);

      const firstMessage = watchFirstMessage();
      await sessionAgain({ status: "ok", session: JAN });
      await readAgain({ status: "ok", data: one() });

      expect(firstMessage()).toEqual({ account: JAN.email, emailField: false, quantities: ["1 szt."] });
      expect(alert().textContent).toBe(SIGNED_IN_AS(JAN.email));
      expect(document.activeElement).toBe(alert());
      expect(placeOrder).toHaveBeenCalledTimes(1);
      expect(status()).toBe("");

      await submit({ status: "ok", number: "R-100001", redirectUrl: null });
      const [first, second] = vi.mocked(placeOrder).mock.calls;
      expect(second[1]).not.toBe(first[1]);
      expect(second[0]).toMatchObject({ buyer: JAN.buyer, expectedLines: [{ variantId: A, quantity: 1 }] });
    });

    it("stays out of date after a discount code read, which reads the cart but not the session", async () => {
      const { session, read } = await refuseGuestHeld();
      await session({ status: "unavailable" });
      await read({ status: "ok", data: cart() });
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
      fireEvent.change(field("Kod rabatowy"), { target: { value: "LATO15" } });
      await act(async () => {
        fireEvent.click(screen.getByRole("button", { name: "Zastosuj" }));
      });
      vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "ok", session: JAN });
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: one() });
      await act(async () => {
        fireEvent.click(submitButton());
      });

      expect(placeOrder).toHaveBeenCalledTimes(1);
      expect(alert().textContent).toBe(SIGNED_IN_AS(JAN.email));
    });
  });
});

describe("CheckoutPage while the order is being placed", () => {
  const PLACING = "Składamy zamówienie…";
  /** The text fields of a guest's form with what `fillGuestForm` types in them. */
  const GUEST_FIELDS: [string, string][] = [
    ["E-mail", GUEST_INPUT.email],
    ["Imię i nazwisko", GUEST_INPUT.fullName],
    ["Telefon", GUEST_INPUT.phone],
    ["Ulica i numer", GUEST_INPUT.street],
    ["Kod pocztowy", GUEST_INPUT.postalCode],
    ["Miejscowość", GUEST_INPUT.city],
  ];
  const providerRadio = (name: string) => screen.getByRole("radio", { name }) as HTMLInputElement;
  const form = () => submitButton().form as HTMLFormElement;
  const twoMethods = (): CartView => ({
    ...cart(),
    delivery: delivery([option(STANDARD, "Kurier standardowy", 0, 39998), option(EXPRESS, "Kurier ekspresowy", 1999, 41997)]),
  });

  /** Holds the answer of the next order; the returned function gives it. */
  function holdOrder(): (result: PlaceOrderResult) => Promise<void> {
    let answer: (result: PlaceOrderResult) => void = () => {};
    vi.mocked(placeOrder).mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
    return (result) => act(async () => answer(result));
  }

  /** A guest's form with two methods, the first one chosen, then the order sent from the focused button and held. */
  async function placeHeld(): Promise<(result: PlaceOrderResult) => Promise<void>> {
    await renderCheckout(twoMethods());
    fillGuestForm();
    const answer = holdOrder();
    submitButton().focus();
    await act(async () => {
      fireEvent.click(submitButton());
    });
    return answer;
  }

  it("keeps every text field as it was, read-only with aria-disabled, while the request runs", async () => {
    await placeHeld();

    for (const [label, value] of GUEST_FIELDS) {
      expect(field(label).readOnly).toBe(true);
      expect(field(label).getAttribute("aria-disabled")).toBe("true");
      expect(field(label).disabled).toBe(false);
      fireEvent.change(field(label), { target: { value: `${value}X` } });
      expect(field(label).value).toBe(value);
    }
  });

  it("keeps the delivery method and the provider as they were: a click, the arrows and Space change nothing", async () => {
    await placeHeld();

    const radios = [deliveryRadio("Kurier standardowy"), deliveryRadio("Kurier ekspresowy"), providerRadio("Szybki przelew (demo)"), providerRadio("Karta płatnicza (demo)")];
    for (const radio of radios) {
      expect(radio.getAttribute("aria-disabled")).toBe("true");
      expect(radio.disabled).toBe(false);
    }
    fireEvent.click(deliveryRadio("Kurier ekspresowy"));
    fireEvent.click(providerRadio("Karta płatnicza (demo)"));
    for (const radio of radios) {
      // A key whose default is cancelled moves no choice: fireEvent answers false for a cancelled event.
      expect(fireEvent.keyDown(radio, { key: "ArrowDown" })).toBe(false);
      expect(fireEvent.keyDown(radio, { key: "ArrowRight" })).toBe(false);
      expect(fireEvent.keyDown(radio, { key: " " })).toBe(false);
    }

    expect(deliveryRadio("Kurier standardowy").checked).toBe(true);
    expect(deliveryRadio("Kurier ekspresowy").checked).toBe(false);
    expect(providerRadio("Szybki przelew (demo)").checked).toBe(true);
    expect(providerRadio("Karta płatnicza (demo)").checked).toBe(false);
    expect(screen.getByTestId("checkout-total").textContent).toBe(price(39998));
  });

  it("keeps the focus on the order button, says it is placing the order, and sends one request for a click, Space and Enter", async () => {
    await placeHeld();

    expect(document.activeElement).toBe(submitButton());
    expect(submitButton().getAttribute("aria-disabled")).toBe("true");
    expect(submitButton().disabled).toBe(false);
    expect(screen.getByTestId("checkout-status").textContent).toBe(PLACING);
    expect(screen.getAllByText(PLACING)).toHaveLength(1);
    await act(async () => {
      fireEvent.click(submitButton());
      fireEvent.submit(form());
    });

    expect(placeOrder).toHaveBeenCalledTimes(1);
  });

  it("sends one request when the order is sent with Enter from a field, again and again", async () => {
    await renderCheckout();
    fillGuestForm();
    holdOrder();
    field("Miejscowość").focus();
    await act(async () => {
      fireEvent.submit(form());
      fireEvent.submit(form());
    });
    await act(async () => {
      fireEvent.submit(form());
    });

    expect(placeOrder).toHaveBeenCalledTimes(1);
    expect(document.activeElement).toBe(field("Miejscowość"));
  });

  it.each<[string, PlaceOrderResult]>([
    ["a failure", { status: "failed" }],
    ["a 422", { status: "invalid", violations: [{ propertyPath: "phone", title: "Podaj 9-cyfrowy numer telefonu." }] }],
  ])("unlocks everything after %s and keeps the fields, the method, the provider and the code", async (_name, result) => {
    await renderCheckout(twoMethods());
    fillGuestForm();
    fireEvent.change(screen.getByLabelText("Kod rabatowy"), { target: { value: "LATO15" } });
    const answer = holdOrder();
    await act(async () => {
      fireEvent.click(submitButton());
    });
    await answer(result);

    expect(screen.getByTestId("checkout-status").textContent).toBe("");
    expect(submitButton().getAttribute("aria-disabled")).toBeNull();
    for (const [label, value] of GUEST_FIELDS) {
      expect(field(label).readOnly).toBe(false);
      expect(field(label).getAttribute("aria-disabled")).toBeNull();
      expect(field(label).value).toBe(value);
    }
    expect(deliveryRadio("Kurier ekspresowy").getAttribute("aria-disabled")).toBeNull();
    expect(providerRadio("Karta płatnicza (demo)").getAttribute("aria-disabled")).toBeNull();
    expect(deliveryRadio("Kurier standardowy").checked).toBe(true);
    expect(providerRadio("Szybki przelew (demo)").checked).toBe(true);
    expect((screen.getByLabelText("Kod rabatowy") as HTMLInputElement).value).toBe("LATO15");

    // Unlocked, the choices and the fields change again.
    fireEvent.click(deliveryRadio("Kurier ekspresowy"));
    expect(deliveryRadio("Kurier ekspresowy").checked).toBe(true);
    type("Telefon", "600 100 201");
    expect(field("Telefon").value).toBe("600 100 201");
  });

  it("is still locked when it goes to the page of the provider after a success", async () => {
    let lockedWhenLeaving: (string | boolean | null)[] = [];
    vi.mocked(navigateTo).mockImplementation(() => {
      lockedWhenLeaving = [submitButton().getAttribute("aria-disabled"), field("Telefon").readOnly, deliveryRadio("Kurier standardowy").getAttribute("aria-disabled")];
    });
    const answer = await placeHeld();
    await answer({ status: "ok", number: "R-100001", redirectUrl: REDIRECT_URL });

    expect(navigateTo).toHaveBeenCalledWith(REDIRECT_URL);
    expect(lockedWhenLeaving).toEqual(["true", true, "true"]);
    expect(screen.getByTestId("checkout-status").textContent).toBe(PLACING);
  });

  describe("the focus after a refusal (F2)", () => {
    const INVALID: PlaceOrderResult = { status: "invalid", violations: [{ propertyPath: "phone", title: "Podaj 9-cyfrowy numer telefonu." }] };
    const alert = () => screen.getByRole("alert");

    /** Sends the order with Enter from „Miejscowość” (the focus is on the field), held. */
    async function sendFromTheCity(): Promise<(result: PlaceOrderResult) => Promise<void>> {
      await renderCheckout();
      fillGuestForm();
      const answer = holdOrder();
      field("Miejscowość").focus();
      await act(async () => {
        fireEvent.submit(form());
      });
      expect(document.activeElement).toBe(field("Miejscowość"));
      return answer;
    }

    /** Sends the order with a click on the focused button, held. */
    async function sendFromTheButton(): Promise<(result: PlaceOrderResult) => Promise<void>> {
      await renderCheckout();
      fillGuestForm();
      const answer = holdOrder();
      submitButton().focus();
      await act(async () => {
        fireEvent.click(submitButton());
      });
      expect(document.activeElement).toBe(submitButton());
      return answer;
    }

    it.each([
      ["Enter in „Miejscowość”", sendFromTheCity],
      ["the button", sendFromTheButton],
    ])("sent with %s, moves the focus to the first wrong field after a 422 when it stayed there", async (_name, send) => {
      const answer = await send();
      await answer(INVALID);

      expect(document.activeElement).toBe(field("Telefon"));
    });

    it.each([
      ["Enter in „Miejscowość”", sendFromTheCity],
      ["the button", sendFromTheButton],
    ])("sent with %s, moves the focus to the message after a failure when it stayed there", async (_name, send) => {
      const answer = await send();
      await answer({ status: "failed" });

      expect(document.activeElement).toBe(alert());
    });

    it.each<[string, () => Promise<(result: PlaceOrderResult) => Promise<void>>, PlaceOrderResult]>([
      ["Enter in „Miejscowość”, a 422", sendFromTheCity, INVALID],
      ["Enter in „Miejscowość”, a failure", sendFromTheCity, { status: "failed" }],
      ["the button, a 422", sendFromTheButton, INVALID],
      ["the button, a failure", sendFromTheButton, { status: "failed" }],
    ])("sent with %s, leaves the focus in the field the customer moved to; the message is an alert", async (_name, send, result) => {
      const answer = await send();
      field("Kod pocztowy").focus();
      await answer(result);

      expect(document.activeElement).toBe(field("Kod pocztowy"));
      if (result.status === "failed") {
        expect(alert().textContent).toBe(SUBMIT_FAILED);
      } else {
        // The focus does not tell about the error of the field, so the error itself is announced.
        expect(field("Telefon").getAttribute("aria-invalid")).toBe("true");
        expect(alert().textContent).toBe("Podaj 9-cyfrowy numer telefonu.");
        expect(alert().id).toBe(field("Telefon").getAttribute("aria-describedby"));
      }
    });

    it.each([
      ["Enter in „Miejscowość”", sendFromTheCity],
      ["the button", sendFromTheButton],
    ])("sent with %s, announces a 422 of the provider when the customer moved the focus; the focus stays", async (_name, send) => {
      const answer = await send();
      field("Kod pocztowy").focus();
      await answer({ status: "invalid", violations: [{ propertyPath: "provider", title: "Wybierz sposób płatności." }] });

      expect(document.activeElement).toBe(field("Kod pocztowy"));
      const group = screen.getByRole("radiogroup", { name: "Sposób płatności" });
      expect(alert().textContent).toBe("Wybierz sposób płatności.");
      expect(group.getAttribute("aria-describedby")).toBe(alert().id);
    });

    it("announces every error of the fields of a 422 when the customer moved the focus", async () => {
      const answer = await sendFromTheCity();
      field("Kod pocztowy").focus();
      await answer({
        status: "invalid",
        violations: [
          { propertyPath: "phone", title: "Podaj 9-cyfrowy numer telefonu." },
          { propertyPath: "postalCode", title: "Podaj kod pocztowy w formacie 00-000." },
        ],
      });

      expect(screen.getAllByRole("alert").map((node) => node.textContent)).toEqual(["Podaj 9-cyfrowy numer telefonu.", "Podaj kod pocztowy w formacie 00-000."]);
      expect(document.activeElement).toBe(field("Kod pocztowy"));
    });

    it.each([
      ["Enter in „Miejscowość”", sendFromTheCity],
      ["the button", sendFromTheButton],
    ])("sent with %s, does not announce the errors of a 422 a second time when the focus goes to the first wrong field", async (_name, send) => {
      const answer = await send();
      await answer(INVALID);

      expect(document.activeElement).toBe(field("Telefon"));
      expect(screen.queryByRole("alert")).toBeNull();
    });

    it("announces the errors of a 422 only for that answer: a later refusal with the focus kept says them without an alert", async () => {
      const answer = await sendFromTheButton();
      field("Kod pocztowy").focus();
      await answer(INVALID);
      expect(alert().textContent).toBe("Podaj 9-cyfrowy numer telefonu.");

      const again = holdOrder();
      submitButton().focus();
      await act(async () => {
        fireEvent.click(submitButton());
      });
      await again(INVALID);

      expect(document.activeElement).toBe(field("Telefon"));
      expect(screen.queryByRole("alert")).toBeNull();
    });

    it("moves the focus to the message when it was lost during the request", async () => {
      const answer = await sendFromTheButton();
      submitButton().blur();
      expect(document.activeElement).toBe(document.body);
      await answer({ status: "failed" });

      expect(document.activeElement).toBe(alert());
    });
  });

  describe("both choices missing (#345)", () => {
    const CHOOSE_DELIVERY = "Wybierz sposób dostawy.";
    const CHOOSE_PROVIDER = "Wybierz sposób płatności.";
    const providerGroup = () => screen.getByRole("radiogroup", { name: "Sposób płatności" });

    it("shows both messages with one click, marks both groups and puts the focus on the first method; nothing is ordered", async () => {
      await renderCheckout(twoMethods(), null, undefined, false);
      await act(async () => {
        fireEvent.click(submitButton());
      });

      expect(placeOrder).not.toHaveBeenCalled();
      const deliveryMessage = within(deliveryGroup()).getByText(CHOOSE_DELIVERY);
      const providerMessage = within(providerGroup()).getByText(CHOOSE_PROVIDER);
      expect(deliveryGroup().getAttribute("aria-invalid")).toBe("true");
      expect(deliveryGroup().getAttribute("aria-describedby")).toBe(deliveryMessage.id);
      expect(providerGroup().getAttribute("aria-invalid")).toBe("true");
      expect(providerGroup().getAttribute("aria-describedby")).toBe(providerMessage.id);
      expect(document.activeElement).toBe(deliveryRadio("Kurier standardowy"));
    });

    it("with only the provider missing asks for it alone, with the focus on its first option", async () => {
      await renderCheckout(twoMethods());
      await act(async () => {
        fireEvent.click(submitButton());
      });

      expect(placeOrder).not.toHaveBeenCalled();
      expect(screen.queryByText(CHOOSE_DELIVERY)).toBeNull();
      expect(deliveryGroup().getAttribute("aria-invalid")).toBeNull();
      expect(within(providerGroup()).getByText(CHOOSE_PROVIDER)).toBeTruthy();
      expect(document.activeElement).toBe(providerRadio("Szybki przelew (demo)"));
    });

    it("with only the method missing asks for it alone, with the focus on its first option", async () => {
      await renderCheckout(twoMethods(), null, undefined, false);
      choose("Szybki przelew (demo)");
      await act(async () => {
        fireEvent.click(submitButton());
      });

      expect(placeOrder).not.toHaveBeenCalled();
      expect(within(deliveryGroup()).getByText(CHOOSE_DELIVERY)).toBeTruthy();
      expect(screen.queryByText(CHOOSE_PROVIDER)).toBeNull();
      expect(providerGroup().getAttribute("aria-invalid")).toBeNull();
      expect(document.activeElement).toBe(deliveryRadio("Kurier standardowy"));
    });
  });
});

describe("CheckoutPage delivery method", () => {
  const CHOOSE_DELIVERY = "Wybierz sposób dostawy.";
  const UNAVAILABLE = "Wybrany sposób dostawy jest niedostępny. Wybierz inny.";
  /** Two methods: a paid one with a threshold and an amount missing to it, and a free one. The totals are the API's. */
  const twoMethods = (): CartView => ({
    ...cart(),
    delivery: {
      fromGross: 0,
      missingToFreeGross: null,
      options: [
        option(STANDARD, "Kurier standardowy", 1999, 41997, { freeFromGross: 50000, missingToFreeGross: 10002 }),
        option(EXPRESS, "Kurier ekspresowy", 0, 39998, { description: "Dostawa następnego dnia roboczego" }),
      ],
    },
  });
  const summary = () => screen.getByRole("region", { name: "Podsumowanie zamówienia" });

  it("offers the methods of the cart in a group „Sposób dostawy” with a label of each: name, description, cost or „Darmowa” and the amount missing to free delivery", async () => {
    await renderCheckout(twoMethods(), null, undefined, false);

    const fieldset = deliveryGroup();
    expect(fieldset.tagName).toBe("FIELDSET");
    expect(fieldset.querySelector("legend")?.textContent).toBe("Sposób dostawy");
    const radios = within(fieldset).getAllByRole("radio") as HTMLInputElement[];
    expect(radios.map((radio) => radio.checked)).toEqual([false, false]);
    expect(new Set(radios.map((radio) => radio.name)).size).toBe(1);
    const [paid, free] = radios.map((radio) => radio.labels?.[0]?.textContent ?? "");
    expect(paid).toContain("Kurier standardowy");
    expect(paid).toContain("Dostawa w 2–3 dni robocze");
    expect(paid).toContain(price(1999));
    expect(paid).toContain(`Do darmowej dostawy brakuje ${price(10002)}`);
    expect(paid).not.toContain("Darmowa");
    expect(free).toContain("Kurier ekspresowy");
    expect(free).toContain("Dostawa następnego dnia roboczego");
    expect(free).toContain("Darmowa");
    expect(free).not.toContain("Do darmowej dostawy brakuje");
  });

  it("without a choice says „Wybierz sposób dostawy” in the summary instead of the delivery and of the total", async () => {
    await renderCheckout(twoMethods(), null, undefined, false);

    expect(within(summary()).getByTestId("checkout-delivery").textContent).toBe("Wybierz sposób dostawy");
    expect(within(summary()).getByTestId("checkout-total").textContent).toBe("Wybierz sposób dostawy");
  });

  it("shows the cost of the chosen method and its total as the API gave them, „Darmowa” for a free one", async () => {
    await renderCheckout(twoMethods(), null, undefined, false);

    fireEvent.click(deliveryRadio("Kurier standardowy"));
    expect(within(summary()).getByTestId("checkout-delivery").textContent).toBe(price(1999));
    expect(within(summary()).getByTestId("checkout-total").textContent).toBe(price(41997));

    fireEvent.click(deliveryRadio("Kurier ekspresowy"));
    expect(within(summary()).getByTestId("checkout-delivery").textContent).toBe("Darmowa");
    expect(within(summary()).getByTestId("checkout-total").textContent).toBe(price(39998));
  });

  it("does not place the order without a method: the message at the group and the focus on its first option", async () => {
    await renderCheckout(twoMethods(), null, undefined, false);
    fillGuestForm();
    await act(async () => {
      fireEvent.click(submitButton());
    });

    expect(placeOrder).not.toHaveBeenCalled();
    const message = within(deliveryGroup()).getByText(CHOOSE_DELIVERY);
    expect(deliveryGroup().getAttribute("aria-describedby")).toBe(message.id);
    expect(document.activeElement).toBe(deliveryRadio("Kurier standardowy"));

    fireEvent.click(deliveryRadio("Kurier ekspresowy"));
    expect(screen.queryByText(CHOOSE_DELIVERY)).toBeNull();
  });

  it("sends the chosen method and its total as the expected total", async () => {
    await renderCheckout(twoMethods(), null, undefined, false);
    fillGuestForm();
    fireEvent.click(deliveryRadio("Kurier standardowy"));
    await submit({ status: "ok", number: "R-100001", redirectUrl: null });

    expect(placeOrder).toHaveBeenCalledWith({ ...GUEST_INPUT, deliveryMethodId: STANDARD, expectedTotalGross: 41997 }, expect.any(String));
  });

  it("makes a new key when another method is chosen", async () => {
    await renderCheckout(twoMethods(), null, undefined, false);
    fillGuestForm();
    fireEvent.click(deliveryRadio("Kurier standardowy"));
    await submit({ status: "failed" });
    fireEvent.click(deliveryRadio("Kurier ekspresowy"));
    await submit({ status: "failed" });

    const [first, second] = vi.mocked(placeOrder).mock.calls;
    expect(second[1]).not.toBe(first[1]);
    expect(second[0]).toMatchObject({ deliveryMethodId: EXPRESS, expectedTotalGross: 39998 });
  });

  it("works from the keyboard: the methods are radios of one group with labels", async () => {
    await renderCheckout(twoMethods(), null, undefined, false);

    for (const radio of within(deliveryGroup()).getAllByRole("radio") as HTMLInputElement[]) {
      expect(radio.type).toBe("radio");
      expect(radio.disabled).toBe(false);
      expect(radio.labels).toHaveLength(1);
    }
  });

  it("has no delivery section for a cart without anything to buy and keeps the message of the empty cart", async () => {
    await renderCheckout(cart(0, []), null, undefined, false);

    expect(screen.queryByRole("group", { name: "Sposób dostawy" })).toBeNull();
    expect(screen.getByText("Twój koszyk jest pusty.")).toBeTruthy();
  });

  it("on a 409 of an unavailable method shows its detail above the form, reads the methods again and makes a new key; a method that is gone is no longer chosen", async () => {
    await renderCheckout(twoMethods(), null, undefined, false);
    fillGuestForm();
    fireEvent.click(deliveryRadio("Kurier standardowy"));
    const withoutStandard: CartView = { ...twoMethods(), delivery: delivery([option(EXPRESS, "Kurier ekspresowy", 0, 39998)]) };
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: withoutStandard });
    await submit({ status: "conflict", reason: "delivery_method_unavailable", detail: UNAVAILABLE });

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain(UNAVAILABLE);
    expect(document.activeElement).toBe(alert);
    expect(within(deliveryGroup()).getAllByRole("radio")).toHaveLength(1);
    expect(deliveryRadio("Kurier ekspresowy").checked).toBe(false);
    expect(within(summary()).getByTestId("checkout-total").textContent).toBe("Wybierz sposób dostawy");

    fireEvent.click(deliveryRadio("Kurier ekspresowy"));
    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    const [first, second] = vi.mocked(placeOrder).mock.calls;
    expect(second[1]).not.toBe(first[1]);
    expect(second[0]).toMatchObject({ deliveryMethodId: EXPRESS, expectedTotalGross: 39998 });
  });

  it("shows a 422 at the delivery method (a checkout from before the delivery) in the message above the form", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({
      status: "invalid",
      violations: [{ propertyPath: "deliveryMethodId", title: "Kasa została zaktualizowana. Odśwież stronę i wybierz sposób dostawy." }],
    });

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain("Kasa została zaktualizowana. Odśwież stronę i wybierz sposób dostawy.");
    expect(document.activeElement).toBe(alert);
  });
});

describe("CheckoutPage delivery states", () => {
  const UNAVAILABLE = "Wybrany sposób dostawy jest niedostępny. Wybierz inny.";
  const NO_METHODS = "Nie możemy teraz przyjąć zamówienia: brak dostępnych sposobów dostawy. Spróbuj później.";
  const summary = () => screen.getByRole("region", { name: "Podsumowanie zamówienia" });
  const live = () => within(summary()).getByTestId("checkout-summary-status");
  const codeField = () => screen.getByLabelText("Kod rabatowy") as HTMLInputElement;

  /** The goods are 500,00 zł; the standard method is free from 300,00 zł, the express one never. Totals are the API's. */
  const above = (): CartView => ({
    ...cart(50000, [line(A, "Buty trailowe", "czarne, 42", 1, 50000)]),
    delivery: delivery([
      option(STANDARD, "Kurier standardowy", 0, 50000, { freeFromGross: 30000 }),
      option(EXPRESS, "Kurier ekspresowy", 3999, 53999, { description: "Dostawa następnego dnia roboczego" }),
    ]),
  });
  /** The same cart after a code that takes 250,00 zł off: the goods are below the threshold, the delivery is paid. */
  const below = (): CartView => ({
    ...cart(25000, [line(A, "Buty trailowe", "czarne, 42", 1, 50000)]),
    totals: { subtotalGross: 50000, discountGross: 25000, gross: 25000, currency: "PLN", itemCount: 1 },
    discount: { status: "applied", code: "POL50", amountGross: 25000, kind: "percent", value: 50 },
    delivery: delivery([
      option(STANDARD, "Kurier standardowy", 1999, 26999, { freeFromGross: 30000, missingToFreeGross: 5000 }),
      option(EXPRESS, "Kurier ekspresowy", 3999, 28999, { description: "Dostawa następnego dnia roboczego" }),
    ]),
  });

  async function applyCode(view: CartView) {
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: view });
    fireEvent.change(codeField(), { target: { value: "pol50" } });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Zastosuj" }));
    });
  }
  async function removeCode(view: CartView) {
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: view });
    await act(async () => {
      fireEvent.click(screen.getByRole("button", { name: "Usuń kod" }));
    });
  }

  it("chooses the only method itself, so the order can be placed without a click on it", async () => {
    await renderCheckout(cart(), null, undefined, false);

    expect(deliveryRadio("Kurier standardowy").checked).toBe(true);
    expect(within(summary()).getByTestId("checkout-delivery").textContent).toBe("Darmowa");
    expect(within(summary()).getByTestId("checkout-total").textContent).toBe(price(39998));
    fillGuestForm();
    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    expect(placeOrder).toHaveBeenCalledWith(GUEST_INPUT, expect.any(String));
  });

  it("chooses nothing among several methods", async () => {
    await renderCheckout(above(), null, undefined, false);

    expect((within(deliveryGroup()).getAllByRole("radio") as HTMLInputElement[]).map((radio) => radio.checked)).toEqual([false, false]);
  });

  it("keeps the chosen method when the summary is read again with a code and the method is still offered", async () => {
    await renderCheckout(above(), null, undefined, false);
    fireEvent.click(deliveryRadio("Kurier ekspresowy"));
    await applyCode(below());

    expect(deliveryRadio("Kurier ekspresowy").checked).toBe(true);
    expect(deliveryRadio("Kurier standardowy").checked).toBe(false);
    expect(within(summary()).getByTestId("checkout-delivery").textContent).toBe(price(3999));
    expect(within(summary()).getByTestId("checkout-total").textContent).toBe(price(28999));
  });

  it("drops the chosen method when a code reads the summary without it, and does not choose it again when it is offered later", async () => {
    await renderCheckout(above(), null, undefined, false);
    fireEvent.click(deliveryRadio("Kurier standardowy"));
    const withoutStandard: CartView = { ...below(), delivery: delivery([option(EXPRESS, "Kurier ekspresowy", 3999, 28999)]) };
    await applyCode(withoutStandard);

    expect(within(deliveryGroup()).getAllByRole("radio")).toHaveLength(1);
    expect(deliveryRadio("Kurier ekspresowy").checked).toBe(false);
    expect(within(summary()).getByTestId("checkout-total").textContent).toBe("Wybierz sposób dostawy");

    await removeCode(above());
    expect((within(deliveryGroup()).getAllByRole("radio") as HTMLInputElement[]).map((radio) => radio.checked)).toEqual([false, false]);
    expect(within(summary()).getByTestId("checkout-total").textContent).toBe("Wybierz sposób dostawy");
  });

  it("shows the lost free delivery at once in the options and the summary and announces it in the live region of the summary", async () => {
    await renderCheckout(above(), null, undefined, false);
    fireEvent.click(deliveryRadio("Kurier standardowy"));
    expect(live().textContent).toBe("");
    expect(live().getAttribute("role")).toBe("status");
    expect(within(summary()).getByTestId("checkout-delivery").textContent).toBe("Darmowa");

    await applyCode(below());

    const label = deliveryRadio("Kurier standardowy").labels?.[0]?.textContent ?? "";
    expect(label).toContain(price(1999));
    expect(label).toContain(`Do darmowej dostawy brakuje ${price(5000)}`);
    expect(label).not.toContain("Darmowa");
    expect(within(summary()).getByTestId("checkout-delivery").textContent).toBe(price(1999));
    expect(within(summary()).getByTestId("checkout-total").textContent).toBe(price(26999));
    expect(live().textContent).toBe(`Dostawa „Kurier standardowy” nie jest już darmowa, kosztuje ${price(1999)}. Do darmowej dostawy brakuje ${price(5000)}.`);
  });

  it("announces that a method became free again when the code is taken off", async () => {
    await renderCheckout(above(), null, undefined, false);
    fireEvent.click(deliveryRadio("Kurier standardowy"));
    await applyCode(below());
    await removeCode(above());

    expect(within(summary()).getByTestId("checkout-delivery").textContent).toBe("Darmowa");
    expect(live().textContent).toBe("Dostawa „Kurier standardowy” jest teraz darmowa.");
  });

  it("says nothing in the live region when the delivery of the chosen method did not change", async () => {
    await renderCheckout(above(), null, undefined, false);
    fireEvent.click(deliveryRadio("Kurier ekspresowy"));
    await applyCode(below());

    expect(live().textContent).toBe("");
  });

  it("announces the lost free delivery after a 409 of the total, which reads the summary again", async () => {
    await renderCheckout(above(), null, undefined, false);
    fillGuestForm();
    fireEvent.click(deliveryRadio("Kurier standardowy"));
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: below() });
    await submit({ status: "conflict", reason: "total_changed", detail: "Cena w koszyku zmieniła się." });

    expect(live().textContent).toContain("Dostawa „Kurier standardowy” nie jest już darmowa");
    expect(within(summary()).getByTestId("checkout-total").textContent).toBe(price(26999));
  });

  it("shows „Darmowa” and no amount missing to free delivery for an option with the cost 0", async () => {
    const view: CartView = { ...cart(), delivery: delivery([option(STANDARD, "Kurier standardowy", 0, 39998, { freeFromGross: 30000, missingToFreeGross: 5000 })]) };
    await renderCheckout(view, null, undefined, false);

    const label = deliveryRadio("Kurier standardowy").labels?.[0]?.textContent ?? "";
    expect(label).toContain("Darmowa");
    expect(label).not.toContain("Do darmowej dostawy brakuje");
  });

  it.each([
    ["no active delivery method", (): CartView => ({ ...cart(), delivery: delivery([]) })],
    ["an answer without delivery (the API from before it)", (): CartView => ({ ...cart(), delivery: undefined as unknown as CartView["delivery"] })],
  ])("with %s says that the order cannot be placed and locks the button", async (_name, view) => {
    await renderCheckout(view(), null, undefined, false);

    const alert = screen.getByText(NO_METHODS);
    expect(alert.closest("[data-slot='alert']")).not.toBeNull();
    expect(screen.queryByRole("group", { name: "Sposób dostawy" })).toBeNull();
    expect(submitButton().disabled).toBe(true);
    fillGuestForm();
    await act(async () => {
      fireEvent.submit(submitButton().form as HTMLFormElement);
    });
    expect(placeOrder).not.toHaveBeenCalled();
  });

  it("has no alert about the methods for a cart without anything to buy", async () => {
    await renderCheckout(cart(0, []), null, undefined, false);

    expect(screen.queryByText(NO_METHODS)).toBeNull();
  });

  it("after a 409 of an unavailable method keeps the message focused and the key new, and does not choose the method that is gone when it comes back", async () => {
    await renderCheckout(above(), null, undefined, false);
    fillGuestForm();
    fireEvent.click(deliveryRadio("Kurier standardowy"));
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: { ...above(), delivery: delivery([option(EXPRESS, "Kurier ekspresowy", 3999, 53999)]) } });
    await submit({ status: "conflict", reason: "delivery_method_unavailable", detail: UNAVAILABLE });

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain(UNAVAILABLE);
    expect(document.activeElement).toBe(alert);
    expect(within(summary()).getByTestId("checkout-total").textContent).toBe("Wybierz sposób dostawy");

    await applyCode(below());
    expect((within(deliveryGroup()).getAllByRole("radio") as HTMLInputElement[]).map((radio) => radio.checked)).toEqual([false, false]);
    fireEvent.click(deliveryRadio("Kurier ekspresowy"));
    await submit({ status: "failed" });
    const [first, second] = vi.mocked(placeOrder).mock.calls;
    expect(second[1]).not.toBe(first[1]);
  });
});

describe("CheckoutPage payment provider", () => {
  const group = () => screen.getByRole("radiogroup", { name: "Sposób płatności" });
  const CHOOSE = "Wybierz sposób płatności.";

  it("offers the providers of the API in a required group with no default", async () => {
    await renderCheckout();

    const radios = within(group()).getAllByRole("radio") as HTMLInputElement[];
    expect(radios.map((radio) => radio.labels?.[0]?.textContent)).toEqual(["Szybki przelew (demo)", "Karta płatnicza (demo)"]);
    expect(radios.map((radio) => radio.checked)).toEqual([false, false]);
    expect(group().getAttribute("aria-required")).toBe("true");
  });

  it("does not place the order and says what is missing when no provider is chosen", async () => {
    await renderCheckout();
    type("E-mail", "anna.nowak@example.com");
    type("Imię i nazwisko", "Anna Nowak");
    type("Telefon", "600 100 200");
    type("Ulica i numer", "ul. Długa 5/3");
    type("Kod pocztowy", "00-950");
    type("Miejscowość", "Warszawa");
    await act(async () => {
      fireEvent.click(submitButton());
    });

    expect(placeOrder).not.toHaveBeenCalled();
    expect(screen.getByText(CHOOSE)).toBeTruthy();
    expect(group().getAttribute("aria-invalid")).toBe("true");
    expect(group().getAttribute("aria-describedby")).toBe(screen.getByText(CHOOSE).id);
    expect(document.activeElement).toBe(within(group()).getAllByRole("radio")[0]);
    expect(submitButton().disabled).toBe(false);
  });

  it("clears the message when a provider is chosen and sends the choice", async () => {
    await renderCheckout();
    fillGuestForm();
    choose("Karta płatnicza (demo)");
    await submit({ status: "ok", number: "R-100001", redirectUrl: null });

    expect(vi.mocked(placeOrder).mock.calls[0][0].provider).toBe("demo-card");
    expect(screen.queryByText(CHOOSE)).toBeNull();
  });

  it("goes to the page of the provider when the order has an address, and keeps the button locked", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({ status: "ok", number: "R-100001", redirectUrl: REDIRECT_URL });

    expect(navigateTo).toHaveBeenCalledWith(REDIRECT_URL);
    expect(push).not.toHaveBeenCalled();
    expect(announced).toEqual([0]);
    expect(submitButton().getAttribute("aria-disabled")).toBe("true");
    expect(field("Telefon").readOnly).toBe(true);
  });

  it("goes to the confirmation page when the payment could not be started", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({ status: "ok", number: "R-100001", redirectUrl: null });

    expect(push).toHaveBeenCalledWith("/checkout/confirmation");
    expect(navigateTo).not.toHaveBeenCalled();
  });

  it("keeps the key of the order when the provider changes between two clicks", async () => {
    await renderCheckout();
    fillGuestForm();
    const formKey = key(keys);
    await submit({ status: "failed" });
    choose("Karta płatnicza (demo)");
    await submit({ status: "ok", number: "R-100001", redirectUrl: null });

    expect(vi.mocked(placeOrder).mock.calls.map((call) => call[1])).toEqual([formKey, formKey]);
    expect(vi.mocked(placeOrder).mock.calls.map((call) => call[0].provider)).toEqual(["demo-transfer", "demo-card"]);
  });

  it("shows a 422 for the provider at the group", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({ status: "invalid", violations: [{ propertyPath: "provider", title: CHOOSE }] });

    expect(screen.getByText(CHOOSE).id).toBe(group().getAttribute("aria-describedby"));
    expect(screen.queryByRole("alert")).toBeNull();
    expect(document.activeElement).toBe(within(group()).getAllByRole("radio")[0]);
  });

  it.each([
    ["could not be read", null],
    ["is empty", []],
  ])("says payments are unavailable instead of the button when the list of providers %s", async (_name, providers) => {
    await renderCheckout(cart(), null, { providers, pendingOrderNumber: null });

    expect(screen.getByText("Płatności są chwilowo niedostępne. Spróbuj za chwilę.")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeNull();
    expect(screen.queryByRole("radiogroup")).toBeNull();
  });
});

describe("CheckoutPage with an order waiting for its payment", () => {
  it("offers to pay the order next to the message about the empty cart", async () => {
    await renderCheckout(cart(0, []), null, { providers: PROVIDERS, pendingOrderNumber: "R-100001" });

    expect(screen.getByText("Twój koszyk jest pusty.")).toBeTruthy();
    expect(screen.getByRole("link", { name: "Opłać zamówienie R-100001" }).getAttribute("href")).toBe("/checkout/confirmation");
  });

  it("does not offer it without such an order", async () => {
    await renderCheckout(cart(0, []));

    expect(screen.queryByRole("link", { name: /^Opłać zamówienie/ })).toBeNull();
  });

  it("does not offer it above a cart that can be ordered", async () => {
    await renderCheckout(cart(), null, { providers: PROVIDERS, pendingOrderNumber: "R-100001" });

    expect(screen.queryByRole("link", { name: /^Opłać zamówienie/ })).toBeNull();
  });
});

describe("CheckoutPage discount code", () => {
  const REJECTIONS = [
    ["invalid", "Ten kod jest nieprawidłowy albo nieaktywny.", null],
    ["used_up", "Ten kod został już wykorzystany.", null],
    ["below_minimum", "Ten kod działa przy zamówieniu od 300,00 zł.", 30000],
  ] as const;

  const applied = (code: string, amountGross: number): NonNullable<CartView["discount"]> => ({
    status: "applied",
    code,
    amountGross,
    kind: "percent",
    value: 10,
  });
  const rejected = (code: string, reason: "invalid" | "used_up" | "below_minimum", detail: string, minimumOrderGross: number | null) => ({
    status: "rejected" as const,
    code,
    reason,
    detail,
    minimumOrderGross,
  });
  /** The cart of the API with a code: the lines as they are, the totals after the discount. */
  const discounted = (code: string, subtotal: number, amount: number): CartView => ({
    ...cart(subtotal - amount),
    totals: { subtotalGross: subtotal, discountGross: amount, gross: subtotal - amount, currency: "PLN", itemCount: 2 },
    discount: applied(code, amount),
  });
  const withRejection = (reason: "invalid" | "used_up" | "below_minimum", detail: string, minimum: number | null): CartView => ({
    ...cart(),
    discount: rejected("LATO15", reason, detail, minimum),
  });

  const codeField = () => screen.getByLabelText("Kod rabatowy") as HTMLInputElement;
  const applyButton = () => screen.getByRole("button", { name: "Zastosuj" }) as HTMLButtonElement;
  const removeButton = () => screen.getByRole("button", { name: "Usuń kod" });
  const total = () => screen.getByTestId("checkout-total").textContent;

  async function apply(code: string, answer: Awaited<ReturnType<typeof loadCart>>) {
    vi.mocked(loadCart).mockResolvedValueOnce(answer);
    fireEvent.change(codeField(), { target: { value: code } });
    await act(async () => {
      fireEvent.click(applyButton());
    });
  }
  const applyOk = (code: string, view: CartView) => apply(code, { status: "ok", data: view });

  it("offers the field below the summary, labelled, limited to 64 characters, with the button „Zastosuj”", async () => {
    await renderCheckout();

    const input = codeField();
    expect(input.maxLength).toBe(64);
    expect(input.value).toBe("");
    expect(applyButton().type).toBe("submit");
    const summary = screen.getByRole("region", { name: "Podsumowanie zamówienia" });
    expect(summary.compareDocumentPosition(input) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Usuń kod" })).toBeNull();
  });

  it.each([
    ["an empty cart", cart(0, [])],
    ["excluded lines", cart(1000, [line(A, "Buty", "", 1, 1000), line(B, "Baton", "", 1, 0, { state: "no_price", unitPrice: null, lineTotal: null })], true)],
  ])("has no field with %s", async (_name, view) => {
    await renderCheckout(view);

    expect(screen.queryByLabelText("Kod rabatowy")).toBeNull();
    expect(screen.queryByRole("button", { name: "Zastosuj" })).toBeNull();
  });

  it("applies a code by reloading the summary with it: the discount line, the total after the discount, „Usuń kod” instead of the field", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));

    expect(loadCart).toHaveBeenLastCalledWith("witaj10");
    expect(screen.getByTestId("checkout-discount").textContent).toBe(`Kod WITAJ10: −${price(4000)}`);
    expect(total()).toBe(price(35998));
    expect(screen.getByTestId("checkout-line-total").textContent).toBe(price(39998));
    expect(screen.queryByLabelText("Kod rabatowy")).toBeNull();
    expect(document.activeElement).toBe(removeButton());
    expect(screen.queryByRole("alert")).toBeNull();
  });

  it("trims the code before it is sent", async () => {
    await renderCheckout();
    await applyOk("  WITAJ10 ", discounted("WITAJ10", 39998, 4000));

    expect(loadCart).toHaveBeenLastCalledWith("WITAJ10");
  });

  it("applies the code with the Enter key of the field and does not order", async () => {
    await renderCheckout();
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: discounted("WITAJ10", 39998, 4000) });
    fireEvent.change(codeField(), { target: { value: "WITAJ10" } });
    await act(async () => {
      fireEvent.submit(codeField().closest("form") as HTMLFormElement);
    });

    expect(screen.getByTestId("checkout-discount")).toBeTruthy();
    expect(placeOrder).not.toHaveBeenCalled();
  });

  it("asks for a code without a request when the field is empty or blank", async () => {
    await renderCheckout();
    fireEvent.change(codeField(), { target: { value: "   " } });
    await act(async () => {
      fireEvent.click(applyButton());
    });

    expect(screen.getByRole("alert").textContent).toBe("Wpisz kod rabatowy.");
    expect(loadCart).toHaveBeenCalledTimes(1);
    expect(document.activeElement).toBe(codeField());
  });

  it("asks for a code, like for an empty field, when the API finds the code empty after its normalisation", async () => {
    await renderCheckout();
    await applyOk("\u200B", cart());

    expect(screen.getByRole("alert").textContent).toBe("Wpisz kod rabatowy.");
    expect(screen.queryByTestId("checkout-discount")).toBeNull();
    expect(total()).toBe(price(39998));
    expect(document.activeElement).toBe(codeField());
  });

  it.each(REJECTIONS)("shows the detail of a code rejected as %s at the field and the summary without a discount", async (reason, detail, minimum) => {
    await renderCheckout();
    await applyOk("lato15", withRejection(reason, detail, minimum));

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toBe(detail);
    expect(codeField().getAttribute("aria-invalid")).toBe("true");
    expect(codeField().getAttribute("aria-describedby")).toBe(alert.id);
    expect(codeField().value).toBe("lato15");
    expect(document.activeElement).toBe(codeField());
    expect(screen.queryByTestId("checkout-discount")).toBeNull();
    expect(screen.queryByRole("button", { name: "Usuń kod" })).toBeNull();
    expect(total()).toBe(price(39998));
  });

  it("clears the message at the field when the customer types another code", async () => {
    await renderCheckout();
    await applyOk("lato15", withRejection("invalid", "Ten kod jest nieprawidłowy albo nieaktywny.", null));
    fireEvent.change(codeField(), { target: { value: "lato1" } });

    expect(screen.queryByRole("alert")).toBeNull();
    expect(codeField().getAttribute("aria-invalid")).toBeNull();
  });

  it("shows the message of a 400 (a code that is too long) at the field and the summary without a code", async () => {
    await renderCheckout();
    await apply("A".repeat(65), { status: "invalid", detail: "Kod rabatowy może mieć najwyżej 64 znaki." });

    expect(screen.getByRole("alert").textContent).toBe("Kod rabatowy może mieć najwyżej 64 znaki.");
    expect(screen.queryByTestId("checkout-discount")).toBeNull();
    expect(total()).toBe(price(39998));
    // The summary is read again without the code, so it is the summary of no code whatever it was before.
    expect(loadCart).toHaveBeenLastCalledWith();
  });

  it("shows the text of the limit of code checks at the field after a 429, reads the summary without the code and keeps the form", async () => {
    await renderCheckout();
    await apply("lato15", { status: "rate_limited" });

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toBe("Zbyt wiele prób sprawdzenia kodu. Spróbuj ponownie za kilka minut.");
    expect(codeField().getAttribute("aria-describedby")).toBe(alert.id);
    expect(codeField().value).toBe("lato15");
    expect(document.activeElement).toBe(codeField());
    expect(loadCart).toHaveBeenLastCalledWith();
    expect(screen.queryByTestId("checkout-discount")).toBeNull();
    expect(total()).toBe(price(39998));
  });

  it("says the code could not be checked when the API is down, and keeps the summary", async () => {
    await renderCheckout();
    await apply("WITAJ10", { status: "failed" });

    expect(screen.getByRole("alert").textContent).toBe("Nie udało się sprawdzić kodu. Spróbuj ponownie.");
    expect(total()).toBe(price(39998));
    expect(applyButton().disabled).toBe(false);
  });

  it("says the session expired when the preview answers 401", async () => {
    await renderCheckout(cart(), JAN);
    await apply("WITAJ10", { status: "unauthorized" });

    expect(screen.getByRole("alert").textContent).toContain("Twoja sesja wygasła");
  });

  it("removes the code: the summary is read again without it, the field is back, empty and focused", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
    await act(async () => {
      fireEvent.click(removeButton());
    });

    expect(loadCart).toHaveBeenLastCalledWith();
    expect(screen.queryByTestId("checkout-discount")).toBeNull();
    expect(total()).toBe(price(39998));
    expect(codeField().value).toBe("");
    expect(document.activeElement).toBe(codeField());
  });

  it("keeps the code when it cannot be removed", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" });
    await act(async () => {
      fireEvent.click(removeButton());
    });

    expect(screen.getByTestId("checkout-discount")).toBeTruthy();
    expect(screen.getByRole("alert").textContent).toBe("Nie udało się odświeżyć podsumowania. Spróbuj ponownie.");
    expect(total()).toBe(price(35998));
  });

  it("announces the application and the removal of a code in a live region", async () => {
    await renderCheckout();
    const live = () => {
      const region = screen.getByTestId("checkout-code-status");
      expect(region.getAttribute("role")).toBe("status");
      return region.textContent;
    };
    expect(live()).toBe("");
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    expect(live()).toBe("Zastosowano kod WITAJ10.");
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
    await act(async () => {
      fireEvent.click(removeButton());
    });
    expect(live()).toBe("Usunięto kod rabatowy.");
  });

  it("orders with the applied code and the total after the discount", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    fillGuestForm();
    await submit({ status: "ok", number: "R-100001", redirectUrl: REDIRECT_URL });

    expect(vi.mocked(placeOrder).mock.calls[0][0]).toEqual({ ...GUEST_INPUT, expectedTotalGross: 35998, discountCode: "witaj10" });
  });

  it("orders without a code field in the request when no code is applied, and after a rejected one", async () => {
    await renderCheckout();
    await applyOk("lato15", withRejection("invalid", "Ten kod jest nieprawidłowy albo nieaktywny.", null));
    fillGuestForm();
    await submit({ status: "ok", number: "R-100001", redirectUrl: REDIRECT_URL });

    expect(vi.mocked(placeOrder).mock.calls[0][0]).toEqual(GUEST_INPUT);
    expect("discountCode" in vi.mocked(placeOrder).mock.calls[0][0]).toBe(false);
  });

  it("sends the lines of the summary read with the code, and of the one read after it is removed", async () => {
    await renderCheckout();
    await applyOk("witaj10", { ...discounted("WITAJ10", 59997, 6000), lines: [line(A, "Buty trailowe", "czarne, 42", 3, 59997)] });
    fillGuestForm();
    await submit({ status: "failed" });
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart(19999, [line(A, "Buty trailowe", "czarne, 42", 1, 19999)]) });
    await act(async () => {
      fireEvent.click(removeButton());
    });
    await submit({ status: "failed" });

    const [withCode, withoutCode] = vi.mocked(placeOrder).mock.calls;
    expect(withCode[0]).toMatchObject({ discountCode: "witaj10", expectedLines: [{ variantId: A, quantity: 3 }] });
    expect(withoutCode[0].expectedLines).toEqual([{ variantId: A, quantity: 1 }]);
  });

  it("orders without the code after it is removed", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
    await act(async () => {
      fireEvent.click(removeButton());
    });
    fillGuestForm();
    await submit({ status: "ok", number: "R-100001", redirectUrl: REDIRECT_URL });

    expect(vi.mocked(placeOrder).mock.calls[0][0]).toEqual(GUEST_INPUT);
  });

  it("starts a new attempt (a new Idempotency-Key) when a code is applied and when it is removed", async () => {
    await renderCheckout();
    fillGuestForm();
    const before = key(keys);
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    const afterApply = key(keys);
    await submit({ status: "failed" });
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
    await act(async () => {
      fireEvent.click(removeButton());
    });
    const afterRemove = key(keys);
    await submit({ status: "failed" });

    expect(afterApply).not.toBe(before);
    expect(afterRemove).not.toBe(afterApply);
    expect(vi.mocked(placeOrder).mock.calls.map((call) => call[1])).toEqual([afterApply, afterRemove]);
  });

  it("does not start a new attempt when a code is only rejected (the request is as it was)", async () => {
    await renderCheckout();
    fillGuestForm();
    const before = key(keys);
    await applyOk("lato15", withRejection("used_up", "Ten kod został już wykorzystany.", null));
    await submit({ status: "failed" });

    expect(vi.mocked(placeOrder).mock.calls[0][1]).toBe(before);
  });

  describe.each(REJECTIONS)("when the order is refused with the 409 of a code rejected as %s", (reason, detail) => {
    async function refuse() {
      await renderCheckout();
      await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
      fillGuestForm();
      const used = key(keys);
      // The summary the checkout reads after the refusal, without the code.
      vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
      await submit({ status: "conflict", reason: "discount_code_rejected", detail });
      await act(async () => {});
      return used;
    }

    it("shows the detail at the field, takes the code off and reads the summary without it", async () => {
      await refuse();

      expect(screen.getByRole("alert").textContent).toBe(detail);
      expect(codeField().getAttribute("aria-describedby")).toBe(screen.getByRole("alert").id);
      expect(document.activeElement).toBe(codeField());
      expect(loadCart).toHaveBeenLastCalledWith();
      expect(screen.queryByTestId("checkout-discount")).toBeNull();
      expect(screen.queryByRole("button", { name: "Usuń kod" })).toBeNull();
      expect(total()).toBe(price(39998));
      expect(field("Telefon").value).toBe("600 100 200");
      expect(submitButton().disabled).toBe(false);
    });

    it("empties the live region, so it does not claim a code that is no longer on the order; the alert at the field announces the refusal", async () => {
      await refuse();

      expect(screen.getByTestId("checkout-code-status").textContent).toBe("");
      expect(screen.getByRole("alert").textContent).toBe(detail);
    });

    it("orders nothing by itself; the next click orders without the code, the new total and a new key", async () => {
      const used = await refuse();

      expect(placeOrder).toHaveBeenCalledTimes(1);
      expect(push).not.toHaveBeenCalled();
      await submit({ status: "ok", number: "R-100001", redirectUrl: null });

      const [first, second] = vi.mocked(placeOrder).mock.calls;
      expect(first[0]).toMatchObject({ discountCode: "witaj10", expectedTotalGross: 35998 });
      expect(second[0]).toEqual(GUEST_INPUT);
      expect(second[1]).not.toBe(used);
    });
  });

  describe("when the order is refused with the 409 of a rejected code and the summary cannot be read without it", () => {
    async function refuseUnread(read: Awaited<ReturnType<typeof loadCart>>, session = null as typeof JAN | null) {
      await renderCheckout(cart(), session);
      await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
      if (session === null) fillGuestForm();
      else fillContact();
      vi.mocked(loadCart).mockResolvedValueOnce(read);
      await submit({ status: "conflict", reason: "discount_code_rejected", detail: "Ten kod został już wykorzystany." });
      await act(async () => {});
    }

    it("keeps the screen consistent: the code stays applied with its line and total, the field does not come back", async () => {
      await refuseUnread({ status: "failed" });

      expect(screen.getByTestId("checkout-discount").textContent).toBe(`Kod WITAJ10: −${price(4000)}`);
      expect(total()).toBe(price(35998));
      expect(screen.queryByLabelText("Kod rabatowy")).toBeNull();
      expect(screen.getByRole("button", { name: "Usuń kod" })).toBeTruthy();
      expect(screen.getByRole("alert").textContent).toContain("Ten kod został już wykorzystany.");
      expect(document.activeElement).toBe(screen.getByRole("button", { name: "Usuń kod" }));
      expect(placeOrder).toHaveBeenCalledTimes(1);
    });

    it("keeps saying the code is applied in the live region, which is true", async () => {
      await refuseUnread({ status: "failed" });

      expect(screen.getByTestId("checkout-code-status").textContent).toBe("Zastosowano kod WITAJ10.");
    });

    it("says the session expired when the summary answers 401", async () => {
      await refuseUnread({ status: "unauthorized" }, JAN);

      expect(screen.getAllByRole("alert").map((alert) => alert.textContent).join(" ")).toContain("Twoja sesja wygasła");
    });
  });

  it("reads the summary again with the same code after a 409 OrderTotalChanged and orders the new total with it", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    fillGuestForm();
    const used = key(keys);
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: discounted("WITAJ10", 41998, 4200) });
    await submit({ status: "conflict", reason: "total_changed", detail: "Kwota zamówienia się zmieniła. Sprawdź podsumowanie." });
    await act(async () => {});

    expect(loadCart).toHaveBeenLastCalledWith("witaj10");
    expect(total()).toBe(price(37798));
    expect(screen.getByTestId("checkout-discount").textContent).toBe(`Kod WITAJ10: −${price(4200)}`);
    expect(screen.getByRole("alert").textContent).toContain("Kwota zamówienia zmieniła się od wczytania kasy.");
    expect(placeOrder).toHaveBeenCalledTimes(1);

    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    const second = vi.mocked(placeOrder).mock.calls[1];
    expect(second[0]).toMatchObject({ discountCode: "witaj10", expectedTotalGross: 37798 });
    expect(second[1]).not.toBe(used);
  });

  it("shows the detail at the field when the code stops working by the time the summary is read again", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValueOnce({
      status: "ok",
      data: { ...cart(), discount: rejected("WITAJ10", "used_up", "Ten kod został już wykorzystany.", null) },
    });
    await submit({ status: "conflict", reason: "total_changed", detail: "Kwota zamówienia się zmieniła. Sprawdź podsumowanie." });
    await act(async () => {});

    const alerts = screen.getAllByRole("alert").map((alert) => alert.textContent);
    expect(alerts).toContain("Ten kod został już wykorzystany.");
    expect(screen.queryByTestId("checkout-discount")).toBeNull();
    expect(total()).toBe(price(39998));
    await submit({ status: "ok", number: "R-100001", redirectUrl: null });
    expect(vi.mocked(placeOrder).mock.calls[1][0]).toEqual(GUEST_INPUT);
  });

  it("empties the live region when the code stops working by the time the summary is read again after a 409 OrderTotalChanged", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    expect(screen.getByTestId("checkout-code-status").textContent).toBe("Zastosowano kod WITAJ10.");
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValueOnce({
      status: "ok",
      data: { ...cart(), discount: rejected("WITAJ10", "below_minimum", "Ten kod działa przy zamówieniu od 300,00 zł.", 30000) },
    });
    await submit({ status: "conflict", reason: "total_changed", detail: "Kwota zamówienia się zmieniła. Sprawdź podsumowanie." });
    await act(async () => {});

    expect(screen.getByTestId("checkout-code-status").textContent).toBe("");
    expect(screen.getAllByRole("alert").map((alert) => alert.textContent)).toContain("Ten kod działa przy zamówieniu od 300,00 zł.");
  });

  it("keeps the announcement of the removal when a later 409 reads the summary without a code", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
    await act(async () => {
      fireEvent.click(removeButton());
    });
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "ok", data: cart() });
    await submit({ status: "conflict", reason: "total_changed", detail: "Kwota zamówienia się zmieniła. Sprawdź podsumowanie." });
    await act(async () => {});

    expect(screen.getByTestId("checkout-code-status").textContent).toBe("Usunięto kod rabatowy.");
  });

  it("does not keep the code over a reload of the page: a new checkout starts without one", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    cleanup();
    vi.mocked(loadCart).mockClear();
    await renderCheckout();

    expect(loadCart).toHaveBeenCalledWith();
    expect(codeField().value).toBe("");
    expect(screen.queryByTestId("checkout-discount")).toBeNull();
    expect(window.localStorage.length + window.sessionStorage.length).toBe(0);
    expect(document.cookie).toBe("");
  });

  it("locks the code controls and the order button while a code is being checked", async () => {
    await renderCheckout();
    let release: (value: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (release = resolve)));
    fireEvent.change(codeField(), { target: { value: "WITAJ10" } });
    await act(async () => {
      fireEvent.click(applyButton());
    });

    expect(applyButton().getAttribute("aria-disabled")).toBe("true");
    expect(applyButton().disabled).toBe(false);
    expect(submitButton().getAttribute("aria-disabled")).toBe("true");
    expect(submitButton().disabled).toBe(false);
    await act(async () => {
      fireEvent.click(applyButton());
      fireEvent.click(submitButton());
    });
    expect(vi.mocked(loadCart).mock.calls.filter((call) => call.length > 0)).toEqual([["WITAJ10"]]);
    expect(placeOrder).not.toHaveBeenCalled();

    await act(async () => release({ status: "ok", data: discounted("WITAJ10", 39998, 4000) }));
    expect(submitButton().getAttribute("aria-disabled")).toBeNull();
    expect(submitButton().disabled).toBe(false);
  });

  it("locks „Usuń kod” and the order button with aria-disabled while the code is being removed", async () => {
    await renderCheckout();
    fillGuestForm();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    let release: (value: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (release = resolve)));
    const reads = vi.mocked(loadCart).mock.calls.length;
    await act(async () => {
      fireEvent.click(removeButton());
    });

    expect(removeButton().getAttribute("aria-disabled")).toBe("true");
    expect((removeButton() as HTMLButtonElement).disabled).toBe(false);
    expect(submitButton().getAttribute("aria-disabled")).toBe("true");
    await act(async () => {
      fireEvent.click(removeButton());
      fireEvent.click(submitButton());
    });
    expect(vi.mocked(loadCart).mock.calls.length).toBe(reads + 1);
    expect(placeOrder).not.toHaveBeenCalled();

    await act(async () => release({ status: "ok", data: cart() }));
    expect(submitButton().getAttribute("aria-disabled")).toBeNull();
  });

  it.each([
    ["applied", (): Awaited<ReturnType<typeof loadCart>> => ({ status: "ok", data: discounted("WITAJ10", 39998, 4000) })],
    ["not checked", (): Awaited<ReturnType<typeof loadCart>> => ({ status: "failed" })],
  ])("leaves the focus where the customer moved it while the code was checked (%s)", async (_name, answer) => {
    await renderCheckout();
    let release: (value: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (release = resolve)));
    fireEvent.change(codeField(), { target: { value: "WITAJ10" } });
    applyButton().focus();
    await act(async () => {
      fireEvent.click(applyButton());
    });
    expect(document.activeElement).toBe(applyButton());
    field("Telefon").focus();

    await act(async () => release(answer()));

    expect(document.activeElement).toBe(field("Telefon"));
  });

  it("moves the focus to the field after a check sent from it with Enter when the focus stayed there", async () => {
    await renderCheckout();
    let release: (value: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (release = resolve)));
    fireEvent.change(codeField(), { target: { value: "LATO15" } });
    codeField().focus();
    await act(async () => {
      fireEvent.submit(codeField().closest("form") as HTMLFormElement);
    });

    await act(async () => release({ status: "ok", data: withRejection("invalid", "Ten kod jest nieprawidłowy albo nieaktywny.", null) }));

    expect(document.activeElement).toBe(codeField());
  });

  it("leaves the focus where the customer moved it while the code was being removed", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    let release: (value: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart).mockReturnValueOnce(new Promise((resolve) => (release = resolve)));
    removeButton().focus();
    await act(async () => {
      fireEvent.click(removeButton());
    });
    field("Telefon").focus();

    await act(async () => release({ status: "failed" }));

    expect(document.activeElement).toBe(field("Telefon"));
  });

  it("locks the field and „Zastosuj” while the order is placed: the field keeps its text and no code is checked", async () => {
    await renderCheckout();
    fillGuestForm();
    fireEvent.change(codeField(), { target: { value: "LATO15" } });
    let answer: (result: PlaceOrderResult) => void = () => {};
    vi.mocked(placeOrder).mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
    const reads = vi.mocked(loadCart).mock.calls.length;
    await act(async () => {
      fireEvent.click(submitButton());
    });

    expect(codeField().readOnly).toBe(true);
    expect(codeField().getAttribute("aria-disabled")).toBe("true");
    fireEvent.change(codeField(), { target: { value: "LATO20" } });
    expect(codeField().value).toBe("LATO15");
    expect(applyButton().getAttribute("aria-disabled")).toBe("true");
    expect(applyButton().disabled).toBe(false);
    await act(async () => {
      fireEvent.click(applyButton());
      fireEvent.submit(codeField().closest("form") as HTMLFormElement);
    });
    expect(vi.mocked(loadCart).mock.calls.length).toBe(reads);

    await act(async () => answer({ status: "failed" }));
    expect(codeField().readOnly).toBe(false);
    expect(codeField().getAttribute("aria-disabled")).toBeNull();
    expect(codeField().value).toBe("LATO15");
    expect(applyButton().getAttribute("aria-disabled")).toBeNull();
  });

  it("locks an applied code while the order is placed: „Usuń kod” sends nothing", async () => {
    await renderCheckout();
    await applyOk("witaj10", discounted("WITAJ10", 39998, 4000));
    fillGuestForm();
    vi.mocked(placeOrder).mockReturnValueOnce(new Promise(() => {}));
    const reads = vi.mocked(loadCart).mock.calls.length;
    await act(async () => {
      fireEvent.click(submitButton());
    });

    expect(removeButton().getAttribute("aria-disabled")).toBe("true");
    expect((removeButton() as HTMLButtonElement).disabled).toBe(false);
    await act(async () => {
      fireEvent.click(removeButton());
    });
    expect(vi.mocked(loadCart).mock.calls.length).toBe(reads);
    expect(screen.getByTestId("checkout-discount")).toBeTruthy();
  });
});
