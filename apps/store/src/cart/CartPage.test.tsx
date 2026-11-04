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
const price = (gross: number) => formatPrice(gross, "PLN");

type Line = CartView["lines"][number];

const line = (productId: string, name: string, quantity: number, overrides: Partial<Line> = {}): Line => ({
  productId,
  product: { name, image: null },
  quantity,
  unitPrice: { gross: 1000, currency: "PLN" },
  lineTotal: { gross: 1000 * quantity, currency: "PLN" },
  state: "purchasable",
  ...overrides,
});

const view = (lines: Line[], totals: Partial<CartView["totals"]> = {}, hasExcludedLines = false): CartView => ({
  id: "c1",
  lines,
  totals: { gross: 0, currency: "PLN", itemCount: lines.reduce((sum, l) => sum + l.quantity, 0), ...totals },
  hasExcludedLines,
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

  it("shows the photo of a product", async () => {
    await renderWith(view([line(A, "Produkt A", 1, { product: { name: "Produkt A", image: makeImage({ alt: "Zdjęcie A" }) } })]));

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

  it("disables the controls while the request runs", async () => {
    await renderWith(before());
    let finish: (value: Awaited<ReturnType<typeof changeCartItemQuantity>>) => void = () => {};
    vi.mocked(changeCartItemQuantity).mockReturnValue(new Promise((resolve) => (finish = resolve)));

    setQuantity("Produkt A", "3");

    expect(qty("Produkt A").disabled).toBe(true);
    expect((screen.getByRole("button", { name: /Usuń/ }) as HTMLButtonElement).disabled).toBe(true);
    await act(async () => finish(ok(before())));
    expect(qty("Produkt A").disabled).toBe(false);
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
