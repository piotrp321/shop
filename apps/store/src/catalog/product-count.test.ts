import { describe, expect, it } from "vitest";
import { formatProductCount } from "./product-count";

describe("formatProductCount", () => {
  it.each([
    [1, "1 produkt"],
    [2, "2 produkty"],
    [3, "3 produkty"],
    [4, "4 produkty"],
    [0, "0 produktów"],
    [5, "5 produktów"],
    [10, "10 produktów"],
    [11, "11 produktów"],
    [12, "12 produktów"],
    [13, "13 produktów"],
    [14, "14 produktów"],
    [15, "15 produktów"],
    [21, "21 produktów"],
    [22, "22 produkty"],
    [23, "23 produkty"],
    [24, "24 produkty"],
    [25, "25 produktów"],
    [100, "100 produktów"],
    [101, "101 produktów"],
    [102, "102 produkty"],
    [111, "111 produktów"],
    [112, "112 produktów"],
    [114, "114 produktów"],
    [122, "122 produkty"],
  ])("%i is written as %s", (count, text) => {
    expect(formatProductCount(count)).toBe(text);
  });
});
