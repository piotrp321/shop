// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from "@testing-library/react";
import { hydrateRoot } from "react-dom/client";
import { renderToStaticMarkup, renderToString } from "react-dom/server";
import { use } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

const nav = vi.hoisted(() => ({ pathname: "/", params: new URLSearchParams(), pending: null as Promise<void> | null }));
vi.mock("next/navigation", () => ({
  usePathname: () => nav.pathname,
  // While `pending` is set the hook suspends, like it does on a page rendered without the address (ISR).
  useSearchParams: () => {
    if (nav.pending) use(nav.pending);
    return nav.params;
  },
}));

import { SearchBox } from "./SearchBox";

beforeEach(() => {
  nav.pathname = "/";
  nav.params = new URLSearchParams();
  nav.pending = null;
});
afterEach(() => {
  cleanup();
  document.body.innerHTML = "";
});

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

  it("has the phrase it is given in the HTML, so the field is filled without JavaScript", () => {
    nav.pathname = "/szukaj";
    nav.params = new URLSearchParams("q=kurtki");
    expect(renderToStaticMarkup(<SearchBox phrase="kurtki" />)).toMatch(/<input[^>]*id="header-search"[^>]*value="kurtki"/);
    render(<SearchBox phrase="kurtki" />);
    expect(field().value).toBe("kurtki");
  });

  it("hydrates the field of the page HTML in place, so what was typed before the hydration stays", async () => {
    // The server does not have the address (a product page is ISR), so the header is the fallback in the HTML.
    nav.pending = new Promise<void>(() => {});
    const container = document.createElement("div");
    document.body.append(container);
    container.innerHTML = renderToString(<SearchBox />);
    const typed = container.querySelector("input") as HTMLInputElement;
    typed.value = "spiwor";
    nav.pending = null;
    await act(async () => {
      hydrateRoot(container, <SearchBox />, { onRecoverableError: () => {} });
    });
    expect(container.querySelector("input")).toBe(typed);
    expect(typed.value).toBe("spiwor");
  });

  it("puts the phrase of /szukaj into the field without replacing it", async () => {
    nav.pathname = "/szukaj";
    nav.params = new URLSearchParams("q=kurtka");
    const { rerender } = render(<SearchBox />);
    const before = field();
    expect(before.value).toBe("kurtka");
    nav.params = new URLSearchParams("q=plecak");
    rerender(<SearchBox />);
    expect(field()).toBe(before);
    expect(field().value).toBe("plecak");
  });

  it("leaves what is typed alone while the phrase of the address does not change", () => {
    nav.pathname = "/szukaj";
    nav.params = new URLSearchParams("q=kurtka&sort=nazwa");
    const { rerender } = render(<SearchBox />);
    fireEvent.change(field(), { target: { value: "kurtka zimowa" } });
    nav.params = new URLSearchParams("q=kurtka&sort=cena-rosnaco");
    rerender(<SearchBox />);
    expect(field().value).toBe("kurtka zimowa");
  });

  it("empties the field when leaving /szukaj", () => {
    nav.pathname = "/szukaj";
    nav.params = new URLSearchParams("q=kurtka");
    const { rerender } = render(<SearchBox />);
    nav.pathname = "/c/buty";
    nav.params = new URLSearchParams();
    rerender(<SearchBox />);
    expect(field().value).toBe("");
  });
});
