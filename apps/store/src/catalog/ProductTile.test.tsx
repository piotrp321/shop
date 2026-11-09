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
const choose = () => screen.queryByRole("button", { name: "Wybierz wariant" }) as HTMLButtonElement | null;
const variantRadio = (label: string | RegExp) => screen.getByRole("radio", { name: label }) as HTMLInputElement;
const expand = () => fireEvent.click(choose()!);
const cartWith = (itemCount: number) => ({ id: "c", lines: [], totals: { subtotalGross: 0, discountGross: 0, gross: 0, currency: "PLN" as const, itemCount }, hasExcludedLines: false, discount: null });

// Shoes in three sizes: 42 at 300\u00a0zł, 43 not purchasable, 44 at 320\u00a0zł with the last units.
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
      expect(choose()).toBeNull();
      expect(screen.queryAllByRole("radio")).toHaveLength(0);
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
    it("shows the from price and the level of the product and one active „Wybierz wariant” without a list or „Do koszyka”", () => {
      render(<ProductTile product={product} offer={SIZES_OFFER} sizes={SIZES} />);

      expect(screen.getByTestId("price").textContent).toBe("od 300,00\u00a0zł");
      expect(screen.getByTestId("availability").textContent).toBe("Dostępny");
      expect(choose()?.disabled).toBe(false);
      expect(screen.queryByRole("combobox")).toBeNull();
      expect(screen.queryAllByRole("radio")).toHaveLength(0);
      expect(button()).toBeNull();
    });

    it("lists the variants of the offer in its order without a default choice after „Wybierz wariant”, which then disappears", () => {
      render(<ProductTile product={product} offer={SIZES_OFFER} sizes={SIZES} />);

      expand();

      expect(choose()).toBeNull();
      expect(screen.getByRole("group", { name: "Kolor, Rozmiar" })).toBeTruthy();
      const radios = screen.getAllByRole("radio") as HTMLInputElement[];
      expect(radios.map((radio) => [radio.closest("label")?.textContent, radio.disabled, radio.checked])).toEqual([
        ["czarne, 42", false, false],
        ["czarne, 43niedostępny", true, false],
        ["czarne, 44", false, false],
      ]);
      expect(button()).toBeNull();
      expect(screen.getByTestId("price").textContent).toBe("od 300,00\u00a0zł");
    });

    it("shows the price and the level of the chosen variant and an active „Do koszyka” that adds it", async () => {
      render(<ProductTile product={product} offer={SIZES_OFFER} sizes={SIZES} />);
      expand();

      fireEvent.click(variantRadio("czarne, 44"));

      expect(variantRadio("czarne, 44").checked).toBe(true);
      expect(screen.getByTestId("price").textContent).toBe("320,00\u00a0zł");
      expect(screen.getByTestId("availability").textContent).toBe("Ostatnie sztuki");
      expect(button()?.disabled).toBe(false);
      fireEvent.click(button()!);
      await screen.findByText("Dodano do koszyka");
      expect(addCartItem).toHaveBeenCalledExactlyOnceWith(variantId(1, 3), 1);
    });

    it("changes the choice to another variant: the price follows and „Do koszyka” adds the new one", async () => {
      render(<ProductTile product={product} offer={SIZES_OFFER} sizes={SIZES} />);
      expand();
      fireEvent.click(variantRadio("czarne, 44"));

      fireEvent.click(variantRadio("czarne, 42"));

      expect(screen.getByTestId("price").textContent).toBe("300,00\u00a0zł");
      expect(screen.getByTestId("availability").textContent).toBe("Dostępny");
      fireEvent.click(button()!);
      await screen.findByText("Dodano do koszyka");
      expect(addCartItem).toHaveBeenCalledExactlyOnceWith(variantId(1, 1), 1);
    });

    it("cannot choose a variant that is not purchasable", () => {
      render(<ProductTile product={product} offer={SIZES_OFFER} sizes={SIZES} />);
      expand();

      fireEvent.click(variantRadio(/czarne, 43/));

      expect(variantRadio(/czarne, 43/).disabled).toBe(true);
      expect(button()).toBeNull();
      expect(screen.getByTestId("price").textContent).toBe("od 300,00\u00a0zł");
    });

    it("shows the price without from when the purchasable variants cost the same", () => {
      render(<ProductTile product={product} offer={{ ...SIZES_OFFER, fromGross: 30000, hasPriceRange: false }} sizes={SIZES} />);

      expect(screen.getByTestId("price").textContent).toBe("300,00\u00a0zł");
    });

    it("names the group Wybierz wariant when the product names no axes", () => {
      render(<ProductTile product={product} offer={{ ...SIZES_OFFER, variantAxes: [] }} sizes={SIZES} />);
      expand();

      expect(screen.getByRole("group", { name: "Wybierz wariant" })).toBeTruthy();
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
    ])("is unavailable without a price, a choice and a button: %s", (_name, offer) => {
      render(<ProductTile product={product} offer={offer} sizes={SIZES} />);

      expect(screen.getByTestId("availability").textContent).toBe("Niedostępny");
      expect(screen.queryByTestId("price")).toBeNull();
      expect(screen.queryByText(/zł/)).toBeNull();
      expect(choose()).toBeNull();
      expect(button()).toBeNull();
      expect(screen.getByText("Produkt 1")).toBeTruthy();
    });
  });

  describe("when the offers could not be loaded", () => {
    it("says the price is unavailable and shows no level, choice or button", () => {
      render(<ProductTile product={product} sizes={SIZES} />);

      expect(screen.getByTestId("price").textContent).toBe("Cena niedostępna");
      expect(screen.queryByTestId("availability")).toBeNull();
      expect(choose()).toBeNull();
      expect(button()).toBeNull();
      expect(screen.getByText("Produkt 1")).toBeTruthy();
    });
  });
});

