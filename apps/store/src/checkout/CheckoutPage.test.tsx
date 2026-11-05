// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { type CartView, loadAccountSession, loadCart, type PlaceOrderResult, placeOrder } from "@/api/client";
import { subscribeToCartChanges } from "@/cart/events";
import { formatPrice } from "@/catalog/format-price";
import { CheckoutPage } from "./CheckoutPage";

vi.mock("@/api/client", () => ({ loadCart: vi.fn(), loadAccountSession: vi.fn(), placeOrder: vi.fn() }));

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
  totals: { gross, currency: "PLN", itemCount: lines.reduce((sum, l) => sum + l.quantity, 0) },
  hasExcludedLines,
});

const JAN = { name: "Jan Kowalski", email: "jan.kowalski@example.com" };

const announced: number[] = [];
let unsubscribe: () => void;
let keys: number;

beforeEach(() => {
  vi.mocked(loadCart).mockReset();
  vi.mocked(loadAccountSession).mockReset();
  vi.mocked(placeOrder).mockReset();
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

async function renderCheckout(view: CartView = cart(), session: { name: string; email: string } | null = null) {
  vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: view });
  vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session });
  render(<CheckoutPage />);
  await screen.findByRole("heading", { level: 1, name: "Kasa" });
  await act(async () => {});
}

const field = (label: string) => screen.getByLabelText(label) as HTMLInputElement;
const type = (label: string, value: string) => fireEvent.change(field(label), { target: { value } });
const submitButton = () => screen.getByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" }) as HTMLButtonElement;

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
}

const GUEST_INPUT = {
  email: "anna.nowak@example.com",
  fullName: "Anna Nowak",
  phone: "600 100 200",
  street: "ul. Długa 5/3",
  postalCode: "00-950",
  city: "Warszawa",
  expectedTotalGross: 39998,
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
    render(<CheckoutPage />);

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
    render(<CheckoutPage />);

    expect(await screen.findByText("Nie udało się wczytać kasy")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Spróbuj ponownie" }));

    expect(await screen.findByRole("button", { name: "Zamawiam z obowiązkiem zapłaty" })).toBeTruthy();
  });

  it("says the session expired when the cart answers 401", async () => {
    vi.mocked(loadCart).mockResolvedValue({ status: "unauthorized" });
    vi.mocked(loadAccountSession).mockResolvedValue({ status: "ok", session: JAN });
    render(<CheckoutPage />);

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
    await submit({ status: "ok", number: "R-100001" });

    expect(placeOrder).toHaveBeenCalledWith(GUEST_INPUT, current);
    expect(announced).toEqual([0]);
    expect(push).toHaveBeenCalledWith("/checkout/confirmation");
  });

  it("sends a customer's form without an e-mail", async () => {
    await renderCheckout(cart(), JAN);
    fillContact();
    await submit({ status: "ok", number: "R-100002" });

    expect(placeOrder).toHaveBeenCalledWith(
      {
        fullName: "Jan Kowalski",
        phone: "600 100 200",
        street: "ul. Długa 5/3",
        postalCode: "00-950",
        city: "Warszawa",
        expectedTotalGross: 39998,
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
    await submit({ status: "ok", number: "R-100001" });

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

  it("shows a 422 that names no field of the form in the message", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({ status: "invalid", detail: "Nieprawidłowe dane.", violations: [] });

    expect(screen.getByRole("alert").textContent).toContain("Nieprawidłowe dane.");
  });

  it("on a 409 of a changed total shows the detail, loads the new summary and keeps the form; the next order has a new key and the new total", async () => {
    await renderCheckout();
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: cart(41000) });
    await submit({ status: "conflict", detail: "Kwota zamówienia się zmieniła. Sprawdź podsumowanie." });

    expect(screen.getByRole("alert").textContent).toContain("Kwota zamówienia się zmieniła. Sprawdź podsumowanie.");
    expect(screen.getByTestId("checkout-total").textContent).toBe(price(41000));
    expect(field("Telefon").value).toBe("600 100 200");
    expect(submitButton().disabled).toBe(false);

    await submit({ status: "ok", number: "R-100001" });
    const [first, second] = vi.mocked(placeOrder).mock.calls;
    expect(second[0].expectedTotalGross).toBe(41000);
    expect(second[1]).not.toBe(first[1]);
  });

  it.each([
    ["Koszyk jest pusty.", cart(0, [])],
    ["Część pozycji w koszyku nie może zostać kupiona. Sprawdź koszyk.", cart(1000, [line(A, "Buty", "", 1, 1000, { state: "no_price", unitPrice: null, lineTotal: null })], true)],
    ["Nie mamy już tylu sztuk części produktów. Sprawdź koszyk.", cart(39998, [line(A, "Buty", "", 2, 39998, { state: "insufficient_stock" })], true)],
  ])("on a 409 „%s” shows the detail with a link back to the cart", async (detail, reloaded) => {
    await renderCheckout();
    fillGuestForm();
    vi.mocked(loadCart).mockResolvedValue({ status: "ok", data: reloaded });
    await submit({ status: "conflict", detail });

    const alert = screen.getByRole("alert");
    expect(alert.textContent).toContain(detail);
    expect(within(alert).getByRole("link", { name: "Wróć do koszyka" }).getAttribute("href")).toBe("/cart");
    expect(push).not.toHaveBeenCalled();
  });

  it("on a 409 of a reused key shows the detail, keeps the form and orders with a new key on the next click", async () => {
    await renderCheckout();
    fillGuestForm();
    await submit({ status: "conflict", detail: "Ten klucz żądania był już użyty dla innego zamówienia." });

    expect(screen.getByRole("alert").textContent).toContain("Ten klucz żądania był już użyty dla innego zamówienia.");
    expect(field("E-mail").value).toBe("anna.nowak@example.com");

    await submit({ status: "ok", number: "R-100001" });
    const [first, second] = vi.mocked(placeOrder).mock.calls;
    expect(second[1]).not.toBe(first[1]);
    expect(second[0]).toEqual(first[0]);
    expect(push).toHaveBeenCalledWith("/checkout/confirmation");
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
