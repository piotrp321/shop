import { describe, expect, it } from "vitest";
import { makeDetails, makeOffer, makeVariant, variantId } from "./fixtures";
import {
  axisValues,
  buildChoiceVariants,
  chosenVariant,
  initialSelection,
  selectValue,
  valueState,
  type ChoiceVariant,
  type Selection,
} from "./variant-choice";

/** A product (n = 1) with the axes Kolor and Rozmiar; each row is one variant: its values and whether it can be bought. */
function twoAxes(rows: [color: string, size: string, purchasable: boolean][]): ChoiceVariant[] {
  const details = makeDetails(1, {
    variantAxes: ["Kolor", "Rozmiar"],
    variants: rows.map(([color, size], index) => ({ variantId: variantId(1, index + 1), label: `${color}, ${size}`, values: [color, size] })),
  });
  const offer = makeOffer(1, {
    variantAxes: ["Kolor", "Rozmiar"],
    variants: rows.map(([color, size, purchasable], index) =>
      makeVariant(1, index + 1, { label: `${color}, ${size}`, purchasable, gross: 10000 + index * 1000, level: index === 0 ? "in_stock" : "low_stock" }),
    ),
  });
  return buildChoiceVariants(details, offer);
}

const NONE: Selection = [null, null];

describe("buildChoiceVariants", () => {
  it("takes the price, the level and purchasable from the offer, matched by the variant id", () => {
    const variants = twoAxes([
      ["czarne", "42", true],
      ["czarne", "44", false],
    ]);
    expect(variants.map((v) => [v.label, v.purchasable, v.gross, v.level])).toEqual([
      ["czarne, 42", true, 10000, "in_stock"],
      ["czarne, 44", false, 11000, "low_stock"],
    ]);
  });

  it("treats a variant of the details that is missing from the offer as not purchasable", () => {
    const details = makeDetails(1, {
      variantAxes: ["Kolor"],
      variants: [
        { variantId: variantId(1, 1), label: "czarne", values: ["czarne"] },
        { variantId: variantId(1, 2), label: "białe", values: ["białe"] },
      ],
    });
    const offer = makeOffer(1, { variantAxes: ["Kolor"], variants: [makeVariant(1, 1, { label: "czarne" })] });
    const variants = buildChoiceVariants(details, offer);
    expect(variants.map((v) => [v.label, v.purchasable])).toEqual([
      ["czarne", true],
      ["białe", false],
    ]);
    expect(variants[1].gross).toBeNull();
  });

  it("ignores a variant of the offer that the details do not have", () => {
    const details = makeDetails(1);
    const offer = makeOffer(1, { variants: [makeVariant(1, 1), makeVariant(1, 2)] });
    expect(buildChoiceVariants(details, offer)).toHaveLength(1);
  });
});

describe("axisValues", () => {
  it("lists the values of an axis in the order of their first appearance in the variants", () => {
    const variants = twoAxes([
      ["niebieskie", "42", true],
      ["czarne", "41", false],
      ["czarne", "42", true],
      ["niebieskie", "40", true],
    ]);
    expect(axisValues(variants, 0)).toEqual(["niebieskie", "czarne"]);
    expect(axisValues(variants, 1)).toEqual(["42", "41", "40"]);
  });
});

describe("valueState", () => {
  const variants = twoAxes([
    ["czarne", "42", true],
    ["czarne", "44", false],
    ["białe", "44", true],
    ["zielone", "42", false],
  ]);

  it("disables a value of the first axis only when no purchasable variant has it", () => {
    expect(valueState(variants, NONE, 0, "czarne")).toBe("enabled");
    expect(valueState(variants, NONE, 0, "białe")).toBe("enabled");
    expect(valueState(variants, NONE, 0, "zielone")).toBe("unavailable");
  });

  it("keeps a later axis disabled, waiting, until the earlier axes are chosen", () => {
    expect(valueState(variants, NONE, 1, "42")).toBe("waiting");
    expect(valueState(variants, NONE, 1, "44")).toBe("waiting");
  });

  it("computes a later axis against the choices of the earlier axes only", () => {
    expect(valueState(variants, ["czarne", null], 1, "42")).toBe("enabled");
    expect(valueState(variants, ["czarne", null], 1, "44")).toBe("unavailable"); // czarne, 44 cannot be bought
    expect(valueState(variants, ["białe", null], 1, "44")).toBe("enabled");
    expect(valueState(variants, ["białe", null], 1, "42")).toBe("unavailable");
  });

  it("does not let the choice on a later axis disable a value of an earlier axis", () => {
    expect(valueState(variants, ["czarne", "42"], 0, "białe")).toBe("enabled");
  });

  it("works for a third axis against both earlier axes", () => {
    const details = makeDetails(1, {
      variantAxes: ["A", "B", "C"],
      variants: [
        { variantId: variantId(1, 1), label: "", values: ["a1", "b1", "c1"] },
        { variantId: variantId(1, 2), label: "", values: ["a1", "b2", "c2"] },
      ],
    });
    const offer = makeOffer(1, { variants: [makeVariant(1, 1), makeVariant(1, 2)] });
    const three = buildChoiceVariants(details, offer);
    expect(valueState(three, ["a1", null, null], 2, "c1")).toBe("waiting");
    expect(valueState(three, ["a1", "b1", null], 2, "c1")).toBe("enabled");
    expect(valueState(three, ["a1", "b1", null], 2, "c2")).toBe("unavailable");
  });
});

