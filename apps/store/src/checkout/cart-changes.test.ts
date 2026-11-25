import { describe, expect, it } from "vitest";
import type { CartView } from "@/api/client";
import { formatPrice } from "@/catalog/format-price";
import { cartChanges } from "./cart-changes";

const A = "00000000-0000-4000-8000-00000000000a";
const B = "00000000-0000-4000-8000-00000000000b";
const C = "00000000-0000-4000-8000-00000000000c";
const D = "00000000-0000-4000-8000-00000000000d";
const PRODUCT = "00000000-0000-4000-8000-0000000000f1";
const STANDARD = "00000000-0000-4000-8000-0000000000d1";
const EXPRESS = "00000000-0000-4000-8000-0000000000d2";
const price = (gross: number) => formatPrice(gross, "PLN");

type Line = CartView["lines"][number];
type DeliveryOption = NonNullable<CartView["delivery"]>["options"][number];

const line = (variantId: string, name: string, variantLabel: string, quantity: number, lineGross: number, overrides: Partial<Line> = {}): Line => ({
  variantId,
  product: { productId: PRODUCT, name, variantLabel, image: null },
  quantity,
  unitPrice: { gross: lineGross / quantity, currency: "PLN" },
  lineTotal: { gross: lineGross, currency: "PLN" },
  state: "purchasable",
  ...overrides,
});

const option = (methodId: string, name: string, costGross: number, totalGross: number): DeliveryOption => ({
  methodId,
  name,
  description: "",
  costGross,
  freeFromGross: null,
  missingToFreeGross: null,
  totalGross,
});

/**
 * A summary of the API. The amounts are given, never added up: `totals` and the options carry what a test says, so a
 * function that summed the lines would show other amounts than the ones expected.
 */
const view = (lines: Line[], { gross = 0, discount = 0, options = [option(STANDARD, "Kurier standardowy", 0, gross)] }: { gross?: number; discount?: number; options?: DeliveryOption[] } = {}): CartView => ({
  id: "c1",
  lines,
  totals: { subtotalGross: gross + discount, discountGross: discount, gross, currency: "PLN", itemCount: lines.length },
  hasExcludedLines: false,
  discount: discount > 0 ? { status: "applied", code: "WITAJ10", amountGross: discount, kind: "percent", value: 10 } : null,
  delivery: { fromGross: 0, missingToFreeGross: null, options },
});

const SHOES = "Buty trailowe Norvane Hala 500";
const shoes40 = (quantity: number, gross = 54900 * quantity) => line(A, SHOES, "czarne, 40", quantity, gross);
const shoes41 = (quantity: number, gross = 54900 * quantity) => line(B, SHOES, "czarne, 41", quantity, gross);

