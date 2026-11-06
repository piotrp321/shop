import type { components } from "@/api/schema";

type ProductDetails = components["schemas"]["ProductDetails"];
type ProductOffer = components["schemas"]["ProductOfferResponse"];

/** A variant of the details with what the offer says about it. */
export type ChoiceVariant = {
  variantId: string;
  label: string;
  /** One value per axis, in the order of the axes. */
  values: string[];
  purchasable: boolean;
  gross: number | null;
  level: components["schemas"]["VariantOfferResponse"]["level"];
};

/** The chosen value of each axis, in the order of the axes; null where nothing is chosen yet. */
export type Selection = (string | null)[];

/**
 * `enabled`: can be chosen. `unavailable`: no purchasable variant has it together with the choices of the earlier axes.
 * `waiting`: an earlier axis has no choice yet.
 */
export type ValueState = "enabled" | "unavailable" | "waiting";

/**
 * The variants of the details, matched by id to the offer, which alone says whether a variant can be bought, its price
 * and its level. A variant the offer does not have cannot be bought; a variant only the offer has is not shown.
 */
export function buildChoiceVariants(details: ProductDetails, offer: ProductOffer): ChoiceVariant[] {
  return details.variants.map((variant) => {
    const offered = offer.variants.find((candidate) => candidate.variantId === variant.variantId);
    return {
      variantId: variant.variantId,
      label: variant.label,
      values: variant.values,
      purchasable: offered?.purchasable === true,
      gross: offered?.gross ?? null,
      level: offered?.level ?? "out_of_stock",
    };
  });
}

/** The values of an axis in the order of their first appearance in the variants (purchasable or not). */
export function axisValues(variants: ChoiceVariant[], axis: number): string[] {
  return [...new Set(variants.map((variant) => variant.values[axis]))];
}

/** Whether a purchasable variant has the given values on the first axes (`prefix[i]` on axis i). */
function existsPurchasable(variants: ChoiceVariant[], prefix: string[]): boolean {
  return variants.some((variant) => variant.purchasable && prefix.every((value, axis) => variant.values[axis] === value));
}

/**
 * The state of a value of an axis, computed hierarchically: against the choices of the earlier axes only, never the
 * later ones, so the first axis can always be changed to any value of a purchasable variant.
 */
export function valueState(variants: ChoiceVariant[], selection: Selection, axis: number, value: string): ValueState {
  const earlier = selection.slice(0, axis);
  if (earlier.some((chosen) => chosen === null)) {
    return "waiting";
  }
  return existsPurchasable(variants, [...(earlier as string[]), value]) ? "enabled" : "unavailable";
}

/**
 * The choice after the click on a value. A disabled value and the value already chosen change nothing (no unselecting).
 * A new value on an axis clears the choices of the later axes that no longer fit the earlier ones.
 */
export function selectValue(variants: ChoiceVariant[], selection: Selection, axis: number, value: string): Selection {
  if (selection[axis] === value || valueState(variants, selection, axis, value) !== "enabled") {
    return selection;
  }
  const next = [...selection];
  next[axis] = value;
  for (let later = axis + 1; later < next.length; later++) {
    const chosen = next[later];
    // An earlier axis cleared above makes this one "waiting", so the clearing runs on through the axes.
    if (chosen !== null && valueState(variants, next, later, chosen) !== "enabled") {
      next[later] = null;
    }
  }
  return next;
}

/** The choice at the first render: the only purchasable variant (among any number of variants), otherwise nothing. */
export function initialSelection(variants: ChoiceVariant[], axisCount: number): Selection {
  const purchasable = variants.filter((variant) => variant.purchasable);
  return purchasable.length === 1 ? purchasable[0].values.slice(0, axisCount) : Array.from({ length: axisCount }, () => null);
}

/** The purchasable variant that has exactly the chosen values; nothing until every axis has a value. */
export function chosenVariant(variants: ChoiceVariant[], selection: Selection): ChoiceVariant | undefined {
  if (selection.some((value) => value === null)) {
    return undefined;
  }
  return variants.find((variant) => variant.purchasable && selection.every((value, axis) => variant.values[axis] === value));
}
