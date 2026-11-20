// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { type CartView, changeCartItemQuantity, loadCart, removeCartItem } from "@/api/client";
import { makeImage } from "@/catalog/fixtures";
import { formatPrice } from "@/catalog/format-price";
import { CartPage } from "./CartPage";
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

beforeEach(() => {
  vi.mocked(loadCart).mockReset();
  vi.mocked(changeCartItemQuantity).mockReset();
  vi.mocked(removeCartItem).mockReset();
  announced.length = 0;
  unsubscribe = subscribeToCartChanges((count) => announced.push(count));
});

afterEach(() => {
  unsubscribe();
  cleanup();
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
  it("says it is loading until the cart arrives", async () => {
    vi.mocked(loadCart).mockReturnValue(new Promise(() => {}));
    render(<CartPage />);

    expect(await screen.findByText("Wczytywanie koszyka…")).toBeTruthy();
  });

  it("offers a retry when the cart cannot be loaded, and shows the cart after it works", async () => {
    vi.mocked(loadCart).mockResolvedValueOnce({ status: "failed" }).mockResolvedValueOnce(ok(view([line(A, "Produkt A", 1)], { gross: 1000 })));
    render(<CartPage />);

    expect(await screen.findByText("Nie udało się wczytać koszyka")).toBeTruthy();
    fireEvent.click(screen.getByRole("button", { name: "Spróbuj ponownie" }));

    expect(await screen.findByText("Produkt A")).toBeTruthy();
    expect(screen.queryByText("Nie udało się wczytać koszyka")).toBeNull();
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

  it("keeps the quantity field and Usuń focusable but inactive while the request runs", async () => {
    await renderWith(before());
    let finish: (value: Awaited<ReturnType<typeof changeCartItemQuantity>>) => void = () => {};
    vi.mocked(changeCartItemQuantity).mockReturnValue(new Promise((resolve) => (finish = resolve)));
    const field = qty("Produkt A");
    const remove = screen.getByRole("button", { name: /Usuń/ }) as HTMLButtonElement;
    field.focus();

    fireEvent.change(field, { target: { value: "3" } });
    fireEvent.keyDown(field, { key: "Enter" });

    expect(field.getAttribute("aria-disabled")).toBe("true");
    expect(remove.getAttribute("aria-disabled")).toBe("true");
    expect(field.disabled).toBe(false);
    expect(remove.disabled).toBe(false);
    expect(field.readOnly).toBe(true);
    expect(document.activeElement).toBe(field);

    // Typing, the arrow keys, another commit and a click on Usuń change nothing and send nothing.
    fireEvent.change(field, { target: { value: "7" } });
    fireEvent.keyDown(field, { key: "ArrowUp" });
    fireEvent.keyDown(field, { key: "Enter" });
    fireEvent.blur(field);
    fireEvent.click(remove);
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

    expect(await screen.findByText("Pozycja została już usunięta")).toBeTruthy();
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

    it("says the session expired when the reload after a 404 gets a 401, with the quantity of the last view", async () => {
      await renderWith(unavailable());
      vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "not_found", detail: "Produkt nie jest już dostępny." });
      vi.mocked(loadCart).mockResolvedValue({ status: "unauthorized" });

      changeUnavailableTo("2");

      expect(await screen.findByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
      expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcart");
      expect(screen.queryByText("Pozycja została już usunięta")).toBeNull();
      expect(quantityField().value).toBe("1");
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

  it("says the session expired on a 401", async () => {
    await renderWith(before());
    vi.mocked(changeCartItemQuantity).mockResolvedValue({ status: "unauthorized" });

    setQuantity("Produkt A", "3");

    expect(await screen.findByText(/Twoja sesja wygasła\. Zaloguj się ponownie\./)).toBeTruthy();
    expect(screen.getByRole("link", { name: "Zaloguj się" }).getAttribute("href")).toBe("/auth/login?returnTo=%2Fcart");
    expect(qty("Produkt A").value).toBe("2");
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
