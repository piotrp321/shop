import { describe, expect, it } from "vitest";
import { categoryHref } from "./catalog-href";

describe("categoryHref", () => {
  it("is /c/ and the slug path", () => {
    expect(categoryHref("bieganie/buty")).toBe("/c/bieganie/buty");
  });

  it("leaves the first page out and adds later pages as a parameter", () => {
    expect(categoryHref("bieganie", 1)).toBe("/c/bieganie");
    expect(categoryHref("bieganie", undefined)).toBe("/c/bieganie");
    expect(categoryHref("bieganie/buty", 2)).toBe("/c/bieganie/buty?page=2");
  });
});
