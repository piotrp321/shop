import { describe, expect, it } from "vitest";
import { metadata, revalidate } from "./page";

describe("the order history page", () => {
  it("is kept out of search engines", () => {
    expect(metadata.robots).toEqual({ index: false, follow: false });
  });

  it("has its own title", () => {
    expect(metadata.title).toBe("Moje zamówienia");
  });

  it("is regenerated at most once an hour, so the menu of the layout comes back after a restart of the store", () => {
    expect(revalidate).toBe(3600);
  });
});
