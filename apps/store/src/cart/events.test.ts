// @vitest-environment jsdom
import { describe, expect, it, vi } from "vitest";
import { announceCartChange, subscribeToCartChanges } from "./events";

describe("cart change events", () => {
  it("tells every subscriber the new number of units", () => {
    const first = vi.fn();
    const second = vi.fn();
    const stopFirst = subscribeToCartChanges(first);
    const stopSecond = subscribeToCartChanges(second);

    announceCartChange(4);
    expect(first).toHaveBeenCalledWith(4);
    expect(second).toHaveBeenCalledWith(4);

    stopFirst();
    stopSecond();
  });

  it("stops after unsubscribing", () => {
    const listener = vi.fn();
    subscribeToCartChanges(listener)();

    announceCartChange(1);
    expect(listener).not.toHaveBeenCalled();
  });
});
