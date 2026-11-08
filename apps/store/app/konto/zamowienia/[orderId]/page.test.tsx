import { describe, expect, it } from "vitest";
import Page, { metadata } from "./page";

describe("the order detail page", () => {
  it("is kept out of search engines", () => {
    expect(metadata.robots).toEqual({ index: false, follow: false });
  });

  it("has its own title", () => {
    expect(metadata.title).toBe("Zamówienie");
  });

  it("hands the id of the path to the client component", async () => {
    const element = await Page({ params: Promise.resolve({ orderId: "0199a0b0-0000-7000-8000-0000000000aa" }) });

    expect(element.props).toEqual({ orderId: "0199a0b0-0000-7000-8000-0000000000aa" });
  });
});
