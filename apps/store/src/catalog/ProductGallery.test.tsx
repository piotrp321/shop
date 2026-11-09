// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, within } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { makeProductPhoto, makeZoomPhoto, PRODUCT_WIDTHS } from "./fixtures";
import { ProductGallery } from "./ProductGallery";

const ALTS = ["Cała para nart", "Końcówki nart", "Narciarz na podejściu"];
const IMAGES = ALTS.map((alt, index) =>
  makeProductPhoto({ alt, variants: { webp: PRODUCT_WIDTHS.map((width) => ({ width, url: `http://imgproxy.test/p${index + 1}-${width}/plain.webp` })) } }),
);
const ZOOM_IMAGES = ALTS.map((alt, index) => makeZoomPhoto(alt, String(index + 1)));
const STRIP_WIDTH = 400;

const scrollTo = vi.fn();

/** Radix gives the focus back in a timer of its own. */
const flushTimers = () =>
  act(() => {
    vi.runOnlyPendingTimers();
  });

beforeEach(() => {
  vi.useFakeTimers();
  scrollTo.mockReset();
  Object.defineProperty(HTMLElement.prototype, "scrollTo", { configurable: true, value: scrollTo });
  Object.defineProperty(HTMLElement.prototype, "clientWidth", { configurable: true, get: () => STRIP_WIDTH });
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
});

const renderGallery = (images = IMAGES, zoomImages = ZOOM_IMAGES) => render(<ProductGallery name="Narty skiturowe Stavro Grań 95" images={images} zoomImages={zoomImages} />);
const strip = () => screen.getByRole("group", { name: "Zdjęcia produktu" });

/** What a swipe does to the strip: the browser scrolls it, `scroll` events follow, then the strip settles. */
function scrollStripTo(element: HTMLElement, left: number) {
  Object.defineProperty(element, "scrollLeft", { configurable: true, writable: true, value: left });
  fireEvent.scroll(element);
  act(() => {
    vi.advanceTimersByTime(300);
  });
}

describe("the strip of photos", () => {
  it("holds every photo in the order of the API, each with its id and the alt text of the API", () => {
    renderGallery();
    const slides = ["zdjecie-1", "zdjecie-2", "zdjecie-3"].map((id) => document.getElementById(id));
    expect(slides.every((slide) => slide !== null && strip().contains(slide))).toBe(true);
    expect(within(strip()).getAllByRole("img").map((img) => (img as HTMLImageElement).alt)).toEqual(ALTS);
  });

  it("loads only the first photo with priority", () => {
    renderGallery();
    const photos = within(strip()).getAllByRole("img") as HTMLImageElement[];
    expect(photos.map((photo) => photo.getAttribute("loading"))).toEqual(["eager", "lazy", "lazy"]);
    expect(photos[0].getAttribute("fetchpriority")).toBe("high");
    expect(photos[1].getAttribute("fetchpriority")).toBeNull();
  });

  it("puts each photo in a button named with its position and its alt text", () => {
    renderGallery();
    expect(screen.getAllByRole("button", { name: /^Powiększ zdjęcie/ }).map((button) => button.getAttribute("aria-label"))).toEqual([
      "Powiększ zdjęcie 1 z 3: Cała para nart",
      "Powiększ zdjęcie 2 z 3: Końcówki nart",
      "Powiększ zdjęcie 3 z 3: Narciarz na podejściu",
    ]);
  });
});

