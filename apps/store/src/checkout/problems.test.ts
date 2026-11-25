import { describe, expect, it } from "vitest";
import { CHECKOUT_BUYER_CHANGED_TYPE, conflictReason } from "./problems";

const PROBLEMS = "tag:piotrp321.github.io,2026:shop/problems/";

describe("the own 409 of the BFF for a changed buyer", () => {
  it("has its own type, outside the types of the API", () => {
    expect(CHECKOUT_BUYER_CHANGED_TYPE).toBe(`${PROBLEMS}checkout-buyer-changed`);
  });

  it("is told by its type", () => {
    expect(conflictReason(`${PROBLEMS}checkout-buyer-changed`)).toBe("buyer_changed");
  });

  it.each([
    ["its detail", "Od wczytania kasy zmienił się kupujący. Nie złożyliśmy zamówienia."],
    ["its type in capitals", `${PROBLEMS}CHECKOUT-BUYER-CHANGED`],
    ["its type with a space", `${PROBLEMS}checkout-buyer-changed `],
    ["its slug alone", "checkout-buyer-changed"],
  ])("is never told by %s", (_name, type) => {
    expect(conflictReason(type)).toBe("unknown");
  });
});
