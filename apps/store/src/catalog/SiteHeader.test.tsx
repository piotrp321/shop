// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, describe, expect, it, vi } from "vitest";

vi.mock("next/navigation", () => ({ usePathname: () => "/", useSearchParams: () => new URLSearchParams() }));

import { STORE_NAME } from "@/site";
import { TREE } from "./fixtures";
import { SearchBox } from "./SearchBox";
import { SiteHeader } from "./SiteHeader";

afterEach(cleanup);

const header = (categories: Parameters<typeof SiteHeader>[0]["categories"] = TREE) => <SiteHeader categories={categories} search={<SearchBox />} />;

/** The tab order of the page without a stylesheet: the document order of the interactive elements. */
const tabOrder = () =>
  [...document.querySelectorAll<HTMLElement>("header a[href], header button, header input")].map((element) => {
    if (element.tagName === "INPUT") return "search field";
    if (element.getAttribute("type") === "submit") return "search button";
    return (element.getAttribute("aria-label") ?? element.textContent ?? "").trim();
  });

describe("SiteHeader", () => {
  it("has the wordmark, the Menu button, the cart and then the search in the document (tab) order, which is the visual order of a phone and of a wide screen", () => {
    render(header());
    const order = tabOrder();
    const position = (name: string | RegExp) => order.findIndex((entry) => (typeof name === "string" ? entry === name : name.test(entry)));
    expect(position(STORE_NAME)).toBe(0);
    expect(position("Menu")).toBeGreaterThan(position(STORE_NAME));
    expect(position(/Koszyk/)).toBeGreaterThan(position("Menu"));
    expect(position("search field")).toBeGreaterThan(position(/Koszyk/));
    expect(position("search button")).toBeGreaterThan(position("search field"));
  });

  it("does not reorder anything with CSS: no `order` utilities in the header", () => {
    expect(renderToStaticMarkup(header())).not.toMatch(/(^|[\s"])(sm:|md:|lg:|xl:)?order-/);
  });

  it("puts the Menu button, closed, with aria-expanded in the header", () => {
    render(header());
    const button = screen.getByRole("button", { name: "Menu" });
    expect(button.getAttribute("aria-expanded")).toBe("false");
    expect(button.closest("header")).not.toBeNull();
  });

  it("has exactly one search form, labelled", () => {
    render(header());
    expect(screen.getAllByRole("search")).toHaveLength(1);
    expect(screen.getAllByRole("searchbox", { name: "Szukaj produktów" })).toHaveLength(1);
  });

  it("keeps the department links in the HTML while the menu is closed", () => {
    const html = renderToStaticMarkup(header());
    expect(html).toContain('href="/c/bieganie/buty/trailowe"');
  });

  it("has no Menu button and no department links when the tree could not be read", () => {
    render(header(null));
    expect(screen.queryByRole("button", { name: "Menu" })).toBeNull();
    expect(screen.queryByRole("navigation", { name: "Działy" })).toBeNull();
    expect(screen.getByRole("link", { name: STORE_NAME })).toBeTruthy();
  });
});