describe("thumbnails and counter", () => {
  it("links each thumbnail to its photo by an anchor, with a name from the link and an empty alt text on the image", () => {
    renderGallery();
    const thumbs = within(screen.getByRole("list", { name: "Miniatury zdjęć" })).getAllByRole("link");
    expect(thumbs.map((link) => [link.getAttribute("href"), link.getAttribute("aria-label")])).toEqual([
      ["#zdjecie-1", "Pokaż zdjęcie 1 z 3"],
      ["#zdjecie-2", "Pokaż zdjęcie 2 z 3"],
      ["#zdjecie-3", "Pokaż zdjęcie 3 z 3"],
    ]);
    const images = thumbs.map((link) => link.querySelector("img") as HTMLImageElement);
    expect(images.map((img) => img.getAttribute("alt"))).toEqual(["", "", ""]);
    expect(images.map((img) => img.getAttribute("loading"))).toEqual(["lazy", "lazy", "lazy"]);
    // The smallest width of the `product` size, no srcset.
    expect(images.map((img) => img.getAttribute("src"))).toEqual(["http://imgproxy.test/p1-640/plain.webp", "http://imgproxy.test/p2-640/plain.webp", "http://imgproxy.test/p3-640/plain.webp"]);
    expect(images[0].hasAttribute("srcset")).toBe(false);
  });

  it("shows the counter as a polite live region and marks the first thumbnail as current", () => {
    renderGallery();
    const counter = screen.getByText("1 / 3");
    expect(counter.getAttribute("aria-live")).toBe("polite");
    const thumbs = screen.getAllByRole("link", { name: /^Pokaż zdjęcie/ });
    expect(thumbs.map((link) => link.getAttribute("aria-current"))).toEqual(["true", null, null]);
  });

  it("follows the strip when it is scrolled (a swipe): the counter and the current thumbnail change", () => {
    renderGallery();
    scrollStripTo(strip(), STRIP_WIDTH * 2);
    expect(screen.getByText("3 / 3")).toBeTruthy();
    expect(screen.getAllByRole("link", { name: /^Pokaż zdjęcie/ }).map((link) => link.getAttribute("aria-current"))).toEqual([null, null, "true"]);
  });

  it("waits for the strip to settle before it changes the counter", () => {
    renderGallery();
    Object.defineProperty(strip(), "scrollLeft", { configurable: true, writable: true, value: STRIP_WIDTH });
    fireEvent.scroll(strip());
    expect(screen.getByText("1 / 3")).toBeTruthy();
    act(() => {
      vi.advanceTimersByTime(300);
    });
    expect(screen.getByText("2 / 3")).toBeTruthy();
  });

  it("scrolls the strip smoothly to the photo on a click and does not follow the link (no address change, no history entry)", () => {
    renderGallery();
    const link = screen.getByRole("link", { name: "Pokaż zdjęcie 3 z 3" });
    const notCancelled = fireEvent.click(link);
    expect(notCancelled).toBe(false);
    expect(scrollTo).toHaveBeenCalledWith({ left: STRIP_WIDTH * 2, behavior: "smooth" });
    expect(window.location.hash).toBe("");
  });

  it("scrolls without animation when the visitor asks for reduced motion", () => {
    vi.stubGlobal("matchMedia", (query: string) => ({ matches: query.includes("reduce"), media: query }));
    renderGallery();
    fireEvent.click(screen.getByRole("link", { name: "Pokaż zdjęcie 2 z 3" }));
    expect(scrollTo).toHaveBeenCalledWith({ left: STRIP_WIDTH, behavior: "auto" });
    vi.unstubAllGlobals();
  });
});

describe("previous and next buttons", () => {
  it("scroll to the neighbour photo and wrap around at the ends", () => {
    renderGallery();
    fireEvent.click(screen.getByRole("button", { name: "Następne zdjęcie" }));
    expect(scrollTo).toHaveBeenLastCalledWith({ left: STRIP_WIDTH, behavior: "smooth" });

    fireEvent.click(screen.getByRole("button", { name: "Poprzednie zdjęcie" }));
    expect(scrollTo).toHaveBeenLastCalledWith({ left: STRIP_WIDTH * 2, behavior: "smooth" });
  });

  it("go on from the photo the strip shows", () => {
    renderGallery();
    scrollStripTo(strip(), STRIP_WIDTH);
    fireEvent.click(screen.getByRole("button", { name: "Następne zdjęcie" }));
    expect(scrollTo).toHaveBeenLastCalledWith({ left: STRIP_WIDTH * 2, behavior: "smooth" });
  });
});

