// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { addCartItem } from "@/api/client";
import { makeOffer, makeVariant } from "./fixtures";
import { TileOffer } from "./TileOffer";

vi.mock("@/api/client", () => ({ addCartItem: vi.fn() }));

const choose = () => screen.getByRole("button", { name: "Wybierz wariant" });
const live = () => screen.getByTestId("tile-live");
const radios = () => screen.getAllByRole("radio") as HTMLInputElement[];
const radio = (name: string | RegExp) => screen.getByRole("radio", { name }) as HTMLInputElement;

// Kajaki by colour: the first one cannot be bought, so the focus goes to the second.
const COLOURS = makeOffer(1, {
  variantAxes: ["Kolor"],
  fromGross: 249900,
  level: "low_stock",
  variants: [
    makeVariant(1, 1, { label: "pomarańczowy", gross: null, purchasable: false, level: "out_of_stock" }),
    makeVariant(1, 2, { label: "zielony", gross: 249900, level: "low_stock" }),
    makeVariant(1, 3, { label: "niebieski", gross: 249900 }),
  ],
});

// Kolor × Rozmiar: seven colours in two sizes, as in the demo data.
const COLOUR_SIZES = makeOffer(2, {
  variantAxes: ["Kolor", "Rozmiar"],
  fromGross: 30000,
  hasPriceRange: true,
  variants: ["czarne", "szare", "granatowe", "zielone", "czerwone", "białe", "żółte"].flatMap((colour, c) =>
    ["M", "L"].map((size, s) => makeVariant(2, c * 2 + s + 1, { label: `${colour}, ${size}`, gross: 30000 + c * 100, purchasable: !(c === 0 && s === 0) })),
  ),
});

beforeEach(() => {
  vi.mocked(addCartItem).mockReset();
});

afterEach(cleanup);

function renderOffer(offer: ReturnType<typeof makeOffer>) {
  return render(
    <TileOffer offer={offer}>
      <p data-testid="price">od 2499,00\u00a0zł</p>
    </TileOffer>,
  );
}

describe("TileOffer", () => {
  it("offers one action before the choice and no variant, list or „Do koszyka”", () => {
    renderOffer(COLOURS);

    expect(choose()).toBeTruthy();
    expect(screen.queryAllByRole("radio")).toHaveLength(0);
    expect(screen.queryByRole("button", { name: "Do koszyka" })).toBeNull();
    expect(screen.queryByRole("combobox")).toBeNull();
  });

  it("moves the focus to the first available variant, not to an unavailable one", () => {
    renderOffer(COLOURS);

    fireEvent.click(choose());

    expect(radio(/pomarańczowy/).disabled).toBe(true);
    expect(document.activeElement).toBe(radio("zielony"));
  });

  it("announces the expansion in the live region, which is in the DOM before it", () => {
    renderOffer(COLOURS);
    expect(live().getAttribute("aria-live")).toBe("polite");
    expect(live().textContent).toBe("");

    fireEvent.click(choose());

    expect(live().textContent).toBe("Rozwinięto wybór wariantu: Kolor. Dostępne warianty: 2 z 3.");
  });

  it("announces the price and the availability of the chosen variant and again after a change", () => {
    renderOffer(COLOURS);
    fireEvent.click(choose());

    fireEvent.click(radio("zielony"));
    expect(live().textContent).toBe("Wybrano wariant: zielony. 2499,00\u00a0zł, Ostatnie sztuki.");

    fireEvent.click(radio("niebieski"));
    expect(live().textContent).toBe("Wybrano wariant: niebieski. 2499,00\u00a0zł, Dostępny.");
  });

  it("shows the price, the availability and „Do koszyka” of the chosen variant", () => {
    renderOffer(COLOURS);
    fireEvent.click(choose());

    fireEvent.click(radio("zielony"));

    expect(screen.getByTestId("price").textContent).toBe("2499,00\u00a0zł");
    expect(screen.getByTestId("availability").textContent).toBe("Ostatnie sztuki");
    expect(screen.getByRole("button", { name: "Do koszyka" })).toBeTruthy();
  });

  it("shows an axis of one name as buttons in a row and the group named after the axis", () => {
    renderOffer(COLOURS);
    fireEvent.click(choose());

    expect(screen.getByRole("group", { name: "Kolor" })).toBeTruthy();
    expect(screen.getByTestId("variant-options").getAttribute("data-form")).toBe("buttons");
    expect(radios().map((input) => input.closest("label")?.textContent)).toEqual(["pomarańczowyniedostępny", "zielony", "niebieski"]);
  });

  it("shows two axes as a list of the whole labels in the order of the offer, 14 of them, the first available focused", () => {
    renderOffer(COLOUR_SIZES);
    fireEvent.click(choose());

    expect(screen.getByRole("group", { name: "Kolor, Rozmiar" })).toBeTruthy();
    expect(screen.getByTestId("variant-options").getAttribute("data-form")).toBe("list");
    expect(radios()).toHaveLength(14);
    expect(radios()[0].closest("label")?.textContent).toBe("czarne, Mniedostępny");
    expect(radios()[13].closest("label")?.textContent).toBe("żółte, L");
    expect(radios()[0].disabled).toBe(true);
    expect(document.activeElement).toBe(radio("czarne, L"));
  });

  it("chooses a variant of two axes and adds it", async () => {
    vi.mocked(addCartItem).mockResolvedValue({ status: "ok", data: { id: "c", lines: [], totals: { subtotalGross: 0, discountGross: 0, gross: 0, currency: "PLN", itemCount: 1 }, hasExcludedLines: false, discount: null } });
    renderOffer(COLOUR_SIZES);
    fireEvent.click(choose());

    fireEvent.click(radio("szare, L"));

    expect(screen.getByTestId("price").textContent).toBe("301,00\u00a0zł");
    fireEvent.click(screen.getByRole("button", { name: "Do koszyka" }));
    await screen.findByText("Dodano do koszyka");
    expect(addCartItem).toHaveBeenCalledExactlyOnceWith(COLOUR_SIZES.variants[3].variantId, 1);
  });

  it("has „Do koszyka” at once for a single variant, with no choice and no „Wybierz wariant”", () => {
    renderOffer(makeOffer(3));

    expect(screen.queryByRole("button", { name: "Wybierz wariant" })).toBeNull();
    expect(screen.queryAllByRole("radio")).toHaveLength(0);
    expect((screen.getByRole("button", { name: "Do koszyka" }) as HTMLButtonElement).disabled).toBe(false);
  });
});
