// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { addCartItem } from "@/api/client";
import { makeOffer, makeProduct, makeVariant, variantId } from "./fixtures";
import { ProductTile } from "./ProductTile";

vi.mock("@/api/client", () => ({ addCartItem: vi.fn() }));

const SIZES = "50vw";
const product = makeProduct(1);
const button = () => screen.queryByRole("button", { name: "Do koszyka" }) as HTMLButtonElement | null;
const picker = () => screen.queryByRole("combobox") as HTMLSelectElement | null;
const cartWith = (itemCount: number) => ({ id: "c", lines: [], totals: { gross: 0, currency: "PLN" as const, itemCount }, hasExcludedLines: false });

// Shoes in three sizes: 42 at 300 zł, 43 not purchasable, 44 at 320 zł with the last units.
const SIZES_OFFER = makeOffer(1, {
  variantAxes: ["Kolor", "Rozmiar"],
  fromGross: 30000,
  hasPriceRange: true,
  level: "in_stock",
  variants: [
    makeVariant(1, 1, { label: "czarne, 42", gross: 30000, level: "in_stock" }),
    makeVariant(1, 2, { label: "czarne, 43", gross: null, level: "in_stock", purchasable: false }),
    makeVariant(1, 3, { label: "czarne, 44", gross: 32000, level: "low_stock" }),
  ],
});

beforeEach(() => {
  vi.mocked(addCartItem).mockReset();
  vi.mocked(addCartItem).mockResolvedValue({ status: "ok", data: cartWith(1) });
});

afterEach(cleanup);

