// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { type CartView, changeCartItemQuantity, loadCart, removeCartItem } from "@/api/client";
import { makeImage } from "@/catalog/fixtures";
import { formatPrice } from "@/catalog/format-price";
import { CartPage } from "./CartPage";
import { actionsFinished } from "./cart-actions";
import { subscribeToCartChanges } from "./events";

vi.mock("@/api/client", () => ({ loadCart: vi.fn(), changeCartItemQuantity: vi.fn(), removeCartItem: vi.fn() }));

const A = "00000000-0000-4000-8000-00000000000a";
const B = "00000000-0000-4000-8000-00000000000b";
const PRODUCT = "00000000-0000-4000-8000-0000000000f1";
const price = (gross: number) => formatPrice(gross, "PLN");

type Line = CartView["lines"][number];

const line = (variantId: string, name: string, quantity: number, overrides: Partial<Line> = {}): Line => ({
  variantId,
  product: { productId: PRODUCT, name, variantLabel: "", image: null },
  quantity,
  unitPrice: { gross: 1000, currency: "PLN" },
  lineTotal: { gross: 1000 * quantity, currency: "PLN" },
  state: "purchasable",
  ...overrides,
});

const view = (lines: Line[], totals: Partial<CartView["totals"]> = {}, hasExcludedLines = false, delivery: CartView["delivery"] = null): CartView => ({
  id: "c1",
  lines,
  totals: {
    subtotalGross: totals.gross ?? 0,
    discountGross: 0,
    gross: 0,
    currency: "PLN",
    itemCount: lines.reduce((sum, l) => sum + l.quantity, 0),
    ...totals,
  },
  hasExcludedLines,
  discount: null,
  delivery,
});

const delivery = (fromGross: number | null, missingToFreeGross: number | null = null): NonNullable<CartView["delivery"]> => ({
  fromGross,
  missingToFreeGross,
  options:
    fromGross === null
      ? []
      : [{ methodId: "00000000-0000-4000-8000-0000000000d1", name: "Kurier", description: "", costGross: fromGross, freeFromGross: null, missingToFreeGross, totalGross: 1000 + fromGross }],
});

const ok = (data: CartView) => ({ status: "ok" as const, data });

const announced: number[] = [];
let unsubscribe: () => void;

type Answer = Awaited<ReturnType<typeof changeCartItemQuantity>>;

// Every held write waits until the test answers it, in the order of the calls.
const held: { resolve: (answer: Answer) => void; answered: boolean }[] = [];
const hold = () => {
  const write = () => new Promise<Answer>((resolve) => held.push({ resolve: (value) => resolve(value), answered: false }));
  vi.mocked(changeCartItemQuantity).mockImplementation(write);
  vi.mocked(removeCartItem).mockImplementation(write);
};
const answer = async (index: number, value: Answer) => {
  expect(held.length, `write ${index + 1} was not sent`).toBeGreaterThan(index);
  held[index].answered = true;
  await act(async () => held[index].resolve(value));
};

beforeEach(() => {
  vi.mocked(loadCart).mockReset();
  vi.mocked(changeCartItemQuantity).mockReset();
  vi.mocked(removeCartItem).mockReset();
  announced.length = 0;
  held.length = 0;
  unsubscribe = subscribeToCartChanges((count) => announced.push(count));
});

afterEach(async () => {
  cleanup();
  unsubscribe();
  // The actions of the cart page outlive it (`actionsFinished`): a write the test left without an answer would keep
  // every later cart page on "Wczytywanie koszyka…". A 401 ends the sending at once and drops what waits.
  await act(async () => {
    for (const write of held) if (!write.answered) write.resolve({ status: "unauthorized" });
  });
  await actionsFinished();
});

const renderWith = async (cart: CartView) => {
  vi.mocked(loadCart).mockResolvedValue(ok(cart));
  render(<CartPage />);
  await screen.findByRole("heading", { name: "Koszyk" });
  await act(async () => {});
};

const qty = (name: string) => screen.getByRole("spinbutton", { name: `Ilość: ${name}` }) as HTMLInputElement;
const setQuantity = (name: string, value: string) => {
  fireEvent.change(qty(name), { target: { value } });
  fireEvent.blur(qty(name));
};

