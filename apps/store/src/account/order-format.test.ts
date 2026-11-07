import { describe, expect, it } from "vitest";
import { formatOrderMoment, ORDER_STATUS_LABELS } from "./order-format";

describe("ORDER_STATUS_LABELS", () => {
  it("names every status of an order as the table of the specification does", () => {
    expect(ORDER_STATUS_LABELS).toEqual({
      new: "Czeka na płatność",
      paid: "Opłacone",
      shipped: "Wysłane",
      delivered: "Dostarczone",
      cancelled: "Anulowane",
      returned: "Zwrócone",
    });
  });
});

describe("formatOrderMoment", () => {
  it("shows the date and the time in Warsaw, winter time", () => {
    expect(formatOrderMoment("2026-11-05T12:00:00+00:00")).toBe("5 listopada 2026, 13:00");
  });

  it("shows summer time in Warsaw too", () => {
    expect(formatOrderMoment("2026-07-05T12:00:00+00:00")).toBe("5 lipca 2026, 14:00");
  });

  it("moves the date over midnight in Warsaw, wherever the browser is", () => {
    expect(formatOrderMoment("2026-11-05T23:30:00+00:00")).toBe("6 listopada 2026, 00:30");
  });

  it("is empty for a moment that is no date", () => {
    expect(formatOrderMoment("not a date")).toBe("");
  });
});
