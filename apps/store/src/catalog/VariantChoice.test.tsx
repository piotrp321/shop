// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { addCartItem } from "@/api/client";
import { makeDetails, makeOffer, makeVariant, variantId } from "./fixtures";
import { VariantChoice } from "./VariantChoice";

vi.mock("@/api/client", () => ({ addCartItem: vi.fn() }));

const cart = { id: "c", lines: [], totals: { gross: 0, currency: "PLN" as const, itemCount: 1 }, hasExcludedLines: false };

beforeEach(() => {
  vi.mocked(addCartItem).mockReset();
  vi.mocked(addCartItem).mockResolvedValue({ status: "ok", data: cart });
});
afterEach(cleanup);

type Row = [color: string, size: string, purchasable: boolean];

function shoes(rows: Row[]) {
  const details = makeDetails(1, {
    variantAxes: ["Kolor", "Rozmiar"],
    variants: rows.map(([color, size], index) => ({ variantId: variantId(1, index + 1), label: `${color}, ${size}`, values: [color, size] })),
  });
  const offer = makeOffer(1, {
    variantAxes: ["Kolor", "Rozmiar"],
    fromGross: 30000,
    hasPriceRange: true,
    variants: rows.map(([color, size, purchasable], index) =>
      makeVariant(1, index + 1, {
        label: `${color}, ${size}`,
        purchasable,
        gross: 30000 + index * 2000,
        level: index % 2 === 0 ? "in_stock" : "low_stock",
      }),
    ),
  });
  return { details, offer };
}

function renderChoice(rows: Row[]) {
  const { details, offer } = shoes(rows);
  return render(
    <VariantChoice details={details} offer={offer}>
      <p data-testid="price">od 300,00&nbsp;zł</p>
    </VariantChoice>,
  );
}

const value = (name: string) => screen.getByRole("button", { name }) as HTMLButtonElement;
const click = (name: string) => fireEvent.click(value(name));
const addButton = () => screen.getByRole("button", { name: "Do koszyka" }) as HTMLButtonElement;
const pressed = (name: string) => value(name).getAttribute("aria-pressed");

const FOUR: Row[] = [
  ["czarne", "42", true],
  ["czarne", "44", false],
  ["białe", "42", false],
  ["białe", "44", true],
];