describe("CartPage loading", () => {
  it("says it is loading in the status region until the cart arrives", async () => {
    vi.mocked(loadCart).mockReturnValue(new Promise(() => {}));
    render(<CartPage />);

    expect((await screen.findByTestId("cart-status")).textContent).toBe("Wczytywanie koszyka…");
    expect(screen.getByTestId("cart-status").getAttribute("role")).toBe("status");
    expect(screen.getAllByText("Wczytywanie koszyka…")).toHaveLength(1);
  });

  it("offers a retry when the cart cannot be loaded, keeps the button during it, and shows the cart after it works", async () => {
    let finish: (result: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart)
      .mockResolvedValueOnce({ status: "failed" })
      .mockReturnValueOnce(new Promise((resolve) => (finish = resolve)));
    render(<CartPage />);

    expect(await screen.findByText("Nie udało się wczytać koszyka")).toBeTruthy();
    const retry = screen.getByRole("button", { name: "Spróbuj ponownie" });
    fireEvent.click(retry);

    // The button stays while the cart is read again; the message of the failure makes way for the loading text.
    expect(screen.getByRole("button", { name: "Spróbuj ponownie" })).toBe(retry);
    expect(screen.queryByText("Nie udało się wczytać koszyka")).toBeNull();
    expect(screen.getByTestId("cart-status").textContent).toBe("Wczytywanie koszyka…");

    await act(async () => finish(ok(view([line(A, "Produkt A", 1)], { gross: 1000 }))));
    expect(await screen.findByText("Produkt A")).toBeTruthy();
    expect(screen.queryByRole("button", { name: "Spróbuj ponownie" })).toBeNull();
    expect(screen.getByTestId("cart-status").textContent).toBe("");
    expect(loadCart).toHaveBeenCalledTimes(2);
  });

  it("says the session expired, with a login link back to the cart, on a 401", async () => {
    vi.mocked(loadCart).mockResolvedValue({ status: "unauthorized" });
    render(<CartPage />);

    expect(await screen.findByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcart");
  });
});

describe("CartPage content", () => {
  it("shows the empty cart", async () => {
    await renderWith(view([]));

    expect(screen.getByText("Twój koszyk jest pusty")).toBeTruthy();
    expect(screen.queryByTestId("cart-total")).toBeNull();
  });

  it("shows a purchasable line with the values of the API and the totals", async () => {
    await renderWith(view([line(A, "Produkt A", 2, { lineTotal: { gross: 2000, currency: "PLN" } })], { gross: 2000, itemCount: 2 }));

    const row = screen.getByRole("listitem");
    expect(within(row).getByText("Produkt A")).toBeTruthy();
    expect(within(row).getByTestId("unit-price").textContent).toBe(price(1000));
    expect(within(row).getByTestId("line-total").textContent).toBe(price(2000));
    expect(qty("Produkt A").value).toBe("2");
    expect(within(row).getByRole("button", { name: /Usuń/ })).toBeTruthy();
    expect(screen.getByTestId("cart-total").textContent).toBe(price(2000));
    expect(screen.getByTestId("cart-item-count").textContent).toBe("2");
    expect(screen.queryByText("Część produktów nie jest wliczona")).toBeNull();
  });

  it("does not compute anything: totals come from the API even when they differ from the lines", async () => {
    await renderWith(view([line(A, "Produkt A", 2)], { gross: 12345, itemCount: 9 }));

    expect(screen.getByTestId("cart-total").textContent).toBe(price(12345));
    expect(screen.getByTestId("cart-item-count").textContent).toBe("9");
  });

  it("shows the label of the variant under the name and names the quantity field after both", async () => {
    await renderWith(
      view([
        line(A, "Buty Asfalt", 1, { product: { productId: PRODUCT, name: "Buty Asfalt", variantLabel: "czarne, 42", image: null } }),
        line(B, "Buty Asfalt", 2, { product: { productId: PRODUCT, name: "Buty Asfalt", variantLabel: "czarne, 43", image: null } }),
      ]),
    );

    expect(screen.getAllByTestId("variant-label").map((label) => label.textContent)).toEqual(["czarne, 42", "czarne, 43"]);
    expect(screen.getByLabelText("Ilość: Buty Asfalt, czarne, 42")).toBeTruthy();
    expect(screen.getByLabelText("Ilość: Buty Asfalt, czarne, 43")).toBeTruthy();
  });

  it("shows no variant label for the default variant", async () => {
    await renderWith(view([line(A, "Hantle", 1)]));

    expect(screen.queryByTestId("variant-label")).toBeNull();
    expect(screen.getByLabelText("Ilość: Hantle")).toBeTruthy();
  });

  it("shows the photo of a product", async () => {
    await renderWith(view([line(A, "Produkt A", 1, { product: { productId: PRODUCT, name: "Produkt A", variantLabel: "", image: makeImage({ alt: "Zdjęcie A" }) } })]));

    expect(screen.getByRole("img", { name: "Zdjęcie A" })).toBeTruthy();
  });

  it.each([
    ["no_price", "Cena niedostępna", { unitPrice: null, lineTotal: null }],
    ["out_of_stock", "Niedostępny", { lineTotal: null }],
    ["insufficient_stock", "Dostępna ilość jest mniejsza niż w koszyku", { lineTotal: null }],
  ] as const)("labels a %s line and keeps its Usuń button", async (state, label, overrides) => {
    await renderWith(view([line(A, "Produkt A", 2, { state, ...overrides })], { gross: 0 }, true));

    const row = screen.getByRole("listitem");
    expect(within(row).getByText(label)).toBeTruthy();
    expect(within(row).getByText("Produkt A")).toBeTruthy();
    expect(within(row).queryByTestId("line-total")).toBeNull();
    expect(within(row).getByRole("button", { name: /Usuń/ })).toBeTruthy();
    expect(qty("Produkt A").value).toBe("2");
  });

  it("shows no unit price for a line without a price", async () => {
    await renderWith(view([line(A, "Produkt A", 1, { state: "no_price", unitPrice: null, lineTotal: null })], {}, true));

    expect(screen.queryByTestId("unit-price")).toBeNull();
  });

  it("shows an unavailable product without name and photo, with the label and Usuń", async () => {
    await renderWith(view([line(A, "x", 1, { product: null, state: "unavailable_product", unitPrice: null, lineTotal: null })], {}, true));

    const row = screen.getByRole("listitem");
    expect(within(row).getByText("Produkt nie jest już dostępny")).toBeTruthy();
    expect(within(row).queryByRole("img")).toBeNull();
    expect(within(row).getByRole("button", { name: /Usuń/ })).toBeTruthy();
    expect(screen.queryByText("x")).toBeNull();
  });

  it("says part of the products is not counted when the API says some lines are excluded", async () => {
    await renderWith(view([line(A, "Produkt A", 1), line(B, "Produkt B", 1, { state: "out_of_stock", lineTotal: null })], { gross: 1000 }, true));

    expect(screen.getByText("Część produktów nie jest wliczona")).toBeTruthy();
    expect(screen.getAllByRole("listitem")).toHaveLength(2);
  });
});

describe("CartPage delivery", () => {
  const withDelivery = (d: CartView["delivery"], gross = 2000) => view([line(A, "Produkt A", 2, { lineTotal: { gross, currency: "PLN" } })], { gross, itemCount: 2 }, false, d);

  it("shows the cheapest delivery under the total", async () => {
    await renderWith(withDelivery(delivery(1999)));

    expect(screen.getByTestId("cart-delivery-from").textContent).toBe(`Dostawa od ${price(1999)}`);
    expect(screen.queryByTestId("cart-delivery-missing")).toBeNull();
  });

  it("says the delivery is free when the API says the cheapest option costs nothing", async () => {
    await renderWith(withDelivery(delivery(0)));

    expect(screen.getByTestId("cart-delivery-from").textContent).toBe("Darmowa dostawa");
    expect(screen.queryByText(/Dostawa od/)).toBeNull();
    expect(screen.queryByTestId("cart-delivery-missing")).toBeNull();
  });

  it("shows how much is missing to free delivery, from the API", async () => {
    await renderWith(withDelivery(delivery(1999, 5001)));

    expect(screen.getByTestId("cart-delivery-from").textContent).toBe(`Dostawa od ${price(1999)}`);
    expect(screen.getByTestId("cart-delivery-missing").textContent).toBe(`Do darmowej dostawy brakuje ${price(5001)}`);
  });

  it.each([
    ["delivery is null", null],
    ["there is no active method", delivery(null)],
  ])("shows no row when %s", async (_name, value) => {
    await renderWith(withDelivery(value));

    expect(screen.queryByTestId("cart-delivery-from")).toBeNull();
    expect(screen.queryByTestId("cart-delivery-missing")).toBeNull();
    expect(screen.queryByText(/dostaw/i)).toBeNull();
  });

  it("announces a change of the rows in a polite live region that is there before the change", async () => {
    await renderWith(withDelivery(delivery(1999, 5001)));
    const region = screen.getByTestId("cart-delivery");
    expect(region.getAttribute("aria-live")).toBe("polite");
    expect(region.contains(screen.getByTestId("cart-delivery-missing"))).toBe(true);
    vi.mocked(changeCartItemQuantity).mockResolvedValue(ok(withDelivery(delivery(0), 9000)));

    setQuantity("Produkt A", "9");

    await vi.waitFor(() => expect(screen.getByTestId("cart-delivery-from").textContent).toBe("Darmowa dostawa"));
    expect(screen.getByTestId("cart-delivery")).toBe(region);
    expect(region.contains(screen.getByTestId("cart-delivery-from"))).toBe(true);
    expect(screen.queryByTestId("cart-delivery-missing")).toBeNull();
  });

  it("keeps an empty live region when there is no delivery row", async () => {
    await renderWith(withDelivery(null));

    const region = screen.getByTestId("cart-delivery");
    expect(region.getAttribute("aria-live")).toBe("polite");
    expect(region.textContent).toBe("");
  });
});

describe("CartPage changing the quantity", () => {
  const before = () => view([line(A, "Produkt A", 2, { lineTotal: { gross: 2000, currency: "PLN" } })], { gross: 2000, itemCount: 2 });

  it("sends the new quantity on blur, shows the cart the API returns and announces its units", async () => {
    await renderWith(before());
    vi.mocked(changeCartItemQuantity).mockResolvedValue(ok(view([line(A, "Produkt A", 3, { lineTotal: { gross: 3000, currency: "PLN" } })], { gross: 3000, itemCount: 3 })));

    setQuantity("Produkt A", "3");

    await vi.waitFor(() => expect(screen.getByTestId("cart-total").textContent).toBe(price(3000)));
    expect(changeCartItemQuantity).toHaveBeenCalledExactlyOnceWith(A, 3);
    expect(screen.getByTestId("line-total").textContent).toBe(price(3000));
    expect(qty("Produkt A").value).toBe("3");
    expect(announced).toEqual([3]);
  });

  it("sends the quantity on Enter, once", async () => {
    await renderWith(before());
    vi.mocked(changeCartItemQuantity).mockResolvedValue(ok(before()));

    fireEvent.change(qty("Produkt A"), { target: { value: "5" } });
    fireEvent.keyDown(qty("Produkt A"), { key: "Enter" });
    fireEvent.blur(qty("Produkt A"));

    await act(async () => {});
    expect(changeCartItemQuantity).toHaveBeenCalledExactlyOnceWith(A, 5);
  });

  it.each([["0"], ["100"], ["-1"], ["1.5"], [""], ["2"]])("sends nothing for %j and shows the quantity of the cart again", async (value) => {
    await renderWith(before());

    setQuantity("Produkt A", value);

    await act(async () => {});
    expect(changeCartItemQuantity).not.toHaveBeenCalled();
    expect(qty("Produkt A").value).toBe("2");
  });

  it("keeps the quantity field focusable but inactive while its change is saved, and leaves Usuń active", async () => {
    await renderWith(before());
    let finish: (value: Awaited<ReturnType<typeof changeCartItemQuantity>>) => void = () => {};
    vi.mocked(changeCartItemQuantity).mockReturnValue(new Promise((resolve) => (finish = resolve)));
    const field = qty("Produkt A");
    const remove = screen.getByRole("button", { name: /Usuń/ }) as HTMLButtonElement;
    field.focus();

    fireEvent.change(field, { target: { value: "3" } });
    fireEvent.keyDown(field, { key: "Enter" });

    expect(field.getAttribute("aria-disabled")).toBe("true");
    expect(remove.hasAttribute("aria-disabled")).toBe(false);
    expect(field.disabled).toBe(false);
    expect(remove.disabled).toBe(false);
    expect(field.readOnly).toBe(true);
    expect(document.activeElement).toBe(field);

    // Typing, the arrow keys and another commit change nothing and send nothing.
    fireEvent.change(field, { target: { value: "7" } });
    fireEvent.keyDown(field, { key: "ArrowUp" });
    fireEvent.keyDown(field, { key: "Enter" });
    fireEvent.blur(field);
    expect(field.value).toBe("3");
    expect(changeCartItemQuantity).toHaveBeenCalledExactlyOnceWith(A, 3);
    expect(removeCartItem).not.toHaveBeenCalled();

    await act(async () => finish(ok(before())));
    expect(field.hasAttribute("aria-disabled")).toBe(false);
    expect(remove.hasAttribute("aria-disabled")).toBe(false);
    expect(field.readOnly).toBe(false);
  });

  it("keeps the focus in the same quantity field after Enter with the new quantity once the change is saved", async () => {
    await renderWith(before());
    vi.mocked(changeCartItemQuantity).mockResolvedValue(ok(view([line(A, "Produkt A", 3, { lineTotal: { gross: 3000, currency: "PLN" } })], { gross: 3000, itemCount: 3 })));
    const field = qty("Produkt A");
    field.focus();

    fireEvent.change(field, { target: { value: "3" } });
    fireEvent.keyDown(field, { key: "Enter" });

    await vi.waitFor(() => expect(screen.getByTestId("cart-total").textContent).toBe(price(3000)));
    expect(qty("Produkt A")).toBe(field);
    expect(document.activeElement).toBe(field);
    expect(field.value).toBe("3");
  });

  it.each([
    ["a 409", { status: "conflict" as const, detail: "Nie mamy tylu sztuk tego produktu." }],
    ["a failure", { status: "failed" as const }],
  ])("keeps the focus in the same quantity field showing the last quantity after %s", async (_name, answer) => {
    await renderWith(before());
    vi.mocked(changeCartItemQuantity).mockResolvedValue(answer);
    const field = qty("Produkt A");
    field.focus();

    fireEvent.change(field, { target: { value: "9" } });
    fireEvent.keyDown(field, { key: "Enter" });

    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(qty("Produkt A")).toBe(field);
    expect(document.activeElement).toBe(field);
    expect(field.value).toBe("2");
  });

  it("keeps the focus in the same quantity field showing the last quantity after Enter with a value out of 1-99", async () => {
    await renderWith(before());
    const field = qty("Produkt A");
    field.focus();

    fireEvent.change(field, { target: { value: "100" } });
    fireEvent.keyDown(field, { key: "Enter" });

    await act(async () => {});
    expect(changeCartItemQuantity).not.toHaveBeenCalled();
    expect(qty("Produkt A")).toBe(field);
    expect(document.activeElement).toBe(field);
    expect(field.value).toBe("2");
  });

  it("lets the focus move to Usuń with Tab while the request runs and keeps it there after a refused change", async () => {
    await renderWith(before());
    let finish: (value: Awaited<ReturnType<typeof changeCartItemQuantity>>) => void = () => {};
    vi.mocked(changeCartItemQuantity).mockReturnValue(new Promise((resolve) => (finish = resolve)));
    const remove = screen.getByRole("button", { name: /Usuń/ });

    fireEvent.change(qty("Produkt A"), { target: { value: "9" } });
    fireEvent.keyDown(qty("Produkt A"), { key: "Enter" });
    remove.focus();

    expect(document.activeElement).toBe(remove);
    await act(async () => finish({ status: "conflict", detail: "Nie mamy tylu sztuk tego produktu." }));
    expect(document.activeElement).toBe(remove);
    expect(qty("Produkt A").value).toBe("2");
  });

  it("shows the detail of a 409 at the line and the quantity of the last view", async () => {
    await renderWith(before());
    vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "conflict", detail: "Nie mamy tylu sztuk tego produktu." });

    setQuantity("Produkt A", "9");

    expect(await screen.findByText("Nie mamy tylu sztuk tego produktu.")).toBeTruthy();
    expect(qty("Produkt A").value).toBe("2");
    expect(screen.getByTestId("cart-total").textContent).toBe(price(2000));
    expect(announced).toEqual([]);
  });

  it("falls back to the general message when a 409 has no detail", async () => {
    await renderWith(before());
    vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "conflict" });

    setQuantity("Produkt A", "9");

    expect(await screen.findByText("Nie udało się zapisać zmian. Spróbuj ponownie.")).toBeTruthy();
  });

  it.each([
    ["a change", () => vi.mocked(changeCartItemQuantity)],
    ["a removal", () => vi.mocked(removeCartItem)],
  ])("shows the text of the limit of cart changes at the line after a 429 of %s, and keeps the last view", async (_name, mocked) => {
    await renderWith(before());
    mocked().mockResolvedValue({ status: "rate_limited" });

    if (_name === "a change") setQuantity("Produkt A", "9");
    else fireEvent.click(screen.getByRole("button", { name: "Usuń: Produkt A" }));

    const alert = await screen.findByRole("alert");
    expect(alert.textContent).toBe("Zbyt wiele zmian koszyka. Spróbuj ponownie za kilka minut.");
    expect(qty("Produkt A").value).toBe("2");
    expect(screen.getByTestId("cart-total").textContent).toBe(price(2000));
    expect(announced).toEqual([]);
  });

  it("shows the general message once for a failure and does not retry", async () => {
    await renderWith(before());
    vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "failed" });

    setQuantity("Produkt A", "4");

    expect(await screen.findByText("Nie udało się zapisać zmian. Spróbuj ponownie.")).toBeTruthy();
    expect(qty("Produkt A").value).toBe("2");
    expect(changeCartItemQuantity).toHaveBeenCalledTimes(1);
  });

  it("reloads the cart and says the line is gone on a 404", async () => {
    await renderWith(view([line(A, "Produkt A", 2), line(B, "Produkt B", 1)], { gross: 3000, itemCount: 3 }));
    vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "not_found", detail: "Tej pozycji nie ma w koszyku." });
    vi.mocked(loadCart).mockResolvedValue(ok(view([line(B, "Produkt B", 1)], { gross: 1000, itemCount: 1 })));

    setQuantity("Produkt A", "3");

    await vi.waitFor(() => expect(screen.getByTestId("cart-status").textContent).toBe("Pozycja została już usunięta"));
    expect(screen.getAllByText("Pozycja została już usunięta")).toHaveLength(1);
    expect(screen.queryByText("Produkt A")).toBeNull();
    expect(screen.getByText("Produkt B")).toBeTruthy();
    expect(loadCart).toHaveBeenCalledTimes(2);
    expect(announced).toEqual([1]);
  });

  describe("a 404 while the line is still in the reloaded cart", () => {
    const gone = (quantity: number) =>
      line(A, "x", quantity, { product: null, state: "unavailable_product", unitPrice: null, lineTotal: null });
    const unavailable = () => view([gone(1)], {}, true);
    const SAVE_FAILED_TEXT = "Nie udało się zapisać zmian. Spróbuj ponownie.";
    const quantityField = () => screen.getByRole("spinbutton", { name: "Ilość" }) as HTMLInputElement;
    const changeUnavailableTo = (value: string) => {
      fireEvent.change(quantityField(), { target: { value } });
      fireEvent.blur(quantityField());
    };

    it("keeps the quantity field of an unavailable_product line enabled", async () => {
      await renderWith(unavailable());

      expect(quantityField().disabled).toBe(false);
    });

    it("shows the detail at the line, no gone notice, the old quantity and Usuń for an unavailable_product line", async () => {
      await renderWith(unavailable());
      vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "not_found", detail: "Produkt nie jest już dostępny." });
      vi.mocked(loadCart).mockResolvedValue(ok(unavailable()));

      changeUnavailableTo("2");

      expect(await screen.findByText("Produkt nie jest już dostępny.")).toBeTruthy();
      expect(screen.queryByText("Pozycja została już usunięta")).toBeNull();
      expect(quantityField().value).toBe("1");
      expect(quantityField().disabled).toBe(false);
      expect(within(screen.getByRole("listitem")).getByRole("button", { name: /Usuń/ })).toBeTruthy();
      expect(loadCart).toHaveBeenCalledTimes(2);
    });

    it("shows the quantity of the reloaded cart when it differs from the last view", async () => {
      await renderWith(view([line(A, "Produkt A", 2)], { gross: 2000, itemCount: 2 }));
      vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "not_found", detail: "Tej pozycji nie ma w koszyku." });
      vi.mocked(loadCart).mockResolvedValue(ok(view([line(A, "Produkt A", 4)], { gross: 4000, itemCount: 4 })));

      setQuantity("Produkt A", "7");

      expect(await screen.findByText("Tej pozycji nie ma w koszyku.")).toBeTruthy();
      expect(qty("Produkt A").value).toBe("4");
      expect(screen.queryByText("Pozycja została już usunięta")).toBeNull();
      expect(announced).toEqual([4]);
    });

    it("shows the detail when removing the line gets a 404 but the line is still in the reloaded cart", async () => {
      await renderWith(unavailable());
      vi.mocked(removeCartItem).mockResolvedValue({ status: "not_found", detail: "Produkt nie jest już dostępny." });
      vi.mocked(loadCart).mockResolvedValue(ok(unavailable()));

      fireEvent.click(screen.getByRole("button", { name: /Usuń/ }));

      expect(await screen.findByText("Produkt nie jest już dostępny.")).toBeTruthy();
      expect(screen.queryByText("Pozycja została już usunięta")).toBeNull();
      expect(screen.getByRole("button", { name: /Usuń/ })).toBeTruthy();
    });

    it("shows the general message at the line when the 404 has no detail", async () => {
      await renderWith(unavailable());
      vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "not_found" });
      vi.mocked(loadCart).mockResolvedValue(ok(unavailable()));

      changeUnavailableTo("2");

      expect(await screen.findByText(SAVE_FAILED_TEXT)).toBeTruthy();
      expect(screen.queryByText("Pozycja została już usunięta")).toBeNull();
      expect(quantityField().value).toBe("1");
    });

    it("shows the view of an expired session when the reload after a 404 gets a 401", async () => {
      await renderWith(unavailable());
      vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "not_found", detail: "Produkt nie jest już dostępny." });
      vi.mocked(loadCart).mockResolvedValue({ status: "unauthorized" });

      changeUnavailableTo("2");

      expect(await screen.findByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
      expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcart");
      expect(screen.queryByText("Pozycja została już usunięta")).toBeNull();
      // The view of an expired session: the message alone, without the lines and the way to the checkout.
      expect(screen.queryAllByRole("listitem")).toHaveLength(0);
      expect(screen.queryByRole("spinbutton")).toBeNull();
      expect(screen.queryByRole("link", { name: "Przejdź do kasy" })).toBeNull();
      expect(screen.queryByRole("button", { name: "Przejdź do kasy" })).toBeNull();
    });

    it("shows the general message at the line when the reload after a 404 fails, with the quantity of the last view", async () => {
      await renderWith(unavailable());
      vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "not_found", detail: "Produkt nie jest już dostępny." });
      vi.mocked(loadCart).mockResolvedValue({ status: "failed" });

      changeUnavailableTo("2");

      expect(await screen.findByText(SAVE_FAILED_TEXT)).toBeTruthy();
      expect(screen.queryByText("Pozycja została już usunięta")).toBeNull();
      expect(quantityField().value).toBe("1");
      expect(announced).toEqual([]);
    });
  });

  it("says the session expired on a 401, without the lines and the way to the checkout", async () => {
    await renderWith(before());
    vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "unauthorized" });

    setQuantity("Produkt A", "3");

    expect(await screen.findByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcart");
    expect(screen.queryAllByRole("listitem")).toHaveLength(0);
    expect(screen.queryByRole("spinbutton")).toBeNull();
    expect(screen.queryByRole("link", { name: "Przejdź do kasy" })).toBeNull();
    expect(screen.getAllByText(/Twoja sesja wygasła/)).toHaveLength(1);
  });
});