describe("keyboard", () => {
  it("moves to the neighbour photo with the arrow keys from a focused photo and keeps the focus in the strip", () => {
    renderGallery();
    const first = screen.getByRole("button", { name: /^Powiększ zdjęcie 1 z 3/ });
    first.focus();
    fireEvent.keyDown(first, { key: "ArrowRight" });
    expect(scrollTo).toHaveBeenLastCalledWith({ left: STRIP_WIDTH, behavior: "smooth" });
    expect(document.activeElement).toBe(screen.getByRole("button", { name: /^Powiększ zdjęcie 2 z 3/ }));

    fireEvent.keyDown(document.activeElement as HTMLElement, { key: "ArrowLeft" });
    expect(scrollTo).toHaveBeenLastCalledWith({ left: 0, behavior: "smooth" });
    expect(document.activeElement).toBe(first);
  });

  it("leaves the other keys alone", () => {
    renderGallery();
    const first = screen.getByRole("button", { name: /^Powiększ zdjęcie 1 z 3/ });
    const notCancelled = fireEvent.keyDown(first, { key: "Enter" });
    expect(notCancelled).toBe(true);
    expect(scrollTo).not.toHaveBeenCalled();
  });
});

describe("one photo and none", () => {
  it("one photo has the enlargement but no thumbnails, counter or arrows", () => {
    renderGallery([IMAGES[0]], [ZOOM_IMAGES[0]]);
    expect(screen.getByRole("button", { name: "Powiększ zdjęcie 1 z 1: Cała para nart" })).toBeTruthy();
    expect(screen.queryByRole("list", { name: "Miniatury zdjęć" })).toBeNull();
    expect(screen.queryByText("1 / 1")).toBeNull();
    expect(screen.queryByRole("button", { name: "Następne zdjęcie" })).toBeNull();
    expect(screen.queryByRole("button", { name: "Poprzednie zdjęcie" })).toBeNull();
  });

  it("no photos show the placeholder and no enlargement", () => {
    renderGallery([], []);
    expect(screen.getByRole("img", { name: "Brak zdjęcia" })).toBeTruthy();
    expect(screen.queryByRole("button")).toBeNull();
    expect(screen.queryByRole("link")).toBeNull();
  });

  it("a photo without a usable variant is left out, the others keep their order and their enlargements", () => {
    const broken = makeProductPhoto({ alt: "Zepsute", variants: {} });
    renderGallery([IMAGES[0], broken, IMAGES[2]], [ZOOM_IMAGES[0], makeZoomPhoto("Zepsute", "x"), ZOOM_IMAGES[2]]);
    expect(within(strip()).getAllByRole("img").map((img) => (img as HTMLImageElement).alt)).toEqual([ALTS[0], ALTS[2]]);
    expect(screen.getAllByRole("button", { name: /^Powiększ zdjęcie/ }).map((button) => button.getAttribute("aria-label"))).toEqual([
      "Powiększ zdjęcie 1 z 2: Cała para nart",
      "Powiększ zdjęcie 2 z 2: Narciarz na podejściu",
    ]);
  });

  it("without the zoom twins of the photos the photos stay and there is no enlargement", () => {
    renderGallery(IMAGES, []);
    expect(within(strip()).getAllByRole("img")).toHaveLength(3);
    expect(screen.queryByRole("button", { name: /^Powiększ/ })).toBeNull();
  });
});