describe("VariantChoice", () => {
  it("offers one group of values per axis, named after the axis, in the order of first appearance", () => {
    renderChoice([
      ["niebieskie", "42", true],
      ["czarne", "41", true],
      ["czarne", "42", true],
    ]);
    const groups = screen.getAllByRole("group");
    expect(groups.map((group) => group.getAttribute("aria-label") ?? group.querySelector("legend")?.textContent)).toEqual(["Kolor", "Rozmiar"]);
    expect(within(groups[0]).getAllByRole("button").map((button) => button.textContent)).toEqual(["niebieskie", "czarne"]);
    expect(within(groups[1]).getAllByRole("button").map((button) => button.textContent)).toEqual(["42", "41"]);
  });

  it("starts without a choice: the price of the product, a disabled button and the later axis waiting", () => {
    renderChoice(FOUR);
    expect(screen.getByTestId("price").textContent).toBe("od 300,00 zł");
    expect(screen.queryByTestId("availability")).toBeNull();
    expect(addButton().disabled).toBe(true);
    expect(pressed("czarne")).toBe("false");
    expect(value("42").disabled).toBe(true);
    expect(value("44").disabled).toBe(true);
  });

  it("disables a value of the first axis without a purchasable variant, with a note", () => {
    renderChoice([
      ["czarne", "42", true],
      ["zielone", "42", false],
    ]);
    expect(value("czarne").disabled).toBe(false);
    const green = screen.getByRole("button", { name: "zielone niedostępny" }) as HTMLButtonElement;
    expect(green.disabled).toBe(true);
  });

  it("computes the later axis against the choice of the earlier one", () => {
    renderChoice(FOUR);
    click("czarne");
    expect(value("42").disabled).toBe(false);
    expect((screen.getByRole("button", { name: "44 niedostępny" }) as HTMLButtonElement).disabled).toBe(true);
    click("białe");
    expect(value("44").disabled).toBe(false);
    expect((screen.getByRole("button", { name: "42 niedostępny" }) as HTMLButtonElement).disabled).toBe(true);
  });

  it("shows the price and the level of the chosen variant and enables the button", () => {
    renderChoice(FOUR);
    click("białe");
    click("44");
    // The fourth variant: 30000 + 3 * 2000, level low_stock (odd index).
    expect(screen.getByTestId("price").textContent).toBe("360,00 zł");
    expect(screen.getByTestId("availability").textContent).toBe("Ostatnie sztuki");
    expect(addButton().disabled).toBe(false);
  });

  it("goes back to the price of the product and a disabled button when a change clears the later choice", () => {
    renderChoice(FOUR);
    click("czarne");
    click("42");
    expect(screen.getByTestId("price").textContent).toBe("300,00 zł");
    click("białe");
    expect(pressed("białe")).toBe("true");
    // 42 is no longer purchasable with białe: cleared and disabled.
    expect(pressed("42 niedostępny")).toBe("false");
    expect(screen.getByTestId("price").textContent).toBe("od 300,00 zł");
    expect(screen.queryByTestId("availability")).toBeNull();
    expect(addButton().disabled).toBe(true);
  });

  it("does not unselect a chosen value on a second click", () => {
    renderChoice(FOUR);
    click("czarne");
    click("czarne");
    expect(pressed("czarne")).toBe("true");
    click("42");
    click("42");
    expect(pressed("42")).toBe("true");
    expect(addButton().disabled).toBe(false);
  });

  it("never blocks the choice when only 'czarne, 42' and 'białe, 44' can be bought", () => {
    renderChoice(FOUR);
    for (const name of ["czarne", "42", "białe", "44", "czarne", "42"]) {
      expect(value(name).disabled).toBe(false);
      click(name);
    }
    expect(pressed("czarne")).toBe("true");
    expect(pressed("42")).toBe("true");
    expect(addButton().disabled).toBe(false);
  });

  it("chooses the only purchasable variant of several at the first render", () => {
    renderChoice([
      ["czarne", "42", false],
      ["czarne", "44", true],
      ["białe", "44", false],
    ]);
    expect(pressed("czarne")).toBe("true");
    expect(pressed("44")).toBe("true");
    expect(screen.getByTestId("price").textContent).toBe("320,00 zł");
    expect(addButton().disabled).toBe(false);
  });

  it("treats a variant missing from the offer as not purchasable", () => {
    const { details, offer } = shoes([
      ["czarne", "42", true],
      ["czarne", "44", true],
    ]);
    const withoutSecond = { ...offer, variants: offer.variants.slice(0, 1) };
    render(
      <VariantChoice details={details} offer={withoutSecond}>
        <p data-testid="price">cena</p>
      </VariantChoice>,
    );
    // The only variant in the offer is chosen from the start; the one missing from it cannot be chosen.
    expect(pressed("42")).toBe("true");
    expect((screen.getByRole("button", { name: "44 niedostępny" }) as HTMLButtonElement).disabled).toBe(true);
  });

  it("adds one unit of the chosen variant", () => {
    renderChoice(FOUR);
    click("białe");
    click("44");
    fireEvent.click(addButton());
    expect(addCartItem).toHaveBeenCalledExactlyOnceWith(variantId(1, 4), 1);
  });

  it("adds nothing while the choice is not complete", () => {
    renderChoice(FOUR);
    click("czarne");
    fireEvent.click(addButton());
    expect(addCartItem).not.toHaveBeenCalled();
  });

  describe("a product with one variant", () => {
    it("shows the values as text, with no choice, and the button adds that variant", () => {
      const { details, offer } = shoes([["czarne", "42", true]]);
      render(
        <VariantChoice details={details} offer={offer}>
          <p data-testid="price">300,00&nbsp;zł</p>
        </VariantChoice>,
      );
      expect(screen.getByText("Kolor: czarne")).toBeTruthy();
      expect(screen.getByText("Rozmiar: 42")).toBeTruthy();
      expect(screen.getAllByRole("button").map((button) => button.textContent)).toEqual(["Do koszyka"]);
      expect(screen.getByTestId("price").textContent).toBe("300,00 zł");
      expect(screen.getByTestId("availability").textContent).toBe("Dostępny");
      fireEvent.click(addButton());
      expect(addCartItem).toHaveBeenCalledExactlyOnceWith(variantId(1, 1), 1);
    });

    it("shows no values for the default variant of a product without axes", () => {
      render(
        <VariantChoice details={makeDetails(1)} offer={makeOffer(1)}>
          <p data-testid="price">123,00&nbsp;zł</p>
        </VariantChoice>,
      );
      expect(screen.queryByRole("group")).toBeNull();
      expect(addButton().disabled).toBe(false);
      fireEvent.click(addButton());
      expect(addCartItem).toHaveBeenCalledExactlyOnceWith(variantId(1, 1), 1);
    });
  });
});
