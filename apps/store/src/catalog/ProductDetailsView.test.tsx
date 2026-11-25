// @vitest-environment jsdom
import { cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { renderToStaticMarkup } from "react-dom/server";
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
    { variantId: variantId(1, 1), label: "czarne, 42", values: ["czarne", "42"], sku: "", ean: "", gtin: "" },
    { variantId: variantId(1, 2), label: "czarne, 44", values: ["czarne", "44"], sku: "", ean: "", gtin: "" },
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
    it("starts with All products and links the categories of the path to their category pages", () => {
      render(<ProductDetailsView details={makeDetails(1)} offer={makeOffer(1)} />);
      const nav = screen.getByRole("navigation", { name: "Ścieżka" });
      const links = within(nav).getAllByRole("link");
      expect(links.map((link) => [link.textContent, link.getAttribute("href")])).toEqual([
        ["Wszystkie produkty", "/"],
        ["Bieganie", "/c/bieganie"],
        ["Buty", "/c/bieganie/buty"],
      ]);
    });
  });

  describe("photos", () => {
    it("shows the gallery: the photos in order in one strip, only the first one with priority, and a thumbnail link for each", () => {
      render(<ProductDetailsView details={makeDetails(1)} offer={makeOffer(1)} />);
      const photos = screen.getAllByRole("img") as HTMLImageElement[];
      expect(photos.map((photo) => photo.alt)).toEqual(["Zdjęcie 1, pierwsze", "Zdjęcie 1, drugie"]);
      expect(photos.map((photo) => photo.getAttribute("loading"))).toEqual(["eager", "lazy"]);
      expect(photos[0].getAttribute("fetchpriority")).toBe("high");
      expect(photos[1].getAttribute("fetchpriority")).toBeNull();
      expect(photos[0].srcset).toContain("http://imgproxy.test/a1-640/plain.webp 640w");
      expect(photos[0].srcset).toContain("http://imgproxy.test/a1-1600/plain.webp 1600w");
      // Two links per photo: the thumbnails are in the HTML twice (CSS shows one list; jsdom has no CSS).
      expect(screen.getAllByRole("link", { name: /^Pokaż zdjęcie/ })).toHaveLength(4);
      expect(screen.getAllByRole("button", { name: /^Powiększ zdjęcie/ })).toHaveLength(2);
    });

    it("puts the gallery before the data of the product", () => {
      render(<ProductDetailsView details={makeDetails(1)} offer={makeOffer(1)} />);
      const gallery = screen.getByRole("group", { name: "Zdjęcia produktu" });
      const heading = screen.getByRole("heading", { level: 1 });
      expect(gallery.compareDocumentPosition(heading) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
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
      // The gallery is a group too; the choices are the groups with a legend.
      expect(screen.getAllByRole("group").flatMap((group) => group.querySelector("legend")?.textContent ?? [])).toEqual(["Kolor", "Rozmiar"]);
      expect((screen.getByRole("button", { name: "Do koszyka" }) as HTMLButtonElement).disabled).toBe(true);
      expect(screen.getByTestId("price").textContent).toBe("od 300,00\u00a0zł");
    });

    it("offers no choice and no button for a product without a purchasable variant", () => {
      const offer = makeOffer(1, { fromGross: null, level: "out_of_stock", variants: [makeVariant(1, 1, { gross: 12300, level: "out_of_stock", purchasable: false })] });
      render(<ProductDetailsView details={makeDetails(1)} offer={offer} />);
      // The gallery has buttons of its own: the arrows and the enlarge buttons.
      expect(screen.queryByRole("button", { name: "Do koszyka" })).toBeNull();
      // No choice of the variant: the only group is the gallery.
      expect(screen.getAllByRole("group").map((group) => group.getAttribute("aria-label"))).toEqual(["Zdjęcia produktu"]);
      expect(screen.getAllByRole("button").map((button) => button.getAttribute("aria-label"))).toEqual([
        "Powiększ zdjęcie 1 z 2: Zdjęcie 1, pierwsze",
        "Powiększ zdjęcie 2 z 2: Zdjęcie 1, drugie",
        "Poprzednie zdjęcie",
        "Następne zdjęcie",
      ]);
    });
  });
});

