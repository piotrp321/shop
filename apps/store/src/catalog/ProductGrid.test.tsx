// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { makeOffer, makeProduct } from "./fixtures";
import { ProductGrid } from "./ProductGrid";

afterEach(cleanup);

describe("ProductGrid", () => {
  it("renders one tile per product", () => {
    render(<ProductGrid products={[makeProduct(1), makeProduct(2), makeProduct(3)]} offers={{}} />);
    expect(screen.getAllByRole("listitem")).toHaveLength(3);
    expect(screen.getByText("Produkt 2")).toBeTruthy();
  });

  it("gives each tile the offer of its product and marks the products without one", () => {
    const [one, two, three] = [makeProduct(1), makeProduct(2), makeProduct(3)];
    render(
      <ProductGrid
        products={[one, two, three]}
        offers={{ [one.id]: makeOffer(1, { fromGross: 5490 }), [two.id]: makeOffer(2, { fromGross: null, level: "out_of_stock", variants: [] }) }}
      />,
    );
    const tiles = screen.getAllByRole("listitem");
    expect(tiles[0].textContent).toContain("54,90");
    expect(tiles[0].textContent).toContain("Dostępny");
    expect(tiles[1].textContent).toContain("Niedostępny");
    expect(tiles[2].textContent).toContain("Cena niedostępna");
    expect(tiles[2].textContent).not.toMatch(/Dostępny|Niedostępny|Ostatnie sztuki/);
  });

  it("renders the photo with a srcset", () => {
    render(<ProductGrid products={[makeProduct(1)]} offers={{}} />);
    const img = screen.getByAltText("Zdjęcie 1");
    expect(img.getAttribute("srcset")).toContain("256w");
    expect(img.getAttribute("sizes")).toBe("(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw");
  });

  it("renders a placeholder for a product without a photo", () => {
    render(<ProductGrid products={[makeProduct(1, { image: null })]} offers={{}} />);
    expect(screen.getByRole("img", { name: "Brak zdjęcia" })).toBeTruthy();
    expect(screen.getByText("Produkt 1")).toBeTruthy();
  });

  it("renders a placeholder for an image without WebP variants", () => {
    const product = makeProduct(1);
    product.image = { ...product.image!, variants: {} };
    render(<ProductGrid products={[product]} offers={{}} />);
    expect(screen.getByRole("img", { name: "Brak zdjęcia" })).toBeTruthy();
  });

  it("shows a message with a link to the whole catalogue for an empty list", () => {
    render(<ProductGrid products={[]} offers={{}} />);
    expect(screen.getByText("Brak produktów do wyświetlenia.")).toBeTruthy();
    expect(screen.getByRole("link", { name: "Zobacz cały katalog" }).getAttribute("href")).toBe("/");
    expect(screen.queryAllByRole("listitem")).toHaveLength(0);
  });
});
