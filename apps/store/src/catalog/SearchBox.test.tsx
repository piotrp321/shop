// @vitest-environment jsdom
import { cleanup, render, screen } from "@testing-library/react";
import { renderToStaticMarkup } from "react-dom/server";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const nav = vi.hoisted(() => ({ pathname: "/", params: new URLSearchParams() }));
vi.mock("next/navigation", () => ({
  usePathname: () => nav.pathname,
  useSearchParams: () => nav.params,
}));

import { SearchBox } from "./SearchBox";

beforeEach(() => {
  nav.pathname = "/";
  nav.params = new URLSearchParams();
});
afterEach(cleanup);

const field = () => screen.getByRole("searchbox", { name: "Szukaj produktów" }) as HTMLInputElement;

describe("SearchBox", () => {
  it("is a GET search form to /szukaj with the phrase in `q`", () => {
    render(<SearchBox />);
    const form = screen.getByRole("search") as HTMLFormElement;
    expect(form.tagName).toBe("FORM");
    expect(form.method).toBe("get"); // the default, so the attribute may be absent
    expect(form.getAttribute("action")).toBe("/szukaj");
    expect(field().getAttribute("name")).toBe("q");
    expect(field().type).toBe("search");
    expect(screen.getByRole("button", { name: "Szukaj" }).getAttribute("type")).toBe("submit");
  });

  it("is empty on other pages, even when the address carries a `q`", () => {
    nav.params = new URLSearchParams("q=kurtka");
    render(<SearchBox />);
    expect(field().value).toBe("");
  });

  it("shows the current phrase on /szukaj", () => {
    nav.pathname = "/szukaj";
    nav.params = new URLSearchParams("q=kurtka+zimowa&o=Kolor%3Aczarne");
    render(<SearchBox />);
    expect(field().value).toBe("kurtka zimowa");
  });

  it("is in the HTML for a browser without JavaScript", () => {
    const html = renderToStaticMarkup(<SearchBox />);
    expect(html).toContain('action="/szukaj"');
    expect(html).toContain('name="q"');
  });
});