describe("cartChanges", () => {
  it("is empty when the two summaries show the same", () => {
    const shown = view([shoes40(2), shoes41(1)], { gross: 164700 });

    expect(cartChanges(shown, view([shoes40(2), shoes41(1)], { gross: 164700 }), STANDARD)).toEqual([]);
  });

  it("tells another quantity of a line", () => {
    const changes = cartChanges(view([shoes40(2)], { gross: 109800 }), view([shoes40(1)], { gross: 109800 }), STANDARD);

    expect(changes).toEqual([`${SHOES}, czarne, 40: było 2 szt., jest 1 szt.`]);
  });

  it("tells a line that is gone", () => {
    const changes = cartChanges(view([shoes40(1), shoes41(1)], { gross: 54900 }), view([shoes40(1)], { gross: 54900 }), STANDARD);

    expect(changes).toEqual([`${SHOES}, czarne, 41: usunięto z koszyka`]);
  });

  it("tells a new line with its quantity", () => {
    const changes = cartChanges(view([shoes40(1)], { gross: 54900 }), view([shoes40(1), shoes41(1)], { gross: 54900 }), STANDARD);

    expect(changes).toEqual([`${SHOES}, czarne, 41: dodano, 1 szt.`]);
  });

  it("tells another value of a line with the same quantity, as the API gave both", () => {
    const changes = cartChanges(view([shoes40(1, 54900)], { gross: 54900 }), view([shoes40(1, 49900)], { gross: 54900 }), STANDARD);

    expect(changes).toEqual([`${SHOES}, czarne, 40: wartość była ${price(54900)}, jest ${price(49900)}`]);
  });

  it("tells another discount of the code", () => {
    const changes = cartChanges(view([shoes40(1)], { gross: 49900, discount: 5000 }), view([shoes40(1)], { gross: 49900, discount: 0 }), STANDARD);

    expect(changes).toEqual([`Rabat: był ${price(5000)}, jest ${price(0)}`]);
  });

  it("tells another cost of the chosen delivery", () => {
    const shown = view([shoes40(1)], { options: [option(STANDARD, "Kurier standardowy", 0, 54900)] });
    const current = view([shoes40(1)], { options: [option(STANDARD, "Kurier standardowy", 1499, 54900)] });

    expect(cartChanges(shown, current, STANDARD)).toEqual([`Dostawa „Kurier standardowy”: była ${price(0)}, jest ${price(1499)}`]);
  });

  it("tells that the chosen delivery method is gone", () => {
    const shown = view([shoes40(1)], { options: [option(STANDARD, "Kurier standardowy", 0, 54900), option(EXPRESS, "Kurier ekspresowy", 1999, 56899)] });
    const current = view([shoes40(1)], { options: [option(EXPRESS, "Kurier ekspresowy", 1999, 56899)] });

    expect(cartChanges(shown, current, STANDARD)).toEqual(["Dostawa „Kurier standardowy” nie jest już dostępna. Wybierz sposób dostawy."]);
  });

  it("tells another total to pay of the chosen delivery, the amounts of its options as the API gave them", () => {
    // The totals of the options are not the goods plus the delivery: the function shows them, it does not add up.
    const shown = view([shoes40(1)], { gross: 1, options: [option(STANDARD, "Kurier standardowy", 0, 54900)] });
    const current = view([shoes40(3)], { gross: 1, options: [option(STANDARD, "Kurier standardowy", 0, 164700)] });

    expect(cartChanges(shown, current, STANDARD)).toEqual([`${SHOES}, czarne, 40: było 1 szt., jest 3 szt.`, `Do zapłaty: było ${price(54900)}, jest ${price(164700)}`]);
  });

  it("tells nothing of the delivery and of the total to pay without a chosen method", () => {
    const shown = view([shoes40(1)], { options: [option(STANDARD, "Kurier standardowy", 0, 54900)] });
    const current = view([shoes40(1)], { options: [option(STANDARD, "Kurier standardowy", 1499, 56399)] });

    expect(cartChanges(shown, current, null)).toEqual([]);
  });

  it("tells nothing of the delivery of a cart that has nothing to buy any more", () => {
    const shown = view([shoes40(1)], { options: [option(STANDARD, "Kurier standardowy", 0, 54900)] });
    const current: CartView = { ...view([]), delivery: null };

    expect(cartChanges(shown, current, STANDARD)).toEqual([`${SHOES}, czarne, 40: usunięto z koszyka`]);
  });

  it("lists the changes in the order of the summary: the lines shown, the new lines, the discount, the delivery, the total", () => {
    const shown = view([shoes40(2), line(C, "Czapka", "", 1, 4999), shoes41(1)], {
      gross: 158400,
      discount: 5000,
      options: [option(STANDARD, "Kurier standardowy", 0, 158400)],
    });
    const current = view([shoes40(1), shoes41(1, 49900), line(D, "Skarpety", "M", 2, 3998)], {
      gross: 108798,
      discount: 0,
      options: [option(STANDARD, "Kurier standardowy", 1499, 110297)],
    });

    expect(cartChanges(shown, current, STANDARD)).toEqual([
      `${SHOES}, czarne, 40: było 2 szt., jest 1 szt.`,
      "Czapka: usunięto z koszyka",
      `${SHOES}, czarne, 41: wartość była ${price(54900)}, jest ${price(49900)}`,
      "Skarpety, M: dodano, 2 szt.",
      `Rabat: był ${price(5000)}, jest ${price(0)}`,
      `Dostawa „Kurier standardowy”: była ${price(0)}, jest ${price(1499)}`,
      `Do zapłaty: było ${price(158400)}, jest ${price(110297)}`,
    ]);
  });

  it("names a product without a variant label by its name alone", () => {
    const changes = cartChanges(view([line(C, "Czapka", "", 1, 4999)]), view([line(C, "Czapka", "", 2, 9998)]), null);

    expect(changes).toEqual(["Czapka: było 1 szt., jest 2 szt."]);
  });

  it("does not fail on a line whose variant Catalog no longer knows: the name of the other summary, or a stand-in", () => {
    const unknown = (quantity: number): Line => line(A, "", "", quantity, 0, { product: null, unitPrice: null, lineTotal: null, state: "unavailable_product" });

    expect(cartChanges(view([shoes40(1)]), view([unknown(2)]), null)).toEqual([`${SHOES}, czarne, 40: było 1 szt., jest 2 szt.`]);
    expect(cartChanges(view([unknown(1)]), view([unknown(2)]), null)).toEqual(["Nieznany produkt: było 1 szt., jest 2 szt."]);
    expect(cartChanges(view([unknown(1)]), view([]), null)).toEqual(["Nieznany produkt: usunięto z koszyka"]);
    // A line that has no value in one of the summaries has no change of its value to tell.
    expect(cartChanges(view([shoes40(1)]), view([{ ...shoes40(1), lineTotal: null, state: "no_price" }]), null)).toEqual([]);
  });
});
