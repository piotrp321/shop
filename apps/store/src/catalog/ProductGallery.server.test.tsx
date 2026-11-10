import { renderToStaticMarkup } from "react-dom/server";
import { describe, expect, it } from "vitest";
import { makeDetails } from "./fixtures";
import { ProductGallery } from "./ProductGallery";

// What the server sends (and the ISR cache keeps): the whole gallery, working without JavaScript, the same for everybody.
const details = makeDetails(1);
const html = renderToStaticMarkup(<ProductGallery name={details.name} images={details.images} zoomImages={details.zoomImages} />);

describe("the gallery in the HTML of the server", () => {
  it("has the strip with every photo and its id, and the thumbnails as links to them", () => {
    expect(html).toContain('id="zdjecie-1"');
    expect(html).toContain('id="zdjecie-2"');
    expect(html).toContain('href="#zdjecie-1"');
    expect(html).toContain('href="#zdjecie-2"');
    expect(html).toContain('aria-label="Pokaż zdjęcie 2 z 2"');
    expect(html).toContain('alt=""');
    expect(html).toContain('alt="Zdjęcie 1, pierwsze"');
    expect(html).toContain('alt="Zdjęcie 1, drugie"');
  });

  it("has both lists of the thumbnails (the one shown from `lg` before the strip, the other after it) with no CSS that reorders", () => {
    const first = html.indexOf('aria-label="Miniatury zdjęć"');
    const strip = html.indexOf('aria-label="Zdjęcia produktu"');
    const second = html.indexOf('aria-label="Miniatury zdjęć"', first + 1);
    expect(first).toBeGreaterThan(-1);
    expect(first).toBeLessThan(strip);
    expect(second).toBeGreaterThan(strip);
    expect(html.indexOf('aria-label="Miniatury zdjęć"', second + 1)).toBe(-1);
    // Every thumbnail is a link in both lists, so the page works without JavaScript at any width.
    expect(html.match(/href="#zdjecie-1"/g)).toHaveLength(2);
    expect(html).not.toMatch(/(^|[\s"])(lg:)?order-/);
  });

  it("has the counter and nothing the JavaScript adds: no arrows, no current thumbnail", () => {
    expect(html).toContain("1 / 2");
    expect(html).not.toContain("Następne zdjęcie");
    expect(html).not.toContain("Poprzednie zdjęcie");
    expect(html).not.toContain("aria-current");
  });

  it("sizes the photos of the strip for the layout: a thumbnail column beside them only with several photos", () => {
    const one = renderToStaticMarkup(<ProductGallery name={details.name} images={details.images.slice(0, 1)} zoomImages={details.zoomImages.slice(0, 1)} />);
    expect(html).toContain("- 6.25rem");
    expect(one).not.toContain("- 6.25rem");
    expect(one).not.toContain("642px");
  });

  it("has no enlargement dialog before the visitor opens it", () => {
    expect(html).not.toContain("role=\"dialog\"");
    expect(html).not.toContain("z" + "a1-1280");
  });
});