describe("selectValue", () => {
  const variants = twoAxes([
    ["czarne", "42", true],
    ["czarne", "44", false],
    ["białe", "44", true],
    ["białe", "42", false],
  ]);

  it("chooses an enabled value", () => {
    expect(selectValue(variants, NONE, 0, "czarne")).toEqual(["czarne", null]);
    expect(selectValue(variants, ["czarne", null], 1, "42")).toEqual(["czarne", "42"]);
  });

  it("does nothing for a disabled value", () => {
    expect(selectValue(variants, ["czarne", null], 1, "44")).toEqual(["czarne", null]); // unavailable
    expect(selectValue(variants, NONE, 1, "42")).toEqual(NONE); // waiting for the first axis
  });

  it("does not unselect a chosen value on a second click", () => {
    expect(selectValue(variants, ["czarne", "42"], 0, "czarne")).toEqual(["czarne", "42"]);
    expect(selectValue(variants, ["czarne", "42"], 1, "42")).toEqual(["czarne", "42"]);
  });

  it("clears a choice of a later axis that no longer fits the changed earlier axis", () => {
    expect(selectValue(variants, ["czarne", "42"], 0, "białe")).toEqual(["białe", null]);
  });

  it("keeps a choice of a later axis that still fits the changed earlier axis", () => {
    const both = twoAxes([
      ["czarne", "42", true],
      ["białe", "42", true],
      ["białe", "44", true],
    ]);
    expect(selectValue(both, ["czarne", "42"], 0, "białe")).toEqual(["białe", "42"]);
  });

  it("clears the axes after the cleared one as well", () => {
    const details = makeDetails(1, {
      variantAxes: ["A", "B", "C"],
      variants: [
        { variantId: variantId(1, 1), label: "", values: ["a1", "b1", "c1"] },
        { variantId: variantId(1, 2), label: "", values: ["a2", "b2", "c1"] },
      ],
    });
    const offer = makeOffer(1, { variants: [makeVariant(1, 1), makeVariant(1, 2)] });
    const three = buildChoiceVariants(details, offer);
    // c1 exists under a2, but only together with b2, so changing a to a2 clears b1 and with it c1.
    expect(selectValue(three, ["a1", "b1", "c1"], 0, "a2")).toEqual(["a2", null, null]);
  });

  it("never blocks the choice when only 'czarne, 42' and 'białe, 44' can be bought", () => {
    const pair = twoAxes([
      ["czarne", "42", true],
      ["czarne", "44", false],
      ["białe", "42", false],
      ["białe", "44", true],
    ]);
    // From every state reachable by clicks, every purchasable variant can still be reached by choosing the first axis again.
    let selection: Selection = NONE;
    for (const [axis, value] of [[0, "czarne"], [1, "42"], [0, "białe"], [1, "44"], [0, "czarne"], [1, "42"]] as const) {
      expect(valueState(pair, selection, axis, value)).toBe("enabled");
      selection = selectValue(pair, selection, axis, value);
    }
    expect(selection).toEqual(["czarne", "42"]);
    expect(chosenVariant(pair, selection)?.label).toBe("czarne, 42");
    selection = selectValue(pair, selection, 0, "białe");
    expect(selection).toEqual(["białe", null]);
    expect(valueState(pair, selection, 1, "44")).toBe("enabled");
    expect(chosenVariant(pair, selectValue(pair, selection, 1, "44"))?.label).toBe("białe, 44");
  });
});

describe("initialSelection", () => {
  it("chooses the only purchasable variant of a product with several variants", () => {
    const variants = twoAxes([
      ["czarne", "42", false],
      ["czarne", "44", true],
      ["białe", "44", false],
    ]);
    expect(initialSelection(variants, 2)).toEqual(["czarne", "44"]);
  });

  it("chooses nothing when two variants can be bought", () => {
    const variants = twoAxes([
      ["czarne", "42", true],
      ["czarne", "44", true],
    ]);
    expect(initialSelection(variants, 2)).toEqual(NONE);
  });

  it("chooses nothing when no variant can be bought", () => {
    expect(initialSelection(twoAxes([["czarne", "42", false]]), 2)).toEqual(NONE);
  });

  it("chooses the one variant of a product, also without axes", () => {
    const single = buildChoiceVariants(makeDetails(1), makeOffer(1));
    expect(initialSelection(single, 0)).toEqual([]);
    expect(chosenVariant(single, [])?.variantId).toBe(variantId(1, 1));
  });
});

describe("chosenVariant", () => {
  const variants = twoAxes([
    ["czarne", "42", true],
    ["czarne", "44", true],
  ]);

  it("is nothing until every axis has a value", () => {
    expect(chosenVariant(variants, NONE)).toBeUndefined();
    expect(chosenVariant(variants, ["czarne", null])).toBeUndefined();
  });

  it("is the purchasable variant with exactly the chosen values, with its price and level", () => {
    const chosen = chosenVariant(variants, ["czarne", "44"]);
    expect(chosen).toMatchObject({ variantId: variantId(1, 2), label: "czarne, 44", gross: 11000, level: "low_stock" });
  });

  it("is nothing for values that no purchasable variant has", () => {
    expect(chosenVariant(twoAxes([["czarne", "42", false]]), ["czarne", "42"])).toBeUndefined();
  });
});
