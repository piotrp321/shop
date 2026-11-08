import { describe, expect, it } from "vitest";
import {
  formatDiscount,
  formatPeriod,
  formatUses,
  groszeToInput,
  STATUS_FILTER_OPTIONS,
  statusLabel,
  statusSeverity,
} from "@/discount-codes/format";

// The grouping space of Intl is a no-break space; compare the text as people read it.
const plain = (text: string) => text.replace(/[  ]/g, " ");

describe("discount code format", () => {
  it("labels every status the API reports", () => {
    expect(statusLabel("disabled")).toBe("Wyłączony");
    expect(statusLabel("planned")).toBe("Zaplanowany");
    expect(statusLabel("active")).toBe("Aktywny");
    expect(statusLabel("expired")).toBe("Wygasły");
    expect(statusSeverity("active")).toBe("success");
    expect(statusSeverity("planned")).toBe("info");
    expect(statusSeverity("disabled")).toBe("warn");
    expect(statusSeverity("expired")).toBe("secondary");
  });

  it("offers all statuses and each one for the filter", () => {
    expect(STATUS_FILTER_OPTIONS.map((option) => option.value)).toEqual(["all", "planned", "active", "expired", "disabled"]);
  });

  it("shows a percent code as a percent and an amount code as złoty", () => {
    expect(formatDiscount("percent", 10)).toBe("10%");
    expect(plain(formatDiscount("amount", 5000))).toBe("50,00 zł");
  });

  it("shows the period in Polish time, with an open end", () => {
    expect(formatPeriod("2026-11-10T23:00:00+00:00", null)).toBe("11.11.2026, 00:00:00 – bez końca");
    expect(formatPeriod("2026-07-01T08:00:00Z", "2026-07-31T21:59:59Z")).toBe("01.07.2026, 10:00:00 – 31.07.2026, 23:59:59");
  });

  it("shows the active uses against the limit, or without a limit", () => {
    expect(formatUses(3, 100)).toBe("3 / 100");
    expect(formatUses(3, null)).toBe("3 (bez limitu)");
  });

  it("writes grosze as the text of a złoty field, on integers", () => {
    expect(groszeToInput(0)).toBe("0,00");
    expect(groszeToInput(5)).toBe("0,05");
    expect(groszeToInput(12345)).toBe("123,45");
    expect(groszeToInput(100000000)).toBe("1000000,00");
  });
});
