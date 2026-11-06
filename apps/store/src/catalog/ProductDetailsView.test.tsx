// @vitest-environment jsdom
import { cleanup, render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { makeDetails, makeOffer, makeVariant, variantId } from "./fixtures";
import { ProductDetailsView } from "./ProductDetailsView";

vi.mock("@/api/client", () => ({ addCartItem: vi.fn() }));

afterEach(cleanup);

const SHOES = makeOffer(1, {
  variantAxes: ["Kolor", "Rozmiar"],
  fromGross: 30000,
  hasPriceRange: true,
  level: "in_stock",
  variants: [
    makeVariant(1, 1, { label: "czarne, 42", gross: 30000, level: "in_stock" }),
    makeVariant(1, 2, { label: "czarne, 44", gross: 32000, level: "low_stock" }),
  ],
});

// The details that go with SHOES: the same two variants, the ids of the offer.
const SHOES_DETAILS = makeDetails(1, {
  variantAxes: ["Kolor", "Rozmiar"],
  variants: [
    { variantId: variantId(1, 1), label: "czarne, 42", values: ["czarne", "42"] },
    { variantId: variantId(1, 2), label: "czarne, 44", values: ["czarne", "44"] },
  ],
});

describe("ProductDetailsView", () => {
  it("shows the name as the one h1 and the short description", () => {
    render(<ProductDetailsView details={makeDetails(1, { name: "Buty Asfalt", shortDescription: "Lekkie buty." })} offer={makeOffer(1)} />);
    expect(screen.getByRole("heading", { level: 1 }).textContent).toBe("Buty Asfalt");
    expect(screen.getByText("Lekkie buty.")).toBeTruthy();
  });

  it("leaves the description out when there is none", () => {
    const { container } = render(<ProductDetailsView details={makeDetails(1, { shortDescription: "" })} offer={makeOffer(1)} />);
    expect(container.querySelector("[data-testid=description]")).toBeNull();
  });

  describe("breadcrumb", () => {
    it("starts with All products and links the categories of the path to the filtered list", () => {
      render(<ProductDetailsView details={makeDetails(1)} offer={makeOffer(1)} />);
      const nav = screen.getByRole("navigation", { name: "Ścieżka" });
      const links = within(nav).getAllByRole("link");
      expect(links.map((link) => [link.textContent, link.getAttribute("href")])).toEqual([
        ["Wszystkie produkty", "/"],
        ["Bieganie", "/?category=bieganie"],
        ["Buty", "/?category=buty"],
      ]);
    });
  });

  describe("photos", () => {
    it("shows all the photos in order, one under the other, and only the first one with priority", () => {
      render(<ProductDetailsView details={makeDetails(1)} offer={makeOffer(1)} />);
      const photos = screen.getAllByRole("img") as HTMLImageElement[];
      expect(photos.map((photo) => photo.alt)).toEqual(["Zdjęcie 1, pierwsze", "Zdjęcie 1, drugie"]);
      expect(photos.map((photo) => photo.getAttribute("loading"))).toEqual(["eager", "lazy"]);
      expect(photos[0].getAttribute("fetchpriority")).toBe("high");
      expect(photos[1].getAttribute("fetchpriority")).toBeNull();
      expect(photos[0].srcset).toContain("http://imgproxy.test/a1-640/plain.webp 640w");
      expect(photos[0].srcset).toContain("http://imgproxy.test/a1-1600/plain.webp 1600w");
    });

    it("shows the placeholder of the tile for a product without photos", () => {
      render(<ProductDetailsView details={makeDetails(1, { images: [] })} offer={makeOffer(1)} />);
      expect(screen.getAllByRole("img").map((img) => img.getAttribute("aria-label"))).toEqual(["Brak zdjęcia"]);
    });
  });

  describe("price and level", () => {
    it("shows 'od X zł' when the purchasable variants cost differently, with the level of the product", () => {
      render(<ProductDetailsView details={SHOES_DETAILS} offer={SHOES} />);
      expect(screen.getByTestId("price").textContent).toBe("od 300,00\u00a0zł");
      expect(screen.getByTestId("availability").textContent).toBe("Dostępny");
    });

    it("shows 'X zł' for one price", () => {
      render(<ProductDetailsView details={makeDetails(1)} offer={makeOffer(1)} />);
      expect(screen.getByTestId("price").textContent).toBe("123,00\u00a0zł");
    });

    it("names the level of the product as the API gave it", () => {
      render(<ProductDetailsView details={makeDetails(1)} offer={makeOffer(1, { level: "low_stock", variants: [makeVariant(1, 1, { level: "low_stock" })] })} />);
      expect(screen.getByTestId("availability").textContent).toBe("Ostatnie sztuki");
    });

    it("shows 'Niedostępny' without a price for a product without a purchasable variant", () => {
      const offer = makeOffer(1, { fromGross: null, level: "out_of_stock", variants: [makeVariant(1, 1, { gross: 12300, level: "out_of_stock", purchasable: false })] });
      render(<ProductDetailsView details={makeDetails(1)} offer={offer} />);
      expect(screen.queryByTestId("price")).toBeNull();
      expect(screen.getByTestId("availability").textContent).toBe("Niedostępny");
    });
  });
  describe("variant choice and add to cart", () => {
    it("offers a choice per axis and a disabled 'Do koszyka' button for a product with several variants", () => {
      render(<ProductDetailsView details={SHOES_DETAILS} offer={SHOES} />);
      expect(screen.getAllByRole("group").map((group) => group.querySelector("legend")?.textContent)).toEqual(["Kolor", "Rozmiar"]);
      expect((screen.getByRole("button", { name: "Do koszyka" }) as HTMLButtonElement).disabled).toBe(true);
      expect(screen.getByTestId("price").textContent).toBe("od 300,00\u00a0zł");
    });

    it("offers no choice and no button for a product without a purchasable variant", () => {
      const offer = makeOffer(1, { fromGross: null, level: "out_of_stock", variants: [makeVariant(1, 1, { gross: 12300, level: "out_of_stock", purchasable: false })] });
      render(<ProductDetailsView details={makeDetails(1)} offer={offer} />);
      expect(screen.queryByRole("button")).toBeNull();
      expect(screen.queryByRole("group")).toBeNull();
    });
  });
});
