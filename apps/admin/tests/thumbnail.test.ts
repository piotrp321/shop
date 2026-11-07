import { describe, expect, it } from "vitest";
import { thumbnailSources } from "@/products/thumbnail";

describe("thumbnailSources", () => {
  it("prefers WebP, uses the smallest width as src and offers all widths as srcset", () => {
    const sources = thumbnailSources({
      jpg: [{ width: 96, url: "https://img.test/a-96.jpg" }],
      webp: [
        { width: 96, url: "https://img.test/a-96.webp" },
        { width: 192, url: "https://img.test/a-192.webp" },
      ],
    });

    expect(sources).toEqual({
      src: "https://img.test/a-96.webp",
      srcset: "https://img.test/a-96.webp 96w, https://img.test/a-192.webp 192w",
    });
  });

  it("falls back to the first format the API gave", () => {
    expect(thumbnailSources({ jpg: [{ width: 96, url: "https://img.test/a.jpg" }] })).toEqual({
      src: "https://img.test/a.jpg",
      srcset: "https://img.test/a.jpg 96w",
    });
  });

  it("gives nothing for an image without variants", () => {
    expect(thumbnailSources({})).toBeNull();
    expect(thumbnailSources({ webp: [] })).toBeNull();
  });
});
