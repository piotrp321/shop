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

  it("has the counter and nothing the JavaScript adds: no arrows, no current thumbnail", () => {
    expect(html).toContain("1 / 2");
    expect(html).not.toContain("Następne zdjęcie");
    expect(html).not.toContain("Poprzednie zdjęcie");
    expect(html).not.toContain("aria-current");
  });

  it("has no enlargement dialog before the visitor opens it", () => {
    expect(html).not.toContain("role=\"dialog\"");
    expect(html).not.toContain("z" + "a1-1280");
  });
});