describe("ProductTile promotion", () => {
  const OMNIBUS = /^Najniższa cena z 30 dni przed obniżką: /;
  const badge = () => screen.queryByTestId("promotion-badge");
  const omnibus = () => screen.queryByTestId("omnibus");
  const PROMOTION = { endsAt: "2026-12-01T00:00:00+01:00", lowestPriorGross: 29900 };

  // 300\u00a0zł regular for the first variant, promoted to 240\u00a0zł; the second is not promoted.
  const PROMOTED = makeOffer(1, {
    variantAxes: ["Rozmiar"],
    fromGross: 24000,
    fromPromotion: { lowestPriorGross: 29900 },
    hasPriceRange: true,
    variants: [
      makeVariant(1, 1, { label: "42", gross: 24000, regularGross: 30000, promotion: PROMOTION }),
      makeVariant(1, 2, { label: "44", gross: 32000 }),
    ],
  });

  it("shows the effective 'od' price with the badge and the Omnibus line for fromPromotion, and no regular price", () => {
    render(<ProductTile product={product} offer={PROMOTED} sizes={SIZES} />);

    expect(screen.getByTestId("price").textContent).toBe("od 240,00\u00a0zł");
    expect(badge()?.textContent).toBe("Promocja");
    expect(omnibus()?.textContent).toBe("Najniższa cena z 30 dni przed obniżką: 299,00\u00a0zł");
    expect(screen.queryByText(/300,00/)).toBeNull();
  });

  it("shows the badge and the Omnibus line together or neither: fromPromotion without lowestPriorGross shows neither", () => {
    render(<ProductTile product={product} offer={{ ...PROMOTED, fromPromotion: { lowestPriorGross: null } }} sizes={SIZES} />);

    expect(screen.getByTestId("price").textContent).toBe("od 240,00\u00a0zł");
    expect(badge()).toBeNull();
    expect(omnibus()).toBeNull();
  });

  it("shows neither without a promotion", () => {
    render(<ProductTile product={product} offer={makeOffer(1)} sizes={SIZES} />);

    expect(badge()).toBeNull();
    expect(omnibus()).toBeNull();
  });

  it("shows the Omnibus line of the chosen promoted variant with its price", () => {
    render(<ProductTile product={product} offer={{ ...PROMOTED, fromPromotion: { lowestPriorGross: 29900 }, variants: [
      makeVariant(1, 1, { label: "42", gross: 24000, regularGross: 30000, promotion: { ...PROMOTION, lowestPriorGross: 27000 } }),
      makeVariant(1, 2, { label: "44", gross: 32000 }),
    ] }} sizes={SIZES} />);

    expand();
    fireEvent.click(variantRadio("42"));

    expect(screen.getByTestId("price").textContent).toBe("240,00\u00a0zł");
    expect(badge()?.textContent).toBe("Promocja");
    expect(omnibus()?.textContent).toBe("Najniższa cena z 30 dni przed obniżką: 270,00\u00a0zł");
    expect(screen.getAllByText(OMNIBUS)).toHaveLength(1);
  });

  it("shows neither badge nor line for a chosen variant without a promotion, though the 'od' price had them", () => {
    render(<ProductTile product={product} offer={PROMOTED} sizes={SIZES} />);

    expand();
    fireEvent.click(variantRadio("44"));

    expect(screen.getByTestId("price").textContent).toBe("320,00\u00a0zł");
    expect(badge()).toBeNull();
    expect(omnibus()).toBeNull();
  });

  it("shows the promotional price of a chosen variant with lowestPriorGross null without badge and line", () => {
    const offer = { ...PROMOTED, fromPromotion: null, variants: [
      makeVariant(1, 1, { label: "42", gross: 24000, regularGross: 30000, promotion: { ...PROMOTION, lowestPriorGross: null } }),
      makeVariant(1, 2, { label: "44", gross: 32000 }),
    ] };
    render(<ProductTile product={product} offer={offer} sizes={SIZES} />);

    expand();
    fireEvent.click(variantRadio("42"));

    expect(screen.getByTestId("price").textContent).toBe("240,00\u00a0zł");
    expect(badge()).toBeNull();
    expect(omnibus()).toBeNull();
  });

  it("shows the badge and the line of a single promoted variant", () => {
    const offer = makeOffer(1, {
      fromGross: 24000,
      fromPromotion: { lowestPriorGross: 29900 },
      variants: [makeVariant(1, 1, { gross: 24000, regularGross: 30000, promotion: PROMOTION })],
    });
    render(<ProductTile product={product} offer={offer} sizes={SIZES} />);

    expect(screen.getByTestId("price").textContent).toBe("240,00\u00a0zł");
    expect(badge()?.textContent).toBe("Promocja");
    expect(omnibus()?.textContent).toBe("Najniższa cena z 30 dni przed obniżką: 299,00\u00a0zł");
  });
});
