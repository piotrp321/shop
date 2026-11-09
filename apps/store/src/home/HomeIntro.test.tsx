// @vitest-environment jsdom
import { cleanup, render, screen, within } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";
import { node, TREE } from "@/catalog/fixtures";
import { HomeIntro } from "./HomeIntro";

afterEach(cleanup);

const FULL_TREE = [node("bieganie", "Bieganie"), node("turystyka", "Turystyka"), node("kemping", "Kemping")];
const images = (container: HTMLElement) => [...container.querySelectorAll("img")];

describe("HomeIntro: the hero", () => {
  it("has the one h1 of the page with the text of the mockup and a short text", () => {
    render(<HomeIntro categories={FULL_TREE} />);
    expect(screen.getAllByRole("heading", { level: 1 }).map((heading) => heading.textContent)).toEqual(["Sprzęt na każdy szlak"]);
    expect(screen.getByText("Bieganie, rower, góry i woda. Wszystko, czego potrzebujesz poza miastem, w jednym sklepie.")).toBeTruthy();
  });

  it("links to the department Turystyka by its slug, with the address of the category page", () => {
    render(<HomeIntro categories={[node("turystyka", "Turystyka", [node("plecaki", "Plecaki")])]} />);
    expect(screen.getByRole("link", { name: "Zobacz Turystykę" }).getAttribute("href")).toBe("/c/turystyka");
  });

  it("has no link when the department is not in the tree, and none when the tree could not be read", () => {
    render(<HomeIntro categories={TREE} />);
    expect(screen.queryByRole("link", { name: "Zobacz Turystykę" })).toBeNull();
    cleanup();
    render(<HomeIntro categories={null} />);
    expect(screen.queryByRole("link", { name: "Zobacz Turystykę" })).toBeNull();
    expect(screen.getByRole("heading", { level: 1, name: "Sprzęt na każdy szlak" })).toBeTruthy();
  });

  it("is a picture: the phone crop up to 767 px in three widths (the widest 828), the wide crop in two as the image", () => {
    const { container } = render(<HomeIntro categories={null} />);
    const picture = container.querySelector("picture")!;
    const source = picture.querySelector("source")!;
    expect(source.getAttribute("media")).toBe("(max-width: 767px)");
    expect(source.getAttribute("srcset")).toBe(
      "/_next/static/media/hero-phone-480.webp 480w, /_next/static/media/hero-phone-640.webp 640w, /_next/static/media/hero-phone-828.webp 828w",
    );
    expect(source.getAttribute("sizes")).toBe("100vw");
    expect([source.getAttribute("width"), source.getAttribute("height")]).toEqual(["828", "1035"]);
    const image = picture.querySelector("img")!;
    expect(image.getAttribute("srcset")).toBe("/_next/static/media/hero-wide-1280.webp 1280w, /_next/static/media/hero-wide-1600.webp 1600w");
    expect(image.getAttribute("src")).toBe("/_next/static/media/hero-wide-1600.webp");
    expect(image.getAttribute("sizes")).toBe("100vw");
    expect([image.getAttribute("width"), image.getAttribute("height")]).toEqual(["1600", "900"]);
  });

  it("describes the photo with the text of the list and loads it with priority (the only image that does)", () => {
    const { container } = render(<HomeIntro categories={FULL_TREE} />);
    const hero = container.querySelector("picture img")!;
    expect(hero.getAttribute("alt")).toBe("Turyści z plecakami idący szlakiem w stronę szczytów");
    expect(hero.getAttribute("fetchpriority")).toBe("high");
    expect(hero.getAttribute("loading")).not.toBe("lazy");
    expect(images(container).filter((image) => image.getAttribute("fetchpriority") === "high")).toHaveLength(1);
  });
});

describe("HomeIntro: the department tiles", () => {
  it("has a tile for every root category: the name in an h2 that is the link to the category page", () => {
    render(<HomeIntro categories={FULL_TREE} />);
    const names = screen.getAllByRole("heading", { level: 2 });
    expect(names.map((heading) => heading.textContent)).toEqual(["Bieganie", "Turystyka", "Kemping"]);
    expect(names.map((heading) => within(heading).getByRole("link").getAttribute("href"))).toEqual(["/c/bieganie", "/c/turystyka", "/c/kemping"]);
  });

  it("has no tiles when the tree could not be read or is empty", () => {
    render(<HomeIntro categories={null} />);
    expect(screen.queryAllByRole("heading", { level: 2 })).toHaveLength(0);
    cleanup();
    render(<HomeIntro categories={[]} />);
    expect(screen.queryAllByRole("heading", { level: 2 })).toHaveLength(0);
  });

  it("gives every photo of a tile alt=\"\" (the name is the text of the link), lazy loading, async decoding, srcset, sizes and size", () => {
    const { container } = render(<HomeIntro categories={[node("bieganie", "Bieganie")]} />);
    const tile = images(container).find((image) => image.getAttribute("alt") === "")!;
    expect(tile.getAttribute("loading")).toBe("lazy");
    expect(tile.getAttribute("decoding")).toBe("async");
    expect(tile.hasAttribute("fetchpriority")).toBe(false);
    expect(tile.getAttribute("srcset")).toBe(
      "/_next/static/media/dept-bieganie-tile-320.webp 320w, /_next/static/media/dept-bieganie-tile-480.webp 480w, /_next/static/media/dept-bieganie-tile-768.webp 768w",
    );
    expect(tile.getAttribute("sizes")).toBe("(min-width: 1440px) 175px, (min-width: 1024px) calc((100vw - 216px) / 7), calc((100vw - 46px) / 2)");
    expect([tile.getAttribute("width"), tile.getAttribute("height")]).toEqual(["768", "1024"]);
    expect(tile.getAttribute("src")).toBe("/_next/static/media/dept-bieganie-tile-768.webp");
  });

  it("shows a tile without a photo for a category that has none, still with its name and link", () => {
    const { container } = render(<HomeIntro categories={[node("kemping", "Kemping")]} />);
    expect(screen.getByRole("link", { name: "Kemping" }).getAttribute("href")).toBe("/c/kemping");
    expect(images(container).filter((image) => image.getAttribute("alt") === "")).toHaveLength(0);
  });

  it("gives every image of the section a srcset, sizes, width and height", () => {
    const { container } = render(<HomeIntro categories={[node("bieganie", "Bieganie"), node("fitness", "Fitness")]} />);
    for (const image of images(container)) {
      for (const attribute of ["srcset", "sizes", "width", "height"]) expect(image.getAttribute(attribute), attribute).toBeTruthy();
    }
  });
});