describe("CartPage removing a line", () => {
  it("removes the line and shows the cart the API returns", async () => {
    await renderWith(view([line(A, "Produkt A", 1), line(B, "Produkt B", 1)], { gross: 2000, itemCount: 2 }));
    vi.mocked(removeCartItem).mockResolvedValue(ok(view([line(B, "Produkt B", 1)], { gross: 1000, itemCount: 1 })));

    fireEvent.click(within(screen.getAllByRole("listitem")[0]).getByRole("button", { name: /Usuń/ }));

    await vi.waitFor(() => expect(screen.queryByText("Produkt A")).toBeNull());
    expect(removeCartItem).toHaveBeenCalledExactlyOnceWith(A);
    expect(announced).toEqual([1]);
  });

  it("shows the empty cart after the last line is removed", async () => {
    await renderWith(view([line(A, "Produkt A", 3)], { gross: 3000, itemCount: 3 }));
    vi.mocked(removeCartItem).mockResolvedValue(ok(view([])));

    fireEvent.click(screen.getByRole("button", { name: /Usuń/ }));

    expect(await screen.findByText("Twój koszyk jest pusty")).toBeTruthy();
    expect(announced).toEqual([0]);
  });

  it("keeps the line and shows the general message when removing fails", async () => {
    await renderWith(view([line(A, "Produkt A", 1)], { gross: 1000, itemCount: 1 }));
    vi.mocked(removeCartItem).mockResolvedValue({ status: "failed" });

    fireEvent.click(screen.getByRole("button", { name: /Usuń/ }));

    expect(await screen.findByText("Nie udało się zapisać zmian. Spróbuj ponownie.")).toBeTruthy();
    expect(screen.getByText("Produkt A")).toBeTruthy();
    expect(announced).toEqual([]);
  });
});

