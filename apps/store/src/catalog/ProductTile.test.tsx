// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { makeProduct } from "./fixtures";
import { ProductTile } from "./ProductTile";

afterEach(cleanup);

const SIZES = "50vw";

describe("ProductTile", () => {
  it("shows the gross price formatted for Poland", () => {
    render(<ProductTile product={makeProduct(1)} price={{ productId: makeProduct(1).id, gross: 12300, currency: "PLN" }} sizes={SIZES} />);
    // getByText collapses the no-break space, so the exact text is read from the element.
    expect(screen.getByText(/123,00/).textContent).toBe("123,00\u00a0zł");
    expect(screen.queryByText("Cena niedostępna")).toBeNull();
    expect(screen.getByTestId("price").textContent).toBe("123,00\u00a0zł");
  });

  it("says the price is unavailable without one", () => {
    render(<ProductTile product={makeProduct(1)} sizes={SIZES} />);
    expect(screen.getByText("Cena niedostępna")).toBeTruthy();
    expect(screen.getByText("Produkt 1")).toBeTruthy();
  });

  describe("availability", () => {
    const productId = makeProduct(1).id;

    it.each([
      ["in_stock", "Dostępny"],
      ["low_stock", "Ostatnie sztuki"],
      ["out_of_stock", "Niedostępny"],
    ] as const)("shows the label of %s", (level, label) => {
      render(<ProductTile product={makeProduct(1)} availability={{ productId, level }} sizes={SIZES} />);
      expect(screen.getByText(label)).toBeTruthy();
      expect(screen.getByTestId("availability").textContent).toBe(label);
      for (const other of ["Dostępny", "Ostatnie sztuki", "Niedostępny"].filter((text) => text !== label)) {
        expect(screen.queryByText(other)).toBeNull();
      }
    });

    it("shows no label without availability", () => {
      render(<ProductTile product={makeProduct(1)} sizes={SIZES} />);
      for (const label of ["Dostępny", "Ostatnie sztuki", "Niedostępny"]) {
        expect(screen.queryByText(label)).toBeNull();
      }
      expect(screen.getByText("Cena niedostępna")).toBeTruthy();
      expect(screen.queryByTestId("availability")).toBeNull();
    });
  });
});