describe("ProductTile", () => {
  describe("links to the product page", () => {
    it("links the name and the photo to /p/<slug>", () => {
      const { container } = render(<ProductTile product={product} offer={makeOffer(1)} sizes={SIZES} />);

      expect(screen.getByRole("link", { name: product.name }).getAttribute("href")).toBe("/p/produkt-1");
      const links = [...container.querySelectorAll("a")];
      expect(links.map((link) => link.getAttribute("href"))).toEqual(["/p/produkt-1", "/p/produkt-1"]);
      expect(links[0].contains(container.querySelector("img"))).toBe(true);
    });

    it("links a tile without a photo and without an offer too", () => {
      render(<ProductTile product={{ ...product, image: null }} sizes={SIZES} />);
      expect(screen.getByRole("link", { name: product.name }).getAttribute("href")).toBe("/p/produkt-1");
    });

    it("keeps the price and the level out of the link", () => {
      render(<ProductTile product={product} offer={makeOffer(1)} sizes={SIZES} />);
      expect(screen.getByRole("link", { name: product.name }).contains(screen.getByTestId("price"))).toBe(false);
    });
  });

  describe("a product with one variant", () => {
    it("shows the gross price formatted for Poland, the level and the button that adds the variant", async () => {
      render(<ProductTile product={product} offer={makeOffer(1)} sizes={SIZES} />);

      // getByText collapses the no-break space, so the exact text is read from the element.
      expect(screen.getByTestId("price").textContent).toBe("123,00 zł");
      expect(screen.getByTestId("availability").textContent).toBe("Dostępny");
      expect(picker()).toBeNull();
      expect(button()?.disabled).toBe(false);

      fireEvent.click(button()!);

      await screen.findByText("Dodano do koszyka");
      expect(addCartItem).toHaveBeenCalledExactlyOnceWith(variantId(1, 1), 1);
    });

    it.each([
      ["in_stock", "Dostępny"],
      ["low_stock", "Ostatnie sztuki"],
    ] as const)("shows the label of %s", (level, label) => {
      render(<ProductTile product={product} offer={makeOffer(1, { level, variants: [makeVariant(1, 1, { level })] })} sizes={SIZES} />);

      expect(screen.getByTestId("availability").textContent).toBe(label);
    });
  });

  describe("a product with two or more variants", () => {
    it("shows the from price and the level of the product and a list without a default choice", () => {
      render(<ProductTile product={product} offer={SIZES_OFFER} sizes={SIZES} />);

      expect(screen.getByTestId("price").textContent).toBe("od 300,00 zł");
      expect(screen.getByTestId("availability").textContent).toBe("Dostępny");
      const list = screen.getByRole("combobox", { name: "Kolor, Rozmiar" }) as HTMLSelectElement;
      expect(list.value).toBe("");
      expect(Array.from(list.options).map((option) => [option.textContent, option.disabled])).toEqual([
        ["Wybierz wariant", true],
        ["czarne, 42", false],
        ["czarne, 43 – niedostępny", true],
        ["czarne, 44", false],
      ]);
      expect(button()?.disabled).toBe(true);
    });

    it("shows the price and the level of the chosen variant and adds it", async () => {
      render(<ProductTile product={product} offer={SIZES_OFFER} sizes={SIZES} />);

      fireEvent.change(picker()!, { target: { value: variantId(1, 3) } });

      expect(screen.getByTestId("price").textContent).toBe("320,00 zł");
      expect(screen.getByTestId("availability").textContent).toBe("Ostatnie sztuki");
      expect(button()?.disabled).toBe(false);
      fireEvent.click(button()!);
      await screen.findByText("Dodano do koszyka");
      expect(addCartItem).toHaveBeenCalledExactlyOnceWith(variantId(1, 3), 1);
    });

    it("cannot choose a variant that is not purchasable", () => {
      render(<ProductTile product={product} offer={SIZES_OFFER} sizes={SIZES} />);

      fireEvent.change(picker()!, { target: { value: variantId(1, 2) } });

      expect(button()?.disabled).toBe(true);
      expect(screen.getByTestId("price").textContent).toBe("od 300,00 zł");
    });

    it("shows the price without from when the purchasable variants cost the same", () => {
      render(<ProductTile product={product} offer={{ ...SIZES_OFFER, fromGross: 30000, hasPriceRange: false }} sizes={SIZES} />);

      expect(screen.getByTestId("price").textContent).toBe("300,00 zł");
    });

    it("names the list Wybierz wariant when the product names no axes", () => {
      render(<ProductTile product={product} offer={{ ...SIZES_OFFER, variantAxes: [] }} sizes={SIZES} />);

      expect(screen.getByRole("combobox", { name: "Wybierz wariant" })).toBeTruthy();
    });
  });

  describe("a product without a purchasable variant", () => {
    it.each([
      ["one variant", makeOffer(1, { fromGross: null, level: "out_of_stock", variants: [makeVariant(1, 1, { level: "out_of_stock", purchasable: false })] })],
      [
        "two variants",
        makeOffer(1, {
          variantAxes: ["Kolor"],
          fromGross: null,
          level: "out_of_stock",
          variants: [makeVariant(1, 1, { label: "zielony", level: "out_of_stock", purchasable: false }), makeVariant(1, 2, { label: "czerwony", gross: null, purchasable: false })],
        }),
      ],
      ["no variant (unknown to Catalog)", makeOffer(1, { fromGross: null, level: "out_of_stock", variants: [] })],
    ])("is unavailable without a price, a list and a button: %s", (_name, offer) => {
      render(<ProductTile product={product} offer={offer} sizes={SIZES} />);

      expect(screen.getByTestId("availability").textContent).toBe("Niedostępny");
      expect(screen.queryByTestId("price")).toBeNull();
      expect(screen.queryByText(/zł/)).toBeNull();
      expect(picker()).toBeNull();
      expect(button()).toBeNull();
      expect(screen.getByText("Produkt 1")).toBeTruthy();
    });
  });

  describe("when the offers could not be loaded", () => {
    it("says the price is unavailable and shows no level, list or button", () => {
      render(<ProductTile product={product} sizes={SIZES} />);

      expect(screen.getByTestId("price").textContent).toBe("Cena niedostępna");
      expect(screen.queryByTestId("availability")).toBeNull();
      expect(picker()).toBeNull();
      expect(button()).toBeNull();
      expect(screen.getByText("Produkt 1")).toBeTruthy();
    });
  });
});
