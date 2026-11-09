import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { STORE_NAME } from "@/site";
import { TREE } from "./fixtures";
import { SiteFooter } from "./SiteFooter";

const hrefs = (html: string) => [...html.matchAll(/href="([^"]*)"/g)].map((match) => match[1]);
const text = (html: string) => html.replace(/<[^>]*>/g, "").replace(/\s+/g, " ").trim();

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date("2031-06-15T12:00:00Z"));
});
afterEach(() => vi.useRealTimers());

describe("SiteFooter", () => {
  it("is a footer with the wordmark linking to the home page", () => {
    const html = renderToStaticMarkup(<SiteFooter categories={TREE} />);
    expect(html).toMatch(/^<footer/);
    expect(html).toMatch(new RegExp(`<a[^>]*href="/"[^>]*>${STORE_NAME}</a>`));
  });

  it("links the top-level departments of the tree, in the order of the tree, and nothing deeper", () => {
    const html = renderToStaticMarkup(<SiteFooter categories={TREE} />);
    expect(html).toContain('aria-label="Linki w stopce"');
    expect(hrefs(html)).toEqual(["/", "/c/bieganie", "/c/rower"]);
    expect(html).not.toContain("Trailowe");
  });

  it("has no links but the wordmark and no navigation when the tree could not be read", () => {
    const html = renderToStaticMarkup(<SiteFooter categories={null} />);
    expect(hrefs(html)).toEqual(["/"]);
    expect(html).not.toContain("<nav");
  });

  it("has no navigation for an empty tree", () => {
    expect(renderToStaticMarkup(<SiteFooter categories={[]} />)).not.toContain("<nav");
  });

  it("shows one line with the current year, the store name and the photo credit", () => {
    const html = renderToStaticMarkup(<SiteFooter categories={TREE} />);
    expect(text(html)).toContain(`© 2031 ${STORE_NAME} · Zdjęcia: Unsplash`);
  });

  it("links only to pages that exist: the home page and the departments", () => {
    const html = renderToStaticMarkup(<SiteFooter categories={TREE} />);
    expect(hrefs(html).every((href) => href === "/" || href.startsWith("/c/"))).toBe(true);
  });
});