describe("CartPage checkout button", () => {
  const EXCLUDED_HINT = "Usuń albo popraw oznaczone pozycje, żeby złożyć zamówienie.";

  it("links to the checkout when the cart has lines that can all be bought", async () => {
    await renderWith(view([line(A, "Produkt A", 2)], { gross: 2000 }));

    expect(screen.getByRole("link", { name: "Przejdź do kasy" }).getAttribute("href")).toBe("/checkout");
    expect(screen.queryByText(EXCLUDED_HINT)).toBeNull();
  });

  it("is inactive, with the reason, when a line cannot be bought", async () => {
    await renderWith(
      view([line(A, "Produkt A", 1), line(B, "Produkt B", 1, { state: "no_price", unitPrice: null, lineTotal: null })], { gross: 1000 }, true),
    );

    expect(screen.queryByRole("link", { name: "Przejdź do kasy" })).toBeNull();
    const button = screen.getByRole("button", { name: "Przejdź do kasy" }) as HTMLButtonElement;
    expect(button.disabled).toBe(true);
    expect(screen.getByText(EXCLUDED_HINT)).toBeTruthy();
  });

  it("is not there for an empty cart", async () => {
    await renderWith(view([]));

    expect(screen.queryByRole("link", { name: "Przejdź do kasy" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Przejdź do kasy" })).toBeNull();
  });
});

describe("CartPage actions during a save", () => {
  const cart = (a: number | null, b: number | null) =>
    view([...(a === null ? [] : [line(A, "Produkt A", a)]), ...(b === null ? [] : [line(B, "Produkt B", b)])]);
  const removeButton = (name: string) => screen.getByRole("button", { name: `Usuń: ${name}` });
  const patches = () => vi.mocked(changeCartItemQuantity).mock.calls;
  const deletes = () => vi.mocked(removeCartItem).mock.calls;

  describe.each([
    ["answered at once", false],
    ["held", true],
  ])("Usuń right after typing a quantity (criterion 1), the change %s", (_name, withHold) => {
    it("removes the line of the typed quantity after its change, and keeps the other one", async () => {
      await renderWith(cart(1, 1));
      if (withHold) hold();
      else {
        vi.mocked(changeCartItemQuantity).mockResolvedValue(ok(cart(3, 1)));
        vi.mocked(removeCartItem).mockResolvedValue(ok(cart(null, 1)));
      }

      setQuantity("Produkt A", "3");
      fireEvent.click(removeButton("Produkt A"));
      if (withHold) {
        await answer(0, ok(cart(3, 1)));
        await answer(1, ok(cart(null, 1)));
      }

      await vi.waitFor(() => expect(screen.queryByText("Produkt A")).toBeNull());
      expect(screen.getByText("Produkt B")).toBeTruthy();
      expect(patches()).toEqual([[A, 3]]);
      expect(deletes()).toEqual([[A]]);
    });

    it("removes the other line after the change, and keeps the typed quantity", async () => {
      await renderWith(cart(1, 1));
      if (withHold) hold();
      else {
        vi.mocked(changeCartItemQuantity).mockResolvedValue(ok(cart(3, 1)));
        vi.mocked(removeCartItem).mockResolvedValue(ok(cart(3, null)));
      }

      setQuantity("Produkt A", "3");
      fireEvent.click(removeButton("Produkt B"));
      if (withHold) {
        await answer(0, ok(cart(3, 1)));
        await answer(1, ok(cart(3, null)));
      }

      await vi.waitFor(() => expect(screen.queryByText("Produkt B")).toBeNull());
      expect(qty("Produkt A").value).toBe("3");
      expect(patches()).toEqual([[A, 3]]);
      expect(deletes()).toEqual([[B]]);
    });
  });

  it("sends a quantity of another line committed during a held change after it, never showing the old one (criterion 2)", async () => {
    await renderWith(cart(1, 1));
    hold();

    setQuantity("Produkt A", "3");
    // Only the field of the saved line is read-only.
    expect(qty("Produkt A").getAttribute("aria-disabled")).toBe("true");
    expect(qty("Produkt B").hasAttribute("aria-disabled")).toBe(false);
    expect(qty("Produkt B").readOnly).toBe(false);
    setQuantity("Produkt B", "2");
    expect(qty("Produkt B").value).toBe("2");
    expect(patches()).toEqual([[A, 3]]);

    await answer(0, ok(cart(3, 1)));
    expect(qty("Produkt A").value).toBe("3");
    expect(qty("Produkt B").value).toBe("2");
    expect(patches()).toEqual([
      [A, 3],
      [B, 2],
    ]);

    await answer(1, ok(cart(3, 2)));
    expect(qty("Produkt A").value).toBe("3");
    expect(qty("Produkt B").value).toBe("2");
    expect(deletes()).toEqual([]);
  });

  it("ignores Enter and blur with the same value while the change of the field is held, and takes a change after it (criterion 3)", async () => {
    await renderWith(cart(1, 1));
    hold();
    const field = qty("Produkt A");
    field.focus();

    fireEvent.change(field, { target: { value: "3" } });
    fireEvent.keyDown(field, { key: "Enter" });
    expect(field.readOnly).toBe(true);
    expect(field.getAttribute("aria-disabled")).toBe("true");
    fireEvent.keyDown(field, { key: "Enter" });
    fireEvent.blur(field);
    expect(patches()).toEqual([[A, 3]]);

    await answer(0, ok(cart(3, 1)));
    expect(field.readOnly).toBe(false);
    setQuantity("Produkt A", "5");
    await answer(1, ok(cart(5, 1)));

    expect(qty("Produkt A").value).toBe("5");
    expect(patches()).toEqual([
      [A, 3],
      [A, 5],
    ]);
    expect(deletes()).toEqual([]);
  });

  it("drops a waiting quantity when the entry changes, and sends the new one only once it is committed (criterion 4)", async () => {
    await renderWith(cart(1, 1));
    hold();

    setQuantity("Produkt A", "3");
    setQuantity("Produkt B", "2");
    fireEvent.change(qty("Produkt B"), { target: { value: "4" } });
    await answer(0, ok(cart(3, 1)));

    expect(patches()).toEqual([[A, 3]]);
    expect(qty("Produkt B").value).toBe("4");
    expect(qty("Produkt B").readOnly).toBe(false);
    expect(qty("Produkt B").hasAttribute("aria-disabled")).toBe(false);

    fireEvent.blur(qty("Produkt B"));
    expect(patches()).toEqual([
      [A, 3],
      [B, 4],
    ]);
    await answer(1, ok(cart(3, 4)));
    expect(qty("Produkt B").value).toBe("4");
    expect(deletes()).toEqual([]);
  });

  it("keeps an uncommitted entry and a waiting quantity when the answer for another line brings a new quantity of them", async () => {
    await renderWith(view([line(A, "Produkt A", 1), line(B, "Produkt B", 1), line(PRODUCT, "Produkt C", 1)]));
    hold();

    setQuantity("Produkt A", "3");
    setQuantity("Produkt B", "2");
    fireEvent.change(qty("Produkt C"), { target: { value: "6" } });
    // Another tab changed B and C in the meantime: the answer for A has their new quantities.
    await answer(0, ok(view([line(A, "Produkt A", 3), line(B, "Produkt B", 7), line(PRODUCT, "Produkt C", 8)])));

    expect(qty("Produkt A").value).toBe("3");
    expect(qty("Produkt B").value).toBe("2");
    expect(qty("Produkt C").value).toBe("6");
    expect(patches()).toEqual([
      [A, 3],
      [B, 2],
    ]);

    // After the answer for its own change the field shows the quantity of that answer.
    await answer(1, ok(view([line(A, "Produkt A", 3), line(B, "Produkt B", 2), line(PRODUCT, "Produkt C", 8)])));
    expect(qty("Produkt B").value).toBe("2");
    expect(qty("Produkt C").value).toBe("6");
  });

  it("shows the new quantity of an untouched field from the answer for another line", async () => {
    await renderWith(cart(1, 1));
    hold();

    setQuantity("Produkt A", "3");
    await answer(0, ok(cart(3, 7)));

    expect(qty("Produkt B").value).toBe("7");
  });

  describe("a failed change with an action waiting (criterion 5)", () => {
    it("shows the message at the failed line with its confirmed quantity and removes the other line", async () => {
      await renderWith(cart(1, 1));
      hold();

      setQuantity("Produkt A", "3");
      fireEvent.click(removeButton("Produkt B"));
      await answer(0, { status: "failed" });
      await answer(1, ok(cart(1, null)));

      expect(screen.queryByText("Produkt B")).toBeNull();
      const rowA = screen.getByRole("listitem");
      expect(within(rowA).getByRole("alert").textContent).toBe("Nie udało się zapisać zmian. Spróbuj ponownie.");
      expect(qty("Produkt A").value).toBe("1");
      expect(patches()).toEqual([[A, 3]]);
      expect(deletes()).toEqual([[B]]);
    });

    it("removes the line of the failed change", async () => {
      await renderWith(cart(1, 1));
      hold();

      setQuantity("Produkt A", "3");
      fireEvent.click(removeButton("Produkt A"));
      await answer(0, { status: "failed" });
      await answer(1, ok(cart(null, 1)));

      expect(screen.queryByText("Produkt A")).toBeNull();
      expect(screen.getByText("Produkt B")).toBeTruthy();
      expect(patches()).toEqual([[A, 3]]);
      expect(deletes()).toEqual([[A]]);
    });

    it.each([
      ["a 409", { status: "conflict" as const, detail: "Nie mamy tylu sztuk tego produktu." }, "Nie mamy tylu sztuk tego produktu."],
      ["a 429", { status: "rate_limited" as const }, "Zbyt wiele zmian koszyka. Spróbuj ponownie za kilka minut."],
    ])("sends a waiting quantity of another line after %s", async (_name, failure, message) => {
      await renderWith(cart(1, 1));
      hold();

      setQuantity("Produkt A", "9");
      setQuantity("Produkt B", "2");
      await answer(0, failure);
      await answer(1, ok(cart(1, 2)));

      expect(within(screen.getAllByRole("listitem")[0]).getByRole("alert").textContent).toBe(message);
      expect(qty("Produkt A").value).toBe("1");
      expect(qty("Produkt B").value).toBe("2");
      expect(patches()).toEqual([
        [A, 9],
        [B, 2],
      ]);
      expect(deletes()).toEqual([]);
    });
  });

  describe.each([
    ["a removal", () => fireEvent.click(removeButton("Produkt B"))],
    ["a quantity", () => setQuantity("Produkt B", "2")],
  ])("a 401 with %s of another line waiting (criterion 6)", (_name, actOnB) => {
    it("shows the view of an expired session and sends nothing for the other line", async () => {
      await renderWith(cart(1, 1));
      hold();

      setQuantity("Produkt A", "3");
      actOnB();
      await answer(0, { status: "unauthorized" });
      await act(async () => {});

      expect(screen.getByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
      expect(screen.queryAllByRole("listitem")).toHaveLength(0);
      expect(screen.queryByRole("link", { name: "Przejdź do kasy" })).toBeNull();
      expect(screen.queryByRole("button", { name: "Przejdź do kasy" })).toBeNull();
      expect(patches()).toEqual([[A, 3]]);
      expect(deletes()).toEqual([]);
    });
  });

  it("drops the waiting actions when the reload after a 404 gets a 401", async () => {
    await renderWith(cart(1, 1));
    hold();
    vi.mocked(loadCart).mockResolvedValue({ status: "unauthorized" });

    setQuantity("Produkt A", "3");
    fireEvent.click(removeButton("Produkt B"));
    await answer(0, { status: "not_found" });
    await act(async () => {});

    expect(screen.getByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
    expect(screen.queryAllByRole("listitem")).toHaveLength(0);
    expect(deletes()).toEqual([]);
  });

  describe("two activations of Usuń of one line (criterion 7)", () => {
    it("send one removal while it is held, with both controls of the line inactive", async () => {
      await renderWith(cart(1, 1));
      hold();
      const remove = removeButton("Produkt A");
      remove.focus();

      fireEvent.click(remove);
      fireEvent.click(remove);

      expect(remove.getAttribute("aria-disabled")).toBe("true");
      expect(qty("Produkt A").getAttribute("aria-disabled")).toBe("true");
      expect(document.activeElement).toBe(remove);
      await answer(0, ok(cart(null, 1)));
      expect(deletes()).toEqual([[A]]);
      expect(patches()).toEqual([]);
    });

    it("send one removal when it waits for another change", async () => {
      await renderWith(cart(1, 1));
      hold();

      setQuantity("Produkt A", "3");
      fireEvent.click(removeButton("Produkt B"));
      fireEvent.click(removeButton("Produkt B"));
      expect(removeButton("Produkt B").getAttribute("aria-disabled")).toBe("true");
      expect(qty("Produkt B").getAttribute("aria-disabled")).toBe("true");
      expect(qty("Produkt B").readOnly).toBe(true);
      await answer(0, ok(cart(3, 1)));
      await answer(1, ok(cart(3, null)));

      expect(screen.queryByText("Produkt B")).toBeNull();
      expect(deletes()).toEqual([[B]]);
      expect(patches()).toEqual([[A, 3]]);
    });
  });

  it("sends the waiting actions in the order they were given, a replacing one in the place of the replaced one", async () => {
    await renderWith(view([line(A, "Produkt A", 1), line(B, "Produkt B", 1), line(PRODUCT, "Produkt C", 1)]));
    hold();

    setQuantity("Produkt A", "3");
    setQuantity("Produkt B", "2");
    setQuantity("Produkt C", "4");
    // The removal of B replaces its waiting quantity and keeps its place before C.
    fireEvent.click(removeButton("Produkt B"));
    await answer(0, ok(view([line(A, "Produkt A", 3), line(B, "Produkt B", 1), line(PRODUCT, "Produkt C", 1)])));
    await answer(1, ok(view([line(A, "Produkt A", 3), line(PRODUCT, "Produkt C", 1)])));
    await answer(2, ok(view([line(A, "Produkt A", 3), line(PRODUCT, "Produkt C", 4)])));

    expect(patches()).toEqual([
      [A, 3],
      [PRODUCT, 4],
    ]);
    expect(deletes()).toEqual([[B]]);
    expect(vi.mocked(removeCartItem).mock.invocationCallOrder[0]).toBeLessThan(vi.mocked(changeCartItemQuantity).mock.invocationCallOrder[1]);
  });

  it("finishes the actions it was given after the cart page is left", async () => {
    vi.mocked(loadCart).mockResolvedValue(ok(cart(1, 1)));
    const page = render(<CartPage />);
    await screen.findByText("Produkt A");
    hold();

    setQuantity("Produkt A", "3");
    fireEvent.click(removeButton("Produkt B"));
    page.unmount();
    await answer(0, ok(cart(3, 1)));
    await answer(1, ok(cart(3, null)));

    expect(patches()).toEqual([[A, 3]]);
    expect(deletes()).toEqual([[B]]);
    expect(announced).toEqual([4, 3]);
  });

  it("sends nothing it was given after the cart page is left when the change gets a 401", async () => {
    vi.mocked(loadCart).mockResolvedValue(ok(cart(1, 1)));
    const page = render(<CartPage />);
    await screen.findByText("Produkt A");
    hold();

    setQuantity("Produkt A", "3");
    fireEvent.click(removeButton("Produkt B"));
    page.unmount();
    await answer(0, { status: "unauthorized" });
    await act(async () => {});

    expect(deletes()).toEqual([]);
  });

  it("reads the cart again only after the actions of the left page are finished when the customer comes back to it", async () => {
    // The cart of the server, changed by the answers of the test.
    let server = cart(1, 1);
    vi.mocked(loadCart).mockImplementation(async () => ok(server));
    const page = render(<CartPage />);
    await screen.findByText("Produkt A");
    hold();

    setQuantity("Produkt A", "3");
    fireEvent.click(removeButton("Produkt B"));
    page.unmount();
    render(<CartPage />);
    await act(async () => {});

    // While the actions of the left page run, the page reads nothing and shows that it is loading.
    expect(screen.getByText("Wczytywanie koszyka…")).toBeTruthy();
    server = cart(3, 1);
    await answer(0, ok(server));
    expect(screen.queryByText("Produkt A")).toBeNull();
    server = cart(3, null);
    await answer(1, ok(server));
    await act(async () => {});

    expect(await screen.findByText("Produkt A")).toBeTruthy();
    expect(qty("Produkt A").value).toBe("3");
    expect(screen.queryByText("Produkt B")).toBeNull();
    expect(patches()).toEqual([[A, 3]]);
    expect(deletes()).toEqual([[B]]);
  });

  it("reads the cart again at once after the actions of the left page end with a 401", async () => {
    vi.mocked(loadCart).mockResolvedValue(ok(cart(1, 1)));
    const page = render(<CartPage />);
    await screen.findByText("Produkt A");
    hold();

    setQuantity("Produkt A", "3");
    fireEvent.click(removeButton("Produkt B"));
    page.unmount();
    render(<CartPage />);
    await act(async () => {});
    vi.mocked(loadCart).mockResolvedValue({ status: "unauthorized" });
    await answer(0, { status: "unauthorized" });
    await act(async () => {});

    expect(screen.getByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
    expect(deletes()).toEqual([]);
  });
});

const statusRegion = () => screen.getByTestId("cart-status");
const removeOf = (name: string) => screen.getByRole("button", { name: `Usuń: ${name}` });
const threeLines = () => view([line(A, "Produkt A", 1), line(B, "Produkt B", 1), line(PRODUCT, "Produkt C", 1)]);
const without = (...names: string[]) => view(threeLines().lines.filter((l) => !names.includes(l.product?.name ?? "")));

describe("CartPage status region", () => {
  it("is one node from the first render through loading, the content and the empty cart, and only its text changes", async () => {
    let finish: (result: Awaited<ReturnType<typeof loadCart>>) => void = () => {};
    vi.mocked(loadCart).mockReturnValue(new Promise((resolve) => (finish = resolve)));
    render(<CartPage />);
    const region = statusRegion();
    expect(region.getAttribute("role")).toBe("status");
    expect(region.textContent).toBe("Wczytywanie koszyka…");

    await act(async () => finish(ok(view([line(A, "Produkt A", 1)]))));
    expect(await screen.findByText("Produkt A")).toBeTruthy();
    expect(statusRegion()).toBe(region);
    expect(region.isConnected).toBe(true);
    expect(region.textContent).toBe("");

    vi.mocked(removeCartItem).mockResolvedValue(ok(view([])));
    fireEvent.click(removeOf("Produkt A"));
    expect(await screen.findByText("Twój koszyk jest pusty")).toBeTruthy();
    expect(statusRegion()).toBe(region);
    expect(region.isConnected).toBe(true);
    expect(region.textContent).toBe("Usunięto z koszyka: Produkt A.");
    expect(screen.getAllByText("Usunięto z koszyka: Produkt A.")).toHaveLength(1);
  });

  it("is there, empty, when the cart cannot be loaded and when the session expired, and is the only status of the screen", async () => {
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" });
    render(<CartPage />);
    await screen.findByText("Nie udało się wczytać koszyka");
    expect(statusRegion().textContent).toBe("");
    expect(screen.getAllByRole("status")).toEqual([statusRegion()]);
    cleanup();

    vi.mocked(loadCart).mockResolvedValue({ status: "unauthorized" });
    render(<CartPage />);
    await screen.findByText(/Twoja sesja wygasła/);
    expect(statusRegion().textContent).toBe("");
    expect(screen.getAllByRole("status")).toEqual([statusRegion()]);
  });

  it("keeps a single status of the screen next to the cart with lines", async () => {
    await renderWith(threeLines());

    expect(screen.getAllByRole("status")).toEqual([statusRegion()]);
  });

  it.each([
    ["a name", line(A, "Produkt A", 1), "Usunięto z koszyka: Produkt A."],
    [
      "a name and the label of the variant",
      line(A, "Buty Asfalt", 1, { product: { productId: PRODUCT, name: "Buty Asfalt", variantLabel: "czarne, 42", image: null } }),
      "Usunięto z koszyka: Buty Asfalt, czarne, 42.",
    ],
    [
      "no name (an unavailable product)",
      line(A, "x", 1, { product: null, state: "unavailable_product", unitPrice: null, lineTotal: null }),
      "Usunięto pozycję z koszyka.",
    ],
  ])("says what was removed for a line with %s", async (_name, removed, text) => {
    await renderWith(view([removed, line(B, "Produkt B", 1)], {}, removed.product === null));
    vi.mocked(removeCartItem).mockResolvedValue(ok(view([line(B, "Produkt B", 1)])));

    fireEvent.click(screen.getAllByRole("button", { name: /^Usuń/ })[0]);

    await vi.waitFor(() => expect(statusRegion().textContent).toBe(text));
    expect(screen.getAllByText(text)).toHaveLength(1);
  });

  it("keeps its text until the next operation starts and then replaces it, so that the same text is announced again", async () => {
    await renderWith(threeLines());
    vi.mocked(removeCartItem).mockResolvedValueOnce(ok(without("Produkt A")));

    fireEvent.click(removeOf("Produkt A"));
    await vi.waitFor(() => expect(statusRegion().textContent).toBe("Usunięto z koszyka: Produkt A."));

    // A quantity of another line starts: the text of the removal is gone at once, and the answer does not bring it back.
    hold();
    setQuantity("Produkt B", "2");
    expect(statusRegion().textContent).toBe("");
    await answer(0, ok(view([line(B, "Produkt B", 2), line(PRODUCT, "Produkt C", 1)])));
    expect(statusRegion().textContent).toBe("");

    fireEvent.click(removeOf("Produkt B"));
    expect(statusRegion().textContent).toBe("");
    await answer(1, ok(without("Produkt A", "Produkt B")));
    expect(statusRegion().textContent).toBe("Usunięto z koszyka: Produkt B.");
  });

  it("says the line is gone when its removal gets a 404 and the reloaded cart does not have it", async () => {
    await renderWith(threeLines());
    vi.mocked(removeCartItem).mockResolvedValue({ status: "not_found" });
    vi.mocked(loadCart).mockResolvedValue(ok(without("Produkt A")));

    fireEvent.click(removeOf("Produkt A"));

    await vi.waitFor(() => expect(statusRegion().textContent).toBe("Pozycja została już usunięta"));
    expect(screen.getAllByText("Pozycja została już usunięta")).toHaveLength(1);
    expect(screen.queryByText("Produkt A")).toBeNull();
  });

  it("keeps the text of a 404 until the action of another line that waited for it ends, and replaces it with its own", async () => {
    await renderWith(threeLines());
    hold();
    vi.mocked(loadCart).mockResolvedValue(ok(without("Produkt A")));

    setQuantity("Produkt A", "3");
    fireEvent.click(removeOf("Produkt B"));
    await answer(0, { status: "not_found" });

    // The removal of B is on its way and says nothing yet: the text of the 404 stays.
    expect(held).toHaveLength(2);
    expect(statusRegion().textContent).toBe("Pozycja została już usunięta");

    await answer(1, ok(without("Produkt A", "Produkt B")));
    expect(statusRegion().textContent).toBe("Usunięto z koszyka: Produkt B.");
  });

  it("keeps the text of a 404 while a quantity of another line that waited for it is saved, and clears it for a new operation", async () => {
    await renderWith(threeLines());
    hold();
    vi.mocked(loadCart).mockResolvedValue(ok(without("Produkt A")));

    setQuantity("Produkt A", "3");
    setQuantity("Produkt B", "2");
    await answer(0, { status: "not_found" });
    expect(statusRegion().textContent).toBe("Pozycja została już usunięta");

    await answer(1, ok(view([line(B, "Produkt B", 2), line(PRODUCT, "Produkt C", 1)])));
    expect(statusRegion().textContent).toBe("Pozycja została już usunięta");

    // An operation the customer starts after the earlier ones ended clears the text.
    setQuantity("Produkt C", "4");
    expect(statusRegion().textContent).toBe("");
  });
});

describe("CartPage focus after removing a line (criterion 12)", () => {
  const removeAndWait = async (name: string, next: CartView) => {
    vi.mocked(removeCartItem).mockResolvedValue(ok(next));
    removeOf(name).focus();
    fireEvent.click(removeOf(name));
    await vi.waitFor(() => expect(screen.queryByText(name)).toBeNull());
  };

  it("goes to Usuń of the next line after a line of several is removed", async () => {
    await renderWith(threeLines());

    await removeAndWait("Produkt A", without("Produkt A"));

    expect(document.activeElement).toBe(removeOf("Produkt B"));
  });

  it("goes to Usuń of the next line after a line in the middle is removed", async () => {
    await renderWith(threeLines());

    await removeAndWait("Produkt B", without("Produkt B"));

    expect(document.activeElement).toBe(removeOf("Produkt C"));
  });

  it("goes to Usuń of the previous line after the last line of the list is removed", async () => {
    await renderWith(threeLines());

    await removeAndWait("Produkt C", without("Produkt C"));

    expect(document.activeElement).toBe(removeOf("Produkt B"));
  });

  it("goes to the text that the cart is empty, which is in the page once, after the only line is removed", async () => {
    await renderWith(view([line(A, "Produkt A", 1)]));

    vi.mocked(removeCartItem).mockResolvedValue(ok(view([])));
    removeOf("Produkt A").focus();
    fireEvent.click(removeOf("Produkt A"));

    const empty = await screen.findByText("Twój koszyk jest pusty");
    expect(screen.getAllByText("Twój koszyk jest pusty")).toHaveLength(1);
    expect(document.activeElement).toBe(empty);
    expect(empty.getAttribute("tabindex")).toBe("-1");
  });

  it("keeps the focus on Usuń while the removal is held, and moves it on the answer", async () => {
    await renderWith(threeLines());
    hold();
    removeOf("Produkt A").focus();

    fireEvent.click(removeOf("Produkt A"));
    expect(document.activeElement).toBe(removeOf("Produkt A"));
    expect(removeOf("Produkt A").getAttribute("aria-disabled")).toBe("true");
    expect(removeOf("Produkt A").hasAttribute("disabled")).toBe(false);

    await answer(0, ok(without("Produkt A")));
    expect(document.activeElement).toBe(removeOf("Produkt B"));
  });

  it("moves the focus also when it was lost with the removed line (on the body) before the answer", async () => {
    await renderWith(threeLines());
    hold();
    removeOf("Produkt B").focus();
    fireEvent.click(removeOf("Produkt B"));

    (document.activeElement as HTMLElement).blur();
    expect(document.activeElement).toBe(document.body);
    await answer(0, ok(without("Produkt B")));

    expect(document.activeElement).toBe(removeOf("Produkt C"));
  });

  it.each([
    ["a quantity field", () => qty("Produkt C")],
    ["another Usuń", () => removeOf("Produkt C")],
  ])("leaves the focus on %s when the customer moved there during the held removal", async (_name, target) => {
    await renderWith(threeLines());
    hold();
    removeOf("Produkt A").focus();
    fireEvent.click(removeOf("Produkt A"));

    target().focus();
    await answer(0, ok(without("Produkt A")));

    expect(screen.queryByText("Produkt A")).toBeNull();
    expect(document.activeElement).toBe(target());
  });

  it("leaves the focus where it is when the only line is removed and the customer moved it to a link meanwhile", async () => {
    vi.mocked(loadCart).mockResolvedValue(ok(view([line(A, "Produkt A", 1)])));
    render(
      <>
        <a href="/inne">Inne</a>
        <CartPage />
      </>,
    );
    await screen.findByText("Produkt A");
    hold();
    removeOf("Produkt A").focus();
    fireEvent.click(removeOf("Produkt A"));

    screen.getByRole("link", { name: "Inne" }).focus();
    await answer(0, ok(view([])));

    expect(await screen.findByText("Twój koszyk jest pusty")).toBeTruthy();
    expect(document.activeElement).toBe(screen.getByRole("link", { name: "Inne" }));
  });

  it("keeps the line, the button and the focus when the removal fails", async () => {
    await renderWith(threeLines());
    vi.mocked(removeCartItem).mockResolvedValue({ status: "failed" });
    const remove = removeOf("Produkt A");
    remove.focus();

    fireEvent.click(remove);

    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(removeOf("Produkt A")).toBe(remove);
    expect(document.activeElement).toBe(remove);
    expect(statusRegion().textContent).toBe("");
  });

  it("moves the focus for a removal that waited for the save of another line, when it is still on its Usuń", async () => {
    await renderWith(threeLines());
    hold();

    setQuantity("Produkt A", "3");
    removeOf("Produkt B").focus();
    fireEvent.click(removeOf("Produkt B"));
    await answer(0, ok(view([line(A, "Produkt A", 3), line(B, "Produkt B", 1), line(PRODUCT, "Produkt C", 1)])));
    expect(document.activeElement).toBe(removeOf("Produkt B"));
    await answer(1, ok(view([line(A, "Produkt A", 3), line(PRODUCT, "Produkt C", 1)])));

    expect(document.activeElement).toBe(removeOf("Produkt C"));
  });

  it("leaves the focus on the control the customer moved to while a removal waited for the save of another line", async () => {
    await renderWith(threeLines());
    hold();

    setQuantity("Produkt A", "3");
    removeOf("Produkt B").focus();
    fireEvent.click(removeOf("Produkt B"));
    qty("Produkt C").focus();
    await answer(0, ok(view([line(A, "Produkt A", 3), line(B, "Produkt B", 1), line(PRODUCT, "Produkt C", 1)])));
    await answer(1, ok(view([line(A, "Produkt A", 3), line(PRODUCT, "Produkt C", 1)])));

    expect(screen.queryByText("Produkt B")).toBeNull();
    expect(document.activeElement).toBe(qty("Produkt C"));
  });

  it("chooses the next line and the name from the cart of the last answer, not from the cart the sending began with", async () => {
    // The save of A answers with a line that came to the cart meanwhile (another tab): it stands after B.
    await renderWith(view([line(A, "Produkt A", 1), line(B, "Produkt B", 1)]));
    hold();

    setQuantity("Produkt A", "3");
    removeOf("Produkt B").focus();
    fireEvent.click(removeOf("Produkt B"));
    await answer(0, ok(view([line(A, "Produkt A", 3), line(B, "Produkt B", 1), line(PRODUCT, "Produkt C", 1)])));
    await answer(1, ok(view([line(A, "Produkt A", 3), line(PRODUCT, "Produkt C", 1)])));

    expect(statusRegion().textContent).toBe("Usunięto z koszyka: Produkt B.");
    expect(document.activeElement).toBe(removeOf("Produkt C"));
  });

  it("moves the focus to the next Usuń also when the removed line was gone already (a 404 and the reloaded cart without it)", async () => {
    await renderWith(threeLines());
    vi.mocked(removeCartItem).mockResolvedValue({ status: "not_found" });
    vi.mocked(loadCart).mockResolvedValue(ok(without("Produkt A")));
    removeOf("Produkt A").focus();

    fireEvent.click(removeOf("Produkt A"));

    await vi.waitFor(() => expect(screen.queryByText("Produkt A")).toBeNull());
    expect(document.activeElement).toBe(removeOf("Produkt B"));
  });
});

describe("CartPage retry after a failed load (criterion 13)", () => {
  type Result = Awaited<ReturnType<typeof loadCart>>;

  const failFirst = async (second: Promise<Result>) => {
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" }).mockReturnValueOnce(second);
    render(
      <>
        <a href="/inne">Inne</a>
        <CartPage />
      </>,
    );
    await screen.findByText("Nie udało się wczytać koszyka");
    const retry = screen.getByRole("button", { name: "Spróbuj ponownie" });
    retry.focus();
    return retry;
  };
  const deferred = () => {
    let finish: (result: Result) => void = () => {};
    const promise = new Promise<Result>((resolve) => (finish = resolve));
    return { promise, finish: (result: Result) => act(async () => finish(result)) };
  };

  it("keeps the focused button on the screen, inactive but not disabled, and sends no second request on another activation", async () => {
    const second = deferred();
    const retry = await failFirst(second.promise);

    fireEvent.click(retry);

    expect(screen.getByRole("button", { name: "Spróbuj ponownie" })).toBe(retry);
    expect(document.activeElement).toBe(retry);
    expect(retry.getAttribute("aria-disabled")).toBe("true");
    expect(retry.hasAttribute("disabled")).toBe(false);
    expect(statusRegion().textContent).toBe("Wczytywanie koszyka…");
    expect(screen.queryByRole("alert")).toBeNull();
    expect(screen.queryByText("Nie udało się wczytać koszyka")).toBeNull();

    fireEvent.click(retry);
    fireEvent.click(retry);
    expect(loadCart).toHaveBeenCalledTimes(2);

    await second.finish(ok(view([line(A, "Produkt A", 1)])));
  });

  it("moves the focus to the title Koszyk after a successful retry, and the region has no text", async () => {
    const second = deferred();
    const retry = await failFirst(second.promise);
    fireEvent.click(retry);

    await second.finish(ok(view([line(A, "Produkt A", 1)])));

    expect(await screen.findByText("Produkt A")).toBeTruthy();
    expect(document.activeElement).toBe(screen.getByRole("heading", { name: "Koszyk" }));
    expect(statusRegion().textContent).toBe("");
  });

  it("keeps the focus on the button after another failure and shows the message again", async () => {
    const second = deferred();
    const retry = await failFirst(second.promise);
    fireEvent.click(retry);

    await second.finish({ status: "failed" });

    expect(await screen.findByRole("alert")).toBeTruthy();
    expect(screen.getByText("Nie udało się wczytać koszyka")).toBeTruthy();
    expect(screen.getByRole("button", { name: "Spróbuj ponownie" })).toBe(retry);
    expect(document.activeElement).toBe(retry);
    expect(retry.hasAttribute("aria-disabled")).toBe(false);
    expect(statusRegion().textContent).toBe("");
  });

  it("does not move the focus when the customer moved it to another control during the retry", async () => {
    const second = deferred();
    const retry = await failFirst(second.promise);
    fireEvent.click(retry);

    screen.getByRole("link", { name: "Inne" }).focus();
    await second.finish(ok(view([line(A, "Produkt A", 1)])));

    expect(await screen.findByText("Produkt A")).toBeTruthy();
    expect(document.activeElement).toBe(screen.getByRole("link", { name: "Inne" }));
  });

  it("moves the focus to the title when it was lost during the retry (on the body)", async () => {
    const second = deferred();
    const retry = await failFirst(second.promise);
    fireEvent.click(retry);

    retry.blur();
    await second.finish(ok(view([line(A, "Produkt A", 1)])));

    expect(await screen.findByText("Produkt A")).toBeTruthy();
    expect(document.activeElement).toBe(screen.getByRole("heading", { name: "Koszyk" }));
  });
});
