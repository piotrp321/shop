// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { type CartView, loadAccountSession, loadCart, type PlaceOrderResult, placeOrder } from "@/api/client";
import { subscribeToCartChanges } from "@/cart/events";
import { formatPrice } from "@/catalog/format-price";
import { CheckoutPage } from "./CheckoutPage";
import { navigateTo } from "./navigate";
import type { PaymentProvider } from "./payment-api";

vi.mock("@/api/client", () => ({ loadCart: vi.fn(), loadAccountSession: vi.fn(), placeOrder: vi.fn() }));
vi.mock("./navigate", () => ({ navigateTo: vi.fn() }));

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

const JAN = { name: "Jan Kowalski", email: "jan.kowalski@example.com" };

const announced: number[] = [];
let unsubscribe: () => void;
let keys: number;

beforeEach(() => {
  vi.mocked(loadCart).mockReset();
  vi.mocked(loadAccountSession).mockReset();
  vi.mocked(placeOrder).mockReset();
  vi.mocked(navigateTo).mockReset();
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
  session: { name: string; email: string } | null = null,
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

  it.each([
    ["the cart", () => vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" })],
    ["the session", () => vi.mocked(loadAccountSession).mockResolvedValueOnce({ status: "unavailable" })],
  ])("offers a retry when %s cannot be loaded", async (_name, fail) => {
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart() });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: null });
    fail();
    render(<CheckoutPage providers={PROVIDERS} pendingOrderNumber={null} />);

    expect(await screen.findByText("Nie udało się wczytać kasy")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Spróbuj ponownie" }));

    expect(await screen.findByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeTruthy();
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

  it("shows a customer the e-mail of the account as text and fills the name in from the account", async () => {
    await renderCheckout(cart(), JAN);

    expect(screen.queryByLabelText("E-mail")).toBeNull();
    expect(screen.getByTestId("account-email").textContent).toBe("jan.kowalski@example.com");
    expect(field("Imię i nazwisko").value).toBe("Jan Kowalski");
    expect(screen.queryByRole("link", { name: "Zaloguj się" })).toBeNull();
  });

  it("does not fill the name in with the e-mail of an account without a name", async () => {
    await renderCheckout(cart(), { name: JAN.email, email: JAN.email });

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
      },
      expect.any(String),
    );
    expect(push).toHaveBeenCalledWith("/checkout/confirmation");
  });

  it("locks the button while the request runs", async () => {
    await renderCheckout();
    fillGuestForm();
    let answer: (result: PlaceOrderResult) => void = () => {};
    vi.mocked(placeOrder).mockReturnValueOnce(new Promise((resolve) => (answer = resolve)));
    await act(async () => {
      fireEvent.click(submitButton());
    });

    expect(submitButton().disabled).toBe(true);
    fireEvent.click(submitButton());
    expect(placeOrder).toHaveBeenCalledTimes(1);

    await act(async () => answer({ status: "failed" }));
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
  ])("moves the focus to the message after %s, because the button was locked", async (_name, result) => {
    await renderCheckout();
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart() });
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

  it("on a 409 of a changed total shows the detail without a link to the cart, loads the new summary and keeps the form; the next order has a new key and the new total", async () => {
    await renderCheckout();
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart(41000) });
    await submit({ status: "conflict", reason: "total_changed", detail: "Kwota zamówienia się zmieniła. Sprawdź podsumowanie." });

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain("Kwota zamówienia się zmieniła. Sprawdź podsumowanie.");
    expect(within(alert).queryByRole("link")).toBeNull();
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
    expect(submitButton().disabled).toBe(true);
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
    expect(screen.getByRole("alert").textContent).toContain("Kwota zamówienia się zmieniła.");
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

    expect(applyButton().disabled).toBe(true);
    expect(submitButton().disabled).toBe(true);
    await act(async () => release({ status: "ok", data: discounted("WITAJ10", 39998, 4000) }));
    expect(submitButton().disabled).toBe(false);
  });
});
