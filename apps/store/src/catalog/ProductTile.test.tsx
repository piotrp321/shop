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

  describe("the add to cart button", () => {
    const product = makeProduct(1);
    const price = { productId: product.id, gross: 12300, currency: "PLN" } as const;
    const button = () => screen.queryByRole("button", { name: "Do koszyka" });

    it.each([["in_stock"], ["low_stock"]] as const)("is shown for a product with a price that is %s", (level) => {
      render(<ProductTile product={product} price={price} availability={{ productId: product.id, level }} sizes={SIZES} />);
      expect(button()).not.toBeNull();
    });

    it("is shown when the availability could not be loaded (the API decides)", () => {
      render(<ProductTile product={product} price={price} sizes={SIZES} />);
      expect(button()).not.toBeNull();
    });

    it("is not shown for an out of stock product", () => {
      render(<ProductTile product={product} price={price} availability={{ productId: product.id, level: "out_of_stock" }} sizes={SIZES} />);
      expect(button()).toBeNull();
    });

    it.each([[undefined], ["in_stock"]] as const)("is not shown for a product without a price (availability %s)", (level) => {
      render(<ProductTile product={product} availability={level && { productId: product.id, level }} sizes={SIZES} />);
      expect(button()).toBeNull();
    });
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