describe("ProductDetailsView product data", () => {
  const variant = (n: number, sku: string, ean: string, gtin: string, label = "", values: string[] = []) => ({ variantId: variantId(1, n), label, values, sku, ean, gtin });
  const codes = () => screen.queryByTestId("variant-codes");

  describe("brand", () => {
    it("shows the brand as plain text above the name", () => {
      render(<ProductDetailsView details={makeDetails(1, { brand: "Hallen" })} offer={makeOffer(1)} />);
      const brand = screen.getByTestId("brand");
      expect(brand.textContent).toBe("Hallen");
      expect(brand.compareDocumentPosition(screen.getByRole("heading", { level: 1 })) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
    });

    it("shows no brand for a product without one", () => {
      render(<ProductDetailsView details={makeDetails(1, { brand: "" })} offer={makeOffer(1)} />);
      expect(screen.queryByTestId("brand")).toBeNull();
    });
  });

  describe("description", () => {
    const description = () => screen.queryByRole("heading", { level: 2, name: "Opis" });

    it("shows an h2 'Opis' with a paragraph for each part separated by an empty line", () => {
      render(<ProductDetailsView details={makeDetails(1, { description: "Pierwszy akapit.\n\nDrugi akapit.\n\n\nTrzeci." })} offer={makeOffer(1)} />);
      expect(description()).not.toBeNull();
      const section = screen.getByTestId("product-description");
      expect([...section.querySelectorAll("p")].map((paragraph) => paragraph.textContent)).toEqual(["Pierwszy akapit.", "Drugi akapit.", "Trzeci."]);
    });

    it("turns a single line end into a line break inside the paragraph", () => {
      render(<ProductDetailsView details={makeDetails(1, { description: "Linia jedna.\nLinia dwa.\n\nNowy akapit." })} offer={makeOffer(1)} />);
      const paragraphs = [...screen.getByTestId("product-description").querySelectorAll("p")];
      expect(paragraphs).toHaveLength(2);
      expect(paragraphs[0].innerHTML).toBe("Linia jedna.<br>Linia dwa.");
    });

    it("shows markup literally as text, without creating elements", () => {
      const text = "<script>alert(1)</script> i <b>pogrubienie</b> &amp;";
      render(<ProductDetailsView details={makeDetails(1, { description: text })} offer={makeOffer(1)} />);
      const section = screen.getByTestId("product-description");
      expect(section.querySelector("p")?.textContent).toBe(text);
      expect(section.querySelector("script, b")).toBeNull();
    });

    it("has no section for an empty description", () => {
      render(<ProductDetailsView details={makeDetails(1, { description: "" })} offer={makeOffer(1)} />);
      expect(description()).toBeNull();
      expect(screen.queryByTestId("product-description")).toBeNull();
    });
  });

  describe("parameters", () => {
    it("shows an h2 'Parametry' and a description list in the order of the API", () => {
      const parameters = [
        { name: "Liczba osób", value: "4" },
        { name: "Masa", value: "3,2 kg" },
        { name: "Kolor", value: "zielony" },
      ];
      render(<ProductDetailsView details={makeDetails(1, { parameters })} offer={makeOffer(1)} />);
      expect(screen.getByRole("heading", { level: 2, name: "Parametry" })).toBeTruthy();
      const list = screen.getByTestId("product-parameters");
      expect(list.tagName).toBe("DL");
      expect([...list.querySelectorAll("dt")].map((term) => term.textContent)).toEqual(["Liczba osób", "Masa", "Kolor"]);
      expect([...list.querySelectorAll("dd")].map((value) => value.textContent)).toEqual(["4", "3,2 kg", "zielony"]);
    });

    it("has no section for a product without parameters", () => {
      render(<ProductDetailsView details={makeDetails(1, { parameters: [] })} offer={makeOffer(1)} />);
      expect(screen.queryByRole("heading", { level: 2, name: "Parametry" })).toBeNull();
      expect(screen.queryByTestId("product-parameters")).toBeNull();
    });
  });

  describe("a product without new data", () => {
    it("has no brand, no sections and no codes, and the rest of the card as before", () => {
      render(<ProductDetailsView details={makeDetails(1)} offer={makeOffer(1)} />);
      expect(screen.queryByTestId("brand")).toBeNull();
      expect(screen.queryAllByRole("heading", { level: 2 })).toEqual([]);
      expect(codes()).toBeNull();
      expect(screen.getByRole("heading", { level: 1 }).textContent).toBe("Produkt 1");
      expect(screen.getByTestId("price").textContent).toBe("123,00\u00a0zł");
      expect(screen.getByRole("button", { name: "Do koszyka" })).toBeTruthy();
    });
  });

  describe("product code and EAN", () => {
    it("shows both of the only variant at once", () => {
      render(<ProductDetailsView details={makeDetails(1, { variants: [variant(1, "NAMIOT-HALNY-4", "5901234123457", "5901234123457")] })} offer={makeOffer(1)} />);
      expect(screen.getByText("Kod produktu: NAMIOT-HALNY-4")).toBeTruthy();
      expect(screen.getByText("EAN: 5901234123457")).toBeTruthy();
    });

    it("shows each only when it is not empty", () => {
      const { rerender } = render(<ProductDetailsView details={makeDetails(1, { variants: [variant(1, "ABC-1", "", "")] })} offer={makeOffer(1)} />);
      expect(codes()?.textContent).toBe("Kod produktu: ABC-1");
      rerender(<ProductDetailsView details={makeDetails(1, { variants: [variant(1, "", "5901234123457", "5901234123457")] })} offer={makeOffer(1)} />);
      expect(codes()?.textContent).toBe("EAN: 5901234123457");
      rerender(<ProductDetailsView details={makeDetails(1, { variants: [variant(1, "", "", "")] })} offer={makeOffer(1)} />);
      expect(codes()).toBeNull();
    });

    it("shows the EAN of a number of restricted circulation, for which the API has no gtin", () => {
      render(<ProductDetailsView details={makeDetails(1, { variants: [variant(1, "X-1", "2000000000008", "")] })} offer={makeOffer(1)} />);
      expect(screen.getByText("EAN: 2000000000008")).toBeTruthy();
    });

    it("shows the codes of the only variant also when the product is unavailable", () => {
      const offer = makeOffer(1, { fromGross: null, level: "out_of_stock", variants: [makeVariant(1, 1, { level: "out_of_stock", purchasable: false })] });
      render(<ProductDetailsView details={makeDetails(1, { variants: [variant(1, "ABC-1", "5901234123457", "5901234123457")] })} offer={offer} />);
      expect(screen.getByText("Kod produktu: ABC-1")).toBeTruthy();
      expect(screen.getByText("EAN: 5901234123457")).toBeTruthy();
    });

    it("shows no codes of an unavailable product with several variants, which has no choice", () => {
      const offer = makeOffer(1, {
        variantAxes: ["Rozmiar"],
        fromGross: null,
        level: "out_of_stock",
        variants: [makeVariant(1, 1, { label: "M", purchasable: false }), makeVariant(1, 2, { label: "L", purchasable: false })],
      });
      const details = makeDetails(1, { variantAxes: ["Rozmiar"], variants: [variant(1, "A-M", "", "", "M", ["M"]), variant(2, "A-L", "", "", "L", ["L"])] });
      render(<ProductDetailsView details={details} offer={offer} />);
      expect(codes()).toBeNull();
    });

    describe("a product with several variants", () => {
      const offer = makeOffer(1, {
        variantAxes: ["Rozmiar"],
        fromGross: 10000,
        hasPriceRange: false,
        variants: [makeVariant(1, 1, { label: "M", gross: 10000 }), makeVariant(1, 2, { label: "L", gross: 10000 })],
      });
      const details = makeDetails(1, {
        variantAxes: ["Rozmiar"],
        variants: [variant(1, "A-M", "5901234123457", "5901234123457", "M", ["M"]), variant(2, "A-L", "2000000000008", "", "L", ["L"])],
      });

      it("shows the codes of the variant after it is chosen, and the codes of the next one after another choice", () => {
        render(<ProductDetailsView details={details} offer={offer} />);
        expect(codes()).toBeNull();
        fireEvent.click(screen.getByRole("button", { name: "M" }));
        expect(screen.getByText("Kod produktu: A-M")).toBeTruthy();
        expect(screen.getByText("EAN: 5901234123457")).toBeTruthy();
        fireEvent.click(screen.getByRole("button", { name: "L" }));
        expect(screen.getByText("Kod produktu: A-L")).toBeTruthy();
        expect(screen.getByText("EAN: 2000000000008")).toBeTruthy();
        expect(screen.queryByText("Kod produktu: A-M")).toBeNull();
      });

      it("has no codes in the HTML of the server before a choice, the same for every visitor", () => {
        const html = renderToStaticMarkup(<ProductDetailsView details={details} offer={offer} />);
        expect(html).not.toContain("Kod produktu");
        expect(html).not.toContain("EAN:");
      });

      it("shows at once the codes of the only purchasable variant, as the choice does", () => {
        const onlyOne = { ...offer, variants: [makeVariant(1, 1, { label: "M", gross: 10000 }), makeVariant(1, 2, { label: "L", gross: null, purchasable: false })] };
        const html = renderToStaticMarkup(<ProductDetailsView details={details} offer={onlyOne} />);
        expect(html).toContain("Kod produktu: A-M");
        expect(html).not.toContain("A-L");
      });
    });
  });
});

describe("ProductDetailsView promotion", () => {
  const badge = () => screen.queryByTestId("promotion-badge");
  const omnibus = () => screen.queryByTestId("omnibus");
  const PROMOTION = { endsAt: "2026-12-01T00:00:00+01:00", lowestPriorGross: 29900 };

  // Size 42 promoted from 300 zł to 240 zł, size 44 at 320 zł.
  const PROMOTED = makeOffer(1, {
    variantAxes: ["Kolor", "Rozmiar"],
    fromGross: 24000,
    fromPromotion: { lowestPriorGross: 29900 },
    hasPriceRange: true,
    variants: [
      makeVariant(1, 1, { label: "czarne, 42", gross: 24000, regularGross: 30000, promotion: { ...PROMOTION, lowestPriorGross: 27000 } }),
      makeVariant(1, 2, { label: "czarne, 44", gross: 32000 }),
    ],
  });
  const choose = (...values: string[]) => values.forEach((value) => fireEvent.click(screen.getByRole("button", { name: value })));

  it("before a choice shows the effective 'od' price with the badge and the Omnibus line of fromPromotion", () => {
    render(<ProductDetailsView details={SHOES_DETAILS} offer={PROMOTED} />);

    expect(screen.getByTestId("price").textContent).toBe("od 240,00\u00a0zł");
    expect(badge()?.textContent).toBe("Promocja");
    expect(omnibus()?.textContent).toBe("Najniższa cena z 30 dni przed obniżką: 299,00\u00a0zł");
  });

  it("before a choice shows neither when fromPromotion has no lowestPriorGross or is absent", () => {
    const { rerender } = render(<ProductDetailsView details={SHOES_DETAILS} offer={{ ...PROMOTED, fromPromotion: { lowestPriorGross: null } }} />);
    expect(screen.getByTestId("price").textContent).toBe("od 240,00\u00a0zł");
    expect(badge()).toBeNull();
    expect(omnibus()).toBeNull();

    rerender(<ProductDetailsView details={SHOES_DETAILS} offer={SHOES} />);
    expect(badge()).toBeNull();
    expect(omnibus()).toBeNull();
  });

  it("after choosing a promoted variant shows its price, the badge and its own Omnibus line", () => {
    render(<ProductDetailsView details={SHOES_DETAILS} offer={PROMOTED} />);

    choose("czarne", "42");

    expect(screen.getByTestId("price").textContent).toBe("240,00\u00a0zł");
    expect(badge()?.textContent).toBe("Promocja");
    expect(omnibus()?.textContent).toBe("Najniższa cena z 30 dni przed obniżką: 270,00\u00a0zł");
  });

  it("after choosing a variant without a promotion shows neither badge nor line", () => {
    render(<ProductDetailsView details={SHOES_DETAILS} offer={PROMOTED} />);

    choose("czarne", "44");

    expect(screen.getByTestId("price").textContent).toBe("320,00\u00a0zł");
    expect(badge()).toBeNull();
    expect(omnibus()).toBeNull();
  });

  it("after choosing a promoted variant with lowestPriorGross null shows the price without badge and line", () => {
    const offer = { ...PROMOTED, variants: [
      makeVariant(1, 1, { label: "czarne, 42", gross: 24000, regularGross: 30000, promotion: { ...PROMOTION, lowestPriorGross: null } }),
      PROMOTED.variants[1],
    ] };
    render(<ProductDetailsView details={SHOES_DETAILS} offer={offer} />);

    choose("czarne", "42");

    expect(screen.getByTestId("price").textContent).toBe("240,00\u00a0zł");
    expect(badge()).toBeNull();
    expect(omnibus()).toBeNull();
  });

  it("shows the badge and the line of the one purchasable variant chosen at once", () => {
    const offer = makeOffer(1, {
      fromGross: 24000,
      fromPromotion: { lowestPriorGross: 29900 },
      variants: [makeVariant(1, 1, { gross: 24000, regularGross: 30000, promotion: PROMOTION })],
    });
    render(<ProductDetailsView details={makeDetails(1)} offer={offer} />);

    expect(screen.getByTestId("price").textContent).toBe("240,00\u00a0zł");
    expect(badge()?.textContent).toBe("Promocja");
    expect(omnibus()?.textContent).toBe("Najniższa cena z 30 dni przed obniżką: 299,00\u00a0zł");
  });
});
