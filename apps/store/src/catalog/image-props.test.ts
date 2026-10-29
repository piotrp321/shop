import { describe, expect, it } from "vitest";
import { makeImage } from "./fixtures";
import { buildImageProps } from "./image-props";

const SIZES = "(min-width: 1024px) 25vw, (min-width: 640px) 33vw, 50vw";

describe("buildImageProps", () => {
  it("builds the srcset from the WebP variants", () => {
    const props = buildImageProps(makeImage(), SIZES);
    expect(props.srcSet).toBe(
      [
        "http://imgproxy.test/u256/plain.webp 256w",
        "http://imgproxy.test/u384/plain.webp 384w",
        "http://imgproxy.test/u512/plain.webp 512w",
        "http://imgproxy.test/u768/plain.webp 768w",
      ].join(", "),
    );
  });

  it("uses the largest variant as src", () => {
    expect(buildImageProps(makeImage(), SIZES).src).toBe("http://imgproxy.test/u768/plain.webp");
  });

  it("orders variants by width even when the API lists them differently", () => {
    const image = makeImage({
      variants: {
        webp: [
          { width: 768, url: "u768" },
          { width: 256, url: "u256" },
        ],
      },
    });
    const props = buildImageProps(image, SIZES);
    expect(props.srcSet).toBe("u256 256w, u768 768w");
    expect(props.src).toBe("u768");
  });

  it("passes sizes and alt through unchanged", () => {
    const props = buildImageProps(makeImage({ alt: "Biegaczka" }), SIZES);
    expect(props.sizes).toBe(SIZES);
    expect(props.alt).toBe("Biegaczka");
  });

  it("derives width and height from the aspect ratio and the largest variant", () => {
    expect(buildImageProps(makeImage({ aspectRatio: "1:1" }), SIZES)).toMatchObject({ width: 768, height: 768 });
    expect(buildImageProps(makeImage({ aspectRatio: "4:3" }), SIZES)).toMatchObject({ width: 768, height: 576 });
  });

  it("rejects an image without WebP variants", () => {
    expect(() => buildImageProps(makeImage({ variants: {} }), SIZES)).toThrow(/webp/i);
    expect(() => buildImageProps(makeImage({ variants: { webp: [] } }), SIZES)).toThrow(/webp/i);
  });

  it("rejects a malformed aspect ratio", () => {
    expect(() => buildImageProps(makeImage({ aspectRatio: "square" }), SIZES)).toThrow(/aspect ratio/i);
    expect(() => buildImageProps(makeImage({ aspectRatio: "0:1" }), SIZES)).toThrow(/aspect ratio/i);
  });
});
