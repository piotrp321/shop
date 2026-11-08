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

const cart = (gross = 39998, lines: Line[] = [line(A, "Buty trailowe", "czarne, 42", 2, 39998)], hasExcludedLines = false): CartView => ({
  id: "c1",
  lines,
  totals: { subtotalGross: gross, discountGross: 0, gross, currency: "PLN", itemCount: lines.reduce((sum, l) => sum + l.quantity, 0) },
  hasExcludedLines,
  discount: null,
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

async function renderCheckout(
  view: CartView = cart(),
  session: { name: string; email: string } | null = null,
  props: PageProps = { providers: PROVIDERS, pendingOrderNumber: null },
) {
  vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: view });
  vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session });
  render(<CheckoutPage {...props} />);
  await screen.findByRole("heading", { level: 1, name: "Kasa" });
  await act(async () => {});
}

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

  it.each<[string, "cart_empty" | "cart_not_orderable" | "insufficient_stock", string]>([
    ["CartEmpty", "cart_empty", "Koszyk jest pusty."],
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

  it("on a 403, 503, 5xx or network error asks to try again", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({ status: "failed" });

    expect(screen.getByRole("alert").textContent).toBe(SUBMIT_FAILED);
    expect(push).not.toHaveBeenCalled();
    expect(announced).toEqual([]);
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
    expect(document.activeElement).toBe(screen.getAllByRole("radio")[0]);
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
    expect(document.activeElement).toBe(screen.getAllByRole("radio")[0]);
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