describe("the enlargement", () => {
  const openOn = (position: number) => fireEvent.click(screen.getByRole("button", { name: new RegExp(`^Powiększ zdjęcie ${position} z 3`) }));
  const dialog = () => screen.getByRole("dialog", { name: "Narty skiturowe Stavro Grań 95" });

  it("opens a dialog titled with the name of the product, on the photo that was visible", () => {
    renderGallery();
    scrollStripTo(strip(), STRIP_WIDTH);
    openOn(2);

    expect(within(dialog()).getByText("2 / 3").getAttribute("aria-live")).toBe("polite");
    expect(scrollTo).not.toHaveBeenCalled();
    const slides = within(dialog()).getAllByRole("img") as HTMLImageElement[];
    expect(slides.map((img) => img.alt)).toEqual(ALTS);
  });

  it("shows the zoom size: a srcset of 1280, 1920 and 2560 px for the whole width, the visible photo eager, the rest lazy", () => {
    renderGallery();
    openOn(2);
    const slides = within(dialog()).getAllByRole("img") as HTMLImageElement[];
    expect(slides[1].srcset).toBe("http://imgproxy.test/z2-1280/plain.webp 1280w, http://imgproxy.test/z2-1920/plain.webp 1920w, http://imgproxy.test/z2-2560/plain.webp 2560w");
    expect(slides.map((img) => img.getAttribute("sizes"))).toEqual(["100vw", "100vw", "100vw"]);
    expect(slides.map((img) => img.getAttribute("loading"))).toEqual(["lazy", "eager", "lazy"]);
    expect(slides[1].className).toContain("object-contain");
  });

  it("changes the photo with the buttons (they wrap around) and with the arrow keys", () => {
    renderGallery();
    openOn(1);
    scrollTo.mockClear();

    fireEvent.click(within(dialog()).getByRole("button", { name: "Poprzednie zdjęcie" }));
    expect(scrollTo).toHaveBeenLastCalledWith({ left: STRIP_WIDTH * 2, behavior: "smooth" });

    fireEvent.click(within(dialog()).getByRole("button", { name: "Następne zdjęcie" }));
    expect(scrollTo).toHaveBeenLastCalledWith({ left: STRIP_WIDTH, behavior: "smooth" });

    fireEvent.keyDown(dialog(), { key: "ArrowRight" });
    expect(scrollTo).toHaveBeenLastCalledWith({ left: STRIP_WIDTH, behavior: "smooth" });
  });

  it("follows a swipe: the counter shows the photo the strip of the enlargement settled on", () => {
    renderGallery();
    openOn(1);
    const zoomStrip = within(dialog()).getByRole("group", { name: "Powiększone zdjęcia" });
    scrollStripTo(zoomStrip, STRIP_WIDTH * 2);
    expect(within(dialog()).getByText("3 / 3")).toBeTruthy();
  });

  it("closes with Escape and with „Zamknij” and gives the focus back to the button that opened it", () => {
    renderGallery();
    const opener = screen.getByRole("button", { name: /^Powiększ zdjęcie 2 z 3/ });
    opener.focus();
    fireEvent.click(opener);
    expect(dialog()).toBeTruthy();

    fireEvent.keyDown(dialog(), { key: "Escape" });
    flushTimers();
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(document.activeElement).toBe(opener);

    fireEvent.click(opener);
    fireEvent.click(within(dialog()).getByRole("button", { name: "Zamknij" }));
    flushTimers();
    expect(screen.queryByRole("dialog")).toBeNull();
    expect(document.activeElement).toBe(opener);
  });

  it("gives the focus back to the opener even when the browser did not focus the button on the click", () => {
    renderGallery();
    const opener = screen.getByRole("button", { name: /^Powiększ zdjęcie 3 z 3/ });
    expect(document.activeElement).toBe(document.body);
    fireEvent.click(opener);
    fireEvent.keyDown(dialog(), { key: "Escape" });
    flushTimers();
    expect(document.activeElement).toBe(opener);
  });

  it("has no previous and next buttons or counter for one photo, but still opens", () => {
    renderGallery([IMAGES[0]], [ZOOM_IMAGES[0]]);
    fireEvent.click(screen.getByRole("button", { name: /^Powiększ zdjęcie 1 z 1/ }));
    expect(within(dialog()).queryByRole("button", { name: "Następne zdjęcie" })).toBeNull();
    expect(within(dialog()).queryByText("1 / 1")).toBeNull();
    expect(within(dialog()).getByRole("button", { name: "Zamknij" })).toBeTruthy();
  });
});
