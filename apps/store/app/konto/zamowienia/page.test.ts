import { describe, expect, it } from "vitest";
import { metadata } from "./page";

describe("the order history page", () => {
  it("is kept out of search engines", () => {
    expect(metadata.robots).toEqual({ index: false, follow: false });
  });

  it("has its own title", () => {
    expect(metadata.title).toBe("Moje zamówienia");
  });
});
