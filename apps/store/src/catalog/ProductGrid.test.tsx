// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { makeProduct } from "./fixtures";
import { ProductGrid } from "./ProductGrid";

afterEach(cleanup);

describe("ProductGrid", () => {
  it("renders one tile per product", () => {
    render(<ProductGrid products={[makeProduct(1), makeProduct(2), makeProduct(3)]} prices={{}} availability={{}} />);
    expect(screen.getAllByRole("listitem")).toHaveLength(3);
    expect(screen.getByText("Produkt 2")).toBeTruthy();
  });

  it("gives each tile its own price and marks the products without one", () => {
    const [one, two] = [makeProduct(1), makeProduct(2)];
    render(<ProductGrid products={[one, two]} prices={{ [one.id]: { productId: one.id, gross: 5490, currency: "PLN" } }} availability={{}} />);
    const tiles = screen.getAllByRole("listitem");
    expect(tiles[0].textContent).toContain("54,90");
    expect(tiles[1].textContent).toContain("Cena niedostępna");
  });

  it("gives each tile its own availability label and leaves out the label of a product without one", () => {
    const [one, two, three] = [makeProduct(1), makeProduct(2), makeProduct(3)];
    render(
      <ProductGrid
        products={[one, two, three]}
        prices={{}}
        availability={{ [one.id]: { productId: one.id, level: "in_stock" }, [two.id]: { productId: two.id, level: "out_of_stock" } }}
      />,
    );
    const tiles = screen.getAllByRole("listitem");
    expect(tiles[0].textContent).toContain("Dostępny");
    expect(tiles[1].textContent).toContain("Niedostępny");
    expect(tiles[2].textContent).not.toMatch(/Dostępny|Niedostępny|Ostatnie sztuki/);
  });

  it("renders the photo with a srcset", () => {
    render(<ProductGrid products={[makeProduct(1)]} prices={{}} availability={{}} />);
    const img = screen.getByAltText("Zdjęcie 1");
    expect(img.getAttribute("srcset")).toContain("256w");
    expect(img.getAttribute("sizes")).toBe("(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw");
  });

  it("renders a placeholder for a product without a photo", () => {
    render(<ProductGrid products={[makeProduct(1, { image: null })]} prices={{}} availability={{}} />);
    expect(screen.getByRole("img", { name: "Brak zdjęcia" })).toBeTruthy();
    expect(screen.getByText("Produkt 1")).toBeTruthy();
  });

  it("renders a placeholder for an image without WebP variants", () => {
    const product = makeProduct(1);
    product.image = { ...product.image!, variants: {} };
    render(<ProductGrid products={[product]} prices={{}} availability={{}} />);
    expect(screen.getByRole("img", { name: "Brak zdjęcia" })).toBeTruthy();
  });

  it("shows a message with a link to the whole catalogue for an empty list", () => {
    render(<ProductGrid products={[]} prices={{}} availability={{}} />);
    expect(screen.getByText("Brak produktów do wyświetlenia.")).toBeTruthy();
    expect(screen.getByRole("link", { name: "Zobacz cały katalog" }).getAttribute("href")).toBe("/");
    expect(screen.queryAllByRole("listitem")).toHaveLength(0);
  });
});
