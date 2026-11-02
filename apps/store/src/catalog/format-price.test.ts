import { describe, expect, it } from "vitest";
import { formatPrice } from "./format-price";

// Intl separates the digits and the currency sign with no-break spaces; the tests compare with plain ones.
const plain = (text: string) => text.replace(/[  ]/g, " ");

describe("formatPrice", () => {
  it("shows grosze as złoty with two decimals in the Polish format", () => {
    expect(plain(formatPrice(5490, "PLN"))).toBe("54,90 zł");
    expect(plain(formatPrice(5000, "PLN"))).toBe("50,00 zł");
    expect(plain(formatPrice(1, "PLN"))).toBe("0,01 zł");
  });

  it("groups thousands for the biggest price", () => {
    expect(plain(formatPrice(99_999_999, "PLN"))).toBe("999 999,99 zł");
  });

  it("uses the no-break space between the amount and the currency", () => {
    expect(formatPrice(5490, "PLN")).toContain(" zł");
  });
});
